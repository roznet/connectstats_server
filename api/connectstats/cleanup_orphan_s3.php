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
 * Cleanup S3 files for inactive users
 *
 * Usage: php cleanup_orphan_s3.php [options]
 *
 * Options:
 *   --dry-run       Show what would be deleted without actually deleting
 *   --days=N        Inactive threshold in days (default: 180)
 *   --verbose       Show detailed progress
 *
 * S3 file structure: assets/users/{cs_user_id}/{fileType}/{file_id}.{fileType}
 *
 * This script will:
 *   1. Find users who haven't used the app in N days
 *   2. Delete their entire S3 directory (assets/users/{cs_user_id}/)
 */

include_once('../shared.php');
include_once('../S3.php');

// Parse command line options
$options = getopt('', ['dry-run', 'days:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php cleanup_orphan_s3.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run       Show what would be deleted without actually deleting\n";
    echo "  --days=N        Inactive threshold in days (default: 180)\n";
    echo "  --verbose       Show detailed progress\n";
    exit(0);
}

$dry_run = isset($options['dry-run']);
$inactive_days = isset($options['days']) ? intval($options['days']) : 180;
$verbose = isset($options['verbose']);

$process = new GarminProcess();
$process->set_verbose($verbose);
$process->ensure_commandline($argv ?? NULL);

$start_time = microtime(true);

function log_msg($msg) {
    global $dry_run;
    $prefix = $dry_run ? '[DRY-RUN] ' : '';
    printf("%s%s: %s\n", $prefix, date('Y-m-d H:i:s'), $msg);
}

log_msg("=== Inactive User S3 Cleanup ===");
log_msg("Inactive threshold: $inactive_days days");
log_msg("Dry run: " . ($dry_run ? 'YES' : 'NO'));
echo "\n";

if (!isset($process->api_config['save_to_s3_bucket'])) {
    log_msg("ERROR: No S3 bucket configured");
    exit(1);
}

$bucket = $process->api_config['save_to_s3_bucket'];
$is_local = strpos($bucket, 'localhost:') === 0;

log_msg("Storage: " . ($is_local ? "Local filesystem" : "S3 bucket: $bucket"));
echo "\n";

// Find inactive users
log_msg("Finding inactive users...");
$inactive_query = "
    SELECT u.cs_user_id
    FROM users u
    LEFT JOIN users_usage uu ON u.cs_user_id = uu.cs_user_id
    WHERE uu.last_ts IS NULL
       OR uu.last_ts < DATE_SUB(NOW(), INTERVAL $inactive_days DAY)
";
$inactive_users = $process->sql->query_as_array($inactive_query);
$total_inactive = count($inactive_users);

log_msg("Found $total_inactive inactive users");
echo "\n";

if ($dry_run) {
    log_msg("DRY RUN - Would delete S3 directories for $total_inactive users:");
    $show_count = min(20, $total_inactive);
    for ($i = 0; $i < $show_count; $i++) {
        $user_id = $inactive_users[$i]['cs_user_id'];
        log_msg("  assets/users/$user_id/");
    }
    if ($total_inactive > 20) {
        log_msg("  ... and " . ($total_inactive - 20) . " more");
    }
    echo "\n";
    log_msg("DRY RUN - No files were deleted. Remove --dry-run to execute.");
    exit(0);
}

$deleted_users = 0;
$deleted_files = 0;
$errors = 0;

if ($is_local) {
    // Local filesystem
    $local_path = str_replace('localhost:', '', $bucket);

    foreach ($inactive_users as $user) {
        $user_id = $user['cs_user_id'];
        $user_dir = "$local_path/assets/users/$user_id";

        if (is_dir($user_dir)) {
            // Count files before deleting
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($user_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            $file_count = iterator_count($files);

            // Delete directory recursively
            $cmd = "rm -rf " . escapeshellarg($user_dir);
            exec($cmd, $output, $return_var);

            if ($return_var === 0) {
                $deleted_users++;
                $deleted_files += $file_count;
                if ($verbose) {
                    log_msg("Deleted: $user_dir ($file_count files)");
                }
            } else {
                $errors++;
                log_msg("ERROR: Failed to delete $user_dir");
            }
        }

        if ($deleted_users % 100 == 0 && $deleted_users > 0) {
            log_msg("Progress: $deleted_users users processed...");
        }
    }
} else {
    // S3 bucket
    $s3 = new S3(
        $process->api_config['s3_access_key'],
        $process->api_config['s3_secret_key']
    );

    foreach ($inactive_users as $user) {
        $user_id = $user['cs_user_id'];
        $prefix = "assets/users/$user_id/";

        // List all objects with this prefix
        $objects = $s3->getBucket($bucket, $prefix);

        if (!empty($objects)) {
            $file_count = count($objects);

            // Delete each object
            $user_deleted = 0;
            foreach ($objects as $path => $info) {
                if ($s3->deleteObject($bucket, $path)) {
                    $user_deleted++;
                    $deleted_files++;
                }
            }

            if ($user_deleted > 0) {
                $deleted_users++;
                if ($verbose) {
                    log_msg("Deleted: $prefix ($user_deleted files)");
                }
            }
        }

        if ($deleted_users % 100 == 0 && $deleted_users > 0) {
            log_msg("Progress: $deleted_users users processed, $deleted_files files deleted...");
        }
    }
}

echo "\n";
log_msg("=== Summary ===");
log_msg("Inactive users processed: $total_inactive");
log_msg("Users with files deleted: $deleted_users");
log_msg("Total files deleted: " . number_format($deleted_files));
if ($errors > 0) {
    log_msg("Errors: $errors");
}

$elapsed = microtime(true) - $start_time;
echo "\n";
log_msg(sprintf("Completed in %.2f seconds", $elapsed));

exit(0);
?>
