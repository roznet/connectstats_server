"""Full integration test — destructive (resets the database)."""

import json
import os
import subprocess
import time

from .client import ConnectStatsClient
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

    # 8. Run schema checks
    test_schema.run(config, suite)
