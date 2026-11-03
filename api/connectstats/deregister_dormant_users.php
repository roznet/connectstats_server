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

/*
 * Deregister dormant users with Garmin, so Garmin stops pushing their activities
 * and they don't have to be moved to OAuth 2.0.
 *
 * Usage: php deregister_dormant_users.php [options]   (from api/connectstats)
 *
 * Without --execute this only reports what would be done.
 *
 * Options:
 *   --execute           Call Garmin and update the database. This can't be undone:
 *                       a deregistered user has to log in again
 *   --days=N            Dormant threshold in days (default: 365, minimum: 180)
 *   --limit=N           Max tokens to process in this run (default: all)
 *   --rate=N            Max Garmin calls per minute (default: 100)
 *   --max-failures=N    Stop after N failures in a row (default: 10)
 *
 * A token is deregistered only if all of these hold:
 *   - it has a secret: tokens without one are already deregistered and can't be signed
 *   - its user hasn't called search/json/file since the threshold, or never did
 *   - the token and its user were created before the threshold
 *   - no token with the same Garmin userId or token value belongs to a user who
 *     doesn't meet the two conditions above
 *
 * On success the token's secret is set to NULL, as the deregistration webhook does, so
 * running again resumes where it stopped. Failed tokens are left unchanged and retried on
 * the next run. Dormant-first order: users who never used the app, then the longest idle.
 *
 * Users, tokens and their data are kept. A returning user gets a 401, which makes the app
 * ask for a new login.
 */

include_once('../shared.php');

$options = getopt('', ['execute', 'days:', 'limit:', 'rate:', 'max-failures:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php deregister_dormant_users.php [--execute] [--days=N] [--limit=N] [--rate=N] [--max-failures=N]\n";
    echo "Without --execute, only reports what would be done.\n";
    exit(0);
}

$execute = isset($options['execute']);
$days = isset($options['days']) ? intval($options['days']) : 365;
$limit = isset($options['limit']) ? intval($options['limit']) : 0;
$rate = isset($options['rate']) ? intval($options['rate']) : 100;
$max_failures = isset($options['max-failures']) ? intval($options['max-failures']) : 10;

$process = new GarminProcess();
$process->ensure_commandline($argv ?? NULL);

function log_msg($fmt, ...$args) {
    global $execute;
    printf("%s%s: %s\n", $execute ? '' : '[DRY-RUN] ', date('Y-m-d H:i:s'), vsprintf($fmt, $args));
}

if ($days < 180) {
    log_msg("ERROR: --days=%d is below the 180 day minimum", $days);
    exit(1);
}
if ($rate < 1 || $max_failures < 1) {
    log_msg("ERROR: --rate and --max-failures must be at least 1");
    exit(1);
}

$url = $process->api_config['url_user_registration'] ?? NULL;
if (!$url) {
    log_msg("ERROR: url_user_registration is missing from the config");
    exit(1);
}

$row = $process->sql->query_first_row("SELECT DATE_SUB(NOW(), INTERVAL $days DAY) AS cutoff");
$cutoff = $row['cutoff'];

// Anything that could belong to someone still around, including tokens that are
// no longer live: they still identify the Garmin account.
$recent = $process->sql->query_as_array("
    SELECT t.userId, t.userAccessToken
    FROM tokens t
    LEFT JOIN users u ON u.cs_user_id = t.cs_user_id
    LEFT JOIN users_usage uu ON uu.cs_user_id = t.cs_user_id
    WHERE uu.last_ts >= '$cutoff' OR u.created_ts >= '$cutoff' OR t.created_ts >= '$cutoff'
");
$protected_ids = [];
$protected_tokens = [];
foreach ($recent as $r) {
    if ($r['userId']) {
        $protected_ids[$r['userId']] = true;
    }
    $protected_tokens[$r['userAccessToken']] = true;
}

$live = $process->sql->query_as_array("
    SELECT t.token_id, t.cs_user_id, t.userId, t.userAccessToken, t.userAccessTokenSecret, uu.last_ts
    FROM tokens t
    JOIN users u ON u.cs_user_id = t.cs_user_id
    LEFT JOIN users_usage uu ON uu.cs_user_id = t.cs_user_id
    WHERE t.userAccessTokenSecret IS NOT NULL AND t.userAccessTokenSecret <> ''
    ORDER BY uu.last_ts IS NOT NULL, uu.last_ts, t.token_id
");

// Several rows can hold the same token value: one Garmin call is enough.
$candidates = [];
$seen = [];
$skipped_protected = 0;
$skipped_duplicate = 0;
foreach ($live as $t) {
    if (isset($protected_tokens[$t['userAccessToken']]) || ($t['userId'] && isset($protected_ids[$t['userId']]))) {
        $skipped_protected++;
    } elseif (isset($seen[$t['userAccessToken']])) {
        $skipped_duplicate++;
    } else {
        $seen[$t['userAccessToken']] = true;
        $candidates[] = $t;
    }
}
$total = count($candidates);
$to_process = $limit > 0 ? array_slice($candidates, 0, $limit) : $candidates;

log_msg("=== Deregister dormant users ===");
log_msg("Cutoff: %s (%d days)", $cutoff, $days);
log_msg("Garmin endpoint: DELETE %s", $url);
log_msg("Live tokens with a user: %d", count($live));
log_msg("Kept, recent user or account: %d", $skipped_protected);
log_msg("Skipped, same token value on another row: %d", $skipped_duplicate);
log_msg("To deregister: %d, in this run: %d", $total, count($to_process));
log_msg("Estimated time at %d/min: %d min", $rate, ceil(count($to_process) / $rate));

$by_year = [];
foreach ($candidates as $t) {
    $year = $t['last_ts'] ? substr($t['last_ts'], 0, 4) : 'never';
    $by_year[$year] = ($by_year[$year] ?? 0) + 1;
}
ksort($by_year);
foreach ($by_year as $year => $count) {
    log_msg("  last used %s: %d", $year, $count);
}

if (!$execute) {
    log_msg("Nothing was changed. Use --execute to deregister.");
    exit(0);
}

/**
 * Returns [http status (0 if no response), response body or curl error]
 */
function garmin_delete($process, $url, $token, $secret) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$process->auth->authorization_header($url, $token, $secret, null, null, 'DELETE')]);
    $body = curl_exec($ch);
    if ($body === false) {
        return [0, curl_error($ch)];
    }
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
}

