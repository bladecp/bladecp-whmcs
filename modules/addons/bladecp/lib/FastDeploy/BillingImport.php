<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Bladecp\FastDeployGateway;
use WHMCS\Module\Addon\Bladecp\FastDeployPolicy;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Turns migrations started by the cPanel Migrator with this partner's key
 * into native WHMCS services, so the partner bills them here.
 *
 * The owner is found only from a cPanel service this WHMCS already holds for
 * the same server and account. Anything else is confirmed by the account
 * holder in the client area, or decided by an administrator. Nothing is
 * guessed: an unproven owner never receives a service.
 */
final class BillingImport
{
    public const REASON_NO_SERVICE = 'no_cpanel_service';
    public const REASON_MULTIPLE = 'multiple_clients';
    public const REASON_RESELLER = 'reseller_account';
    public const REASON_NO_PRODUCT = 'no_product';
    public const REASON_PRODUCTS = 'multiple_products';
    public const REASON_CONFLICT = 'link_conflict';
    public const REASON_INVALID = 'invalid_item';
    public const REASON_CLIENT = 'client_incomplete';
    public const REASON_SERVICE = 'service_failed';
    public const REASON_CLIENT_AREA = 'client_area_unavailable';
    /** WHMCS products could not be read; retried on the next run. */
    public const REASON_PRODUCT_UNAVAILABLE = 'product_unavailable';

    private const MAX_PAGES = 10;
    private const RESELLER_LOOKUP_PREFIX = 'migration_billing_reseller_lookup_server_';
    private const UUID = '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D';
    private const ACCOUNT = '/^[a-z0-9][a-z0-9_.-]{0,31}$/D';
    private const TRANSIENT = ['link_pending', 'lock_unavailable', 'service_server_failed', self::REASON_PRODUCT_UNAVAILABLE];

    /**
     * Cron entry point. One run at a time for the whole installation.
     *
     * @param float|null $deadline microtime after which no new item starts
     */
    public static function run(?float $deadline = null): array
    {
        if (!class_exists(Capsule::class)) {
            return ['ok' => false, 'servers' => []];
        }
        $release = Commercial::lock('import');
        if ($release === null) {
            return ['ok' => false, 'servers' => []];
        }
        try {
            BillingStore::installSchema();
            $servers = [];
            // Billing does not depend on the client area Fast Deploy: every
            // usable BladeCP server bills unless its administrator opted out.
            foreach (BillingSettings::serverIds() as $serverId) {
                $serverId = (int) $serverId;
                $gateway = $serverId > 0 ? FastDeployGateway::forBilling($serverId) : null;
                if ($gateway === null || !method_exists($gateway, 'billingQueue')) {
                    continue;
                }
                if ($deadline !== null && microtime(true) >= $deadline) {
                    break;
                }
                try {
                    $servers[$serverId] = self::runForServer($gateway, $deadline);
                } catch (\Throwable) {
                    // One partner server never blocks the others.
                }
            }
            return ['ok' => true, 'servers' => $servers];
        } finally {
            $release();
        }
    }

