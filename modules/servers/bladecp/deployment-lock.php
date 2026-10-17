<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Managed installations keep this file outside the replaceable module tree.
// Hold the shared lock for the request, including deferred class autoloads.
$bladecpLockPath = dirname(__DIR__) . '/.bladecp-deploy.lock';
if (is_file($bladecpLockPath) && !isset($GLOBALS['bladecp_deployment_lock'])) {
    $bladecpLock = fopen($bladecpLockPath, 'r');
    $bladecpDeadline = microtime(true) + 30;
    do {
        if ($bladecpLock !== false && flock($bladecpLock, LOCK_SH | LOCK_NB)) {
            $GLOBALS['bladecp_deployment_lock'] = $bladecpLock;
            break;
        }
        usleep(50000);
    } while (microtime(true) < $bladecpDeadline);
    if (!isset($GLOBALS['bladecp_deployment_lock'])) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 30');
        }
        exit('Module update in progress. Please retry shortly.');
    }
}
unset($bladecpLockPath, $bladecpLock, $bladecpDeadline);
