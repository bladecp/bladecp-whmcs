<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\ClientArea as StackClientArea;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates client-area rendering to the shared handler. */
final class ClientArea
{
    public static function run(array $params): array
    {
        return StackClientArea::run($params);
    }
}
