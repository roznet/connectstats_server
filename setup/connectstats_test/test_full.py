"""Full integration test — destructive (resets the database)."""

import base64
import copy
import hashlib
import json
import os
import subprocess
import time

from .client import ConnectStatsClient, Response
from .config import Config
from .runner import TestSuite
from . import test_schema


# __file__ is setup/connectstats_test/test_full.py → up 2 = setup/, up 3 = project root
SETUP_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROJECT_ROOT = os.path.dirname(SETUP_DIR)


def _load_sample(filename: str) -> dict:
    path = os.path.join(SETUP_DIR, filename)
    with open(path) as f:
        return json.load(f)


def _patch_timestamp(data: dict) -> dict:
    """Replace the sample timestamp with current time."""
    now = int(time.time())
    raw = json.dumps(data)
    raw = raw.replace("1557209607", str(now))
    return json.loads(raw)


def _run_php(script: str, args: list[str], cwd: str, verbose: bool = False) -> bool:
    """Run a PHP script via subprocess. Returns True on success."""
    cmd = ["php", script] + args
    if verbose:
        print(f"    $ {' '.join(cmd)}")
    result = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=60)
    if verbose and result.stdout:
        for line in result.stdout.strip().split("\n"):
            print(f"      {line}")
    if result.returncode != 0:
        if verbose and result.stderr:
            for line in result.stderr.strip().split("\n"):
                print(f"      [stderr] {line}")
        return False
    return True


def _db_query(config: Config, query: str, params: tuple = ()) -> list:
    with config.db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute(query, params)
        return cursor.fetchall()


def _db_execute(config: Config, query: str, params: tuple = ()) -> None:
    with config.db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute(query, params)
        conn.commit()


def _wait_for_caches(config: Config, garmin_dir: str, suite: TestSuite, max_wait: int = 15) -> None:
    """Wait for the queue worker to process webhooks, then process what's left by hand."""
    tables = (("cache_activities", "runactivities.php"), ("cache_fitfiles", "runfitfiles.php"))
    for _ in range(max_wait):
        pending = sum(_db_query(config, f"SELECT COUNT(*) FROM {t} WHERE processed_ts IS NULL")[0][0] for t, _s in tables)
        if pending == 0:
            return
        time.sleep(1)
    for table, script in tables:
        for row in _db_query(config, f"SELECT cache_id FROM {table} WHERE processed_ts IS NULL ORDER BY cache_id"):
            _run_php(script, [str(row[0])], garmin_dir, verbose=suite.verbose)


