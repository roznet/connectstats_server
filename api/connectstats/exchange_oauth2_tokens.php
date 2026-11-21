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

/*
 * Exchange OAuth 1.0 tokens for Garmin OAuth 2.0 tokens (see designs/oauth2-migration.md).
 *
 * Usage: php exchange_oauth2_tokens.php [options]   (from api/connectstats)
 *
 * Without --execute this only reports what would be done.
 *
 * Options:
 *   --execute           Call Garmin and store the OAuth 2.0 tokens. After the exchange the
 *                       OAuth 1.0 token stays valid at Garmin for 30 days only
 *   --limit=N           Max Garmin users to exchange in this run (default: all)
 *   --rate=N            Max exchange calls per minute (default: 150, Garmin allows 200)
 *   --max-failures=N    Stop after N failures in a row (default: 10)
 *   --user-id=ID        Only this Garmin userId (own account first)
 *   --cs-user-id=N      Only this cs_user_id
 *
 * One exchange per Garmin user: the grant is per user, and the credentials are stored in
 * `garmin_oauth2` keyed by userId, so all of that user's `tokens` rows use them. The token
 * exchanged is the user's newest live one (has a secret and a cs_user_id). Users who already
 * have OAuth 2.0 credentials are skipped, so running again resumes where it stopped.
 *
 * Live tokens without a userId get it first from url_user_id (OAuth 1.0), in --execute mode.
 * Live tokens without a cs_user_id are skipped and listed: they can't be used by the app.
 *
 * Logs token_id, cs_user_id and userId only, never tokens.
 */

include_once('../shared.php');