    /** @return array<string,int> */
    public static function runForServer(object $api, ?float $deadline = null): array
    {
        $counts = ['linked' => 0, 'claim' => 0, 'review' => 0, 'closed' => 0, 'skipped' => 0, 'retry' => 0];
        $cursor = null;
        $seen = [];
        $complete = false;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $api->billingQueue($cursor);
            if (!is_array($result)) {
                break;
            }
            foreach ($result['items'] ?? [] as $raw) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    // Cut by the time budget: the rest waits for the next run,
                    // and no review is closed from an incomplete read.
                    return $counts;
                }
                $requestUuid = is_array($raw) ? strtolower(trim((string) ($raw['request_uuid'] ?? ''))) : '';
                if ($requestUuid !== '') {
                    $seen[$requestUuid] = true;
                }
                try {
                    $outcome = is_array($raw) ? self::importItem($api, $raw) : 'skipped';
                } catch (\Throwable) {
                    $outcome = 'retry';
                }
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
            $cursor = $result['nextCursor'] ?? null;
            if (!is_string($cursor) || $cursor === '') {
                $complete = true;
                break;
            }
        }
        if ($complete) {
            // A review whose migration left the queue was decided elsewhere
            // or has ended. Only a complete, successful read may close it.
            $counts['closed'] += BillingStore::closeReviewsNotIn((int) $api->serverId(), array_keys($seen));
        }
        return $counts;
    }

    /** @return 'linked'|'claim'|'review'|'closed'|'skipped'|'retry' */
    public static function importItem(object $api, array $raw): string
    {
        $serverId = (int) $api->serverId();
        $item = self::item($raw);
        if ($item === null || $serverId <= 0) {
            return 'skipped';
        }
        if (in_array($item['phase'], ['cancelled', 'failed'], true)) {
            return 'skipped';
        }
        if (!self::complete($item)) {
            BillingStore::record($serverId, $item, BillingStore::REVIEW, self::REASON_INVALID);
            return 'review';
        }
        $release = Commercial::lock('request:' . $item['requestUuid']);
        if ($release === null) {
            return 'retry';
        }
        try {
            $current = BillingStore::forRequest($serverId, $item['requestUuid']);
            if (($current['state'] ?? '') === BillingStore::REVIEW && ($current['reason'] ?? '') === self::REASON_CONFLICT) {
                // The platform already holds another link: only an
                // administrator decides, never a new service every cron.
                return 'review';
            }
            if (($current['state'] ?? '') === BillingStore::LINKED && (int) ($current['clientId'] ?? 0) > 0) {
                // Still queued after a local link: the response was lost.
                // The same client repeats the same link for the same service.
                return self::link($api, $item, (int) $current['clientId']);
            }
            $decision = self::decide($item);
            if ($decision['action'] === 'retry') {
                return 'retry';
            }
            if ($decision['action'] !== 'review') {
                $product = self::product($serverId, $item['planId']);
                if (($product['reason'] ?? '') === self::REASON_PRODUCT_UNAVAILABLE) {
                    return 'retry';
                }
                if (isset($product['reason'])) {
                    $decision = ['action' => 'review', 'reason' => $product['reason']];
                }
            }
            if ($decision['action'] === 'link') {
                return self::link($api, $item, (int) $decision['clientId']);
            }
            if ($decision['action'] === 'claim') {
                return self::requestClaim($api, $item);
            }
            BillingStore::record($serverId, $item, BillingStore::REVIEW, (string) $decision['reason']);
            return 'review';
        } finally {
            $release();
        }
    }

    /**
     * The one path that gives a migration its native service: the importer,
     * an administrator's review and the account holder's confirmation all
     * end here, so the service and its link are always made the same way.
     *
     * @return array{ok:bool,status:string,serviceId?:int,error?:string}
     */
    public static function attach(object $api, array $item, int $clientId): array
    {
        $serverId = (int) $api->serverId();
        $installationId = strtolower(trim((string) $api->installationId()));
        if ($serverId <= 0 || $installationId === '' || $clientId <= 0 || !self::complete($item)) {
            return self::failure('invalid_request');
        }
        $product = self::product($serverId, (int) $item['planId']);
        if (isset($product['reason'])) {
            return self::failure((string) $product['reason']);
        }
        $owner = self::owner($clientId);
        if ($owner === null) {
            return self::failure(self::REASON_CLIENT);
        }
        $reserved = Commercial::reserveLinkedService($serverId, $clientId, (int) $product['productId'], $item['requestUuid'], $installationId, $item['mode'], $item['domain']);
        $serviceId = (int) ($reserved['serviceId'] ?? 0);
        if (($reserved['ok'] ?? false) !== true || $serviceId <= 0) {
            return self::failure((string) ($reserved['error'] ?? 'service_failed'), $serviceId);
        }
        try {
            $api->linkBilling($item['requestUuid'], $clientId, $serviceId, $owner);
        } catch (ApiException $error) {
            $code = self::code($error);
            if ($error->httpStatus === 409 && in_array($code, ['MIGRATION_BILLING_LINK_CONFLICT', 'MIGRATION_NOT_LINKABLE'], true)) {
                // The link belongs to another service or the migration has
                // ended: the service made for it must not stay to be billed.
                Commercial::cancelPendingService($serviceId, $clientId, $serverId, $item['requestUuid'], $installationId);
                return self::failure($code === 'MIGRATION_NOT_LINKABLE' ? 'not_linkable' : self::REASON_CONFLICT, $serviceId);
            }
            return self::failure('link_pending', $serviceId);
        } catch (\Throwable) {
            return self::failure('link_pending', $serviceId);
        }
        // An earlier attempt for another client of this installation never
        // got the link. Its pending service is closed, never billed.
        foreach (BillingLink::reservations($item['requestUuid'], $installationId) as $other) {
            if ($other['serviceId'] !== $serviceId && $other['status'] === 'pending') {
                Commercial::cancelPendingService($other['serviceId'], $other['clientId'], $other['serverId'], $item['requestUuid'], $installationId);
            }
        }
        return ['ok' => true, 'status' => 'linked', 'serviceId' => $serviceId];
    }

    /** Administrator decision for a stored review. */
    public static function resolve(object $api, array $row, int $clientId): array
    {
        $serverId = (int) $api->serverId();
        if (($row['state'] ?? '') !== BillingStore::REVIEW || (int) ($row['serverId'] ?? 0) !== $serverId) {
            return self::failure('review_closed');
        }
        $item = BillingStore::item($row);
        $release = Commercial::lock('request:' . $item['requestUuid']);
        if ($release === null) {
            return self::failure('lock_unavailable');
        }
        try {
            $result = self::attach($api, $item, $clientId);
            self::remember($serverId, $item, $result, $clientId, (string) ($row['reason'] ?? ''));
            return $result;
        } finally {
            $release();
        }
    }

    /**
     * Account holder confirmation. The verified token only names the
     * request; the owner is the client who is logged in.
     *
     * @param array<string,mixed> $verified response of the claim verification
     */
    public static function claim(object $api, array $verified, int $clientId): array
    {
        $item = self::item($verified);
        $installation = strtolower(trim((string) ($verified['installation_id'] ?? $verified['installationId'] ?? '')));
        if ($item === null || !self::complete($item)
            || ($installation !== '' && !hash_equals(strtolower((string) $api->installationId()), $installation))) {
            return self::failure('invalid_claim');
        }
        $release = Commercial::lock('request:' . $item['requestUuid']);
        if ($release === null) {
            return self::failure('lock_unavailable');
        }
        try {
            $result = self::attach($api, $item, $clientId);
            self::remember((int) $api->serverId(), $item, $result, $clientId, null);
            return $result + ['requestUuid' => $item['requestUuid']];
        } finally {
            $release();
        }
    }

    /** @return array{action:'link'|'claim'|'review'|'retry',clientId?:int,reason?:string} */
    public static function decide(array $item): array
    {
        $owner = $item['owner'];
        $reseller = $owner !== null ? $owner !== 'root' : $item['initiatedBy'] === 'reseller';
        if ($reseller) {
            // A reseller's client who migrates alone becomes a direct client.
            return $item['initiatedBy'] === 'account'
                ? ['action' => 'claim']
                : ['action' => 'review', 'reason' => self::REASON_RESELLER];
        }
        $clients = self::cpanelClients($item['serverHostname'], $item['username']);
        if ($clients === null) {
            return ['action' => 'retry'];
        }
        if (count($clients) === 1) {
            return ['action' => 'link', 'clientId' => $clients[0]];
        }
        if (count($clients) > 1) {
            return ['action' => 'review', 'reason' => self::REASON_MULTIPLE];
        }
        return $item['initiatedBy'] === 'account'
            ? ['action' => 'claim']
            : ['action' => 'review', 'reason' => self::REASON_NO_SERVICE];
    }

    /**
     * Clients holding the cPanel account on a WHMCS server whose hostname or
     * IP is the source server. Only an Active or Suspended service proves who
     * holds the account now: cPanel reuses usernames, so a Terminated,
     * Cancelled, Fraud or never provisioned (Pending) service of a former
     * client must not receive the bill. A client with an Active service is
     * preferred; otherwise more than one client is reported as ambiguous.
     *
     * @return list<int>|null null when WHMCS cannot be read
     */
    public static function cpanelClients(?string $host, ?string $username): ?array
    {
        if ($host === null || $username === null || !class_exists(Capsule::class)) {
            return [];
        }
        try {
            $serverIds = [];
            foreach (Capsule::table('tblservers')->get() as $server) {
                if (strtolower(trim((string) ($server->type ?? ''))) === 'bladecp') {
                    continue;
                }
                $name = strtolower(rtrim(trim((string) ($server->hostname ?? '')), '.'));
                $ip = strtolower(trim((string) ($server->ipaddress ?? '')));
                if (($name !== '' && hash_equals($name, $host)) || ($ip !== '' && hash_equals($ip, $host))) {
                    $serverIds[] = (int) ($server->id ?? 0);
                }
            }
            $serverIds = array_values(array_filter(array_unique($serverIds), static fn (int $id): bool => $id > 0));
            if ($serverIds === []) {
                return [];
            }
            $clients = [];
            foreach (Capsule::table('tblhosting')->whereIn('server', $serverIds)->where('username', $username)->get() as $row) {
                $clientId = (int) ($row->userid ?? 0);
                $status = strtolower(trim((string) ($row->domainstatus ?? '')));
                if ($clientId <= 0 || !in_array($status, ['active', 'suspended'], true)) {
                    continue;
                }
                $active = $status === 'active';
                $clients[$clientId] = ($clients[$clientId] ?? false) || $active;
            }
            ksort($clients, SORT_NUMERIC);
            if (count($clients) <= 1) {
                return array_keys($clients);
            }
            $withActive = array_keys(array_filter($clients));
            return count($withActive) === 1 ? $withActive : array_keys($clients);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Suggestion for an administrator only: the client holding the reseller
     * account that owns the migrated account, on the same server. Off by
     * default and never used by the importer.
     *
     * @return list<int>
     */
    public static function resellerClients(int $serverId, array $row): array
    {
        $owner = $row['sourceOwner'] ?? null;
        if (!self::resellerLookupEnabled($serverId) || !is_string($owner) || $owner === 'root'
            || preg_match(self::ACCOUNT, $owner) !== 1) {
            return [];
        }
        $host = is_string($row['sourceServer'] ?? null) ? strtolower((string) $row['sourceServer']) : null;
        return self::cpanelClients($host, $owner) ?? [];
    }

    public static function resellerLookupEnabled(int $serverId): bool
    {
        if ($serverId <= 0 || !class_exists(Capsule::class)) {
            return false;
        }
        try {
            return (string) Capsule::table('tbladdonmodules')->where('module', 'bladecp')
                ->where('setting', self::RESELLER_LOOKUP_PREFIX . $serverId)->value('value') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Called only after the native admin permission and CSRF checks. */
    public static function saveResellerLookup(int $serverId, bool $enabled): bool
    {
        if ($serverId <= 0 || !class_exists(Capsule::class)) {
            return false;
        }
        try {
            $setting = self::RESELLER_LOOKUP_PREFIX . $serverId;
            $query = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', $setting);
            if ($query->exists()) {
                $query->update(['value' => $enabled ? '1' : '0']);
            } else {
                Capsule::table('tbladdonmodules')->insert(['module' => 'bladecp', 'setting' => $setting, 'value' => $enabled ? '1' : '0']);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The BladeCP product of the migration plan on this server: a recurring
     * Stack product, not retired, whose server group holds this server and no
     * other. Hidden products count, since nothing here is sold to a visitor.
     * The client area Fast Deploy list only breaks a tie between several.
     *
     * @return array{productId?:int,reason?:string}
     */
    public static function product(int $serverId, int $planId): array
    {
        if ($serverId <= 0 || $planId <= 0) {
            return ['reason' => self::REASON_NO_PRODUCT];
        }
        if (!class_exists(Capsule::class)) {
            return ['reason' => self::REASON_PRODUCT_UNAVAILABLE];
        }
        try {
            $matches = [];
            $groups = [];
            foreach (Capsule::table('tblproducts')->where('servertype', 'bladecp')->where('configoption2', (string) $planId)->get() as $row) {
                $id = (int) ($row->id ?? 0);
                if ($id <= 0 || strtolower(trim((string) ($row->configoption1 ?? ''))) !== 'stack-container'
                    || strtolower(trim((string) ($row->paytype ?? ''))) !== 'recurring' || (int) ($row->retired ?? 0) !== 0) {
                    continue;
                }
                $groupId = (int) ($row->servergroup ?? 0);
                if (!isset($groups[$groupId])) {
                    $members = [];
                    foreach (Capsule::table('tblservergroupsrel')->where('groupid', $groupId)->get() as $member) {
                        $members[] = (int) ($member->serverid ?? 0);
                    }
                    $groups[$groupId] = array_values(array_unique($members));
                }
                if ($groupId > 0 && $groups[$groupId] === [$serverId]) {
                    $matches[] = $id;
                }
            }
        } catch (\Throwable) {
            return ['reason' => self::REASON_PRODUCT_UNAVAILABLE];
        }
        sort($matches, SORT_NUMERIC);
        if (count($matches) === 1) {
            return ['productId' => $matches[0]];
        }
        if ($matches === []) {
            return ['reason' => self::REASON_NO_PRODUCT];
        }
        $policy = FastDeployPolicy::load($serverId);
        $allowed = is_array($policy['products'] ?? null)
            ? array_values(array_intersect($matches, array_map('intval', array_keys($policy['products'])))) : [];
        return count($allowed) === 1 ? ['productId' => $allowed[0]] : ['reason' => self::REASON_PRODUCTS];
    }

    /** @return array{email:string,first_name:string,last_name:string,company_name?:string}|null */
    public static function owner(int $clientId): ?array
    {
        if ($clientId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $row = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($row === null) {
                return null;
            }
            $email = trim((string) ($row->email ?? ''));
            $first = trim((string) ($row->firstname ?? ''));
            $last = trim((string) ($row->lastname ?? ''));
            $company = trim((string) ($row->companyname ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $first === ''
                || mb_strlen($first) > 100 || mb_strlen($last) > 100) {
                return null;
            }
            $owner = ['email' => $email, 'first_name' => $first, 'last_name' => $last];
            if ($company !== '') {
                $owner['company_name'] = mb_substr($company, 0, 150);
            }
            return $owner;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Profile fields the review form asks for, the ones WHMCS and fraud modules expect of a client. */
    public const CLIENT_FIELDS = ['firstname', 'lastname', 'email', 'phonenumber', 'address1', 'city', 'state', 'postcode', 'country', 'companyname'];

    /**
     * Creates the client an administrator typed in, through the native
     * AddClient with the WHMCS profile validation on. No welcome email is
     * sent, and an existing email is never duplicated. Only the company is
     * optional here; WHMCS still applies its own optional field settings.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,clientId?:int,error?:string}
     */
    public static function createClient(array $input): array
    {
        $client = [];
        foreach (self::CLIENT_FIELDS as $field) {
            $value = $input[$field] ?? '';
            $client[$field] = is_string($value) ? trim($value) : '';
        }
        $client['email'] = strtolower($client['email']);
        $client['country'] = strtoupper($client['country']);
        foreach ($client as $field => $value) {
            if (($value === '' && $field !== 'companyname') || mb_strlen($value) > ($field === 'email' ? 254 : 100)) {
                return ['ok' => false, 'error' => 'client_invalid'];
            }
        }
        if (!filter_var($client['email'], FILTER_VALIDATE_EMAIL) || preg_match('/^[A-Z]{2}$/D', $client['country']) !== 1) {
            return ['ok' => false, 'error' => 'client_invalid'];
        }
        if (!function_exists('localAPI') || !class_exists(Capsule::class)) {
            return ['ok' => false, 'error' => 'client_create_failed'];
        }
        try {
            $existing = Capsule::table('tblclients')->where('email', $client['email'])->first();
            if ($existing !== null) {
                return ['ok' => false, 'error' => 'client_exists', 'clientId' => (int) ($existing->id ?? 0)];
            }
            $created = localAPI('AddClient', array_filter($client, static fn (string $value): bool => $value !== '') + [
                'password2' => bin2hex(random_bytes(24)),
                'noemail' => true,
            ]);
            $clientId = filter_var($created['clientid'] ?? null, FILTER_VALIDATE_INT);
            return ($created['result'] ?? '') === 'success' && $clientId !== false && (int) $clientId > 0
                ? ['ok' => true, 'clientId' => (int) $clientId]
                : ['ok' => false, 'error' => 'client_create_failed'];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'client_create_failed'];
        }
    }

    /** HTTPS base of this WHMCS client area, as the platform builds the confirmation link. */
    public static function clientAreaUrl(): ?string
    {
        $url = trim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''));
        if ($url === '' && class_exists('\\WHMCS\\Config\\Setting')) {
            try {
                $url = trim((string) \WHMCS\Config\Setting::getValue('SystemURL'));
            } catch (\Throwable) {
                $url = '';
            }
        }
        $url = rtrim($url, '/');
        $parts = $url === '' ? false : parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        return $url;
    }

    /**
     * Normalized queue item. The same shape comes from the billing queue and
     * from a verified account confirmation.
     *
     * @return array{requestUuid:string,phase:string,kind:string,serverHostname:?string,username:?string,owner:?string,initiatedBy:?string,domain:?string,planId:int,locationId:int,mode:string}|null
     */
    public static function item(array $raw): ?array
    {
        $requestUuid = strtolower(trim((string) ($raw['request_uuid'] ?? $raw['requestUuid'] ?? '')));
        if (preg_match(self::UUID, $requestUuid) !== 1) {
            return null;
        }
        $source = is_array($raw['source'] ?? null) ? $raw['source'] : [];
        $plan = is_array($raw['plan'] ?? null) ? $raw['plan'] : [];
        $commercial = is_array($raw['commercial'] ?? null) ? $raw['commercial'] : [];
        $host = strtolower(rtrim(trim((string) ($source['server_hostname'] ?? '')), '.'));
        $username = strtolower(trim((string) ($source['username'] ?? '')));
        $owner = strtolower(trim((string) ($source['owner'] ?? '')));
        $initiated = strtolower(trim((string) ($source['initiated_by'] ?? '')));
        $domain = strtolower(rtrim(trim((string) ($source['domain'] ?? '')), '.'));
        $mode = (string) ($commercial['mode'] ?? '');
        return [
            'requestUuid' => $requestUuid,
            'phase' => strtolower(trim((string) ($raw['phase'] ?? ''))),
            'kind' => strtolower(trim((string) ($source['kind'] ?? ''))),
            'serverHostname' => self::host($host) ? $host : null,
            'username' => preg_match(self::ACCOUNT, $username) === 1 ? $username : null,
            'owner' => preg_match(self::ACCOUNT, $owner) === 1 ? $owner : null,
            'initiatedBy' => in_array($initiated, ['root', 'account', 'reseller'], true) ? $initiated : null,
            'domain' => self::host($domain) && str_contains($domain, '.') ? $domain : null,
            'planId' => self::positive($plan['partner_plan_id'] ?? null) ?? 0,
            'locationId' => self::positive($plan['location_id'] ?? null) ?? 0,
            'mode' => in_array($mode, FastDeployPolicy::MODES, true) ? $mode : '',
        ];
    }

    private static function complete(array $item): bool
    {
        return ($item['kind'] ?? '') === 'cpanel' && ($item['serverHostname'] ?? null) !== null
            && ($item['username'] ?? null) !== null && ($item['initiatedBy'] ?? null) !== null
            && (int) ($item['planId'] ?? 0) > 0 && ($item['mode'] ?? '') !== '';
    }

    private static function link(object $api, array $item, int $clientId): string
    {
        $serverId = (int) $api->serverId();
        $result = self::attach($api, $item, $clientId);
        return self::remember($serverId, $item, $result, $clientId, null);
    }

    private static function requestClaim(object $api, array $item): string
    {
        $serverId = (int) $api->serverId();
        $url = self::clientAreaUrl();
        if ($url === null) {
            BillingStore::record($serverId, $item, BillingStore::REVIEW, self::REASON_CLIENT_AREA);
            return 'review';
        }
        try {
            $api->requireClaim($item['requestUuid'], $url);
        } catch (ApiException $error) {
            $code = self::code($error);
            if ($error->httpStatus === 409 && $code === 'MIGRATION_NOT_LINKABLE') {
                BillingStore::record($serverId, $item, BillingStore::CLOSED, 'not_linkable');
                return 'closed';
            }
            if ($error->httpStatus === 409) {
                $reason = $code === 'MIGRATION_CLAIM_NOT_ALLOWED' ? self::REASON_NO_SERVICE : self::REASON_CONFLICT;
                BillingStore::record($serverId, $item, BillingStore::REVIEW, $reason);
                return 'review';
            }
            return 'retry';
        } catch (\Throwable) {
            return 'retry';
        }
        BillingStore::record($serverId, $item, BillingStore::CLAIM, null);
        return 'claim';
    }

    /** Persists the outcome of an attach and maps it to the run summary. */
    private static function remember(int $serverId, array $item, array $result, int $clientId, ?string $reviewReason): string
    {
        if (($result['ok'] ?? false) === true) {
            BillingStore::record($serverId, $item, BillingStore::LINKED, null, $clientId, (int) ($result['serviceId'] ?? 0));
            return 'linked';
        }
        $error = (string) ($result['error'] ?? '');
        if ($error === 'not_linkable') {
            BillingStore::record($serverId, $item, BillingStore::CLOSED, 'not_linkable', $clientId);
            return 'closed';
        }
        if (in_array($error, self::TRANSIENT, true)) {
            return 'retry';
        }
        $known = [self::REASON_CONFLICT, self::REASON_CLIENT, self::REASON_NO_PRODUCT, self::REASON_PRODUCTS];
        $reason = in_array($error, $known, true) ? $error : ($reviewReason !== null && $reviewReason !== '' ? $reviewReason : self::REASON_SERVICE);
        BillingStore::record($serverId, $item, BillingStore::REVIEW, $reason, $clientId);
        return 'review';
    }

    private static function code(ApiException $error): string
    {
        $body = $error->body ?? [];
        return strtoupper(trim((string) ($body['code'] ?? $body['error']['code'] ?? '')));
    }

    private static function host(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value) === 1;
    }

    private static function positive(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array{ok:false,status:string,error:string,serviceId?:int} */
    private static function failure(string $error, int $serviceId = 0): array
    {
        return array_filter(['ok' => false, 'status' => 'error', 'error' => $error, 'serviceId' => $serviceId > 0 ? $serviceId : null],
            static fn ($value): bool => $value !== null);
    }
}
