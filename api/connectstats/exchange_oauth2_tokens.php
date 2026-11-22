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
 *   --limit=N           Max tokens to exchange in this run (default: all)
 *   --rate=N            Max exchange calls per minute (default: 150, Garmin allows 200)
 *   --max-failures=N    Stop after N failures in a row (default: 10)
 *   --max-dead=N        Stop after N dead tokens in a row (default: 100)
 *   --user-id=ID        Only this Garmin userId (own account first)
 *   --cs-user-id=N      Only this cs_user_id
 *
 * Every live token is exchanged: Garmin (2026-11-22) says "each token = 1 account, you need
 * to convert each token". Credentials are stored in `garmin_oauth2` per Garmin userId, shared
 * by all that user's `tokens` rows: tokens go oldest first, so for a userId with several
 * tokens the grant kept is the newest token's. Each exchanged token gets
 * `tokens.oauth2_exchanged_ts`, so running again resumes where it stopped.
 *
 * Live = has a secret, a cs_user_id and isn't exchanged yet. Live tokens without a userId get
 * it first from url_user_id (OAuth 1.0), in --execute mode. Tokens without a cs_user_id are
 * skipped and listed: the app can't use them.
 *
 * Dead tokens: Garmin answers 401 "OAuthToken is invalid" for a token whose grant no longer
 * exists (about 1%, often an old token of a user who has a newer one). Logged as DEAD and left
 * unchanged: the secret may still be what an app install signs our requests with. They're
 * retried on every run and gather at the head of the list, so they don't count as failures;
 * --max-dead stops a run that only gets "invalid" answers.
 *
 * Logs token_id, cs_user_id and userId only, never tokens.
 */

include_once('../shared.php');

