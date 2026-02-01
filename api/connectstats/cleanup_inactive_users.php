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
 * Cleanup data for inactive users
 *
 * Usage: php cleanup_inactive_users.php [options]
 *
 * Options:
 *   --dry-run           Show what would be deleted without actually deleting
 *   --days=N            Inactive threshold in days (default: 90)
 *   --batch=N           Process N users per batch (default: 100)
 *   --skip-s3           Skip S3 cleanup (only clean database)
 *   --skip-optimize     Skip OPTIMIZE TABLE at the end
 *   --include-orphans   Also delete data with NULL cs_user_id
 *   --verbose           Show detailed progress
 *
 * This script will:
 *   1. Find users who haven't used the app in N days
 *   2. Delete their activities, weather, fitfiles, fitsession records
 *   3. Delete their FIT files from S3 bucket
 *   4. Optionally delete orphaned records (NULL cs_user_id)
 *   5. Run OPTIMIZE TABLE to reclaim disk space
 */

include_once('../shared.php');

// Parse command line options
$options = getopt('', ['dry-run', 'days:', 'batch:', 'skip-s3', 'skip-optimize', 'include-orphans', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php cleanup_inactive_users.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run           Show what would be deleted without actually deleting\n";
    echo "  --days=N            Inactive threshold in days (default: 90)\n";
    echo "  --batch=N           Process N users per batch (default: 100)\n";
    echo "  --skip-s3           Skip S3 cleanup (only clean database)\n";
    echo "  --skip-optimize     Skip OPTIMIZE TABLE at the end\n";
    echo "  --include-orphans   Also delete data with NULL cs_user_id\n";
    echo "  --verbose           Show detailed progress\n";
    exit(0);
}

$dry_run = isset($options['dry-run']);
$inactive_days = isset($options['days']) ? intval($options['days']) : 90;
$batch_size = isset($options['batch']) ? intval($options['batch']) : 100;
$skip_s3 = isset($options['skip-s3']);
$skip_optimize = isset($options['skip-optimize']);
$include_orphans = isset($options['include-orphans']);
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

function format_bytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

log_msg("=== Inactive User Cleanup ===");
log_msg("Inactive threshold: $inactive_days days");
log_msg("Batch size: $batch_size users");
log_msg("Dry run: " . ($dry_run ? 'YES' : 'NO'));
log_msg("Skip S3: " . ($skip_s3 ? 'YES' : 'NO'));
log_msg("Include orphans: " . ($include_orphans ? 'YES' : 'NO'));
echo "\n";

// Get table sizes before cleanup
$tables = ['activities', 'weather', 'fitfiles', 'fitsession', 'assets_s3'];
$before_sizes = [];
foreach ($tables as $table) {
    $size_row = $process->sql->query_first_row(
        "SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = '$table'"
    );
    $count_row = $process->sql->query_first_row("SELECT COUNT(*) as cnt FROM `$table`");
    $before_sizes[$table] = [
        'count' => $count_row['cnt'] ?? 0,
        'size_mb' => $size_row['size_mb'] ?? 0
    ];
}

// Find inactive users
log_msg("Finding inactive users...");
$inactive_query = "
    SELECT u.cs_user_id, uu.last_ts,
           (SELECT COUNT(*) FROM activities WHERE cs_user_id = u.cs_user_id) as activity_count
    FROM users u
    LEFT JOIN users_usage uu ON u.cs_user_id = uu.cs_user_id
    WHERE uu.last_ts IS NULL
       OR uu.last_ts < DATE_SUB(NOW(), INTERVAL $inactive_days DAY)
    ORDER BY activity_count DESC
";
$inactive_users = $process->sql->query_as_array($inactive_query);
$total_inactive = count($inactive_users);

log_msg("Found $total_inactive inactive users");

// Count data to be deleted
$count_query = "
    SELECT
        (SELECT COUNT(*) FROM activities a
         LEFT JOIN users_usage uu ON a.cs_user_id = uu.cs_user_id
         WHERE uu.last_ts IS NULL OR uu.last_ts < DATE_SUB(NOW(), INTERVAL $inactive_days DAY)) as activities,
        (SELECT COUNT(*) FROM weather w
         LEFT JOIN users_usage uu ON w.cs_user_id = uu.cs_user_id
         WHERE uu.last_ts IS NULL OR uu.last_ts < DATE_SUB(NOW(), INTERVAL $inactive_days DAY)) as weather,
        (SELECT COUNT(*) FROM fitfiles f
         LEFT JOIN users_usage uu ON f.cs_user_id = uu.cs_user_id
         WHERE uu.last_ts IS NULL OR uu.last_ts < DATE_SUB(NOW(), INTERVAL $inactive_days DAY)) as fitfiles,
        (SELECT COUNT(*) FROM fitsession fs
         LEFT JOIN users_usage uu ON fs.cs_user_id = uu.cs_user_id
         WHERE uu.last_ts IS NULL OR uu.last_ts < DATE_SUB(NOW(), INTERVAL $inactive_days DAY)) as fitsession
";
$counts = $process->sql->query_first_row($count_query);

log_msg("Data to delete from inactive users:");
log_msg("  - activities: " . number_format($counts['activities']));
log_msg("  - weather: " . number_format($counts['weather']));
log_msg("  - fitfiles: " . number_format($counts['fitfiles']));
log_msg("  - fitsession: " . number_format($counts['fitsession']));
echo "\n";

// Count orphaned data
if ($include_orphans) {
    $orphan_counts = $process->sql->query_first_row("
        SELECT
            (SELECT COUNT(*) FROM activities WHERE cs_user_id IS NULL) as activities,
            (SELECT COUNT(*) FROM weather WHERE cs_user_id IS NULL) as weather,
            (SELECT COUNT(*) FROM fitfiles WHERE cs_user_id IS NULL) as fitfiles,
            (SELECT COUNT(*) FROM fitsession WHERE cs_user_id IS NULL) as fitsession
    ");
    log_msg("Orphaned data (NULL cs_user_id) to delete:");
    log_msg("  - activities: " . number_format($orphan_counts['activities']));
    log_msg("  - weather: " . number_format($orphan_counts['weather']));
    log_msg("  - fitfiles: " . number_format($orphan_counts['fitfiles']));
    log_msg("  - fitsession: " . number_format($orphan_counts['fitsession']));
    echo "\n";
}

if ($dry_run) {
    log_msg("DRY RUN - No data will be deleted. Remove --dry-run to execute.");
    exit(0);
}

// Process users in batches
$processed = 0;
$deleted_counts = ['activities' => 0, 'weather' => 0, 'fitfiles' => 0, 'fitsession' => 0, 's3_files' => 0];

log_msg("Starting deletion in batches of $batch_size...");

foreach (array_chunk($inactive_users, $batch_size) as $batch_num => $batch) {
    $user_ids = array_map(function($u) { return $u['cs_user_id']; }, $batch);
    $user_ids_str = implode(',', $user_ids);

    if (empty($user_ids_str)) continue;

    // Get S3 paths before deleting fitfiles records
    if (!$skip_s3 && isset($process->api_config['save_to_s3_bucket'])) {
        $s3_paths = $process->sql->query_as_array(
            "SELECT s3.s3path, s3.file_id
             FROM assets_s3 s3
             JOIN fitfiles f ON s3.file_id = f.file_id
             WHERE f.cs_user_id IN ($user_ids_str)"
        );

        $bucket = $process->api_config['save_to_s3_bucket'];
        $is_local = strpos($bucket, 'localhost:') === 0;

        if ($is_local) {
            // Local filesystem cleanup
            $local_path = str_replace('localhost:', '', $bucket);
            foreach ($s3_paths as $row) {
                $file_path = $local_path . '/' . $row['s3path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                    $deleted_counts['s3_files']++;
                }
            }
        } else {
            // S3 cleanup
            if (!empty($s3_paths)) {
                include_once('../S3.php');
                $s3 = new S3(
                    $process->api_config['s3_access_key'],
                    $process->api_config['s3_secret_key']
                );

                foreach ($s3_paths as $row) {
                    if ($s3->deleteObject($bucket, $row['s3path'])) {
                        $deleted_counts['s3_files']++;
                    }
                }
            }
        }

        // Delete assets_s3 records
        $process->sql->execute_query(
            "DELETE s3 FROM assets_s3 s3
             JOIN fitfiles f ON s3.file_id = f.file_id
             WHERE f.cs_user_id IN ($user_ids_str)"
        );
    }

    // Delete from each table
    $result = $process->sql->execute_query("DELETE FROM fitsession WHERE cs_user_id IN ($user_ids_str)");
    $deleted_counts['fitsession'] += $process->sql->connection->affected_rows;

    $result = $process->sql->execute_query("DELETE FROM weather WHERE cs_user_id IN ($user_ids_str)");
    $deleted_counts['weather'] += $process->sql->connection->affected_rows;

    $result = $process->sql->execute_query("DELETE FROM activities WHERE cs_user_id IN ($user_ids_str)");
    $deleted_counts['activities'] += $process->sql->connection->affected_rows;

    $result = $process->sql->execute_query("DELETE FROM fitfiles WHERE cs_user_id IN ($user_ids_str)");
    $deleted_counts['fitfiles'] += $process->sql->connection->affected_rows;

    $processed += count($batch);

    if ($verbose || $batch_num % 10 == 0) {
        log_msg("Processed $processed / $total_inactive users...");
    }
}

// Delete orphaned data
if ($include_orphans) {
    log_msg("Deleting orphaned data (NULL cs_user_id)...");

    // Get orphan S3 paths
    if (!$skip_s3 && isset($process->api_config['save_to_s3_bucket'])) {
        $s3_paths = $process->sql->query_as_array(
            "SELECT s3.s3path, s3.file_id
             FROM assets_s3 s3
             JOIN fitfiles f ON s3.file_id = f.file_id
             WHERE f.cs_user_id IS NULL"
        );

        $bucket = $process->api_config['save_to_s3_bucket'];
        $is_local = strpos($bucket, 'localhost:') === 0;

        if ($is_local) {
            $local_path = str_replace('localhost:', '', $bucket);
            foreach ($s3_paths as $row) {
                $file_path = $local_path . '/' . $row['s3path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                    $deleted_counts['s3_files']++;
                }
            }
        } else if (!empty($s3_paths)) {
            include_once('../S3.php');
            $s3 = new S3(
                $process->api_config['s3_access_key'],
                $process->api_config['s3_secret_key']
            );
            foreach ($s3_paths as $row) {
                if ($s3->deleteObject($bucket, $row['s3path'])) {
                    $deleted_counts['s3_files']++;
                }
            }
        }

        $process->sql->execute_query(
            "DELETE s3 FROM assets_s3 s3
             JOIN fitfiles f ON s3.file_id = f.file_id
             WHERE f.cs_user_id IS NULL"
        );
    }

    $process->sql->execute_query("DELETE FROM fitsession WHERE cs_user_id IS NULL");
    $deleted_counts['fitsession'] += $process->sql->connection->affected_rows;

    $process->sql->execute_query("DELETE FROM weather WHERE cs_user_id IS NULL");
    $deleted_counts['weather'] += $process->sql->connection->affected_rows;

    $process->sql->execute_query("DELETE FROM activities WHERE cs_user_id IS NULL");
    $deleted_counts['activities'] += $process->sql->connection->affected_rows;

    $process->sql->execute_query("DELETE FROM fitfiles WHERE cs_user_id IS NULL");
    $deleted_counts['fitfiles'] += $process->sql->connection->affected_rows;
}

echo "\n";
log_msg("=== Deletion Summary ===");
log_msg("Deleted activities: " . number_format($deleted_counts['activities']));
log_msg("Deleted weather: " . number_format($deleted_counts['weather']));
log_msg("Deleted fitfiles: " . number_format($deleted_counts['fitfiles']));
log_msg("Deleted fitsession: " . number_format($deleted_counts['fitsession']));
log_msg("Deleted S3 files: " . number_format($deleted_counts['s3_files']));
echo "\n";

// Optimize tables to reclaim space
if (!$skip_optimize) {
    log_msg("Running OPTIMIZE TABLE to reclaim disk space...");
    log_msg("(This may take a while for large tables)");

    foreach ($tables as $table) {
        if ($table === 'assets_s3') continue; // Skip, usually small

        log_msg("  Optimizing $table...");
        $process->sql->execute_query("OPTIMIZE TABLE `$table`");
    }

    echo "\n";
}

// Get table sizes after cleanup
$after_sizes = [];
foreach ($tables as $table) {
    $size_row = $process->sql->query_first_row(
        "SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = '$table'"
    );
    $count_row = $process->sql->query_first_row("SELECT COUNT(*) as cnt FROM `$table`");
    $after_sizes[$table] = [
        'count' => $count_row['cnt'] ?? 0,
        'size_mb' => $size_row['size_mb'] ?? 0
    ];
}

log_msg("=== Space Reclaimed ===");
foreach ($tables as $table) {
    $before = $before_sizes[$table];
    $after = $after_sizes[$table];
    $saved_mb = $before['size_mb'] - $after['size_mb'];
    $saved_rows = $before['count'] - $after['count'];
    log_msg(sprintf("  %s: %.2f MB -> %.2f MB (saved %.2f MB, %d rows)",
        $table, $before['size_mb'], $after['size_mb'], $saved_mb, $saved_rows));
}

$elapsed = microtime(true) - $start_time;
echo "\n";
log_msg(sprintf("Completed in %.2f seconds", $elapsed));

exit(0);
?>