def _test_notifications_without_token(client: ConnectStatsClient, config: Config, suite: TestSuite, garmin_dir: str) -> None:
    now = int(time.time())

    # A newer, deregistered token of the same Garmin user: downloads must not use it
    _db_execute(config,
                "INSERT INTO tokens (cs_user_id, userId, userAccessToken, userAccessTokenSecret) "
                "SELECT cs_user_id, userId, 'testtoken-dead', NULL FROM tokens WHERE userAccessToken = 'testtoken'")
    cs_user_id, user_id = _db_query(config, "SELECT cs_user_id, userId FROM tokens WHERE userAccessToken = 'testtoken'")[0]

    # Old and new format in one notification, as during the transition
    template = copy.deepcopy(_load_sample("sample-backfill-activities.json")["activities"][0])
    template["startTimeInSeconds"] = now
    new_format = dict(template, summaryId="9000000001", userId=user_id)
    new_format.pop("userAccessToken", None)
    old_format = dict(template, summaryId="9000000002", userId=user_id, userAccessToken="testtoken",
                      startTimeInSeconds=now + 1000)
    unknown_user = dict(new_format, summaryId="9000000003", userId="unknown-garmin-user", startTimeInSeconds=now + 2000)

    suite.start_test("activities without token accepted")
    resp = client.post_json("api/garmin/activities", {"activities": [new_format, old_format, unknown_user]})
    suite.check(resp.ok, "activities without token accepted", f"status={resp.status}")

    # Multi sport: the parent links its children by userId
    # (new summaryIds: the step above already stored these activities, with their token)
    multi = copy.deepcopy(_load_sample("sample-backfill-activities-multi.json"))
    for activity in multi["activities"]:
        activity["userId"] = user_id
        activity.pop("userAccessToken", None)
        activity["summaryId"] = activity["summaryId"].replace("401866", "901866")
        if "parentSummaryId" in activity:
            activity["parentSummaryId"] = activity["parentSummaryId"].replace("401866", "901866")
    suite.start_test("multi sport without token accepted")
    resp = client.post_json("api/garmin/activities", multi)
    suite.check(resp.ok, "multi sport without token accepted", f"status={resp.status}")

    # File with the token parameter Garmin adds to the callback URL
    callback = "http://app/mock/garmin/activity_file.php?id=9000000011&token=callback-token"
    suite.start_test("file without token accepted")
    resp = client.post_json("api/garmin/file", {"file": [{
        "userId": user_id, "summaryId": "9000000011", "fileType": "FIT",
        "callbackURL": callback, "startTimeInSeconds": now,
    }]})
    suite.check(resp.ok, "file without token accepted", f"status={resp.status}")

    _wait_for_caches(config, garmin_dir, suite)

    rows = dict((r[0], r[1]) for r in _db_query(
        config, "SELECT summaryId, cs_user_id FROM activities WHERE summaryId IN ('9000000001','9000000002','9000000003')"))
    suite.start_test("activity without token linked to user")
    suite.check(rows.get("9000000001") == cs_user_id, "activity without token linked to user", f"got {rows}")
    suite.start_test("activity with token linked to user")
    suite.check(rows.get("9000000002") == cs_user_id, "activity with token linked to user", f"got {rows}")
    suite.start_test("activity of unknown user saved, not linked")
    suite.check("9000000003" in rows and rows["9000000003"] is None, "activity of unknown user saved, not linked", f"got {rows}")

    suite.start_test("multi sport child linked to parent without token")
    linked = _db_query(config,
                       "SELECT c.parent_activity_id, p.activity_id FROM activities c, activities p "
                       "WHERE c.summaryId = '9018666913' AND p.summaryId = '9018666911'")
    suite.check(bool(linked) and linked[0][0] == linked[0][1], "multi sport child linked to parent without token", f"got {linked}")

    # The download needs the live token: the mock refuses any other
    suite.start_test("file without token downloaded with live token")
    found = []
    for _ in range(15):
        found = _db_query(config, "SELECT file_id, cs_user_id, asset_id FROM fitfiles WHERE summaryId = '9000000011'")
        if found and found[0][2]:
            break
        time.sleep(1)
    if found and not found[0][2]:
        _run_php("runcallback.php", ["fitfiles", str(found[0][0])], garmin_dir, verbose=suite.verbose)
        found = _db_query(config, "SELECT file_id, cs_user_id, asset_id FROM fitfiles WHERE summaryId = '9000000011'")
    suite.check(bool(found) and bool(found[0][2]) and found[0][1] == cs_user_id,
                "file without token downloaded with live token", f"got {found}")


def _test_deregistration(client: ConnectStatsClient, config: Config, suite: TestSuite) -> None:
    def live_tokens() -> set:
        return set(r[0] for r in _db_query(
            config, "SELECT userAccessToken FROM tokens WHERE userAccessTokenSecret IS NOT NULL AND userAccessToken LIKE 'dereg-%'"))

    for token, user in (("dereg-a1", "dereg-user-a"), ("dereg-a2", "dereg-user-a"), ("dereg-b1", "dereg-user-b"),
                        ("dereg-b2", "dereg-user-b"), ("dereg-c1", "dereg-user-c")):
        _db_execute(config, "INSERT INTO tokens (userId, userAccessToken, userAccessTokenSecret) VALUES (%s, %s, 'secret')", (user, token))

    suite.start_test("deregistration by userId clears that user only")
    resp = client.post_json("api/garmin/deregistration", {"deregistrations": [{"userId": "dereg-user-a"}]})
    live = live_tokens()
    suite.check(resp.ok and live == {"dereg-b1", "dereg-b2", "dereg-c1"},
                "deregistration by userId clears that user only", f"status={resp.status}, live={live}")

    suite.start_test("deregistration by token clears that token only")
    resp = client.post_json("api/garmin/deregistration", {"deregistrations": [{"userId": "dereg-user-b", "userAccessToken": "dereg-b1"}]})
    live = live_tokens()
    suite.check(resp.ok and live == {"dereg-b2", "dereg-c1"},
                "deregistration by token clears that token only", f"status={resp.status}, live={live}")

    suite.start_test("deregistration without identifier is an error")
    resp = client.post_json("api/garmin/deregistration", {"deregistrations": [{"other": "value"}]})
    live = live_tokens()
    suite.check(resp.status == 500 and live == {"dereg-b2", "dereg-c1"},
                "deregistration without identifier is an error", f"status={resp.status}, live={live}")

    suite.start_test("deregistration ignores SQL in userId")
    resp = client.post_json("api/garmin/deregistration", {"deregistrations": [{"userId": "x' OR '1'='1"}]})
    live = live_tokens()
    suite.check(live == {"dereg-b2", "dereg-c1"}, "deregistration ignores SQL in userId", f"status={resp.status}, live={live}")


