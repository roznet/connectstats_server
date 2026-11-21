<?php
/*
 *  MIT Licence
 *
 *  Copyright (c) 2026 Brice Rosenzweig.
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *
 */

/**
 * Garmin OAuth 2.0 credentials, server side (see designs/oauth2-migration.md).
 *
 * The grant is per Garmin user, so credentials live in `garmin_oauth2`, keyed by the
 * Garmin `userId`, and every `tokens` row of that user shares them.
 *
 * - Access tokens last 24 hours and are refreshed REFRESH_MARGIN seconds before expiry.
 * - Every refresh returns a new refresh token and the old one stops working, so a refresh
 *   runs under a per-user lock (GET_LOCK) and re-reads the row once it holds it: queue
 *   workers run concurrently, and a lost update would lose the user's access.
 * - A refresh Garmin rejects for good (400/401) removes the user's Garmin credentials and
 *   their app credentials, so the app gets a 401 and asks for a new login.
 *
 * Nothing here does anything for a user without a `garmin_oauth2` row, so this is inactive
 * until the token exchange or the first OAuth 2.0 login.
 */
class GarminOAuth2 {

    const DEFAULT_URLS = [
        'url_oauth2_token' => 'https://diauth.garmin.com/di-oauth2-service/oauth/token',
        'url_oauth2_exchange' => 'https://apis.garmin.com/partner-gateway/rest/user/token-exchange',
        'url_oauth2_user_id' => 'https://apis.garmin.com/partner-gateway/rest/user/id',
        'url_oauth2_user_permissions' => 'https://apis.garmin.com/partner-gateway/rest/user/permissions',
        'url_oauth2_user_registration' => 'https://apis.garmin.com/partner-gateway/rest/user/registration',
    ];

    // Garmin's spec: subtract at least 600 s from expires_in
    const REFRESH_MARGIN = 600;
    // Used when a response has no refresh_token_expires_in (spec: 7775998 s, about 90 days)
    const DEFAULT_REFRESH_LIFETIME = 7775998;
    const LOCK_TIMEOUT = 30;

    private $sql;
    private $api_config;
    private $verbose = false;
    // Last failure, for callers that report it
    public $last_error = null;

    function __construct($sql, $api_config) {
        $this->sql = $sql;
        $this->api_config = $api_config;
    }

    function set_verbose($verbose) {
        $this->verbose = $verbose;
    }

    private function log($fmt, ...$args) {
        if ($this->verbose) {
            $msg = count($args) > 0 ? vsprintf($fmt, $args) : $fmt;
            printf("OAUTH2: %s" . PHP_EOL, $msg);
        }
    }

    function url($key) {
        return $this->api_config[$key] ?? self::DEFAULT_URLS[$key];
    }

    /**
     * The client credentials Garmin issues at conversion. Without them nothing that talks
     * to Garmin's token endpoint can work: login and refresh are off.
     */
    function is_configured() {
        return !empty($this->api_config['oauth2ClientId']) && !empty($this->api_config['oauth2ClientSecret']);
    }

    // =========================================================================
    // Storage
    // =========================================================================

