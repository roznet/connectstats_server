<?php
/**
 * Test-stack error logger (auto_prepend_file, docker/php/test-errors.ini; never used in prod).
 *
 * The code sets error_reporting(E_ALL & ~E_DEPRECATED) at runtime, which hides deprecations
 * whatever php.ini says. An error handler is called for every error regardless of
 * error_reporting, so this logs all warnings, notices and deprecations to the error log
 * (stderr: Apache's log, or the queue task's log file) with a PHP-TEST prefix.
 * "hidden" marks messages the normal settings would not have shown (or @-suppressed ones).
 * Fatal errors are caught at shutdown.
 */
set_error_handler(function (int $no, string $str, string $file = '', int $line = 0): bool {
    static $names = [
        E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated',
        E_USER_ERROR => 'User Error', E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice', E_USER_DEPRECATED => 'User Deprecated',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
    ];
    $hidden = (error_reporting() & $no) ? '' : ' (hidden)';
    error_log(sprintf('PHP-TEST %s%s: %s in %s:%d', $names[$no] ?? "E$no", $hidden, $str, $file, $line));
    return true; // logged here; skip PHP's own handler so nothing is reported twice
});

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log(sprintf('PHP-TEST Fatal: %s in %s:%d', $e['message'], $e['file'], $e['line']));
    }
});
