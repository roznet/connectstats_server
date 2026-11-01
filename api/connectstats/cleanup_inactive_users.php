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
 *   --orphan-assets     Also delete assets rows (and their S3 files) whose fitfiles row is gone
 *   --limit=N           Max orphan assets to process per run (default: 100000)
 *   --skip-inactive     Don't process inactive users (e.g. only --orphan-assets)
 *   --verbose           Show detailed progress
 *
 * This script will:
 *   1. Find users who haven't used the app in N days and still have data
 *   2. Delete their FIT files from S3 and their assets rows
 *   3. Delete their activities, weather, fitfiles, fitsession records
 *   4. Optionally do the same for orphaned records (NULL cs_user_id)
 *   5. Optionally delete assets left behind by earlier cleanups
 *   6. Run OPTIMIZE TABLE to reclaim disk space
 *
 * Only the data is deleted: users, users_usage and tokens rows are kept so a
 * returning user can still sync.
 */

include_once('../shared.php');

// Parse command line options
$options = getopt('', ['dry-run', 'days:', 'batch:', 'skip-s3', 'skip-optimize', 'include-orphans', 'orphan-assets', 'limit:', 'skip-inactive', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php cleanup_inactive_users.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run           Show what would be deleted without actually deleting\n";
    echo "  --days=N            Inactive threshold in days (default: 90)\n";
    echo "  --batch=N           Process N users per batch (default: 100)\n";
    echo "  --skip-s3           Skip S3 cleanup (only clean database)\n";
    echo "  --skip-optimize     Skip OPTIMIZE TABLE at the end\n";
    echo "  --include-orphans   Also delete data with NULL cs_user_id\n";
    echo "  --orphan-assets     Also delete assets rows (and their S3 files) whose fitfiles row is gone\n";
    echo "  --limit=N           Max orphan assets to process per run (default: 100000)\n";
    echo "  --skip-inactive     Don't process inactive users (e.g. only --orphan-assets)\n";
    echo "  --verbose           Show detailed progress\n";
    exit(0);
}

$dry_run = isset($options['dry-run']);
$inactive_days = isset($options['days']) ? intval($options['days']) : 90;
$batch_size = isset($options['batch']) ? intval($options['batch']) : 100;
$skip_s3 = isset($options['skip-s3']);
$skip_optimize = isset($options['skip-optimize']);
$include_orphans = isset($options['include-orphans']);
$orphan_assets = isset($options['orphan-assets']);
$orphan_limit = isset($options['limit']) ? intval($options['limit']) : 100000;
$skip_inactive = isset($options['skip-inactive']);
$verbose = isset($options['verbose']);

$process = new GarminProcess();
$process->set_verbose($verbose);
$process->ensure_commandline($argv ?? NULL);

$bucket = $process->api_config['save_to_s3_bucket'] ?? NULL;
if ($bucket === NULL) {
    $skip_s3 = true;
}

// MySQL 8 caches information_schema sizes for a day by default, which made
// the before/after report always show 0 MB saved.
$process->sql->execute_query("SET SESSION information_schema_stats_expiry = 0");

$start_time = microtime(true);

function log_msg($msg) {
    global $dry_run;
    $prefix = $dry_run ? '[DRY-RUN] ' : '';
    printf("%s%s: %s\n", $prefix, date('Y-m-d H:i:s'), $msg);
}

function table_sizes($process, $tables) {
    $sizes = [];
    foreach ($tables as $table) {
        $size_row = $process->sql->query_first_row(
            "SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '$table'"
        );
        $count_row = $process->sql->query_first_row("SELECT COUNT(*) as cnt FROM `$table`");
        $sizes[$table] = [
            'count' => $count_row['cnt'] ?? 0,
            'size_mb' => $size_row['size_mb'] ?? 0
        ];
    }
    return $sizes;
}

/**
 * Delete the stored files and the assets rows for the given asset rows (asset_id, path).
 * Rows whose file could not be deleted are kept so a later run can retry.
 * Returns ['files' => stored files deleted, 'rows' => assets rows deleted]. Exits if S3 itself fails.
 */
function delete_assets($process, $rows) {
    global $skip_s3, $bucket;

    if (empty($rows)) {
        return ['files' => 0, 'rows' => 0];
    }

    $deleted_files = 0;
    if (!$skip_s3) {
        $paths = [];
        foreach ($rows as $row) {
            if (substr($row['path'], 0, 3) == 's3:') {
                $paths[] = substr($row['path'], 3);
            }
        }
        if (!empty($paths)) {
            $failed = $process->delete_many_from_s3_bucket($bucket, $paths);
            if ($failed === false) {
                log_msg("ERROR: S3 delete request failed, stopping");
                exit(1);
            }
            if (!empty($failed)) {
                log_msg("WARNING: " . count($failed) . " files could not be deleted, keeping their assets rows");
                $failed = array_flip(array_map(function($p) { return 's3:' . $p; }, $failed));
                $rows = array_filter($rows, function($r) use ($failed) { return !isset($failed[$r['path']]); });
            }
            $deleted_files = count($paths) - count($failed);
        }
    }

    if (empty($rows)) {
        return ['files' => $deleted_files, 'rows' => 0];
    }
    $asset_ids_str = implode(',', array_map(function($r) { return intval($r['asset_id']); }, $rows));
    $process->sql->execute_query("DELETE FROM assets WHERE asset_id IN ($asset_ids_str)");
    $deleted_rows = $process->sql->connection->affected_rows;

    return ['files' => $deleted_files, 'rows' => $deleted_rows];
}

/**
 * Delete all data matching a cs_user_id condition (e.g. "IN (1,2,3)" or "IS NULL").
 */
function delete_user_data($process, $user_condition, &$deleted_counts) {
    // assets first: they are found through fitfiles
    $assets = $process->sql->query_as_array(
        "SELECT a.asset_id, a.path
         FROM assets a
         JOIN fitfiles f ON a.file_id = f.file_id
         WHERE f.cs_user_id $user_condition"
    );
    $deleted = delete_assets($process, $assets);
    $deleted_counts['s3_files'] += $deleted['files'];
    $deleted_counts['assets'] += $deleted['rows'];

    foreach (['fitsession', 'weather', 'activities', 'fitfiles'] as $table) {
        $process->sql->execute_query("DELETE FROM `$table` WHERE cs_user_id $user_condition");
        $deleted_counts[$table] += $process->sql->connection->affected_rows;
    }
}

log_msg("=== Inactive User Cleanup ===");
log_msg("Inactive threshold: $inactive_days days");
log_msg("Batch size: $batch_size users");
log_msg("Dry run: " . ($dry_run ? 'YES' : 'NO'));
log_msg("Skip S3: " . ($skip_s3 ? 'YES' : 'NO'));
log_msg("Include orphans: " . ($include_orphans ? 'YES' : 'NO'));
log_msg("Orphan assets: " . ($orphan_assets ? "YES (limit $orphan_limit)" : 'NO'));
log_msg("Skip inactive users: " . ($skip_inactive ? 'YES' : 'NO'));
echo "\n";

$tables = ['activities', 'weather', 'fitfiles', 'fitsession', 'assets'];
$before_sizes = table_sizes($process, $tables);

$inactive_users = [];
$total_inactive = 0;
if (!$skip_inactive) {
    // Find inactive users
    log_msg("Finding inactive users...");
    $inactive_users = $process->sql->query_as_array("
        SELECT u.cs_user_id
        FROM users u
        LEFT JOIN users_usage uu ON u.cs_user_id = uu.cs_user_id
        WHERE uu.last_ts IS NULL
           OR uu.last_ts < DATE_SUB(NOW(), INTERVAL $inactive_days DAY)
    ");
    log_msg("Found " . count($inactive_users) . " inactive users");

    // Most inactive users were already cleaned in a previous run. fitfiles, weather
    // and fitsession have no cs_user_id index, so every batch is a full table scan:
    // only keep the users that still have data.
    $users_with_data = [];
    foreach (['activities', 'fitfiles', 'weather', 'fitsession'] as $table) {
        $rows = $process->sql->query_as_array("SELECT DISTINCT cs_user_id FROM `$table` WHERE cs_user_id IS NOT NULL");
        foreach ($rows as $row) {
            $users_with_data[$row['cs_user_id']] = true;
        }
    }
    $inactive_users = array_values(array_filter($inactive_users, function($u) use ($users_with_data) {
        return isset($users_with_data[$u['cs_user_id']]);
    }));
    $total_inactive = count($inactive_users);
    log_msg("Of which $total_inactive still have data");
}

if ($total_inactive > 0) {
    $user_ids_str = implode(',', array_map(function($u) { return intval($u['cs_user_id']); }, $inactive_users));
    log_msg("Data to delete from inactive users:");
    foreach (['activities', 'weather', 'fitfiles', 'fitsession'] as $table) {
        $row = $process->sql->query_first_row("SELECT COUNT(*) AS cnt FROM `$table` WHERE cs_user_id IN ($user_ids_str)");
        log_msg("  - $table: " . number_format($row['cnt'] ?? 0));
    }
    echo "\n";
}

if ($include_orphans) {
    log_msg("Orphaned data (NULL cs_user_id) to delete:");
    foreach (['activities', 'weather', 'fitfiles', 'fitsession'] as $table) {
        $row = $process->sql->query_first_row("SELECT COUNT(*) AS cnt FROM `$table` WHERE cs_user_id IS NULL");
        log_msg("  - $table: " . number_format($row['cnt'] ?? 0));
    }
    echo "\n";
}

if ($orphan_assets) {
    $row = $process->sql->query_first_row(
        "SELECT COUNT(*) AS cnt FROM assets a LEFT JOIN fitfiles f ON a.file_id = f.file_id WHERE f.file_id IS NULL"
    );
    log_msg("Orphaned assets (no fitfiles row): " . number_format($row['cnt'] ?? 0) . ", will process up to " . number_format($orphan_limit));
    echo "\n";
}

if ($dry_run) {
    log_msg("DRY RUN - No data will be deleted. Remove --dry-run to execute.");
    exit(0);
}

$processed = 0;
$deleted_counts = ['activities' => 0, 'weather' => 0, 'fitfiles' => 0, 'fitsession' => 0, 'assets' => 0, 's3_files' => 0];

log_msg("Starting deletion in batches of $batch_size...");

foreach (array_chunk($inactive_users, $batch_size) as $batch_num => $batch) {
    $user_ids_str = implode(',', array_map(function($u) { return intval($u['cs_user_id']); }, $batch));
    delete_user_data($process, "IN ($user_ids_str)", $deleted_counts);

    $processed += count($batch);
    if ($verbose || $batch_num % 10 == 0) {
        log_msg("Processed $processed / $total_inactive users...");
    }
}

if ($include_orphans) {
    log_msg("Deleting orphaned data (NULL cs_user_id)...");
    delete_user_data($process, "IS NULL", $deleted_counts);
}

if ($orphan_assets) {
    log_msg("Deleting orphaned assets...");
    $done = 0;
    $last_asset_id = 0;
    while ($done < $orphan_limit) {
        $chunk = min(1000, $orphan_limit - $done);
        $rows = $process->sql->query_as_array(
            "SELECT a.asset_id, a.path
             FROM assets a
             LEFT JOIN fitfiles f ON a.file_id = f.file_id
             WHERE f.file_id IS NULL AND a.asset_id > $last_asset_id
             ORDER BY a.asset_id
             LIMIT $chunk"
        );
        if (empty($rows)) {
            break;
        }
        $last_asset_id = end($rows)['asset_id'];
        $deleted = delete_assets($process, $rows);
        $deleted_counts['s3_files'] += $deleted['files'];
        $deleted_counts['assets'] += $deleted['rows'];
        $done += count($rows);
        if ($verbose || $done % 10000 == 0) {
            log_msg("Orphaned assets processed: " . number_format($done));
        }
    }
}

echo "\n";
log_msg("=== Deletion Summary ===");
log_msg("Deleted activities: " . number_format($deleted_counts['activities']));
log_msg("Deleted weather: " . number_format($deleted_counts['weather']));
log_msg("Deleted fitfiles: " . number_format($deleted_counts['fitfiles']));
log_msg("Deleted fitsession: " . number_format($deleted_counts['fitsession']));
log_msg("Deleted assets: " . number_format($deleted_counts['assets']));
log_msg("Deleted S3 files: " . number_format($deleted_counts['s3_files']));
echo "\n";

// Optimize tables to reclaim space
if (!$skip_optimize) {
    log_msg("Running OPTIMIZE TABLE to reclaim disk space...");
    log_msg("(This may take a while for large tables)");

    foreach ($tables as $table) {
        log_msg("  Optimizing $table...");
        $process->sql->execute_query("OPTIMIZE TABLE `$table`");
    }

    echo "\n";
}

$after_sizes = table_sizes($process, $tables);

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
