"""Non-destructive API smoke tests."""

from .client import ConnectStatsClient
from .runner import TestSuite


def run(client: ConnectStatsClient, suite: TestSuite) -> None:
    """Run smoke tests against an existing database with at least one user."""

    # Unauthenticated request should be rejected
    suite.start_test("unauthenticated search → 401")
    resp = client.get("api/connectstats/search?token_id=1&start=0&limit=50")
    suite.check(resp.status == 401, "unauthenticated search → 401", f"got {resp.status}")

    # Wrong token (token 2 requesting token 1's data) should be rejected
    suite.start_test("wrong token → 401")
    resp = client.get("api/connectstats/search?token_id=1&start=0&limit=50", token_id=2)
    suite.check(resp.status == 401, "wrong token → 401", f"got {resp.status}")

    # Authenticated search should return valid JSON
    suite.start_test("authenticated search → valid JSON")
    resp = client.get("api/connectstats/search?token_id=1&start=0&limit=50", token_id=1)
    suite.check(
        resp.ok and resp.json_data is not None,
        "authenticated search → valid JSON",
        f"status={resp.status}, has_json={resp.json_data is not None}",
    )

    # JSON response should have activityList key
    suite.start_test("search response has activityList")
    has_key = resp.json_data is not None and "activityList" in resp.json_data
    suite.check(has_key, "search response has 'activityList'")

    # JSON endpoint (fitsession table)
    suite.start_test("json endpoint (fitsession) → valid JSON")
    resp = client.get("api/connectstats/json?token_id=1&limit=50&table=fitsession", token_id=1)
    suite.check(
        resp.ok and resp.json_data is not None,
        "json endpoint (fitsession) → valid JSON",
        f"status={resp.status}",
    )

    # ValidateUser endpoint
    suite.start_test("validateuser → valid JSON with token_id")
    resp = client.get("api/connectstats/validateuser?token_id=1", token_id=1)
    has_token_id = resp.json_data is not None and "token_id" in resp.json_data
    suite.check(
        resp.ok and has_token_id,
        "validateuser → valid JSON with token_id",
        f"status={resp.status}, has_token_id={has_token_id}",
    )

    # File endpoint with invalid activity_id — should not crash
    suite.start_test("file with invalid activity_id → graceful error")
    resp = client.get("api/connectstats/file?token_id=1&activity_id=999999", token_id=1)
    suite.check(
        resp.status != 500,
        "file with invalid activity_id → graceful error",
        f"got status {resp.status}",
    )
