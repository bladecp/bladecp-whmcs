<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api\Exceptions;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** 429 — rate limit; caller pode reententar respeitando Retry-After. */
final class RateLimitException extends ApiException
{
}
