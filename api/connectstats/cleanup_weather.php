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
 * Remove weather rows that carry no data
 *
 * Usage: php cleanup_weather.php [options]
 *
 * Options:
 *   --dry-run        Count what would change without changing anything
 *   --drop-darksky   Also drop Dark Sky data (provider shut down in 2023)
 *   --range=N        file_id range per batch (default: 20000)
 *   --skip-optimize  Skip OPTIMIZE TABLE at the end
 *
 * Each row keeps only the providers that returned data: Visual Crossing
 * "No data available" answers, error answers and empty rows are dropped.
 * A row left with no provider is deleted.
 */

include_once('../shared.php');

$options = getopt('', ['dry-run', 'drop-darksky', 'range:', 'skip-optimize', 'help']);

if (isset($options['help'])) {
    echo "Usage: php cleanup_weather.php [--dry-run] [--drop-darksky] [--range=N] [--skip-optimize]\n";
    exit(0);
}

$dry_run = isset($options['dry-run']);
$drop_darksky = isset($options['drop-darksky']);
$range = isset($options['range']) ? intval($options['range']) : 20000;
$skip_optimize = isset($options['skip-optimize']);

$process = new GarminProcess();
$process->ensure_commandline($argv ?? NULL);
$process->sql->execute_query("SET SESSION information_schema_stats_expiry = 0");

function log_msg($msg) {
    global $dry_run;
    printf("%s%s: %s\n", $dry_run ? '[DRY-RUN] ' : '', date('Y-m-d H:i:s'), $msg);
}

function table_mb($process) {
    $row = $process->sql->query_first_row(
        "SELECT ROUND((data_length + index_length) / 1024 / 1024) AS mb FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'weather'"
    );
    return $row['mb'] ?? 0;
}

/**
 * Providers in a weather json that carry data
 */
function providers_with_data($process, $weather) {
    global $drop_darksky;

    $keep = [];
    if (!is_array($weather)) {
        return $keep;
    }
    foreach ($weather as $provider => $data) {
        switch ($provider) {
            case 'visualCrossing':
                if ($process->weather_visualCrossing_has_data($data)) {
                    $keep[$provider] = $data;
                }
                break;
            case 'darkSky':
                // errors come back as {"code":..,"error":..}
                if (!$drop_darksky && is_array($data) && isset($data['latitude']) && !isset($data['code'])) {
                    $keep[$provider] = $data;
                }
                break;
            default:
                // openWeatherMap or older formats: keep anything non-empty without an error code
                if (!empty($data) && !isset($data['code']) && !isset($data['errorCode'])) {
                    $keep[$provider] = $data;
                }
        }
    }
    return $keep;
}

log_msg("=== Weather Cleanup ===");
log_msg("Drop Dark Sky: " . ($drop_darksky ? 'YES' : 'NO'));

$bounds = $process->sql->query_first_row("SELECT MIN(file_id) AS lo, MAX(file_id) AS hi, COUNT(*) AS n FROM weather");
$before_mb = table_mb($process);
log_msg(sprintf("Rows: %s, file_id %s..%s, %s MB", number_format($bounds['n']), $bounds['lo'], $bounds['hi'], $before_mb));

$counts = ['kept' => 0, 'deleted' => 0, 'updated' => 0];
$start_time = microtime(true);

for ($lo = intval($bounds['lo']); $lo <= intval($bounds['hi']); $lo += $range) {
    $hi = $lo + $range - 1;
    $rows = $process->sql->query_as_array("SELECT file_id, json FROM weather WHERE file_id BETWEEN $lo AND $hi");

    $delete_ids = [];
    foreach ($rows as $row) {
        $weather = json_decode($row['json'], true);
        // Rows with providers at the top level ({"latitude":..}) are old Dark Sky answers
        if (is_array($weather) && isset($weather['latitude'])) {
            $weather = ['darkSky' => $weather];
        }
        $keep = providers_with_data($process, $weather);
        if (empty($keep)) {
            $delete_ids[] = intval($row['file_id']);
        } elseif (!is_array($weather) || count($keep) != count($weather)) {
            $counts['updated']++;
            if (!$dry_run) {
                // ts = ts: keep the original timestamp (the column auto-updates)
                $json = $process->sql->connection->real_escape_string(json_encode($keep));
                $process->sql->execute_query(sprintf("UPDATE weather SET json = '%s', ts = ts WHERE file_id = %d", $json, $row['file_id']));
            }
        } else {
            $counts['kept']++;
        }
    }

    if (!empty($delete_ids)) {
        $counts['deleted'] += count($delete_ids);
        if (!$dry_run) {
            $process->sql->execute_query("DELETE FROM weather WHERE file_id IN (" . implode(',', $delete_ids) . ")");
        }
    }

    if (($lo - intval($bounds['lo'])) % ($range * 50) == 0) {
        log_msg(sprintf("file_id %d: kept %s, updated %s, deleted %s",
            $hi, number_format($counts['kept']), number_format($counts['updated']), number_format($counts['deleted'])));
    }
}

log_msg(sprintf("=== Summary === kept %s, updated %s, deleted %s",
    number_format($counts['kept']), number_format($counts['updated']), number_format($counts['deleted'])));

if (!$dry_run && !$skip_optimize) {
    log_msg("Running OPTIMIZE TABLE weather...");
    $process->sql->execute_query("OPTIMIZE TABLE weather");
    log_msg(sprintf("weather: %s MB -> %s MB", $before_mb, table_mb($process)));
}

log_msg(sprintf("Completed in %.0f seconds", microtime(true) - $start_time));
exit(0);
?>
