<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// A versão identifica o contrato público do módulo. O checkout de
// desenvolvimento nunca expõe sua revisão. O exportador materializa somente
// a tag do pacote público aprovado.
$moduleVersion = '0.9.0-rc.10';
$publicReleaseTag = 'v0.9.0-rc.10';
$distributionChannel = str_starts_with($publicReleaseTag, '@')
    ? 'development'
    : (str_contains($moduleVersion, '-') ? 'prerelease' : 'stable');
$moduleRevision = null;
