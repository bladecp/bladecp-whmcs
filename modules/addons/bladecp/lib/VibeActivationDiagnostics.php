<?php
declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Internal activation journal. Diagnostic failures must never change the operation. */
final class VibeActivationDiagnostics
{
    private static array $running = [];
    private static array $failedSteps = [];
    private static bool $shutdownRegistered = false;
    private const STAGES = ['start', 'intent', 'identity', 'exchange', 'finalize', 'order_accept', 'sso'];

    public static function context(int $serviceId, string $trace): array
    {
        $context = ['service_id' => $serviceId, 'trace' => VibeFreeTrace::id($trace)];
        try {
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            $context += ['order_id' => (int) ($service->orderid ?? 0), 'client_id' => (int) ($service->userid ?? 0)];
        } catch (\Throwable) { /* Logging cannot become a prerequisite for access. */ }
        return $context;
    }

    public static function run(string $stage, array $context, callable $operation, ?Client $client = null): mixed
    {
        if (!in_array($stage, self::STAGES, true)) { return $operation(); }
        $key = ($context['trace'] ?? '') . ':' . $stage;
        $started = hrtime(true);
        self::$running[$key] = [$stage, $context, $started];
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                $fatal = error_get_last();
                foreach (self::$running as [$stage, $context, $started]) {
                    self::emit($stage, 'interrupted', $context + [
                        'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
                        'reason' => in_array($fatal['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) ? 'php_fatal' : 'request_ended',
                    ]);
                }
            });
        }
        self::emit($stage, 'started', $context);
        try {
            $result = $operation();
            $failed = is_array($result) && (($result['ok'] ?? true) === false
                || (isset($result['result']) && $result['result'] !== 'success')
                || (isset($result['status']) && !in_array($result['status'], ['ready', 'link_required', 'identity_proved', 'identity_proof_required'], true)));
            self::emit($stage, $failed ? 'failed' : 'completed', $context + [
                'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
                'reason' => $failed ? 'operation_rejected' : 'ok',
            ] + ($client?->lastRequestDiagnostics() ?? []));
            return $result;
        } catch (\Throwable $error) {
            $transport = $error instanceof ApiException ? ($client?->lastRequestDiagnostics() ?? []) : [];
            $reason = self::failureReason($error, $transport);
            self::emit($stage, 'failed', $context + [
                'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
                'reason' => $reason,
                'exception_type' => $error instanceof \TypeError ? 'TypeError' : ($error instanceof \PDOException ? 'PDOException' : ($error instanceof ApiException ? 'ApiException' : 'Throwable')),
                'source_file' => basename($error->getFile()),
                'source_line' => $error->getLine(),
            ] + $transport + ($error instanceof ApiException ? ['http_status' => $error->httpStatus] : []));
            throw $error;
        } finally {
            unset(self::$running[$key]);
        }
    }

    public static function failureReason(\Throwable $error, array $transport): string
    {
        if (!$error instanceof ApiException) { return 'local_exception'; }
        return match ((int) ($transport['curl_errno'] ?? 0)) {
            28 => 'timeout', 5, 6 => 'dns', 7 => 'connection',
            18 => 'incomplete_response', 35, 51, 58, 60 => 'tls',
            52 => 'empty_response', 55, 56 => 'connection_interrupted',
            0 => $error->httpStatus > 0 ? 'http_rejected' : 'transport',
            default => 'transport',
        };
    }

    /** Strict allowlist excludes email, tokens, arbitrary messages, URLs and bodies. */
    public static function payload(string $stage, string $outcome, array $fields): ?array
    {
        if (!in_array($stage, self::STAGES, true) || !in_array($outcome, ['started', 'completed', 'failed', 'interrupted'], true)) { return null; }
        $safe = ['stage' => $stage, 'outcome' => $outcome];
        foreach (['service_id', 'order_id', 'client_id', 'duration_ms', 'http_duration_ms', 'http_status', 'curl_errno', 'source_line'] as $field) {
            if (isset($fields[$field]) && is_int($fields[$field]) && $fields[$field] >= 0) { $safe[$field] = $fields[$field]; }
        }
        if (preg_match('/^[a-f0-9]{16}$/D', (string) ($fields['trace'] ?? ''))) { $safe['trace'] = $fields['trace']; }
        if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', (string) ($fields['correlation_id'] ?? ''))) { $safe['correlation_id'] = $fields['correlation_id']; }
        if (in_array($fields['reason'] ?? '', ['ok', 'recovered', 'operation_rejected', 'php_fatal', 'request_ended', 'local_exception', 'timeout', 'dns', 'connection', 'incomplete_response', 'tls', 'empty_response', 'connection_interrupted', 'http_rejected', 'transport'], true)) { $safe['reason'] = $fields['reason']; }
        if (in_array($fields['exception_type'] ?? '', ['TypeError', 'PDOException', 'ApiException', 'Throwable'], true)) { $safe['exception_type'] = $fields['exception_type']; }
        if (preg_match('/^[A-Za-z0-9_-]+\.php$/D', (string) ($fields['source_file'] ?? ''))) { $safe['source_file'] = $fields['source_file']; }
        return $safe;
    }

    private static function emit(string $stage, string $outcome, array $fields): void
    {
        try {
            $key = ($fields['trace'] ?? '') . ':' . $stage;
            if ($outcome === 'completed' && isset(self::$failedSteps[$key])) {
                $fields['reason'] = 'recovered';
                unset(self::$failedSteps[$key]);
            } elseif ($outcome === 'failed') { self::$failedSteps[$key] = true; }
            $safe = self::payload($stage, $outcome, $fields);
            if ($safe === null) { return; }
            try { Logger::logCall('VibeActivation/' . $stage, [], $safe); } catch (\Throwable) { /* Other sinks remain available. */ }
            if (PHP_SAPI !== 'cli') {
                error_log('[bladecp:vibe-activation] ' . json_encode($safe, JSON_UNESCAPED_SLASHES));
            }
            if ((in_array($outcome, ['failed', 'interrupted'], true) || ($safe['reason'] ?? '') === 'recovered') && function_exists('logActivity')) {
                logActivity('BladeCP Vibe: pedido ' . ($safe['order_id'] ?? 0)
                    . ', serviço ' . ($safe['service_id'] ?? 0) . ', etapa ' . $stage
                    . ', resultado ' . $outcome . ', motivo ' . ($safe['reason'] ?? 'unknown')
                    . ', HTTP ' . ($safe['http_status'] ?? 0) . ', cURL ' . ($safe['curl_errno'] ?? 0)
                    . ', duração ' . ($safe['duration_ms'] ?? 0) . 'ms, trace ' . ($safe['trace'] ?? '')
                    . ', API ' . ($safe['correlation_id'] ?? 'indisponível'), (int) ($safe['client_id'] ?? 0));
            }
        } catch (\Throwable) { /* Preserve the original operation and exception. */ }
    }
}
