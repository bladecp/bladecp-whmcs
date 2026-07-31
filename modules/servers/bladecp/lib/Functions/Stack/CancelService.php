<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Manages immediate and end-of-cycle service cancellations. */
final class CancelService
{
    /** Fallback reason accepted by the cancellation endpoint. */
    public const DEFAULT_REASON = 'Cancelamento solicitado via WHMCS billing.';

    /** Categoria padrão aceita pelo endpoint de cancelamento. */
    public const DEFAULT_CATEGORY = 'other';

    /** @return array{when:string,reasonCategory:string,reasonText:string} */
    public static function buildBody(
        string $when,
        string $category,
        string $text
    ): array {
        $when = in_array($when, ['now', 'cycle_end'], true) ? $when : 'cycle_end';
        $category = trim($category) !== '' ? trim($category) : self::DEFAULT_CATEGORY;
        $text = trim($text);
        if (mb_strlen($text) < 20) {
            $text = self::DEFAULT_REASON;
        }
        return [
            'when' => $when,
            'reasonCategory' => $category,
            'reasonText' => $text,
        ];
    }

    /** Cancels a provision and reports an already-pending cancellation clearly. */
    public static function cancel(array $params, string $when): string
    {
        $uuid = ServiceProperties::provisionUuid($params);
        if ($uuid === null) {
            return 'BladeCP: provision UUID ausente.';
        }
        $client = Client::fromParams($params);
        $body = self::buildBody($when, self::DEFAULT_CATEGORY, self::DEFAULT_REASON);
        $key = 'whmcs-' . ($params['serviceid'] ?? '0')
            . '-cancel-' . $body['when'];

        try {
            $response = $client->post(
                "/provisions/{$uuid}/cancel",
                $body,
                ['Idempotency-Key' => $key]
            );
        } catch (ApiException $e) {
            if ($e->httpStatus === 409) {
                Logger::logCall(
                    'CancelService',
                    ['uuid' => $uuid, 'when' => $body['when']],
                    ['conflict' => $e->getMessage()]
                );
                return 'BladeCP: já existe um cancelamento pendente para '
                    . 'este serviço'
                    . ($e->getMessage() !== '' ? ' (' . $e->getMessage() . ')' : '')
                    . '.';
            }
            throw $e;
        }

        Logger::logCall(
            'CancelService',
            ['uuid' => $uuid, 'when' => $body['when']],
            $response
        );
        return 'success';
    }
}
