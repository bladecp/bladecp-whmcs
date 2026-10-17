<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Wrapper de logModuleCall com auto-scrub do API key / Authorization
 * Usa somente o log de módulo do WHMCS, sem arquivo de log próprio.
 */
final class Logger
{
    private const REDACT = '***REDACTED***';
    private const ERROR_LOG_INTERVAL_SECONDS = 60;

    /** @var array<string,int> */
    private static array $lastErrorLogAt = [];

    public static function logCall(
        string $action,
        mixed $request,
        mixed $response
    ): void {
        if (!function_exists('logModuleCall')) {
            return; // fora do runtime WHMCS (ex.: análise estática)
        }
        if (!self::shouldLog($action, $response)) {
            return;
        }
        logModuleCall(
            'bladecp',
            $action,
            self::scrub($request),
            self::scrub($response),
            '',
            [
                'serveraccesshash', 'access_hash', 'Authorization', 'api_key',
                'token', 'secret', 'webhook_secret', 'new_password', 'password',
                'assertion',
            ]
        );
    }

    /** Redação reutilizável para mensagens que precisam chegar à UI. */
    public static function redact(string $value): string
    {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return json_encode(self::scrub($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: self::REDACT;
        }
        $value = preg_replace('/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/s', self::REDACT, $value) ?? '';
        $value = preg_replace('/([?&](?:code|state|ticket|codeVerifier|returnState|assertion)=)[^&#\s]*/i', '$1' . self::REDACT, $value) ?? '';
        $value = preg_replace('~(/provision-link/)[^/?#\s"<>]+~i', '$1' . self::REDACT, $value) ?? '';
        $value = preg_replace(
            '/\b(?:whsec_|(?:bcp|vh)_(?:live|sand)_)[A-Za-z0-9._-]+/i',
            self::REDACT,
            $value
        ) ?? '';
        $value = preg_replace(
            '/(Authorization\s*[:=]\s*(?:Bearer|Basic)\s+)[^\s,;]+/i',
            '$1' . self::REDACT,
            $value
        ) ?? '';
        $value = preg_replace(
            '/\b(secret|token|access[_-]?hash|api[_-]?key|password)\b\s*[:=]\s*([^\s,;]+)/i',
            '$1=' . self::REDACT,
            $value
        ) ?? '';
        return $value;
    }

    /** Remove segredos e dados pessoais desnecessários antes do log. */
    private static function scrub(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::redact($data);
        }
        if ($data instanceof \Throwable) {
            return ['exception' => self::redact($data->getMessage())];
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $key = is_string($k) ? strtolower($k) : $k;
                $sensitive = is_string($key) && (
                    in_array($key, ['new_password', 'password', 'owner'], true)
                    || in_array($key, ['code', 'state', 'ticket', 'grant', 'codeverifier', 'returnstate', 'sessionfingerprint', 'privatekey', 'encryptedprivatekey'], true)
                    || str_contains($key, 'secret')
                    || str_contains($key, 'assertion')
                    || str_contains($key, 'token')
                    || str_contains($key, 'authorization')
                    || str_contains($key, 'accesshash')
                    || str_contains($key, 'access_hash')
                    || str_contains($key, 'api_key')
                );
                $out[$k] = $sensitive ? self::REDACT : self::scrub($v);
            }
            return $out;
        }
        return $data;
    }

    /** Evita repetição de falhas idênticas em loops de cron. */
    private static function shouldLog(string $action, mixed $response): bool
    {
        if (!is_array($response) || !isset($response['error'])) {
            return true;
        }
        $key = $action . ':' . hash('sha256', self::redact((string) $response['error']));
        $now = time();
        $last = self::$lastErrorLogAt[$key] ?? 0;
        if ($last > 0 && ($now - $last) < self::ERROR_LOG_INTERVAL_SECONDS) {
            return false;
        }
        self::$lastErrorLogAt[$key] = $now;
        return true;
    }
}