def _test_sql_injection(client: ConnectStatsClient, config: Config, suite: TestSuite, garmin_dir: str) -> None:
    now = int(time.time())
    template = copy.deepcopy(_load_sample("sample-backfill-activities.json")["activities"][0])
    template["startTimeInSeconds"] = now + 3000
    template.pop("userAccessToken", None)

    # Without escaping, this token matches the first row of tokens and links the activity to that user
    by_token = dict(template, summaryId="9200000001", userId="sql-user", userAccessToken="x' OR '1'='1")
    by_user = dict(template, summaryId="9200000002", userId="x' OR '1'='1", startTimeInSeconds=now + 3001)
    by_summary = dict(template, summaryId="sql'summary", userId="sql-user2", startTimeInSeconds=now + 3002)

    suite.start_test("hostile notification accepted")
    resp = client.post_json("api/garmin/activities", {"activities": [by_token, by_user, by_summary]})
    suite.check(resp.ok, "hostile notification accepted", f"status={resp.status}")

    _wait_for_caches(config, garmin_dir, suite)

    rows = dict((r[0], (r[1], r[2])) for r in _db_query(
        config, "SELECT summaryId, cs_user_id, userId FROM activities WHERE summaryId IN ('9200000001','9200000002','sql''summary')"))
    suite.start_test("hostile token doesn't link to another user")
    suite.check("9200000001" in rows and rows["9200000001"][0] is None, "hostile token doesn't link to another user", f"got {rows}")
    suite.start_test("hostile userId stored as is, not linked")
    suite.check(rows.get("9200000002") == (None, "x' OR '1'='1"), "hostile userId stored as is, not linked", f"got {rows}")
    suite.start_test("quote in summaryId stored as is")
    suite.check("sql'summary" in rows, "quote in summaryId stored as is", f"got {rows}")


def _test_schema_upgrade(client: ConnectStatsClient, config: Config, suite: TestSuite, api_dir: str) -> None:
    def auto_increment_count() -> int:
        return _db_query(config, "SELECT COUNT(*) FROM information_schema.columns "
                                 "WHERE table_schema = DATABASE() AND extra LIKE '%%auto_increment%%'")[0][0]

    before = auto_increment_count()

    # A version older than the code makes the next request apply the schema again
    _db_execute(config, "DELETE FROM `schema`")
    _db_execute(config, "INSERT INTO `schema` (version) VALUES (1)")
    resp = client.post_json("api/garmin/deregistration", {"deregistrations": []})

    suite.start_test("schema upgrade keeps AUTO_INCREMENT")
    after = auto_increment_count()
    suite.check(resp.ok and before > 0 and after == before, "schema upgrade keeps AUTO_INCREMENT",
                f"status={resp.status}, before={before}, after={after}")

    suite.start_test("insert works after schema upgrade")
    _db_execute(config, "INSERT INTO users (userId) VALUES ('after-schema-upgrade')")
    suite.check(True, "insert works after schema upgrade")

    # Real changes: a new column and a larger column, on a table of its own
    script = (
        "include_once('../shared.php');"
        "$p = new GarminProcess();"
        "$p->sql->create_or_alter('test_alter', ["
        "'id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'name' => 'VARCHAR(8)',"
        "'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP']);"
        "$p->sql->execute_query(\"INSERT INTO test_alter (name) VALUES ('first')\");"
        "$p->sql->create_or_alter('test_alter', ["
        "'id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'name' => 'VARCHAR(16)',"
        "'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'added' => 'BIGINT(20) UNSIGNED']);"
        "$p->sql->execute_query(\"INSERT INTO test_alter (name) VALUES ('second')\");"
    )
    result = subprocess.run(["php", "-r", script], cwd=os.path.join(api_dir, "garmin"), capture_output=True, text=True, timeout=60)
    columns = dict((r[0], (r[1], r[2])) for r in _db_query(
        config, "SELECT column_name, column_type, extra FROM information_schema.columns "
                "WHERE table_schema = DATABASE() AND table_name = 'test_alter'"))
    count = _db_query(config, "SELECT COUNT(*) FROM test_alter")[0][0] if columns else 0
    _db_execute(config, "DROP TABLE IF EXISTS test_alter")

    suite.start_test("column change applied, rest kept")
    ok = (columns.get("name", ("",))[0] == "varchar(16)" and "added" in columns
          and "auto_increment" in columns.get("id", ("", ""))[1]
          and "on update" in columns.get("ts", ("", ""))[1].lower() and count == 2)
    suite.check(ok, "column change applied, rest kept", f"columns={columns}, rows={count}, stderr={result.stderr[:200]}")


