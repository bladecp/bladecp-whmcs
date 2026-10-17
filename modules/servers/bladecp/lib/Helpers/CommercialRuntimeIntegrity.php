<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Detects a partially copied commercial module cohort before a customer flow. */
final class CommercialRuntimeIntegrity
{
    public static function ready(): bool
    {
        foreach ([
            CommercialActionResume::class,
            CommercialUpgradeResume::class,
            VibePlanReference::class,
            VibeServiceFields::class,
            \WHMCS\Module\Server\Bladecp\Ui\PartnerBrand::class,
        ] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }
        return true;
    }
}
