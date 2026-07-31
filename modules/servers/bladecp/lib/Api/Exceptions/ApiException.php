<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api\Exceptions;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Erro genérico da Customer API (status >= 400 não mapeado). */
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?array $body = null
    ) {
        parent::__construct($message);
    }
}
