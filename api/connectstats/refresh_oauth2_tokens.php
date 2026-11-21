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
 * Scheduled refresh of Garmin OAuth 2.0 tokens (see designs/oauth2-migration.md).
 *
 * Usage: php refresh_oauth2_tokens.php [--days=N] [--dry-run]   (from api/connectstats)
 *
 * Access tokens are refreshed on demand when a download needs one, but downloads are skipped
 * for users idle over 45 days, so their refresh token (3 months) would expire unused and they
 * would have to log in again. This refreshes every user whose refresh token expires within
 * --days (default 30). Every refresh returns a new refresh token with a new expiry.
 *
 * A refresh Garmin rejects for good removes that user's credentials (GarminOAuth2::refresh_user):
 * the app gets a 401 and asks for a login. Those are counted as "removed".
 *
 * Exit 1 if users need refreshing but the OAuth 2.0 client isn't configured, or if every
 * refresh failed, so the healthcheck reports it.
 */

include_once('../shared.php');

$options = getopt('', ['days:', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: php refresh_oauth2_tokens.php [--days=N] [--dry-run]\n";
    exit(0);
}
$days = isset($options['days']) ? max(1, intval($options['days'])) : 30;
$dry_run = isset($options['dry-run']);

$process = new GarminProcess();
$process->ensure_commandline($argv ?? NULL);
$process->ensure_schema();

$threshold = time() + $days * 86400;
$rows = $process->sql->query_as_array(sprintf(
    'SELECT userId, refresh_expires_ts FROM garmin_oauth2 WHERE refresh_expires_ts < %d ORDER BY refresh_expires_ts', $threshold));
$total = $process->sql->query_first_row('SELECT COUNT(*) AS n FROM garmin_oauth2');

printf("%s: %d Garmin users with OAuth 2.0 credentials, %d expiring within %d days\n", date('Y-m-d H:i:s'), $total['n'] ?? 0, count($rows), $days);

if (!$rows || $dry_run) {
    exit(0);
}
if (!$process->oauth2->is_configured()) {
    echo "ERROR: oauth2ClientId/oauth2ClientSecret missing from the config\n";
    exit(1);
}

$ok = 0;
$removed = 0;
$failed = 0;
foreach ($rows as $row) {
    $userId = $row['userId'];
    $token = $process->oauth2->refresh_user($userId, true);
    $after = $process->oauth2->credentials($userId);
    if (!$after) {
        printf("REMOVED userId=%s: %s\n", $userId, $process->oauth2->last_error);
        $removed++;
    } elseif (intval($after['refresh_expires_ts']) > intval($row['refresh_expires_ts'])) {
        $ok++;
    } else {
        printf("FAILED userId=%s: %s\n", $userId, $process->oauth2->last_error);
        $failed++;
    }
    usleep(100000);
}

printf("%s: refreshed %d, removed %d, failed %d\n", date('Y-m-d H:i:s'), $ok, $removed, $failed);
exit($ok == 0 && ($failed + $removed) > 0 ? 1 : 0);
