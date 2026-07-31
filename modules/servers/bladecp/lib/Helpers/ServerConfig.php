<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Resolve a credencial configurada no servidor WHMCS para as telas que não
 * recebem os parâmetros do serviço. Falhas de leitura ou de descriptografia
 * retornam `null` para manter o carregamento da tela seguro.
 */
final class ServerConfig
{
    /**
     * Server `bladecp` ativo → shape de `$params` de
     * `Client::fromParams`. `null` se não dá pra resolver.
     *
     * @return array<string,string>|null
     */
    public static function params(): ?array
    {
        if (
            !function_exists('localAPI')
            || !class_exists(\WHMCS\Database\Capsule::class)
        ) {
            return null;
        }
        $context = AdminServerContext::resolve();
        if ($context['state'] !== 'configured' || $context['serverId'] === null) {
            return null;
        }

        return self::paramsForServerId($context['serverId']);
    }

    /**
     * Resolve um Server record específico para callers administrativos.
     * Provisionamento não usa este método: continua em `Client::fromParams`
     * com os `$params` entregues pelo WHMCS ao serviço.
     *
     * @return array<string,string>|null
     */
    public static function paramsForServerId(int $serverId): ?array
    {
        if (
            $serverId <= 0
            || !function_exists('localAPI')
            || !class_exists(\WHMCS\Database\Capsule::class)
        ) {
            return null;
        }
        try {
            $row = \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $serverId)
                ->where('type', 'bladecp')
                ->where('disabled', 0)
                ->first();
            if ($row === null) {
                return null;
            }
            $raw = trim((string) ($row->accesshash ?? ''));
            if ($raw === '') {
                return null;
            }
            // O WHMCS pode guardar o accesshash em PLAINTEXT ou
            // ENCRIPTADO (varia por versão/config). Se já casa o
            // formato de API key BladeCP, é plaintext → usa direto;
            // senão, decripta via WHMCS. Determinístico, cobre os 2.
            if (preg_match('/^(bcp|vh)_(live|sand)_/', $raw) === 1) {
                $key = $raw;
            } else {
                $dec = localAPI('DecryptPassword', ['password2' => $raw]);
                $key = trim((string) ($dec['password'] ?? ''));
            }
            if ($key === '') {
                return null;
            }
            return [
                'serveraccesshash' => $key,
                'serverhostname' => (string) ($row->hostname ?? ''),
                'serversecure' => !empty($row->secure) ? 'on' : '',
                'serverport' => (string) ($row->port ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Client resolvido do Server record, ou `null` (fail-soft). */
    public static function client(): ?Client
    {
        $params = self::params();

        return $params === null ? null : Client::fromParams($params);
    }

    /** Client do Server record administrativo explicitamente selecionado. */
    public static function clientForServerId(int $serverId): ?Client
    {
        $params = self::paramsForServerId($serverId);

        return $params === null ? null : Client::fromParams($params);
    }
}
