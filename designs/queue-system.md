# Queue System

## Overview

The server uses a database-backed queue system to process tasks asynchronously. This decouples webhook reception from processing, ensuring:
- Fast webhook responses to Garmin
- Reliable task execution with retry capability
- Scalable processing across multiple workers

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Webhook    │────▶│   Queue     │────▶│  Processor  │
│  Receiver   │     │   Table     │     │   (cron)    │
└─────────────┘     └─────────────┘     └─────────────┘
                          │                    │
                          │                    ▼
                          │            ┌─────────────┐
                          │            │  Execute    │
                          │            │  PHP Script │
                          │            └─────────────┘
                          │                    │
                          └────────────────────┘
                                 (status update)
```

## Database Schema

### tasks Table

```sql
CREATE TABLE tasks (
    task_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_id BIGINT(20) UNSIGNED DEFAULT NULL, -- Processor ID (for locking)
    task_command VARCHAR(512),     -- Command to execute (e.g., 'php runactivities.php 123')
    task_cwd VARCHAR(128),         -- Current working directory for task execution
    exec_status INT UNSIGNED,      -- Exit status of the executed command
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_ts DATETIME,
    finished_ts DATETIME,
    not_before_ts DATETIME         -- Delay execution until this timestamp
);
```

### queues Table

Tracks active processors for heartbeat monitoring (formerly `queue_processor`).

```sql
CREATE TABLE queues (
    queue_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    queue_index BIGINT(20) UNSIGNED DEFAULT NULL, -- Index of the queue process (0 to N-1)
    queue_pid BIGINT(20) UNSIGNED DEFAULT NULL,   -- OS Process ID
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    heartbeat_ts DATETIME,         -- Last time processor checked in
    status VARCHAR(16),            -- e.g., 'running', 'stop', 'dead:timeout'
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Queue Class

Location: `api/queue.php`

### Key Methods

```php
class Queue {
    // Initialize queue connection
    function __construct($config);

    // Add task to queue
    function add_task($command, $cwd, $not_before = null);

    // Run the queue processor loop for a specific queue_id
    function run(int $queue_id);

    // Finds one task, executes it, and updates its status
    // Combines logic for next_task, process_task, complete_task, fail_task
    function run_one_task(int $queue_index);
    
    // Manage queue processes (start, stop, kill, list, clean)
    function start_queues();
    function stop_queues();
    function kill_queues();
    function list_queues();
    function clean_tasks_and_queues();
}
```

### Adding Tasks

```php
$queue = new Queue($api_config);

// Immediate execution
$queue->add_task('php runactivities.php 123', '/path/to/cwd');

// Delayed execution
$queue->add_task('php runfitfiles.php 456', '/path/to/cwd', time() + 60);
```

## Task Types

| Type | Script | Purpose |
|------|--------|---------|
| activities | runactivities.php | Process activity webhook |
| fitfiles | runfitfiles.php | Process file webhook |
| callback | runcallback.php | Download FIT file |
| fitextract | runfitextract.php | Extract FIT session data |
| maintenance | maintenance.php | Database cleanup (e.g. from /api/garmin/maintenance.php) |
| notification | activity.php / push.php | Send push notifications (from /api/notifications/) |

## Task Scripts

### runactivities.php

Processes activity cache entries:

```php
include_once('../shared.php');

$process = new GarminProcess();
$process->ensure_commandline($argv);

if (isset($argv[1])) {
    $cache_id = intval($argv[1]);
    $process->process('activities', $cache_id, $required);
}
```

### runfitfiles.php

Processes FIT file cache entries:

```php
$process->process('fitfiles', $cache_id, $required);
// After processing, queues callback task for download
```

### runcallback.php

Downloads FIT file from Garmin's callback URL:

```php
$process->file_callback_one($file_id);
// After download, queues fitextract task
```

### runfitextract.php

Extracts session data from FIT file:

```php
$process->fit_extract($file_id, $data_mesgs);
// Also triggers weather query
```

## Queue Processor

Location: `api/queue/runqueue.php`

The queue processor runs as a background daemon (typically via cron). The `runqueue.php` script calls `Queue::run()`:

```php
$queue = new Queue($api_config);
$queue->run($queue_id); // Loops infinitely, processing tasks
```

### Cron Setup

```bash
# Example: Start N queue processors on boot (where N is $queue_count in config)
# And ensure they are running by running queuectl.php start every minute
# * * * * * cd /var/www/html/api && php queue/queuectl.php start >> /var/log/queue.log 2>&1
```

## Task Lifecycle

```
┌──────────┐
│ pending  │◀─────────────────────────┐
└────┬─────┘                          │
     │ run_one_task()                 │ (if failed and retry logic implemented)
     ▼                                │
┌──────────┐                          │
│ running  │──────────────────────────┤
└────┬─────┘                          │
     │                                │
     ├────────────┐                   │
     ▼            ▼                   │
┌──────────┐ ┌──────────┐            │
│completed │ │  failed  │─────────────┘
└──────────┘ └──────────┘
```

## Configuration

Queue uses a separate database (optional but recommended):

```php
$api_config = array(
    // Main database
    'database' => 'connectstats_db',

    // Queue database (can be same as main)
    'db_queue' => 'connectstats_queue',

    // Same connection credentials
    'db_host' => 'localhost',
    'db_username' => 'user',
    'db_password' => 'pass',
);
```

## Task Chaining

Tasks can queue follow-up tasks:

```
cache_activities received
         │
         ▼
runactivities.php (task 1)
         │
         │ (if FIT file available)
         ▼
runcallback.php (task 2)
         │
         ▼
runfitextract.php (task 3)
         │
         │ (if weather enabled)
         ▼
weather query (inline in fitextract)
```

## Error Handling

### Task Failure

When a task fails:
1. `exec_status` is updated in the `tasks` table.
2. Detailed error messages are typically found in the task's individual log file.

### Retry Logic

Manual retry:
```bash
# Re-queue a failed task (via api/queue/queuectl.php)
php queuectl.php add "php runactivities.php {cache_id}"
```

Automatic retry (not currently implemented but could be added):
```php
// Logic would be within Queue::run_one_task or the task script itself
// if ($exec_status !== 0 && $task['retry_count'] < 3) {
//     $this->add_task($task['task_command'], $task['task_cwd'], time() + 300);
// }
```

## Monitoring

### Check Queue Status

```sql
-- Pending tasks
SELECT task_command, COUNT(*)
FROM tasks WHERE finished_ts IS NULL AND started_ts IS NULL AND not_before_ts <= NOW()
GROUP BY task_command;

-- Failed tasks
SELECT task_id, task_command, started_ts, finished_ts, exec_status
FROM tasks WHERE exec_status != 0
ORDER BY finished_ts DESC LIMIT 10;

-- Long-running tasks
SELECT t.task_id, t.task_command, t.started_ts,
       TIMEDIFF(NOW(), t.started_ts) AS runtime, q.queue_pid AS processor_pid
FROM tasks t
LEFT JOIN queues q ON t.queue_id = q.queue_id
WHERE t.finished_ts IS NULL AND t.started_ts IS NOT NULL
  AND TIMEDIFF(NOW(), t.started_ts) > '00:05:00'; -- Adjust timeout as needed
```

### Queue Control Script

`api/queue/queuectl.php`:
- View queue status (`list`)
- Stop running processors gracefully (`stop`)
- Kill running processors (`kill`)
- Clean up old tasks/queues (`clean`)
- Add new tasks (`add`)
- View running processes (`ps`)

## Performance Considerations

1. **Separate database**: Prevents queue operations from blocking main API
2. **Indexing**: Index on relevant fields (`finished_ts`, `started_ts`, `not_before_ts`, `queue_id`) for efficient polling
3. **Task distribution**: Tasks are distributed among `N` queues using `MOD(task_id, N) = queue_index`.
4. **Multiple processors**: Can run multiple processor instances (`$queue_count` in config).

## Task Execution

Tasks are executed as separate PHP processes:

```php
function run_one_task($queue_index) {
    // ... (logic to find and lock task)
    $command = $task_row['task_command'];
    $cwd = $task_row['task_cwd'];

    // Execute the command
    chdir($cwd);
    exec("$command > $log_file 2>&1", $output, $status);
    // ... (update task status)
}
```

This isolation ensures:
- Memory is released between tasks
- Crashes don't affect the processor
- Logs are captured per-task (in `log/YYYYMMDD/task_{task_id}.log`)
