<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Resolves the explicitly configured commercial tier for a Vibe service. */
final class VibePlanTier
{
    public const FREE = 'free';
    public const PRO = 'pro';

    /** @param array<string,mixed> $params */
    public static function fromParams(array $params): ?string
    {
        $options = is_array($params['configoptions'] ?? null)
            ? $params['configoptions']
            : [];
        $value = $options['BladeCP Vibe Plan Tier']
            ?? $params['configoption4']
            ?? null;

        return self::normalize($value);
    }

    public static function normalize(mixed $value): ?string
    {
        $tier = strtolower(trim((string) $value));
        return in_array($tier, [self::FREE, self::PRO], true) ? $tier : null;
    }

    public static function isForbiddenDowngrade(?string $current, ?string $destination): bool
    {
        return $current === self::PRO && $destination === self::FREE;
    }
}