def _b64u(s: str) -> str:
    return base64.urlsafe_b64encode(s.encode()).decode().rstrip("=")


VERIFIER = "v" * 50
REDIRECT = "connectstats://oauth2"


def _oauth2_login(client: ConnectStatsClient, user_id: str) -> Response:
    return client.request("POST", "api/connectstats/user_register_oauth2", fields={
        "code": f"code.{_b64u(user_id)}", "code_verifier": VERIFIER, "redirect_uri": REDIRECT})


def _wait_for_file(config: Config, suite: TestSuite, garmin_dir: str, summary_id: str) -> list:
    found = []
    for _ in range(15):
        found = _db_query(config, "SELECT file_id, cs_user_id, asset_id FROM fitfiles WHERE summaryId = %s", (summary_id,))
        if found and found[0][2]:
            return found
        time.sleep(1)
    if found and not found[0][2]:
        _run_php("runcallback.php", ["fitfiles", str(found[0][0])], garmin_dir, verbose=suite.verbose)
        found = _db_query(config, "SELECT file_id, cs_user_id, asset_id FROM fitfiles WHERE summaryId = %s", (summary_id,))
    return found


def _post_file(client: ConnectStatsClient, user_id: str, summary_id: str) -> Response:
    return client.post_json("api/garmin/file", {"file": [{
        "userId": user_id, "summaryId": summary_id, "fileType": "FIT",
        "callbackURL": f"http://app/mock/garmin/activity_file.php?id={summary_id}&token=callback-token",
        "startTimeInSeconds": int(time.time()),
    }]})


