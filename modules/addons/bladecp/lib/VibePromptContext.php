<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\SaleGuard;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Read-only homepage eligibility, never an activation or identity authority. */
final class VibePromptContext
{
    /** @return array{endpoint:string,state:string,available:bool}|null */
    public static function current(): ?array
    {
        // No catalogue calls, schema changes or client data lookups when off.
        $enabled = ClientExperienceSettings::enabledServerIds();
        if ($enabled === []) {
            return null;
        }
        $identity = IdentityHandoff::currentIdentity();
        $support = false;
        if ($identity === null) {
            $identity = IdentityHandoff::currentMasqueradedIdentity();
            $support = true;
        }
        if ($identity === null) {
            return null;
        }
        try {
            $product = VibeFreeActivation::canonicalFreeProduct();
            if ($product === null) {
                return null;
            }
            // A group with several possible servers cannot attest which
            // installation the native Free order would select.
            $groupId = (int) ($product->servergroup ?? 0);
            if ($groupId <= 0) {
                return null;
            }
            $servers = [];
            foreach (Capsule::table('tblservergroupsrel')->where('groupid', $groupId)->get() as $relation) {
                $relatedId = (int) ($relation->serverid ?? 0);
                $related = Capsule::table('tblservers')->where('id', $relatedId)->first();
                if ($related === null) {
                    return null;
                }
                if ((int) ($related->disabled ?? 0) !== 0) {
                    continue;
                }
                $candidate = AdminServerContext::candidate($relatedId);
                if ($candidate === null) {
                    return null;
                }
                $servers[$candidate['id']] = true;
            }
            if (count($servers) !== 1) {
                return null;
            }
            $serverId = (int) array_key_first($servers);
            if (!in_array($serverId, $enabled, true)) {
                return null;
            }
            // The existing Free sale gate uses the global catalogue context.
            // Require it to agree with the product-derived server, never use
            // an administrator's selection to assign a client to a partner.
            $catalogueContext = AdminServerContext::resolve();
            if ($catalogueContext['state'] !== 'configured' || $catalogueContext['serverId'] !== $serverId) {
                return null;
            }
            $free = [];
            foreach (Capsule::table('tblhosting')->where('userid', $identity['clientId'])->get() as $service) {
                $ownedProduct = Capsule::table('tblproducts')->where('id', (int) ($service->packageid ?? 0))->first();
                if ($ownedProduct === null || ($ownedProduct->servertype ?? '') !== 'bladecp'
                    || strtolower(trim((string) ($ownedProduct->configoption1 ?? ''))) !== 'vibe') {
                    continue;
                }
                $tier = strtolower(trim((string) ($ownedProduct->configoption4 ?? '')));
                if ($tier === 'free') {
                    $free[] = $service;
                } elseif (in_array((string) ($service->domainstatus ?? ''), ['Active', 'Pending'], true)) {
                    return null;
                }
            }
            if (count($free) > 1) {
                return null;
            }
            $state = 'new';
            if ($free !== []) {
                $service = $free[0];
                if (!in_array((string) ($service->domainstatus ?? ''), ['Active', 'Pending'], true)
                    || (int) ($service->packageid ?? 0) !== (int) $product->id
                    || (int) ($service->server ?? 0) !== $serverId) {
                    return null;
                }
                $state = strtolower((string) $service->domainstatus);
            }
            if ($support && ($state !== 'active' || VibeSupportClientArea::serviceId() !== (int) $free[0]->id)) {
                return null;
            }
            $client = ServerConfig::clientForServerId($serverId);
            $endpoint = $client === null ? null : self::endpoint($client->baseUrl());
            if ($endpoint === null) {
                return null;
            }
            // Display uses the existing local ceiling without blocking the
            // dashboard on a remote request. The fixed activation route still
            // performs its authoritative live sale and identity checks.
            return [
                'endpoint' => $endpoint,
                'state' => $state,
                'available' => SaleGuard::checkLocalProductId((int) $product->id) === null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function endpoint(string $apiBase): ?string
    {
        return match (rtrim($apiBase, '/')) {
            'https://api.bladecp.com/api/v1' => 'https://bladecp.app/api/reseller/launch-intents',
            'https://api.bladecp.cloud/api/v1' => 'https://bladecp.work/api/reseller/launch-intents',
            default => null,
        };
    }
}
