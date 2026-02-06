"""Configuration parsing and DB connection management."""

import re
import os
from dataclasses import dataclass
from contextlib import contextmanager
from typing import Optional

import mysql.connector


@dataclass
class Config:
    consumer_key: str
    consumer_secret: str
    service_key: str
    service_key_secret: str
    db_host: str
    db_username: str
    db_password: str
    database: str
    db_queue: str

    @classmethod
    def from_php_config(cls, path: str) -> "Config":
        """Parse a PHP config.php file and return a Config instance."""
        regexp = re.compile(r"'([a-zA-Z_]+)' +=> +'([-.0-9a-zA-Z_!]+)',")
        raw: dict[str, str] = {}

        with open(path) as f:
            for line in f:
                m = regexp.search(line)
                if m:
                    raw[m.group(1)] = m.group(2)

        return cls(
            consumer_key=raw["consumerKey"],
            consumer_secret=raw["consumerSecret"],
            service_key=raw.get("serviceKey", ""),
            service_key_secret=raw.get("serviceKeySecret", ""),
            db_host=raw["db_host"],
            db_username=raw["db_username"],
            db_password=raw["db_password"],
            database=raw["database"],
            db_queue=raw.get("db_queue", ""),
        )

    @contextmanager
    def db_connection(self, database: Optional[str] = None):
        """Yield a mysql.connector connection, closing it on exit."""
        conn = mysql.connector.connect(
            host=self.db_host,
            user=self.db_username,
            passwd=self.db_password,
            database=database or self.database,
        )
        try:
            yield conn
        finally:
            conn.close()

    @contextmanager
    def queue_connection(self):
        """Yield a connection to the queue database."""
        with self.db_connection(self.db_queue) as conn:
            yield conn


def default_config_path() -> str:
    """Return the default config.php path: <project_root>/api/config.php."""
    # __file__ is setup/connectstats_test/config.py → up 2 = setup/ → up 3 = project root
    project_root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    return os.path.join(project_root, "api", "config.php")
