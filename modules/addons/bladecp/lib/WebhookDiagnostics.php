<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Addon\Bladecp\Admin\I18n;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Diagnóstico read-only das entregas outbound do endpoint deste WHMCS.
 *
 * A Customer API nunca devolve payload ou segredo nesta superfície. Ainda
 * assim, normalizamos e redigimos `lastError` antes de entregar dados à UI,
 * pois mensagens de upstream não são uma fronteira de confiança.
 */
final class WebhookDiagnostics
{
    public const PAGE_SIZE = 25;

    public const STATUSES = [
        'pending',
        'delivering',
        'succeeded',
        'failed',
        'dead',
    ];

    /**
     * @return array{items:list<array<string,mixed>>,page:int,perPage:int,total:int}|null
     */
    public static function deliveries(
        string $endpointUuid,
        string $status = '',
        int $page = 1,
        int $perPage = self::PAGE_SIZE
    ): ?array {
        $endpointUuid = trim($endpointUuid);
        if ($endpointUuid === '') {
            return null;
        }
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $status = in_array($status, self::STATUSES, true) ? $status : '';

        $query = [
            'endpoint_id=' . rawurlencode($endpointUuid),
            'page=' . $page,
            'limit=' . $perPage,
        ];
        if ($status !== '') {
            $query[] = 'status=' . rawurlencode($status);
        }

        try {
            $client = self::client();
            if ($client === null) {
                return null;
            }
            return self::parseDeliveries(
                $client->getEnvelope(
                    '/webhook-deliveries?' . implode('&', $query)
                ),
                $page,
                $perPage
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reenvia uma entrega sem expor o conteúdo recebido. A API cria uma nova entrega,
     * preservando `eventId` para manter o contrato de deduplicação.
     *
     * @return array{ok:bool,error:string,delivery:?array}
     */
    public static function replay(string $deliveryUuid): array
    {
        $deliveryUuid = trim($deliveryUuid);
        if ($deliveryUuid === '') {
            return ['ok' => false, 'error' => I18n::t('admin.webhooks.replay_invalid'), 'delivery' => null];
        }
        try {
            $client = self::client();
            if ($client === null) {
                return [
                    'ok' => false,
                    'error' => I18n::t('viewmodels.webhookConfig.errors.serverNotConfigured'),
                    'delivery' => null,
                ];
            }
            $response = $client->post(
                '/webhook-deliveries/' . rawurlencode($deliveryUuid) . '/replay',
                []
            );
            $delivery = self::parseReplay($response);
            if ($delivery === null) {
                return [
                    'ok' => false,
                    'error' => I18n::t('admin.webhooks.replay_unconfirmed'),
                    'delivery' => null,
                ];
            }
            return ['ok' => true, 'error' => '', 'delivery' => $delivery];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Falha ao reenviar: ' . self::redact($e->getMessage()),
                'delivery' => null,
            ];
        }
    }

    /**
     * @return array{items:list<array<string,mixed>>,page:int,perPage:int,total:int}
     */
    public static function parseDeliveries(
        mixed $response,
        int $page = 1,
        int $perPage = self::PAGE_SIZE
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        if (!is_array($response)) {
            return ['items' => [], 'page' => $page, 'perPage' => $perPage, 'total' => 0];
        }

        $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];
        $data = $response['data'] ?? $response;
        if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
            $meta = array_merge($meta, is_array($data['meta'] ?? null) ? $data['meta'] : []);
            $data = $data['items'];
        }
        $rows = is_array($data) && array_is_list($data) ? $data : [];
        $items = [];
        foreach ($rows as $row) {
            $parsed = self::parseDelivery($row);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }

        $resolvedPage = self::number($meta, ['page'], $page);
        $resolvedPerPage = self::number(
            $meta,
            ['limit', 'perPage', 'per_page'],
            $perPage
        );
        $total = self::number($meta, ['total'], count($items));
        return [
            'items' => $items,
            'page' => max(1, $resolvedPage),
            'perPage' => max(1, $resolvedPerPage),
            'total' => max(0, $total),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function parseDelivery(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $uuid = trim((string) ($row['uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }
        $status = trim((string) ($row['status'] ?? ''));
        return [
            'uuid' => $uuid,
            'eventId' => trim((string) ($row['eventId'] ?? '')),
            'eventType' => trim((string) ($row['eventType'] ?? '')),
            'status' => in_array($status, self::STATUSES, true) ? $status : 'unknown',
            'attempt' => max(0, (int) ($row['attempt'] ?? 0)),
            'responseCode' => is_numeric($row['responseCode'] ?? null)
                ? (int) $row['responseCode'] : null,
            'lastError' => self::redact((string) ($row['lastError'] ?? '')),
            'nextAttemptAt' => trim((string) ($row['nextAttemptAt'] ?? '')),
            'createdAt' => trim((string) ($row['createdAt'] ?? '')),
            'updatedAt' => trim((string) ($row['updatedAt'] ?? '')),
        ];
    }

    /** @return array<string,string>|null */
    public static function parseReplay(mixed $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $uuid = trim((string) ($data['uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }
        return [
            'uuid' => $uuid,
            'eventId' => trim((string) ($data['eventId'] ?? '')),
            'status' => trim((string) ($data['status'] ?? '')),
            'replayOf' => trim((string) ($data['replayOf'] ?? '')),
        ];
    }

    /**
     * Redige segredos conhecidos e headers de autorização em mensagens.
     */
    public static function redact(string $value): string
    {
        $value = class_exists(Logger::class)
            ? Logger::redact($value)
            : $value;
        $value = trim($value);
        return function_exists('mb_substr')
            ? mb_substr($value, 0, 500)
            : substr($value, 0, 500);
    }

    private static function number(array $source, array $keys, int $fallback): int
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                return (int) $source[$key];
            }
        }
        return $fallback;
    }

    private static function client(): ?\WHMCS\Module\Server\Bladecp\Api\Client
    {
        if (!class_exists(ServerConfig::class)) {
            return null;
        }
        try {
            return ServerConfig::client();
        } catch (\Throwable) {
            return null;
        }
    }
}
