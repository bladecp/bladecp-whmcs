<?php

/**
 * Addon lang (english). A UI do addon é renderizada por AdminPage
 * com strings próprias; este arquivo existe pelo contrato WHMCS de
 * addon module (presença de lang/english.php).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$_ADDONLANG = [
    'bladecp_locale' => 'english',
    'name' => 'BladeCP',
    'description' => 'Component bundles + order-form Configurable Options sync. '
        . 'No credentials stored here — the API key lives on the Server record.',
];
