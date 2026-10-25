<?php
/*
 *  MIT Licence
 *
 *  Copyright (c) 2019 Brice Rosenzweig.
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
 * Authentication handler for ConnectStats Server
 *
 * Supports:
 * - OAuth 1.0 signature verification (for app-to-server and server-to-Garmin)
 * - System/service key authentication (for maintenance tasks)
 *
 * Future (OAuth 2.0 migration):
 * - Bearer token authentication (server-issued tokens for app-to-server)
 * - OAuth 2.0 token management (for server-to-Garmin)
 */
class Auth {

    private $sql;
    private $api_config;
    private $verbose = false;
    private $start_ts;

    // Status collector for error reporting (optional)
    private $status = null;

    /**
     * @param sql_helper $sql Database connection
     * @param array $api_config Configuration array with consumerKey, consumerSecret, etc.
     */
    function __construct($sql, $api_config) {
        $this->sql = $sql;
        $this->api_config = $api_config;
        $this->start_ts = microtime(true);
    }

    /**
     * Set verbose mode for debugging
     */
    function set_verbose($verbose) {
        $this->verbose = $verbose;
    }

    /**
     * Set status collector for error reporting
     */
    function set_status($status) {
        $this->status = $status;
    }

    /**
     * Log message if verbose mode is enabled
     */
    private function log($tag, $fmt, ...$args) {
        if (!$this->verbose) {
            return;
        }
        $msg = vsprintf($fmt, $args);
        printf("%s:%.3f: %s".PHP_EOL, $tag, microtime(true) - $this->start_ts, $msg);
    }

    // =========================================================================
    // OAuth 1.0 Header Parsing
    // =========================================================================

    /**
     * Parse OAuth 1.0 Authorization header into key-value pairs
     *
     * @param string $header The Authorization header value
     * @return array Parsed OAuth parameters
     */
    function interpret_authorization_header($header) {
        $maps = array();
        $split = explode(', ', str_replace('OAuth ', '', $header));

        foreach ($split as $def) {
            $sub = explode('=', str_replace('"', '', $def));
            if (count($sub) > 1) {
                $maps[$sub[0]] = $sub[1];
            }
        }
        return $maps;
    }

    // =========================================================================
    // OAuth 1.0 Signature Generation
    // =========================================================================

    /**
     * Generate OAuth 1.0 Authorization header
     *
     * @param string $full_url The full URL being requested
     * @param string $userAccessToken OAuth access token
     * @param string $userAccessTokenSecret OAuth access token secret
     * @param string|null $nonce Optional nonce (for verification), generated if null
     * @param string|null $timestamp Optional timestamp (for verification), generated if null
     * @return string The complete Authorization header
     */
    function authorization_header($full_url, $userAccessToken, $userAccessTokenSecret, $nonce = null, $timestamp = null) {
        $consumerKey = $this->api_config['consumerKey'];
        $consumerSecret = $this->api_config['consumerSecret'];

        $url_info = parse_url($full_url);

        $get_params = array();
        if (isset($url_info['query'])) {
            parse_str($url_info['query'], $get_params);
        }

        $url = sprintf('%s://%s%s', $url_info['scheme'], $url_info['host'], $url_info['path']);

        if ($nonce === null) {
            $nonce = bin2hex(random_bytes(16));
        }
        if ($timestamp === null) {
            $timestamp = (string)round(microtime(true));
        }

        $method = 'GET';
        $version = '1.0';

        $oauth_params = array(
            'oauth_consumer_key' => $consumerKey,
            'oauth_token' => $userAccessToken,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => $timestamp,
            'oauth_version' => $version
        );

        $all_params = array_merge($oauth_params, $get_params);
        $params_order = array_keys($all_params);
        sort($params_order);

        $base_params = array();
        foreach ($params_order as $param) {
            array_push($base_params, sprintf('%s=%s', $param, $all_params[$param]));
        }

        $base = sprintf('%s&%s&%s', $method, rawurlencode($url), rawurlencode(implode('&', $base_params)));

        $key = rawurlencode($consumerSecret) . '&' . rawurlencode($userAccessTokenSecret);
        $oauth_params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        $header_params = array_keys($oauth_params);
        sort($header_params);
        $headers = array();
        foreach ($header_params as $param) {
            array_push($headers, sprintf('%s="%s"', $param, rawurlencode($oauth_params[$param])));
        }

        $header = sprintf('Authorization: OAuth %s', implode(', ', $headers));

        return $header;
    }

    /**
     * Generate OAuth 1.0 Authorization header using token_id lookup
     *
     * @param string $full_url The full URL being requested
     * @param int $token_id The token ID to look up
     * @return string The complete Authorization header
     */
    function authorization_header_for_token_id($full_url, $token_id) {
        $row = $this->sql->query_first_row("SELECT * FROM tokens WHERE token_id = $token_id");
        return $this->authorization_header($full_url, $row['userAccessToken'], $row['userAccessTokenSecret']);
    }

    // =========================================================================
    // Request Authentication (App -> Server)
    // =========================================================================

