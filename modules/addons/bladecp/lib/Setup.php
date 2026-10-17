<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\AuthException;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Addon\Bladecp\Admin\I18n;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Assistente de configuração da conexão BladeCP.
 */
final class Setup
{
    /** Hosts da API por ambiente. */
    public const HOST_SANDBOX = 'api.bladecp.cloud';
    public const HOST_PRODUCTION = 'api.bladecp.com';

    /** @var array{state:string,ok:bool,who:?string,error:?string}|null */
    private static ?array $currentConnectionHealth = null;

    /**
     * Detecta o ambiente pelo prefixo da chave. Puro/determinístico.
     *
     * @return array{env:string,host:string,label:string}|null  null se
     *   o prefixo não for reconhecido.
     */
    public static function detectEnv(string $key): ?array
    {
        if (preg_match('/^(?:bcp|vh)_(live|sand)_/', trim($key), $m) !== 1) {
            return null;
        }

        return $m[1] === 'sand'
            ? ['env' => 'sandbox', 'host' => self::HOST_SANDBOX, 'label' => I18n::t('setup.environment.sandbox')]
            : ['env' => 'production', 'host' => self::HOST_PRODUCTION, 'label' => I18n::t('setup.environment.production')];
    }

    /**
     * Checagens de ambiente (read-only). Respondem "meu upload deu
     * certo e o servidor aguenta o módulo?" antes de pedir a chave.
     *
     * @return list<array{label:string,ok:bool,detail:string}>
     */
    public static function preflight(): array
    {
        $curl = function_exists('curl_init');
        $json = function_exists('json_decode');
        $ssl = extension_loaded('openssl');

        return [
            [
                'label' => 'PHP ≥ 8.1',
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'detail' => PHP_VERSION,
            ],
            [
                'label' => 'Extensão cURL',
                'ok' => $curl,
                'detail' => $curl ? 'disponível' : 'ausente',
            ],
            [
                'label' => 'Extensão JSON',
                'ok' => $json,
                'detail' => $json ? 'disponível' : 'ausente',
            ],
            [
                'label' => 'SSL (OpenSSL)',
                'ok' => $ssl,
                'detail' => $ssl ? 'disponível' : 'ausente',
            ],
        ];
    }