    function credentials($userId) {
        $stmt = $this->sql->connection->prepare('SELECT * FROM garmin_oauth2 WHERE userId = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    function has_credentials($userId) {
        return $userId && $this->credentials($userId) !== null;
    }

    /**
     * Save a token response (exchange, code exchange or refresh) for a Garmin user.
     * Replaces any previous grant: the latest one is the one that works.
     *
     * @param string $source 'exchange', 'login' or 'refresh', kept for diagnostics
     */
    function store($userId, $response, $source, $permissions = null) {
        if (!$userId || empty($response['access_token']) || empty($response['refresh_token'])) {
            $this->last_error = 'token response without access_token or refresh_token';
            return false;
        }
        $now = time();
        $access_expires = $now + intval($response['expires_in'] ?? 86400);
        $refresh_expires = $now + intval($response['refresh_token_expires_in'] ?? self::DEFAULT_REFRESH_LIFETIME);
        $scope = isset($response['scope']) ? (string)$response['scope'] : null;
        $perm = $permissions !== null ? json_encode($permissions) : null;

        $query = 'INSERT INTO garmin_oauth2 (userId, access_token, refresh_token, access_expires_ts, refresh_expires_ts, scope, permissions, source, created_ts)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                  ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token),
                      access_expires_ts = VALUES(access_expires_ts), refresh_expires_ts = VALUES(refresh_expires_ts),
                      scope = COALESCE(VALUES(scope), scope), permissions = COALESCE(VALUES(permissions), permissions),
                      source = VALUES(source), refresh_failures = 0';
        $stmt = $this->sql->connection->prepare($query);
        if (!$stmt) {
            $this->last_error = 'prepare failed: ' . $this->sql->connection->error;
            return false;
        }
        $stmt->bind_param('sssiisss', $userId, $response['access_token'], $response['refresh_token'],
                          $access_expires, $refresh_expires, $scope, $perm, $source);
        $ok = $stmt->execute();
        if (!$ok) {
            $this->last_error = 'store failed: ' . $stmt->error;
        }
        $stmt->close();
        return $ok;
    }

    function store_permissions($userId, $permissions) {
        $stmt = $this->sql->connection->prepare('UPDATE garmin_oauth2 SET permissions = ? WHERE userId = ?');
        if (!$stmt) {
            return false;
        }
        $perm = json_encode($permissions);
        $stmt->bind_param('ss', $perm, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * The Garmin user is gone (deregistered, or refresh rejected for good): remove the
     * Garmin credentials, and the app credentials of every row of that user so the app
     * gets a 401 and asks for a new login.
     */
    function remove_user($userId) {
        $ok = true;
        foreach (['DELETE FROM garmin_oauth2 WHERE userId = ?',
                  'UPDATE tokens SET userAccessTokenSecret = NULL, server_token_hash = NULL WHERE userId = ?'] as $query) {
            $stmt = $this->sql->connection->prepare($query);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $userId);
            $ok = $stmt->execute() && $ok;
            $stmt->close();
        }
        return $ok;
    }

    // =========================================================================
    // Access tokens
    // =========================================================================

    /**
     * A valid access token for the Garmin user, refreshed if needed.
     * NULL if the user has no OAuth 2.0 credentials, or they can't be refreshed.
     */
    function access_token($userId) {
        if (!$userId) {
            return null;
        }
        $row = $this->credentials($userId);
        if (!$row) {
            return null;
        }
        if (intval($row['access_expires_ts']) > time() + self::REFRESH_MARGIN) {
            return $row['access_token'];
        }
        return $this->refresh_user($userId, false);
    }

    /**
     * Refresh the user's tokens under a per-user lock.
     *
     * @param bool $force refresh even if the access token is still valid (scheduled job,
     *                    or Garmin answered 401 to a call with it)
     * @param string|null $rejected_access_token with $force: only refresh if this is still the
     *                    stored token, otherwise another worker already did
     * @return string|null the access token, NULL if there's none
     */
    function refresh_user($userId, $force, $rejected_access_token = null) {
        $lock = $this->lock_name($userId);
        $got = $this->sql->query_first_row(sprintf("SELECT GET_LOCK('%s', %d) AS l", $lock, self::LOCK_TIMEOUT));
        if (!$got || intval($got['l']) !== 1) {
            $this->last_error = 'could not get the refresh lock';
            $this->log('%s: %s', $userId, $this->last_error);
            return null;
        }
        try {
            // Re-read once locked: another worker may have refreshed already
            $row = $this->credentials($userId);
            if (!$row) {
                return null;
            }
            $valid = intval($row['access_expires_ts']) > time() + self::REFRESH_MARGIN;
            if ($valid && (!$force || ($rejected_access_token !== null && $row['access_token'] !== $rejected_access_token))) {
                return $row['access_token'];
            }
            if (!$this->is_configured()) {
                $this->last_error = 'oauth2ClientId/oauth2ClientSecret missing from the config';
                return $valid ? $row['access_token'] : null;
            }

            list($status, $json, $body) = $this->token_request([
                'grant_type' => 'refresh_token',
                'refresh_token' => $row['refresh_token'],
            ]);
            if ($status == 200 && $json && $this->store($userId, $json, 'refresh')) {
                $this->log('%s: refreshed', $userId);
                return $json['access_token'];
            }
            $this->last_error = sprintf('refresh failed http=%d %s', $status, substr((string)$body, 0, 200));
            $this->log('%s: %s', $userId, $this->last_error);

            if ($status == 400 || $status == 401) {
                // invalid_grant & co: the refresh token is dead, the user has to log in again
                $this->remove_user($userId);
                $this->record_failure($userId, 'refresh rejected, credentials removed: ' . $this->last_error);
                return null;
            }
            // Network, 5xx, 429: try again later, and keep using the current token while it lasts
            $this->count_failure($userId);
            $this->record_failure($userId, $this->last_error);
            return intval($row['access_expires_ts']) > time() ? $row['access_token'] : null;
        } finally {
            $this->sql->query_first_row(sprintf("SELECT RELEASE_LOCK('%s') AS l", $lock));
        }
    }

    private function lock_name($userId) {
        return 'cs_oauth2_' . md5($userId);
    }

    private function count_failure($userId) {
        $stmt = $this->sql->connection->prepare('UPDATE garmin_oauth2 SET refresh_failures = refresh_failures + 1 WHERE userId = ?');
        if ($stmt) {
            $stmt->bind_param('s', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function record_failure($userId, $message) {
        $status = new StatusCollector();
        $status->clear('garmin_oauth2');
        $status->error($message);
        $status->record($this->sql, ['userId' => $userId]);
    }

    // =========================================================================
    // Garmin calls
    // =========================================================================

    /**
     * POST to Garmin's token endpoint with the client credentials.
     * Garmin's spec shows them both as a Basic header and as fields: `oauth2ClientAuth`
     * in the config picks 'basic', anything else sends fields.
     *
     * @return array [http status, decoded json or null, raw body]
     */
    function token_request($params) {
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        $clientId = $this->api_config['oauth2ClientId'] ?? '';
        $clientSecret = $this->api_config['oauth2ClientSecret'] ?? '';
        if (($this->api_config['oauth2ClientAuth'] ?? 'body') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($clientSecret));
        } else {
            $params['client_id'] = $clientId;
            $params['client_secret'] = $clientSecret;
        }
        list($status, $body) = $this->http('POST', $this->url('url_oauth2_token'), $headers, http_build_query($params));
        $json = $body ? json_decode($body, true) : null;
        return [$status, is_array($json) ? $json : null, $body];
    }

    /**
     * Login: trade the authorization code the app got from Garmin for tokens.
     * @return array [http status, decoded json or null, raw body]
     */
    function exchange_authorization_code($code, $code_verifier, $redirect_uri) {
        return $this->token_request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $code_verifier,
            'redirect_uri' => $redirect_uri,
        ]);
    }

    /**
     * Migration: trade a user's OAuth 1.0 token for OAuth 2.0 tokens, signed with OAuth 1.0.
     * @param Auth $auth for the OAuth 1.0 signature
     * @return array [http status, decoded json or null, raw body]
     */
    function exchange_oauth1_token($auth, $userAccessToken, $userAccessTokenSecret) {
        $url = $this->url('url_oauth2_exchange');
        $headers = [$auth->authorization_header($url, $userAccessToken, $userAccessTokenSecret, null, null, 'POST'),
                    'Accept: application/json'];
        list($status, $body) = $this->http('POST', $url, $headers, '');
        $json = $body ? json_decode($body, true) : null;
        return [$status, is_array($json) ? $json : null, $body];
    }

    /**
     * Call Garmin with the user's Bearer token. On a 401, refresh once and retry,
     * in case the token was revoked or expired early.
     *
     * @return array [http status (0: no token or no response), body]
     */
    function bearer_request($method, $url, $userId, $body = null) {
        $token = $this->access_token($userId);
        if (!$token) {
            return [0, $this->last_error ?? 'no OAuth 2.0 credentials'];
        }
        list($status, $data) = $this->http($method, $url, ['Authorization: Bearer ' . $token], $body);
        if ($status == 401) {
            $token = $this->refresh_user($userId, true, $token);
            if (!$token) {
                return [401, $data];
            }
            list($status, $data) = $this->http($method, $url, ['Authorization: Bearer ' . $token], $body);
        }
        return [$status, $data];
    }

    /** Bearer call with a token that isn't stored yet (login, before we know the userId) */
    function get_with_access_token($url, $access_token) {
        return $this->http('GET', $url, ['Authorization: Bearer ' . $access_token, 'Accept: application/json']);
    }

    /**
     * Remove the user's consent at Garmin (DELETE user/registration). Garmin then sends a
     * deregistration webhook for the user, which clears the same rows again.
     */
    function delete_registration($userId) {
        return $this->bearer_request('DELETE', $this->url('url_oauth2_user_registration'), $userId);
    }

    /**
     * @return array [http status (0 if no response), body or curl error]
     */
    function http($method, $url, $headers = [], $body = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $data = curl_exec($ch);
        if ($data === false) {
            $error = curl_error($ch);
            $this->log('%s %s failed: %s', $method, $url, $error);
            return [0, $error];
        }
        return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $data];
    }
}
