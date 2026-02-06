"""API client with OAuth signing for ConnectStats server."""

import hashlib
import hmac
import base64
import json
import json.decoder
import random
import string
import time
import urllib.parse
from dataclasses import dataclass
from typing import Optional

import urllib3

from .config import Config


def _try_parse_json(data: bytes) -> Optional[dict]:
    """Try to parse JSON, handling PHP responses that append errors after valid JSON."""
    try:
        return json.loads(data)
    except (json.JSONDecodeError, UnicodeDecodeError):
        pass
    # PHP may append HTML errors after valid JSON — try to decode the prefix
    try:
        text = data.decode("utf-8", errors="replace")
        decoder = json.JSONDecoder()
        obj, _ = decoder.raw_decode(text)
        if isinstance(obj, dict):
            return obj
    except (json.JSONDecodeError, ValueError):
        pass
    return None


@dataclass
class Response:
    status: int
    body: bytes
    json_data: Optional[dict] = None

    @property
    def ok(self) -> bool:
        return 200 <= self.status < 300


class ConnectStatsClient:
    """HTTP client for the ConnectStats API with OAuth 1.0 signing."""

    def __init__(self, config: Config, base_url: str):
        self.config = config
        self.base_url = base_url.rstrip("/")
        self._pool = urllib3.PoolManager()
        self._tokens: dict[int, tuple[str, str]] = {}

    def _load_token(self, token_id: int) -> tuple[str, str]:
        """Load access token and secret from DB for a given token_id."""
        if token_id in self._tokens:
            return self._tokens[token_id]

        with self.config.db_connection() as conn:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT userAccessToken, userAccessTokenSecret FROM tokens WHERE token_id = %s",
                (token_id,),
            )
            row = cursor.fetchone()
            if row is None:
                raise ValueError(f"Token {token_id} not found")
            self._tokens[token_id] = (row[0], row[1])
            return self._tokens[token_id]

    @staticmethod
    def _nonce(size: int = 16) -> str:
        return "".join(random.choice(string.ascii_uppercase + string.digits) for _ in range(size))

    def _oauth_header(self, url: str, token: str, secret: str) -> dict[str, str]:
        """Build OAuth 1.0 Authorization header."""
        parsed = urllib.parse.urlparse(url)
        get_params = dict(urllib.parse.parse_qsl(parsed.query))
        url_base = f"{parsed.scheme}://{parsed.netloc}{parsed.path}"

        nonce = self._nonce()
        now = str(int(time.time()))

        oauth_params = {
            "oauth_consumer_key": self.config.consumer_key,
            "oauth_token": token,
            "oauth_nonce": nonce,
            "oauth_timestamp": now,
            "oauth_signature_method": "HMAC-SHA1",
            "oauth_version": "1.0",
        }

        all_params = dict(oauth_params)
        all_params.update(get_params)

        params_str = "&".join(
            f"{k}={urllib.parse.quote(all_params[k])}"
            for k in sorted(all_params.keys())
        )
        base_str = "&".join([
            "GET",
            urllib.parse.quote(url_base, safe=""),
            urllib.parse.quote(params_str),
        ])
        key = "&".join([
            urllib.parse.quote(self.config.consumer_secret),
            urllib.parse.quote(secret),
        ])

        digest = hmac.new(key.encode(), base_str.encode(), hashlib.sha1).digest()
        signature = base64.b64encode(digest)
        oauth_params["oauth_signature"] = signature

        header_parts = ", ".join(
            f'{k}="{urllib.parse.quote(oauth_params[k])}"'
            for k in sorted(oauth_params.keys())
        )
        return {"Authorization": f"OAuth {header_parts}"}

    def get(self, path: str, token_id: Optional[int] = None, system: bool = False) -> Response:
        """Make an authenticated GET request."""
        url = f"{self.base_url}/{path.lstrip('/')}"

        headers: dict[str, str] = {}
        if system:
            headers = self._oauth_header(url, self.config.service_key, self.config.service_key_secret)
        elif token_id is not None:
            access_token, access_secret = self._load_token(token_id)
            headers = self._oauth_header(url, access_token, access_secret)

        resp = self._pool.request("GET", url, headers=headers)
        return Response(status=resp.status, body=resp.data, json_data=_try_parse_json(resp.data))

    def post_json(self, path: str, data: dict) -> Response:
        """POST JSON data (no auth — used for Garmin webhook simulation)."""
        url = f"{self.base_url}/{path.lstrip('/')}"
        encoded = json.dumps(data).encode()

        resp = self._pool.request(
            "POST",
            url,
            body=encoded,
            headers={"Content-Type": "application/json;charset=utf-8"},
        )
        return Response(status=resp.status, body=resp.data, json_data=_try_parse_json(resp.data))

    def clear_token_cache(self) -> None:
        """Clear cached tokens (useful after DB reset)."""
        self._tokens.clear()