    /** Server record do contexto administrativo ou null. Fail-soft. */
    public static function existingServer(): ?object
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $context = AdminServerContext::resolve();
            $serverId = (int) ($context['serverId'] ?? 0);
            if (($context['state'] ?? '') !== 'configured' || $serverId <= 0) {
                return null;
            }
            return \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $serverId)
                ->where('type', 'bladecp')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Valida a chave contra a API (GET /me) no host derivado. Não grava
     * nada. Fail-soft.
     *
     * @return array{state:string,ok:bool,who:?string,error:?string}
     */
    public static function validate(string $key, string $host): array
    {
        if (!class_exists(Client::class)) {
            return [
                'state' => 'unavailable',
                'ok' => false,
                'who' => null,
                'error' => I18n::t('admin.setup.server_module_unavailable'),
            ];
        }
        try {
            $me = Client::fromParams([
                'serveraccesshash' => trim($key),
                'serverhostname' => $host,
                'serversecure' => 'on',
                'serverport' => '',
            ])->get('/me');

            return [
                'state' => 'valid',
                'ok' => true,
                'who' => self::whoFrom($me),
                'error' => null,
            ];
        } catch (AuthException $e) {
            return [
                'state' => 'rejected',
                'ok' => false,
                'who' => null,
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'state' => 'unavailable',
                'ok' => false,
                'who' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Classifica a saúde da conexão sem confundir configuração persistida
     * com autorização válida. O segundo argumento permite testar a regra sem
     * rede; quando omitido, a API é consultada.
     *
     * @param array<string,mixed>|null $params
     * @param array{state?:string,ok:bool,who:?string,error:?string}|null $validation
     * @return array{state:string,ok:bool,who:?string,error:?string}
     */
    public static function connectionHealth(
        ?array $params = null,
        ?array $validation = null
    ): array {
        if ($params === null) {
            return [
                'state' => 'unconfigured',
                'ok' => false,
                'who' => null,
                'error' => null,
            ];
        }

        $key = trim((string) ($params['serveraccesshash'] ?? ''));
        if ($key === '') {
            return [
                'state' => 'rejected',
                'ok' => false,
                'who' => null,
                'error' => 'BladeCP: configure o Server Access Hash.',
            ];
        }

        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            $host = self::HOST_PRODUCTION;
        }
        $result = $validation ?? self::validate($key, $host);
        $state = (string) ($result['state'] ?? ($result['ok'] ? 'valid' : 'unavailable'));
        if (!in_array($state, ['valid', 'rejected', 'unavailable'], true)) {
            $state = $result['ok'] ? 'valid' : 'unavailable';
        }

        return [
            'state' => $state,
            'ok' => $state === 'valid' && (bool) $result['ok'],
            'who' => is_string($result['who'] ?? null) ? $result['who'] : null,
            'error' => is_string($result['error'] ?? null) ? $result['error'] : null,
        ];
    }

    /**
     * Saúde da conexão do servidor administrativo atual, consultada no máximo
     * uma vez durante a mesma requisição PHP.
     *
     * @return array{state:string,ok:bool,who:?string,error:?string}
     */
    public static function currentConnectionHealth(bool $refresh = false): array
    {
        if (!$refresh && self::$currentConnectionHealth !== null) {
            return self::$currentConnectionHealth;
        }

        return self::$currentConnectionHealth = self::connectionHealth(
            ServerConfig::params()
        );
    }

    /**
     * Cria (ou atualiza, se já houver) o Server record bladecp com a
     * chave encriptada e o host derivado. Idempotente. Fail-soft.
     *
     * A proteção da credencial é fail-closed: se o WHMCS não puder
     * encriptá-la, nenhum Server record é criado ou atualizado. Isso
     * preserva a configuração anterior e impede persistência em texto
     * simples por este fluxo.
     *
     * @param array{env:string,host:string,label:string} $env
     * @return array{ok:bool,mode:string,error:?string}
     */
    public static function saveServer(string $key, array $env): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return self::saveFailure();
        }
        try {
            $context = AdminServerContext::resolve();
            if (in_array((string) ($context['state'] ?? ''), ['ambiguous', 'invalid'], true)) {
                return self::saveFailure(I18n::t('admin.setup.select_server_first'));
            }
            $hash = self::encryptStrict(trim($key));
            if ($hash === null) {
                return self::saveFailure();
            }
            // Filtra pelas colunas que existem (tolera variação de
            // schema entre versões do WHMCS).
            $cols = array_flip(
                \WHMCS\Database\Capsule::schema()->getColumnListing('tblservers')
            );
            $existing = self::existingServer();

            return \WHMCS\Database\Capsule::connection()->transaction(
                static function () use ($cols, $env, $existing, $hash): array {
                    if ($existing !== null) {
                        \WHMCS\Database\Capsule::table('tblservers')
                            ->where('id', $existing->id)
                            ->update(array_intersect_key([
                                'hostname' => $env['host'],
                                'secure' => 'on',
                                'port' => '',
                                'accesshash' => $hash,
                                'active' => 1,
                                'disabled' => 0,
                            ], $cols));
                        if (!AdminServerContext::setSelectedId((int) $existing->id)) {
                            throw new \RuntimeException('admin-server-selection-failed');
                        }

                        return ['ok' => true, 'mode' => 'updated', 'error' => null];
                    }

                    $serverId = (int) \WHMCS\Database\Capsule::table('tblservers')
                        ->insertGetId(array_intersect_key([
                            'name' => 'BladeCP',
                            'hostname' => $env['host'],
                            'type' => 'bladecp',
                            'secure' => 'on',
                            'port' => '',
                            'username' => '',
                            'password' => '',
                            'accesshash' => $hash,
                            'ipaddress' => '',
                            'active' => 1,
                            'disabled' => 0,
                            'maxallowedservices' => '0',
                            'statusaddress' => '',
                        ], $cols));
                    if ($serverId <= 0 || !AdminServerContext::setSelectedId($serverId)) {
                        throw new \RuntimeException('admin-server-selection-failed');
                    }

                    return ['ok' => true, 'mode' => 'created', 'error' => null];
                }
            );
        } catch (\Throwable) {
            return self::saveFailure();
        }
    }

    /**
     * Identifica, sem expor o valor, um Access Hash legado ainda em
     * plaintext. A leitura continua compatível; este sinal permite que
     * telas futuras ofereçam uma migração explícita e segura.
     */
    public static function hasLegacyPlaintextAccessHash(?object $server): bool
    {
        if ($server === null || !isset($server->accesshash) || !is_string($server->accesshash)) {
            return false;
        }

        return preg_match('/^(?:bcp|vh)_(?:live|sand)_/', trim($server->accesshash)) === 1;
    }

    /**
     * Extrai um nome amigável do /me, defensivo ao shape (raiz ou
     * sob `data`). Puro.
     */
    public static function whoFrom(array $me): ?string
    {
        $src = (isset($me['data']) && is_array($me['data'])) ? $me['data'] + $me : $me;
        foreach (['name', 'partner', 'company', 'display_name', 'email'] as $k) {
            if (!empty($src[$k]) && is_string($src[$k])) {
                return $src[$k];
            }
        }

        return null;
    }

    /**
     * Encripta a chave com o esquema do próprio WHMCS (mesmo usado no
     * Access Hash). Não há fallback para plaintext: uma gravação nova
     * precisa falhar caso a criptografia não esteja disponível.
     */
    private static function encryptStrict(string $key): ?string
    {
        if ($key === '' || !function_exists('localAPI')) {
            return null;
        }

        try {
            $r = localAPI('EncryptPassword', ['password2' => $key]);
            $enc = trim((string) ($r['password'] ?? ''));
            if (($r['result'] ?? '') !== 'success' || $enc === '' || hash_equals($key, $enc)) {
                return null;
            }

            return $enc;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{ok:false,mode:string,error:string} */
    private static function saveFailure(?string $message = null): array
    {
        $message ??= I18n::t('admin.setup.credential_save_failed');
        return [
            'ok' => false,
            'mode' => '',
            'error' => $message,
        ];
    }
}
