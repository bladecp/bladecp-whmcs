<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Shared;

use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Lists provisioned services for WHMCS server synchronization. */
final class ListAccounts
{
    public static function run(array $params): array
    {
        $client = Client::fromParams($params);
        if ($client->configError() !== null) {
            return ['success' => false, 'accounts' => []];
        }

        try {
            $response = self::allProvisions($client);
        } catch (\Throwable) {
            return ['success' => false, 'accounts' => []];
        }

        return [
            'success' => true,
            'accounts' => self::toAccounts(is_array($response) ? $response : []),
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<int,array{domain:string,status:string}>
     */
    public static function toAccounts(array $response): array
    {
        // Accept both plain lists and paginated responses.
        $list = $response['items'] ?? $response['data'] ?? $response;
        if (!is_array($list)) {
            return [];
        }

        $accounts = [];
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }
            $domain = trim((string) (
                $p['primary_domain'] ?? $p['primaryDomain'] ?? $p['domain'] ?? ''
            ));
            if ($domain === '') {
                continue;
            }
            $accounts[] = [
                'domain' => $domain,
                'status' => (string) ($p['status'] ?? 'unknown'),
            ];
        }

        return $accounts;
    }

    /** @return array<int,array<string,mixed>> */
    private static function allProvisions(Client $client): array
    {
        $all = [];
        $limit = 100;
        for ($page = 1; $page <= 100; $page++) {
            $response = $client->getEnvelope(
                '/provisions?page=' . $page . '&limit=' . $limit
            );
            $data = $response['data'] ?? $response;
            $items = is_array($data)
                ? ($data['items'] ?? $data)
                : [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $all[] = $item;
                    }
                }
            }
            $meta = is_array($response['meta'] ?? null)
                ? $response['meta']
                : (is_array($data['meta'] ?? null) ? $data['meta'] : []);
            // Stop when the response does not provide pagination metadata.
            if (!array_key_exists('hasMore', $meta)) {
                break;
            }
            $hasMore = $meta['hasMore'] === true
                || $meta['has_more'] === true;
            if (!$hasMore || count($items) < $limit) {
                break;
            }
        }
        return $all;
    }
}