def _test_oauth2(client: ConnectStatsClient, config: Config, suite: TestSuite, api_dir: str, garmin_dir: str) -> None:
    cs_dir = os.path.join(api_dir, "connectstats")

    # Status endpoint tells the app which login to use (dev config has garmin_login = oauth2)
    suite.start_test("status returns garmin_login and client id")
    resp = client.request("GET", "api/status/api")
    data = resp.json_data or {}
    suite.check(data.get("garmin_login") == "oauth2" and data.get("garmin_client_id") == "local-oauth2-client-id",
                "status returns garmin_login and client id", f"got {data}")

    # Login
    suite.start_test("oauth2 login")
    resp = _oauth2_login(client, "oauth2-user")
    login = resp.json_data or {}
    ok = resp.ok and login.get("token_id") and login.get("server_token") and login.get("userId") == "oauth2-user"
    suite.check(bool(ok), "oauth2 login", f"status={resp.status} body={resp.body[:200]!r}")
    if not ok:
        return
    token_id, server_token = login["token_id"], login["server_token"]

    suite.start_test("oauth2 login stores a hash, not the server token")
    rows = _db_query(config, "SELECT server_token_hash, userAccessToken, userAccessTokenSecret FROM tokens WHERE token_id = %s", (token_id,))
    suite.check(bool(rows) and rows[0][0] == hashlib.sha256(server_token.encode()).hexdigest() and rows[0][1] is None and rows[0][2] is None,
                "oauth2 login stores a hash, not the server token", f"got {rows}")
    suite.start_test("oauth2 login reports activity export")
    suite.check(login.get("activity_export") is True, "oauth2 login reports activity export", f"got {login}")
    resp = _oauth2_login(client, "noexport-user")
    suite.start_test("oauth2 login without activity export flagged")
    suite.check(resp.ok and (resp.json_data or {}).get("activity_export") is False,
                "oauth2 login without activity export flagged", f"status={resp.status} body={resp.body[:200]!r}")

    for name, method, fields, expected in (
            ("oauth2 login with bad code", "POST", {"code": "bad", "code_verifier": VERIFIER, "redirect_uri": REDIRECT}, 401),
            ("oauth2 login with GET refused", "GET", None, 405),
            ("oauth2 login without verifier refused", "POST", {"code": "code.x", "redirect_uri": REDIRECT}, 400)):
        suite.start_test(name)
        resp = client.request(method, "api/connectstats/user_register_oauth2", fields=fields)
        suite.check(resp.status == expected, name, f"status={resp.status}")

    # Bearer auth app -> server
    suite.start_test("bearer search")
    resp = client.get_bearer(f"api/connectstats/search?token_id={token_id}&start=0&limit=5", server_token)
    suite.check(resp.ok and resp.json_data is not None and "activityList" in resp.json_data, "bearer search", f"status={resp.status}")
    suite.start_test("bearer with another token_id rejected")
    resp = client.get_bearer("api/connectstats/search?token_id=1&start=0&limit=5", server_token)
    suite.check(resp.status == 401, "bearer with another token_id rejected", f"status={resp.status}")
    suite.start_test("invalid bearer rejected")
    resp = client.get_bearer(f"api/connectstats/search?token_id={token_id}&start=0&limit=5", "not-the-token")
    suite.check(resp.status == 401, "invalid bearer rejected", f"status={resp.status}")
    suite.start_test("bearer validateuser hides hash")
    resp = client.get_bearer(f"api/connectstats/validateuser?token_id={token_id}", server_token)
    suite.check(resp.ok and resp.json_data and resp.json_data.get("token_id") is not None and "server_token_hash" not in resp.json_data,
                "bearer validateuser hides hash", f"status={resp.status} body={resp.body[:200]!r}")

    # Exchange: own account first, as on prod
    suite.start_test("exchange dry run changes nothing")
    _run_php("exchange_oauth2_tokens.php", ["--user-id=testuser"], cs_dir, verbose=suite.verbose)
    suite.check(not _db_query(config, "SELECT userId FROM garmin_oauth2 WHERE userId = 'testuser'"),
                "exchange dry run changes nothing")
    suite.start_test("exchange for one user")
    ok = _run_php("exchange_oauth2_tokens.php", ["--execute", "--user-id=testuser"], cs_dir, verbose=suite.verbose)
    rows = _db_query(config, "SELECT source, access_token LIKE 'at.%%', refresh_expires_ts > UNIX_TIMESTAMP() + 80*86400 FROM garmin_oauth2 WHERE userId = 'testuser'")
    suite.check(ok and rows == [("exchange", 1, 1)], "exchange for one user", f"ok={ok} rows={rows}")
    suite.start_test("exchange is one user only")
    others = _db_query(config, "SELECT COUNT(*) FROM garmin_oauth2 WHERE source = 'exchange'")[0][0]
    suite.check(others == 1, "exchange is one user only", f"exchanged={others}")
    suite.start_test("oauth1 app auth works after exchange")
    resp = client.get("api/connectstats/search?token_id=1&start=0&limit=5", token_id=1)
    suite.check(resp.ok, "oauth1 app auth works after exchange", f"status={resp.status}")

    # Downloads use Bearer once the user has OAuth 2.0 credentials: without the OAuth 1.0
    # secret (as 30 days after the exchange), only Bearer can work
    secret = _db_query(config, "SELECT userAccessTokenSecret FROM tokens WHERE userAccessToken = 'testtoken'")[0][0]
    _db_execute(config, "UPDATE tokens SET userAccessTokenSecret = NULL WHERE userAccessToken = 'testtoken'")
    try:
        _post_file(client, "testuser", "9300000001")
        _wait_for_caches(config, garmin_dir, suite)
        suite.start_test("file downloaded with bearer")
        found = _wait_for_file(config, suite, garmin_dir, "9300000001")
        suite.check(bool(found) and bool(found[0][2]), "file downloaded with bearer", f"got {found}")

        # Expired access token: refreshed before the call, refresh token rotated
        old_refresh = _db_query(config, "SELECT refresh_token FROM garmin_oauth2 WHERE userId = 'testuser'")[0][0]
        _db_execute(config, "UPDATE garmin_oauth2 SET access_expires_ts = UNIX_TIMESTAMP() WHERE userId = 'testuser'")
        _post_file(client, "testuser", "9300000002")
        _wait_for_caches(config, garmin_dir, suite)
        found = _wait_for_file(config, suite, garmin_dir, "9300000002")
        rows = _db_query(config, "SELECT refresh_token, access_expires_ts > UNIX_TIMESTAMP() + 3600, source FROM garmin_oauth2 WHERE userId = 'testuser'")
        suite.start_test("expired access token refreshed")
        suite.check(bool(found) and bool(found[0][2]) and rows and rows[0][0] != old_refresh and rows[0][1] == 1 and rows[0][2] == "refresh",
                    "expired access token refreshed", f"found={found} rows={[(r[1], r[2]) for r in rows]}")

        # Token Garmin rejects before its expiry: refresh once and retry
        _db_execute(config, "UPDATE garmin_oauth2 SET access_token = CONCAT('at.', %s, '.stale') WHERE userId = 'testuser'", (_b64u("testuser"),))
        _post_file(client, "testuser", "9300000003")
        _wait_for_caches(config, garmin_dir, suite)
        found = _wait_for_file(config, suite, garmin_dir, "9300000003")
        suite.start_test("rejected access token refreshed and retried")
        suite.check(bool(found) and bool(found[0][2]), "rejected access token refreshed and retried", f"got {found}")
    finally:
        _db_execute(config, "UPDATE tokens SET userAccessTokenSecret = %s WHERE userAccessToken = 'testtoken'", (secret,))

    # Scheduled refresh: refreshes what expires soon; a rejected refresh removes the credentials
    _oauth2_login(client, "revoked-user")
    _oauth2_login(client, "flaky-user")
    _db_execute(config, "UPDATE garmin_oauth2 SET refresh_expires_ts = UNIX_TIMESTAMP() + 86400 "
                        "WHERE userId IN ('testuser', 'revoked-user', 'flaky-user')")
    before = dict(_db_query(config, "SELECT userId, refresh_token FROM garmin_oauth2"))
    _run_php("refresh_oauth2_tokens.php", [], cs_dir, verbose=suite.verbose)
    after = dict(_db_query(config, "SELECT userId, refresh_token FROM garmin_oauth2"))
    suite.start_test("scheduled refresh renews expiring tokens")
    suite.check(after.get("testuser") not in (None, before.get("testuser")) and after.get("oauth2-user") == before.get("oauth2-user"),
                "scheduled refresh renews expiring tokens", "")
    suite.start_test("rejected refresh removes credentials")
    revoked = _db_query(config, "SELECT COUNT(*) FROM tokens WHERE userId = 'revoked-user' AND server_token_hash IS NOT NULL")[0][0]
    suite.check("revoked-user" not in after and revoked == 0, "rejected refresh removes credentials", f"live={revoked}")
    suite.start_test("failed refresh keeps credentials")
    flaky = _db_query(config, "SELECT refresh_failures FROM garmin_oauth2 WHERE userId = 'flaky-user'")
    suite.check(after.get("flaky-user") == before.get("flaky-user") and flaky and flaky[0][0] >= 1,
                "failed refresh keeps credentials", f"got {flaky}")

    # Deregistration webhook by userId removes OAuth 2.0 credentials and server tokens
    resp = _oauth2_login(client, "dereg-oauth2")
    dereg = resp.json_data or {}
    client.post_json("api/garmin/deregistration", {"deregistrations": [{"userId": "dereg-oauth2"}]})
    suite.start_test("deregistration removes oauth2 credentials")
    resp = client.get_bearer(f"api/connectstats/search?token_id={dereg.get('token_id')}&start=0&limit=5", dereg.get("server_token", ""))
    gone = not _db_query(config, "SELECT userId FROM garmin_oauth2 WHERE userId = 'dereg-oauth2'")
    suite.check(resp.status == 401 and gone, "deregistration removes oauth2 credentials", f"status={resp.status} gone={gone}")

    # Logout: DELETE at Garmin, then the token stops working
    suite.start_test("disconnect oauth2 user")
    resp = client.get_bearer(f"api/connectstats/user_disconnect?token_id={token_id}", server_token)
    data = resp.json_data or {}
    suite.check(resp.ok and data.get("status") == 1 and data.get("garmin") == "oauth2" and data.get("garmin_http") == 204,
                "disconnect oauth2 user", f"status={resp.status} body={resp.body[:200]!r}")
    suite.start_test("token unusable after disconnect")
    resp = client.get_bearer(f"api/connectstats/search?token_id={token_id}&start=0&limit=5", server_token)
    gone = not _db_query(config, "SELECT userId FROM garmin_oauth2 WHERE userId = 'oauth2-user'")
    suite.check(resp.status == 401 and gone, "token unusable after disconnect", f"status={resp.status} gone={gone}")

    # OAuth 1.0 user disconnect: signed DELETE (mock needs secret = "secret-" + token)
    _db_execute(config, "INSERT INTO tokens (cs_user_id, userId, userAccessToken, userAccessTokenSecret) VALUES (999, 'disc-user', 'disc1', 'secret-disc1')")
    disc_id = _db_query(config, "SELECT token_id FROM tokens WHERE userAccessToken = 'disc1'")[0][0]
    client.clear_token_cache()
    suite.start_test("disconnect oauth1 user")
    resp = client.get(f"api/connectstats/user_disconnect?token_id={disc_id}", token_id=disc_id)
    data = resp.json_data or {}
    live = _db_query(config, "SELECT userAccessTokenSecret FROM tokens WHERE token_id = %s", (disc_id,))[0][0]
    suite.check(resp.ok and data.get("garmin") == "oauth1" and data.get("garmin_http") == 204 and live is None,
                "disconnect oauth1 user", f"status={resp.status} body={resp.body[:200]!r}")


