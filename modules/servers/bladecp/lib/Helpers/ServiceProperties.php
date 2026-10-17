<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Lê propriedades persistidas do serviço de forma tolerante a contextos
 * distintos do WHMCS.
 */
final class ServiceProperties
{
    public static function save(array $params, array $props): void
    {
        $model = $params['model'] ?? null;
        if ($model && isset($model->serviceProperties)) {
            $model->serviceProperties->save($props);
        }
    }

    public static function get(array $params, string $key, mixed $default = null): mixed
    {
        $model = $params['model'] ?? null;
        if ($model && isset($model->serviceProperties)) {
            $v = $model->serviceProperties->get($key);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        // Usa os campos personalizados quando a propriedade do serviço não
        // estiver disponível. A busca também tolera diferenças de maiúsculas.
        $cf = $params['customfields'] ?? null;
        if (is_array($cf)) {
            if (
                array_key_exists($key, $cf)
                && $cf[$key] !== '' && $cf[$key] !== null
            ) {
                return $cf[$key];
            }
            foreach ($cf as $k => $v) {
                if (
                    is_string($k)
                    && strcasecmp(trim($k), trim($key)) === 0
                    && $v !== '' && $v !== null
                ) {
                    return $v;
                }
            }
        }
        return $default;
    }

    public static function provisionUuid(array $params): ?string
    {
        $uuid = self::get($params, 'BladeCP Provision UUID');
        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** Public Customer API identifier for a Vibe reseller account. */
    public static function vibeAccountId(array $params): ?string
    {
        $accountId = self::get($params, 'BladeCP Vibe Account ID');
        return is_string($accountId) && trim($accountId) !== ''
            ? trim($accountId)
            : null;
    }
}
