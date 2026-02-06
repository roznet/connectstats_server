"""Non-destructive schema validation tests."""

from .config import Config
from .runner import TestSuite


# Tables created by ensure_schema() in shared.php
MAIN_TABLES = [
    "usage", "users", "users_usage", "tokens",
    "cache_activities", "cache_activities_map",
    "cache_fitfiles", "cache_fitfiles_map",
    "activities", "weather", "fitsession", "fitfiles",
    "assets", "assets_s3", "schema",
]

# Tables in the queue database
QUEUE_TABLES = ["tasks", "queues", "schema"]

# Key columns that must exist on critical tables
KEY_COLUMNS = {
    "activities": ["activity_id", "parent_activity_id", "summaryId", "cs_user_id"],
    "tokens": ["token_id", "userAccessToken", "userAccessTokenSecret", "cs_user_id"],
    "fitfiles": ["file_id", "callbackURL", "summaryId", "activity_id"],
    "users": ["cs_user_id", "userId"],
}

# Tables that need AUTO_INCREMENT on their primary key
AUTO_INCREMENT_TABLES = {
    "users": "cs_user_id",
    "tokens": "token_id",
    "activities": "activity_id",
    "fitfiles": "file_id",
    "cache_activities": "cache_id",
    "cache_fitfiles": "cache_id",
    "assets": "asset_id",
    "assets_s3": "asset_id",
    "usage": "usage_id",
}


def _table_exists(cursor, table: str) -> bool:
    cursor.execute("SHOW TABLES LIKE %s", (table,))
    return cursor.fetchone() is not None


def _column_exists(cursor, table: str, column: str) -> bool:
    cursor.execute("SHOW COLUMNS FROM `%s` LIKE %%s" % table, (column,))
    return cursor.fetchone() is not None


def _has_auto_increment(cursor, table: str, column: str) -> bool:
    cursor.execute("SHOW COLUMNS FROM `%s` WHERE Field = %%s" % table, (column,))
    row = cursor.fetchone()
    if row is None:
        return False
    # Extra field (index 5) contains 'auto_increment'
    return "auto_increment" in (row[5] if len(row) > 5 else "").lower()


def run(config: Config, suite: TestSuite) -> None:
    """Run schema validation tests."""

    # --- Main database ---
    with config.db_connection() as conn:
        cursor = conn.cursor()

        # Check dev marker
        suite.start_test("dev marker table exists")
        suite.check(_table_exists(cursor, "dev"), "dev marker table exists",
                     "Required for reset_schema safety check")

        # Check all main tables
        for table in MAIN_TABLES:
            suite.start_test(f"table {table} exists")
            suite.check(_table_exists(cursor, table), f"table '{table}' exists")

        # Schema version
        suite.start_test("schema version >= 8")
        cursor.execute("SELECT MAX(version) AS v FROM `schema`")
        row = cursor.fetchone()
        version = int(row[0]) if row and row[0] else 0
        suite.check(version >= 8, "schema version >= 8", f"got {version}")

        # Key columns
        for table, columns in KEY_COLUMNS.items():
            if not _table_exists(cursor, table):
                continue
            for col in columns:
                suite.start_test(f"{table}.{col} exists")
                suite.check(
                    _column_exists(cursor, table, col),
                    f"{table}.{col} exists",
                )

        # AUTO_INCREMENT
        for table, col in AUTO_INCREMENT_TABLES.items():
            if not _table_exists(cursor, table):
                continue
            suite.start_test(f"{table}.{col} AUTO_INCREMENT")
            suite.check(
                _has_auto_increment(cursor, table, col),
                f"{table}.{col} has AUTO_INCREMENT",
            )

    # --- Queue database ---
    if not config.db_queue:
        suite.start_test("queue database configured")
        suite.check(False, "queue database configured", "db_queue not set in config")
        return

    with config.queue_connection() as conn:
        cursor = conn.cursor()

        for table in QUEUE_TABLES:
            suite.start_test(f"queue table {table} exists")
            suite.check(_table_exists(cursor, table), f"queue table '{table}' exists")

        # Queue schema version
        if _table_exists(cursor, "schema"):
            suite.start_test("queue schema version >= 2")
            cursor.execute("SELECT MAX(version) AS v FROM `schema`")
            row = cursor.fetchone()
            version = int(row[0]) if row and row[0] else 0
            suite.check(version >= 2, "queue schema version >= 2", f"got {version}")