$options = getopt('', ['execute', 'limit:', 'rate:', 'max-failures:', 'user-id:', 'cs-user-id:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php exchange_oauth2_tokens.php [--execute] [--limit=N] [--rate=N] [--max-failures=N] [--user-id=ID] [--cs-user-id=N]\n";
    echo "Without --execute, only reports what would be done.\n";
    exit(0);
}

$execute = isset($options['execute']);
$limit = isset($options['limit']) ? intval($options['limit']) : 0;
$rate = isset($options['rate']) ? intval($options['rate']) : 150;
$max_failures = isset($options['max-failures']) ? intval($options['max-failures']) : 10;
$only_user_id = $options['user-id'] ?? null;
$only_cs_user_id = isset($options['cs-user-id']) ? intval($options['cs-user-id']) : 0;

$process = new GarminProcess();
$process->ensure_commandline($argv ?? NULL);
$process->ensure_schema();

function log_msg($fmt, ...$args) {
    global $execute;
    printf("%s%s: %s\n", $execute ? '' : '[DRY-RUN] ', date('Y-m-d H:i:s'), vsprintf($fmt, $args));
}

if ($rate < 1 || $rate > 200 || $max_failures < 1) {
    log_msg("ERROR: --rate must be 1 to 200 and --max-failures at least 1");
    exit(1);
}

$where = "t.userAccessTokenSecret IS NOT NULL AND t.userAccessTokenSecret <> '' AND t.userAccessToken IS NOT NULL";
if ($only_user_id !== null) {
    $where .= sprintf(" AND t.userId = '%s'", $process->sql->escape($only_user_id));
}
if ($only_cs_user_id > 0) {
    $where .= sprintf(" AND t.cs_user_id = %d", $only_cs_user_id);
}
$live = $process->sql->query_as_array("
    SELECT t.token_id, t.cs_user_id, t.userId, t.userAccessToken, t.userAccessTokenSecret, g.userId AS has_oauth2
    FROM tokens t
    LEFT JOIN garmin_oauth2 g ON g.userId = t.userId
    WHERE $where
    ORDER BY t.token_id DESC
");

$no_cs_user = [];
$no_user_id = [];
$already = [];
$candidates = [];
foreach ($live as $t) {
    if (!$t['cs_user_id']) {
        $no_cs_user[] = $t['token_id'];
    } elseif (!$t['userId']) {
        $no_user_id[] = $t;
    } elseif ($t['has_oauth2']) {
        $already[$t['userId']] = true;
    } elseif (!isset($candidates[$t['userId']])) {
        // Newest first: the first row seen for a userId is the one to exchange
        $candidates[$t['userId']] = $t;
    }
}

log_msg("=== Exchange OAuth 1.0 tokens for OAuth 2.0 ===");
log_msg("Garmin endpoint: POST %s", $process->oauth2->url('url_oauth2_exchange'));
log_msg("Live tokens: %d", count($live));
log_msg("Garmin users already exchanged: %d", count($already));
log_msg("Skipped, no cs_user_id: %d%s", count($no_cs_user), $no_cs_user ? ' (token_id ' . implode(', ', array_slice($no_cs_user, 0, 20)) . ')' : '');
log_msg("Missing userId, looked up first: %d", count($no_user_id));
log_msg("Garmin users to exchange: %d", count($candidates));

if (!$execute) {
    $n = $limit > 0 ? min($limit, count($candidates) + count($no_user_id)) : count($candidates) + count($no_user_id);
    log_msg("Estimated time at %d/min: %d min", $rate, ceil($n / $rate));
    log_msg("Nothing was changed. Use --execute to exchange.");
    exit(0);
}

// Fill in missing userIds, so those tokens join their user (or become new candidates)
foreach ($no_user_id as $t) {
    $data = $process->get_url_data($process->api_config['url_user_id'], $t['userAccessToken'], $t['userAccessTokenSecret']);
    $json = $data ? json_decode($data, true) : null;
    $userId = isset($json['userId']) ? $process->validate_token($json['userId']) : '';
    if (!$userId) {
        log_msg("FAILED token_id=%d: no userId from url_user_id", $t['token_id']);
        continue;
    }
    $process->sql->execute_query(sprintf("UPDATE tokens SET userId = '%s' WHERE token_id = %d", $process->sql->escape($userId), $t['token_id']));
    log_msg("userId filled for token_id=%d", $t['token_id']);
    if (!isset($already[$userId]) && !isset($candidates[$userId]) && !$process->oauth2->has_credentials($userId)) {
        $t['userId'] = $userId;
        $candidates[$userId] = $t;
    }
}

$to_process = array_values($candidates);
if ($limit > 0) {
    $to_process = array_slice($to_process, 0, $limit);
}
log_msg("In this run: %d, about %d min at %d/min", count($to_process), ceil(count($to_process) / $rate), $rate);

$done = 0;
$failed = 0;
$skipped_now = 0;
$failures_in_a_row = 0;
$interval = 60.0 / $rate;
$start_time = microtime(true);

foreach ($to_process as $i => $t) {
    $token_id = intval($t['token_id']);
    $userId = $t['userId'];

    // The run can take a while: the user may have been exchanged or logged in since
    if ($process->oauth2->has_credentials($userId)) {
        log_msg("SKIP token_id=%d userId=%s: already has OAuth 2.0 credentials", $token_id, $userId);
        $skipped_now++;
        continue;
    }
    $now = $process->sql->query_first_row(sprintf('SELECT userAccessTokenSecret FROM tokens WHERE token_id = %d', $token_id));
    if (empty($now['userAccessTokenSecret'])) {
        log_msg("SKIP token_id=%d userId=%s: deregistered since", $token_id, $userId);
        $skipped_now++;
        continue;
    }

    $call_start = microtime(true);
    list($status, $json, $body) = $process->oauth2->exchange_oauth1_token($process->auth, $t['userAccessToken'], $t['userAccessTokenSecret']);

    if ($status >= 200 && $status < 300 && $json && !empty($json['access_token'])) {
        if (!$process->oauth2->store($userId, $json, 'exchange')) {
            // Garmin has issued the grant: stop rather than lose more of them
            log_msg("ERROR: token_id=%d userId=%s exchanged but not stored (%s), stopping", $token_id, $userId, $process->oauth2->last_error);
            exit(1);
        }
        log_msg("OK token_id=%d cs_user_id=%d userId=%s http=%d", $token_id, $t['cs_user_id'], $userId, $status);
        $done++;
        $failures_in_a_row = 0;
    } else {
        // Body without tokens: a token in the body would be one Garmin issued, don't log it
        $snippet = $json && isset($json['access_token']) ? '(token response without refresh_token)' : substr((string)$body, 0, 200);
        log_msg("FAILED token_id=%d cs_user_id=%d userId=%s http=%d %s", $token_id, $t['cs_user_id'], $userId, $status, $snippet);
        $failed++;
        $failures_in_a_row++;
        if ($failures_in_a_row >= $max_failures) {
            log_msg("ERROR: %d failures in a row, stopping", $failures_in_a_row);
            break;
        }
        if ($status == 429) {
            log_msg("Rate limited, waiting 60s");
            sleep(60);
        }
    }

    if (($done + $failed) > 0 && ($done + $failed) % 500 == 0) {
        log_msg("Progress: %d / %d", $i + 1, count($to_process));
    }

    $wait = $interval - (microtime(true) - $call_start);
    if ($wait > 0) {
        usleep((int)($wait * 1000000));
    }
}

log_msg("=== Summary ===");
log_msg("Exchanged: %d, failed: %d, skipped: %d, in %d s", $done, $failed, $skipped_now, microtime(true) - $start_time);

exit($failures_in_a_row >= $max_failures ? 1 : 0);
