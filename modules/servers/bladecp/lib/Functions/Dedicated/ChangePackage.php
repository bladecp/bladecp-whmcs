<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Dedicated plan changes require manual service. */
final class ChangePackage
{
    public static function run(array $params): string
    {
        return 'BladeCP: a troca de plano deste servidor exige atendimento manual.';
    }
}
