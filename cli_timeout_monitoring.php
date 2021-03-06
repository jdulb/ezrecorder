<?php


/**
 *  This CLI script performs various monitoring tasks. It is started when the user starts a recording, and stopped when they stop recording.
 * This script is called by qtbstart and qtbstop.
 * Current checks performed:
 * - timeout check (checks whether the user has forgotten to stop recording, and publish the recording if they did)
 */
/**
 * Timeout check:
 * For the first "threshold" seconds (typically 2 or 3 hours), we decide to trust the user.
 * After that, we check that there has been activity at least once every "timeout" seconds (typically 15 min).
 * This program is meant to be run as a crontask at least once every "timeout" seconds
 */
require_once 'global_config.inc';
require_once $basedir . 'lib_model.php';

require_once $session_lib;

global $service;
$service = true;

Logger::$print_logs = true;

$logger->log(EventType::RECORDER_TIMEOUT_MONITORING, LogLevel::INFO, "Monitoring started", array(basename(__FILE__)));

// Saves the time when the recording has been init
$init_time = time();
$fct_initstarttime_set = "session_" . $session_module . "_initstarttime_set";
$fct_initstarttime_set($init_time);

// Delays, in seconds
$threshold_timeout = 7200; // Threshold before we start worrying about the user
//$threshold_timeout = 120; // Threshold before we start worrying about the user
$timeout = 900; // Timeout after which we consider a user has forgotten to stop their recording
//$timeout = 30;
$sleep_time = 60; // Duration of the sleep between two checks

set_time_limit(0);
$pid = getmypid();
fwrite(fopen($recorder_monitoring_pid, 'w'), $pid);

//get asset name ?

// This is the main loop. Runs until the lock file disappears
while (true) {

    $fct_is_locked = "session_" . $session_module . "_is_locked";

    //Stop conditions:
    // We stop if the pid file does not exist anymore ("kill -9" simulation)
    // or the file contains an other pid
    // or the recorder is not locked anymore
    if (!file_exists($recorder_monitoring_pid)) {
        $logger->log(EventType::RECORDER_TIMEOUT_MONITORING, LogLevel::INFO, "Monitoring stopped. Cause: Monitoring pid file not found", array(basename(__FILE__)));
        die;
    }
    if ($pid != file_get_contents($recorder_monitoring_pid)) {
        $logger->log(EventType::RECORDER_TIMEOUT_MONITORING, LogLevel::INFO, "Monitoring stopped. Cause: Could not read monitoring file", array(basename(__FILE__)));
        die;
    }
    if (!$fct_is_locked()) {
        $logger->log(EventType::RECORDER_TIMEOUT_MONITORING, LogLevel::INFO, "Monitoring stopped. Cause: Recorder is not locked anymore", array(basename(__FILE__)));
        die;
    }

    // Timeout check

    $fct_last_request_get = "session_" . $session_module . "_last_request_get";

    $lastmod_time = $fct_last_request_get();
    $now = time();

    // if record was started at least $threshold_timeout seconds ago
    // and if no request received in the last $timeout seconds 
    // force stop the recorder
    $diff_lastmod = $now - $lastmod_time;
    $diff_init = $now - $init_time;
    
    if ($diff_init > $threshold_timeout && $diff_lastmod > $timeout) {
        $date_format = "Y_M_D_H:s";
        $logger->log(EventType::RECORDER_TIMEOUT_MONITORING, LogLevel::INFO, "Timeout triggered after $diff_lastmod seconds. Init: $init_time / Last request: $lastmod_time.", array(basename(__FILE__)));
        mail($mailto_admins, 'Recording timed out', 'The recording in classroom ' . $classroom 
             . ' was stopped and published in private album because there has been no user activity since ' 
             . ($diff_lastmod) . ' seconds ago. Time: ' . date($date_format,$now) . ' .Last request: ' . date($date_format, $lastmod_time)
             . ' Start time: ' . date($date_format,$init_time) . '');

        controller_recording_force_quit();
    }
    
    //$logger->log(EventType::RECORDER_TIMEOUT_MONITORING, LogLevel::DEBUG, "diffmod: $diff_lastmod. diffinit: $diff_init", array(basename(__FILE__)));

    sleep($sleep_time);
}
