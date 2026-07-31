<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api\Exceptions;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** 401/403 — API key inválida/revogada ou sem permissão. */
final class AuthException extends ApiException
{
}