def _test_user_id_header(client: ConnectStatsClient, config: Config, suite: TestSuite, garmin_dir: str) -> None:
    template = copy.deepcopy(_load_sample("sample-backfill-activities.json")["activities"][0])
    template.pop("userAccessToken", None)
    template.pop("userId", None)
    template.update(summaryId="9400000001", startTimeInSeconds=int(time.time()) + 4000)
    cs_user_id = _db_query(config, "SELECT cs_user_id FROM tokens WHERE userAccessToken = 'testtoken'")[0][0]

    suite.start_test("notification with userId in a header")
    resp = client.post_json("api/garmin/activities", {"activities": [template]}, headers={"Garmin-User-Id": "testuser"})
    _wait_for_caches(config, garmin_dir, suite)
    rows = _db_query(config, "SELECT cs_user_id, userId FROM activities WHERE summaryId = '9400000001'")
    logged = _db_query(config, "SELECT COUNT(*) FROM error_cache_activities WHERE message LIKE '%%taken from a header%%'")[0][0]
    suite.check(resp.ok and rows == [(cs_user_id, "testuser")] and logged >= 1,
                "notification with userId in a header", f"status={resp.status} rows={rows} logged={logged}")


def run(client: ConnectStatsClient, config: Config, suite: TestSuite) -> None:
    """Run the full destructive integration test."""

    api_dir = os.path.join(PROJECT_ROOT, "api")
    garmin_dir = os.path.join(api_dir, "garmin")

    # 1. Reset database
    suite.start_test("reset database")
    resp = client.get("api/connectstats/reset", system=True)
    suite.check(resp.ok, "reset database", f"status={resp.status}")
    client.clear_token_cache()

    # 2. Register test users
    suite.start_test("register user 1")
    resp = client.get("api/connectstats/user_register?userAccessToken=testtoken&userAccessTokenSecret=testsecret")
    ok1 = resp.ok
    suite.check(ok1, "register user 1", f"status={resp.status}")

    suite.start_test("register user 2")
    resp = client.get("api/connectstats/user_register?userAccessToken=testtoken2&userAccessTokenSecret=testsecret2")
    ok2 = resp.ok
    suite.check(ok2, "register user 2", f"status={resp.status}")

    if not (ok1 and ok2):
        suite.start_test("cannot continue without users")
        suite.check(False, "cannot continue without registered users")
        return

    # 3. Upload activities
    activities_data = _load_sample("sample-backfill-activities.json")
    activities_data = _patch_timestamp(activities_data)
    sent_activities = activities_data.get("activities", [])

    suite.start_test("upload activities")
    resp = client.post_json("api/garmin/activities", activities_data)
    suite.check(resp.ok, "upload activities", f"status={resp.status}, sent {len(sent_activities)}")

    # 4. Upload file notification
    file_data = _load_sample("sample-file-local.json")
    file_data = _patch_timestamp(file_data)

    suite.start_test("upload file notification")
    resp = client.post_json("api/garmin/file", file_data)
    suite.check(resp.ok, "upload file notification", f"status={resp.status}")

    # 5. Process tasks — wait for queue worker, then handle any remaining
    #    The webhook POST triggers queue tasks; give the worker time to process
    suite.start_test("wait for queue processing")
    waited = 0
    max_wait = 10
    while waited < max_wait:
        with config.db_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM cache_activities WHERE processed_ts IS NULL")
            pending_act = cursor.fetchone()[0]
            cursor.execute("SELECT COUNT(*) FROM cache_fitfiles WHERE processed_ts IS NULL")
            pending_fit = cursor.fetchone()[0]
        if pending_act == 0 and pending_fit == 0:
            break
        if waited == 0 and suite.verbose:
            print(f"    waiting for queue ({pending_act} activities, {pending_fit} fitfiles pending)...")
        time.sleep(1)
        waited += 1
    if pending_act > 0 or pending_fit > 0:
        # Queue didn't finish — process manually
        suite.check(True, "wait for queue processing", f"timed out, processing manually")

        with config.db_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT cache_id FROM cache_activities WHERE processed_ts IS NULL ORDER BY cache_id")
            cache_rows = cursor.fetchall()

        suite.start_test("process activity caches")
        all_ok = True
        for (cache_id,) in cache_rows:
            ok = _run_php("runactivities.php", [str(cache_id)], garmin_dir, verbose=suite.verbose)
            if not ok:
                all_ok = False
        suite.check(all_ok, "process activity caches", f"{len(cache_rows)} caches")

        with config.db_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT cache_id FROM cache_fitfiles WHERE processed_ts IS NULL ORDER BY cache_id")
            fit_cache_rows = cursor.fetchall()

        suite.start_test("process fitfile caches")
        all_ok = True
        for (cache_id,) in fit_cache_rows:
            ok = _run_php("runfitfiles.php", [str(cache_id)], garmin_dir, verbose=suite.verbose)
            if not ok:
                all_ok = False
        suite.check(all_ok, "process fitfile caches", f"{len(fit_cache_rows)} caches")
    else:
        suite.check(True, "wait for queue processing", f"done in {waited}s")

    # 6. Validate activities
    suite.start_test("fetch activities via search")
    resp = client.get("api/connectstats/search?token_id=1&start=0&limit=50", token_id=1)
    suite.check(
        resp.ok and resp.json_data is not None and "activityList" in resp.json_data,
        "fetch activities via search",
        f"status={resp.status}",
    )

    if resp.json_data and "activityList" in resp.json_data:
        fetched = resp.json_data["activityList"]

        # Count comparison
        suite.start_test("activity count matches")
        suite.check(
            len(fetched) == len(sent_activities),
            "activity count matches",
            f"sent={len(sent_activities)}, fetched={len(fetched)}",
        )

        # Check all summaryIds present
        fetched_ids = {a["summaryId"] for a in fetched}
        sent_ids = {a["summaryId"] for a in sent_activities}
        missing = sent_ids - fetched_ids
        suite.start_test("all summaryIds present")
        suite.check(len(missing) == 0, "all summaryIds present",
                     f"missing: {missing}" if missing else "")

        # Verify parent/child relationships
        cs_id_map = {a["cs_activity_id"]: a for a in fetched if "cs_activity_id" in a}
        parent_ok = True
        parent_msg = ""
        for a in fetched:
            if "cs_parent_activity_id" in a and a["cs_parent_activity_id"]:
                if a["cs_parent_activity_id"] not in cs_id_map:
                    parent_ok = False
                    parent_msg = f"orphan child {a.get('cs_activity_id')} -> parent {a['cs_parent_activity_id']}"
                    break
            if a.get("isParent"):
                children = [
                    s for s in fetched
                    if s.get("cs_parent_activity_id") == a["cs_activity_id"]
                ]
                if len(children) == 0:
                    parent_ok = False
                    parent_msg = f"parent {a.get('cs_activity_id')} has no children"
                    break

        suite.start_test("parent/child relationships valid")
        suite.check(parent_ok, "parent/child relationships valid", parent_msg)

    # 7. Validate auth: wrong token rejected
    suite.start_test("wrong token rejected after rebuild")
    resp = client.get("api/connectstats/search?token_id=1&start=0&limit=50", token_id=2)
    suite.check(resp.status == 401, "wrong token rejected after rebuild", f"got {resp.status}")

    # 8. Notifications without userAccessToken (format after the OAuth 2.0 migration)
    _test_notifications_without_token(client, config, suite, garmin_dir)

    # 9. Deregistration by token and by userId
    _test_deregistration(client, config, suite)

    # 10. Hostile values in notifications
    _test_sql_injection(client, config, suite, garmin_dir)

    # 11. Schema upgrade keeps AUTO_INCREMENT and only changes what changed
    _test_schema_upgrade(client, config, suite, api_dir)

    # 12. OAuth 2.0: login, Bearer auth, exchange, Bearer downloads, refresh, disconnect
    _test_oauth2(client, config, suite, api_dir, garmin_dir)

    # 13. Notification with the Garmin userId in a header instead of the body
    _test_user_id_header(client, config, suite, garmin_dir)

    # 14. Run schema checks
    test_schema.run(config, suite)