$done = 0;
$failed = 0;
$skipped_now = 0;
$failures_in_a_row = 0;
$interval = 60.0 / $rate;
$start_time = microtime(true);

foreach ($to_process as $i => $t) {
    $token_id = intval($t['token_id']);

    // The run can last hours: the user may have come back since the list was built.
    $now = $process->sql->query_first_row("
        SELECT t.userAccessTokenSecret, uu.last_ts >= '$cutoff' AS is_recent
        FROM tokens t
        LEFT JOIN users_usage uu ON uu.cs_user_id = t.cs_user_id
        WHERE t.token_id = $token_id
    ");
    if (!$now || !$now['userAccessTokenSecret'] || $now['is_recent']) {
        log_msg("SKIP token_id=%d cs_user_id=%d: no longer dormant or already deregistered", $token_id, $t['cs_user_id']);
        $skipped_now++;
        continue;
    }

    $call_start = microtime(true);
    list($status, $body) = garmin_delete($process, $url, $t['userAccessToken'], $t['userAccessTokenSecret']);

    if ($status >= 200 && $status < 300) {
        $escaped = $process->sql->escape($t['userAccessToken']);
        if (!$process->sql->execute_query("UPDATE tokens SET userAccessTokenSecret = NULL WHERE userAccessToken = '$escaped'")) {
            log_msg("ERROR: token_id=%d deregistered but not updated (%s), stopping", $token_id, $process->sql->lasterror);
            exit(1);
        }
        log_msg("OK token_id=%d cs_user_id=%d last_used=%s http=%d", $token_id, $t['cs_user_id'], $t['last_ts'] ?? 'never', $status);
        $done++;
        $failures_in_a_row = 0;
    } else {
        log_msg("FAILED token_id=%d cs_user_id=%d http=%d %s", $token_id, $t['cs_user_id'], $status, substr((string)$body, 0, 200));
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

    if ($done + $failed > 0 && ($done + $failed) % 500 == 0) {
        log_msg("Progress: %d / %d", $i + 1, count($to_process));
    }

    $wait = $interval - (microtime(true) - $call_start);
    if ($wait > 0) {
        usleep((int)($wait * 1000000));
    }
}

log_msg("=== Summary ===");
log_msg("Deregistered: %d, failed: %d, skipped: %d, in %d s", $done, $failed, $skipped_now, microtime(true) - $start_time);

exit($failures_in_a_row >= $max_failures ? 1 : 0);