$options = getopt('', ['execute', 'limit:', 'rate:', 'max-failures:', 'max-dead:', 'user-id:', 'cs-user-id:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php exchange_oauth2_tokens.php [--execute] [--limit=N] [--rate=N] [--max-failures=N] [--max-dead=N] [--user-id=ID] [--cs-user-id=N]\n";
    echo "Without --execute, only reports what would be done.\n";
    exit(0);
}

$execute = isset($options['execute']);
$limit = isset($options['limit']) ? intval($options['limit']) : 0;
$rate = isset($options['rate']) ? intval($options['rate']) : 150;
$max_failures = isset($options['max-failures']) ? intval($options['max-failures']) : 10;
$max_dead = isset($options['max-dead']) ? intval($options['max-dead']) : 100;
$only_user_id = $options['user-id'] ?? null;
$only_cs_user_id = isset($options['cs-user-id']) ? intval($options['cs-user-id']) : 0;

$process = new GarminProcess();
$process->ensure_commandline($argv ?? NULL);
$process->ensure_schema();

function log_msg($fmt, ...$args) {
    global $execute;
    printf("%s%s: %s\n", $execute ? '' : '[DRY-RUN] ', date('Y-m-d H:i:s'), vsprintf($fmt, $args));
}

if ($rate < 1 || $rate > 200 || $max_failures < 1 || $max_dead < 1) {
    log_msg("ERROR: --rate must be 1 to 200, --max-failures and --max-dead at least 1");
    exit(1);
}

$where = "t.userAccessTokenSecret IS NOT NULL AND t.userAccessTokenSecret <> '' AND t.userAccessToken IS NOT NULL";
if ($only_user_id !== null) {
    $where .= sprintf(" AND t.userId = '%s'", $process->sql->escape($only_user_id));
}
if ($only_cs_user_id > 0) {
    $where .= sprintf(" AND t.cs_user_id = %d", $only_cs_user_id);
}
// Oldest first, so the newest token of a userId is exchanged last and its grant is the one kept
$live = $process->sql->query_as_array("
    SELECT t.token_id, t.cs_user_id, t.userId, t.userAccessToken, t.userAccessTokenSecret, t.oauth2_exchanged_ts
    FROM tokens t
    WHERE $where
    ORDER BY t.token_id
");

$no_cs_user = [];
$no_user_id = [];
$exchanged = 0;
$candidates = [];
$seen_tokens = [];
foreach ($live as $t) {
    if ($t['oauth2_exchanged_ts']) {
        $exchanged++;
    } elseif (!$t['cs_user_id']) {
        $no_cs_user[] = $t['token_id'];
    } elseif (isset($seen_tokens[$t['userAccessToken']])) {
        // Same token value on several rows: one call, all rows marked
        continue;
    } elseif (!$t['userId']) {
        $no_user_id[] = $t;
        $seen_tokens[$t['userAccessToken']] = true;
    } else {
        $candidates[] = $t;
        $seen_tokens[$t['userAccessToken']] = true;
    }
}
$users = count(array_unique(array_column($candidates, 'userId')));

log_msg("=== Exchange OAuth 1.0 tokens for OAuth 2.0 ===");
log_msg("Garmin endpoint: POST %s", $process->oauth2->url('url_oauth2_exchange'));
log_msg("Live tokens: %d", count($live));
log_msg("Already exchanged: %d", $exchanged);
log_msg("Skipped, no cs_user_id: %d%s", count($no_cs_user), $no_cs_user ? ' (token_id ' . implode(', ', array_slice($no_cs_user, 0, 20)) . ')' : '');
log_msg("Missing userId, looked up first: %d", count($no_user_id));
log_msg("Tokens to exchange: %d, for %d Garmin users", count($candidates), $users);

if (!$execute) {
    $n = count($candidates) + count($no_user_id);
    $n = $limit > 0 ? min($limit, $n) : $n;
    log_msg("Estimated time at %d/min: %d min", $rate, ceil($n / $rate));
    log_msg("Nothing was changed. Use --execute to exchange.");
    exit(0);
}

// Fill in missing userIds first
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
    $t['userId'] = $userId;
    $candidates[] = $t;
}

$to_process = $limit > 0 ? array_slice($candidates, 0, $limit) : $candidates;
log_msg("In this run: %d, about %d min at %d/min", count($to_process), ceil(count($to_process) / $rate), $rate);

$done = 0;
$dead = 0;
$failed = 0;
$skipped_now = 0;
$failures_in_a_row = 0;
$dead_in_a_row = 0;
$interval = 60.0 / $rate;
$start_time = microtime(true);

foreach ($to_process as $i => $t) {
    $token_id = intval($t['token_id']);
    $userId = $t['userId'];

    // The run can take a while: the token may have been exchanged or deregistered since
    $now = $process->sql->query_first_row(sprintf('SELECT userAccessTokenSecret, oauth2_exchanged_ts FROM tokens WHERE token_id = %d', $token_id));
    if (!empty($now['oauth2_exchanged_ts'])) {
        log_msg("SKIP token_id=%d userId=%s: already exchanged", $token_id, $userId);
        $skipped_now++;
        continue;
    }
    if (empty($now['userAccessTokenSecret'])) {
        log_msg("SKIP token_id=%d userId=%s: deregistered since", $token_id, $userId);
        $skipped_now++;
        continue;
    }

    $call_start = microtime(true);
    list($status, $json, $body) = $process->oauth2->exchange_oauth1_token($process->auth, $t['userAccessToken'], $t['userAccessTokenSecret']);

    if ($status >= 200 && $status < 300 && $json && !empty($json['access_token'])) {
        $escaped = $process->sql->escape($t['userAccessToken']);
        if (!$process->oauth2->store($userId, $json, 'exchange')
            || !$process->sql->execute_query("UPDATE tokens SET oauth2_exchanged_ts = NOW() WHERE userAccessToken = '$escaped'")) {
            // Garmin has issued the grant: stop rather than lose more of them
            log_msg("ERROR: token_id=%d userId=%s exchanged but not stored (%s %s), stopping", $token_id, $userId,
                    $process->oauth2->last_error, $process->sql->lasterror ?? '');
            exit(1);
        }
        log_msg("OK token_id=%d cs_user_id=%d userId=%s http=%d", $token_id, $t['cs_user_id'], $userId, $status);
        $done++;
        $failures_in_a_row = 0;
        $dead_in_a_row = 0;
    } elseif ($status == 401 && stripos((string)$body, 'OAuthToken is invalid') !== false) {
        log_msg("DEAD token_id=%d cs_user_id=%d userId=%s http=401 OAuthToken is invalid, left unchanged", $token_id, $t['cs_user_id'], $userId);
        $dead++;
        $dead_in_a_row++;
        if ($dead_in_a_row >= $max_dead) {
            log_msg("ERROR: %d dead tokens in a row, stopping", $dead_in_a_row);
            break;
        }
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

    if (($done + $dead + $failed) > 0 && ($done + $dead + $failed) % 500 == 0) {
        log_msg("Progress: %d / %d", $i + 1, count($to_process));
    }

    $wait = $interval - (microtime(true) - $call_start);
    if ($wait > 0) {
        usleep((int)($wait * 1000000));
    }
}

log_msg("=== Summary ===");
log_msg("Exchanged: %d, dead: %d, failed: %d, skipped: %d, in %d s", $done, $dead, $failed, $skipped_now, microtime(true) - $start_time);

exit($failures_in_a_row >= $max_failures || $dead_in_a_row >= $max_dead ? 1 : 0);
