<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Canonical product capability policy for the WHMCS module.
 *
 * A product type can be recognized for compatibility without being
 * configurable for new products. Handlers are deliberately listed per
 * operation so routing never falls back to a neighbouring product type.
 */
final class ProductCapability
{
    /** @var array<string, array{namespace: string|null, configurable: bool, legacy: bool, operations: list<string>}> */
    private const TYPES = [
        ProductType::STACK_CONTAINER => [
            'namespace' => 'Stack',
            'configurable' => true,
            'legacy' => false,
            'operations' => [
                'CreateAccount',
                'SuspendAccount',
                'UnsuspendAccount',
                'TerminateAccount',
                'ChangePackage',
                'CancelAtCycleEnd',
                'CancelNow',
                'ChangePassword',
                'ServiceSingleSignOn',
                'ClientArea',
            ],
        ],
        ProductType::EDGE_CLOUD => [
            'namespace' => 'Edge',
            'configurable' => true,
            'legacy' => false,
            'operations' => [
                'CreateAccount',
                'SuspendAccount',
                'UnsuspendAccount',
                'TerminateAccount',
                'ChangePackage',
                'CancelAtCycleEnd',
                'CancelNow',
                'ChangePassword',
                'ServiceSingleSignOn',
                'ClientArea',
            ],
        ],
        ProductType::DEDICATED => [
            'namespace' => 'Dedicated',
            'configurable' => true,
            'legacy' => false,
            'operations' => [
                'CreateAccount',
                'SuspendAccount',
                'UnsuspendAccount',
                'TerminateAccount',
                'ChangePackage',
                'CancelAtCycleEnd',
                'CancelNow',
                'ChangePassword',
                'ServiceSingleSignOn',
                'ClientArea',
            ],
        ],
        ProductType::VIBE => [
            // Vibe has its own Customer API account resource. Keep every
            // operation explicit so it can never fall back to provisioning.
            'namespace' => 'Vibe',
            'configurable' => true,
            'legacy' => false,
            'operations' => [
                'CreateAccount',
                'SuspendAccount',
                'UnsuspendAccount',
                'TerminateAccount',
                'ChangePackage',
                'CancelAtCycleEnd',
                'CancelNow',
                'ServiceSingleSignOn',
                'ClientArea',
            ],
        ],
    ];

    /** @return list<string> */
    public static function recognizedTypes(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return list<string> */
    public static function configurableTypes(): array
    {
        return array_values(array_filter(
            self::recognizedTypes(),
            static fn (string $type): bool => self::TYPES[$type]['configurable']
        ));
    }

    public static function recognizes(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function isLegacy(string $type): bool
    {
        self::requireRecognized($type);

        return self::TYPES[$type]['legacy'];
    }

    public static function namespaceFor(string $type): string
    {
        self::requireRecognized($type);
        $namespace = self::TYPES[$type]['namespace'];
        if ($namespace === null) {
            throw new \RuntimeException(
                "BladeCP: tipo de produto legado '{$type}' não possui handlers implementados."
            );
        }

        return $namespace;
    }

    public static function handlerClass(string $type, string $operation): string
    {
        self::requireRecognized($type);
        if (self::TYPES[$type]['legacy']) {
            throw new \RuntimeException(
                "BladeCP: tipo de produto legado '{$type}' não pode executar '{$operation}'."
            );
        }
        if (!in_array($operation, self::TYPES[$type]['operations'], true)) {
            throw new \RuntimeException(
                "BladeCP: operação '{$operation}' não é suportada para o tipo '{$type}'."
            );
        }

        return 'WHMCS\\Module\\Server\\Bladecp\\Functions\\'
            . self::namespaceFor($type)
            . "\\{$operation}";
    }

    private static function requireRecognized(string $type): void
    {
        if (!self::recognizes($type)) {
            throw new \RuntimeException("BladeCP: tipo de produto não suportado ('{$type}').");
        }
    }
}
