<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Discriminator: the `BladeCP Product Type` ConfigOption decides which
 * Product types are parsed here. Their configuration and operational
 * capabilities live in ProductCapability, the single source of truth.
 */
final class ProductType
{
    public const STACK_CONTAINER = 'stack-container';
    public const EDGE_CLOUD = 'edge-cloud';
    public const DEDICATED = 'dedicated';
    public const VIBE = 'vibe';

    /** Resolve the canonical Customer API product type from WHMCS params. */
    public static function getFromParams(array $params): string
    {
        $raw = $params['configoptions']['BladeCP Product Type']
            ?? $params['configoption1']
            ?? null;
        $value = is_string($raw) ? trim(strtolower($raw)) : '';

        if (!ProductCapability::recognizes($value)) {
            $shown = $value === '' ? 'ausente' : $value;
            throw new \RuntimeException(
                "BladeCP: tipo de produto não suportado ou ausente ('{$shown}')."
            );
        }

        return $value;
    }

    /** Return the handler namespace for an operational product type. */
    public static function namespaceFor(string $type): string
    {
        return ProductCapability::namespaceFor($type);
    }
}