    /**
     * Authenticate a user request using OAuth 1.0 signature
     *
     * Verifies that the Authorization header signature matches the stored
     * token secret for the given token_id.
     *
     * @param int $token_id The token_id from the request
     * @param int $system_token_value Value representing system token (to reject)
     * @return bool True if authenticated, dies with 401 on failure
     */
    function authenticate_header($token_id, $system_token_value = -1) {
        $failed = true;

        // System token should never be used for user authentication
        if ($token_id == $system_token_value) {
            $this->auth_failed('system token not allowed');
        }

        $full_url = $this->get_request_url();
        $header = $this->get_authorization_header();

        if ($header !== null) {
            $failed = !$this->verify_oauth1_signature($header, $full_url, $token_id);
        }

        if ($failed) {
            $this->auth_failed('signature verification failed');
        }

        return true;
    }

    /**
     * Authenticate a system/maintenance call using service key
     *
     * Verifies the request is signed with the serviceKey/serviceKeySecret
     * from configuration.
     *
     * @return bool True if authenticated, dies with 401 on failure
     */
    function authenticate_system_call() {
        $failed = true;

        $full_url = $this->get_request_url();
        $header = $this->get_authorization_header();

        if ($header !== null) {
            $maps = $this->interpret_authorization_header($header);
            if (isset($maps['oauth_token']) && isset($maps['oauth_nonce']) && isset($maps['oauth_signature'])) {
                $reconstructed = $this->authorization_header(
                    $full_url,
                    $this->api_config['serviceKey'],
                    $this->api_config['serviceKeySecret'],
                    $maps['oauth_nonce'],
                    $maps['oauth_timestamp']
                );
                $reconstructed = str_replace('Authorization: ', '', $reconstructed);
                $reconmaps = $this->interpret_authorization_header($reconstructed);

                if (urldecode($reconmaps['oauth_signature']) == urldecode($maps['oauth_signature'])) {
                    $failed = false;
                }
            }
        }

        if ($failed) {
            $this->auth_failed('system authentication failed');
        }

        return true;
    }

    /**
     * Verify OAuth 1.0 signature against stored token
     *
     * @param string $header Authorization header
     * @param string $full_url Request URL
     * @param int $token_id Expected token_id
     * @return bool True if signature is valid and token_id matches
     */
    private function verify_oauth1_signature($header, $full_url, $token_id) {
        $maps = $this->interpret_authorization_header($header);

        if (!isset($maps['oauth_token']) || !isset($maps['oauth_nonce']) || !isset($maps['oauth_signature'])) {
            return false;
        }

        $userAccessToken = $maps['oauth_token'];
        $escaped_token = $this->sql->connection->real_escape_string($userAccessToken);
        $row = $this->sql->query_first_row(
            "SELECT userAccessTokenSecret, token_id FROM tokens WHERE userAccessToken = '$escaped_token'"
        );

        if (!isset($row['token_id'])) {
            return false;
        }

        $reconstructed = $this->authorization_header(
            $full_url,
            $userAccessToken,
            $row['userAccessTokenSecret'],
            $maps['oauth_nonce'],
            $maps['oauth_timestamp']
        );
        $reconstructed = str_replace('Authorization: ', '', $reconstructed);
        $reconmaps = $this->interpret_authorization_header($reconstructed);

        return urldecode($reconmaps['oauth_signature']) == urldecode($maps['oauth_signature'])
               && $row['token_id'] == $token_id;
    }

    // =========================================================================
    // Authenticated HTTP Requests (Server -> External APIs)
    // =========================================================================

    /**
     * Execute an HTTP GET request with OAuth 1.0 authentication
     *
     * @param string $url The URL to fetch
     * @param string|null $userAccessToken OAuth token (null for unauthenticated)
     * @param string|null $userAccessTokenSecret OAuth secret (null for unauthenticated)
     * @return string|false Response data or false on failure
     */
    function get_url_data($url, $userAccessToken = null, $userAccessTokenSecret = null) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if ($userAccessToken && $userAccessTokenSecret) {
            $headers = [$this->authorization_header($url, $userAccessToken, $userAccessTokenSecret)];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $this->log('CURL', 'AuthToken: %s URL: %s', $userAccessToken, $url);
        } else {
            $this->log('CURL', '%s', $url);
        }

        $data = curl_exec($ch);

        if ($data === false) {
            $error = sprintf('CURL Failed %s', curl_error($ch));
            $this->log('ERROR', $error);
            if ($this->status) {
                $this->status->error($error);
            }
        }

        curl_close($ch);
        return $data;
    }

    /**
     * Execute an HTTP GET request using token_id for authentication
     *
     * @param string $url The URL to fetch
     * @param int $token_id The token_id to look up credentials for
     * @return string|false Response data or false on failure
     */
    function get_url_data_for_token_id($url, $token_id) {
        $row = $this->sql->query_first_row("SELECT userAccessToken, userAccessTokenSecret FROM tokens WHERE token_id = $token_id");
        if (!$row) {
            return false;
        }
        return $this->get_url_data($url, $row['userAccessToken'], $row['userAccessTokenSecret']);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get the full request URL from server variables
     */
    private function get_request_url() {
        return sprintf('%s://%s%s', $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
    }

    /**
     * Get the Authorization header from the request
     */
    private function get_authorization_header() {
        $headers = apache_request_headers();
        return isset($headers['Authorization']) ? $headers['Authorization'] : null;
    }

    /**
     * Handle authentication failure - log and die with 401
     */
    private function auth_failed($reason = '') {
        if ($this->verbose) {
            $this->log('ERROR', 'authorization failed: %s', $reason);
        } else {
            header('HTTP/1.1 401 Unauthorized error');
        }
        die;
    }
}
