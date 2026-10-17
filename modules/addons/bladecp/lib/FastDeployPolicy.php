<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ProductType;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Partner settings only. Payment and runtime authority are separate concerns. */
final class FastDeployPolicy
{
    public const MODES = ['payment_before', 'payment_after', 'trial'];
    public const EXPIRY_ACTIONS = ['manual', 'suspend', 'suspend_delete'];
    private const PREFIX = 'client_fast_deploy_policy_server_';

    public static function rule(array $input): ?array
    {
        $mode = $input['mode'] ?? null;
        if (!in_array($mode, self::MODES, true)) {
            return null;
        }
        $key = $mode === 'trial' ? 'trial_days' : 'payment_days';
        $days = null;
        if ($mode !== 'payment_before') {
            $raw = $input[$key] ?? null;
            if ((!is_int($raw) && !is_string($raw)) || preg_match('/^[1-9][0-9]{0,2}$/D', (string) $raw) !== 1) {
                return null;
            }
            $days = (int) $raw;
            if ($days > 365) { return null; }
        }
        $expiry = null;
        if ($mode !== 'payment_before' && ($input['expiry'] ?? null) !== null) {
            if (!is_array($input['expiry'])) { return null; }
            $empty = ($input['expiry']['action'] ?? null) === ''
                && ($input['expiry']['grace_days'] ?? null) === ''
                && ($input['expiry']['retention_days'] ?? null) === '';
            if (!$empty) {
                $expiry = self::expiry($input['expiry']);
                if ($expiry === null) { return null; }
            }
        }
        return ['mode' => $mode, 'trial_days' => $mode === 'trial' ? $days : null,
            'payment_days' => $mode === 'payment_after' ? $days : null, 'expiry' => $expiry];
    }

    /** Explicit expiry policy; absent legacy settings never opt into an action. */
    public static function expiry(array $input): ?array
    {
        $action = $input['action'] ?? null;
        if (!in_array($action, self::EXPIRY_ACTIONS, true)) { return null; }
        $grace = self::days($input['grace_days'] ?? null, 0);
        $retention = $action === 'suspend_delete' ? self::days($input['retention_days'] ?? null, 1) : null;
        if ($grace === null || ($action === 'suspend_delete' && $retention === null)) { return null; }
        return ['action' => $action, 'grace_days' => $grace, 'retention_days' => $retention];
    }

    private static function days(mixed $value, int $minimum): ?int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^(0|[1-9][0-9]{0,2})$/D', (string) $value) !== 1) { return null; }
        $days = (int) $value;
        return $days >= $minimum && $days <= 365 ? $days : null;
    }

    /** Products available for policy configuration, not proof of live capacity or sale authority. */
    public static function products(int $serverId): array
    {
        if (AdminServerContext::candidate($serverId) === null) { return []; }
        try {
            $products = [];
            $groups = [];
            foreach (Capsule::table('tblproducts')->where('servertype', 'bladecp')->get() as $row) {
                $id = (int) ($row->id ?? 0);
                $plan = (string) ($row->configoption2 ?? '');
                if ($id <= 0 || ($row->configoption1 ?? '') !== ProductType::STACK_CONTAINER
                    || ($row->paytype ?? '') !== 'recurring' || !ctype_digit($plan) || (int) $plan <= 0) {
                    continue;
                }
                $groupId = (int) ($row->servergroup ?? 0);
                if (!isset($groups[$groupId])) {
                    $ids = [];
                    foreach (Capsule::table('tblservergroupsrel')->where('groupid', $groupId)->get() as $member) {
                        $ids[] = (int) $member->serverid;
                    }
                    $groups[$groupId] = array_values(array_unique($ids));
                }
                if ($groups[$groupId] !== [$serverId]) { continue; }
                $products[$id] = ['id' => $id, 'planId' => (int) $plan, 'name' => (string) $row->name,
                    'productGroupId' => (int) ($row->gid ?? 0),
                    'visible' => !(bool) ($row->hidden ?? false) && !(bool) ($row->retired ?? false)];
            }
            ksort($products, SORT_NUMERIC);
            return $products;
        } catch (\Throwable) {
            return [];
        }
    }

    /** No default commercial policy is silently assigned to an installation. */
    public static function load(int $serverId): ?array
    {
        try {
            $row = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', self::PREFIX . $serverId)->first();
            if ($row === null) { return null; }
            $config = json_decode((string) $row->value, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($config) || ($config['version'] ?? null) !== 1
                || !is_array($config['default'] ?? null) || !is_array($config['products'] ?? null)) { return null; }
            $default = self::rule($config['default']);
            if ($default === null) { return null; }
            $products = [];
            foreach ($config['products'] as $id => $override) {
                if (preg_match('/^[1-9][0-9]*$/D', (string) $id) !== 1) { return null; }
                if ($override !== null && (!is_array($override) || self::rule($override) === null)) { return null; }
                // Legacy product rules are accepted only as allowlist entries.
                $products[(int) $id] = null;
            }
            return ['version' => 1, 'default' => $default, 'products' => $products];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Called after native admin ACL, selected-server and CSRF checks. One setting is one atomic snapshot. */
    public static function save(int $serverId, array $input): bool
    {
        if (AdminServerContext::candidate($serverId) === null || !is_array($input['default'] ?? null)
            || !is_array($input['products'] ?? [])) { return false; }
        $default = self::rule($input['default']);
        if ($default === null) { return false; }
        $candidates = self::products($serverId);
        $selected = [];
        foreach (($input['products'] ?? []) as $id => $row) {
            if (!is_array($row) || !in_array($row['enabled'] ?? '0', ['0', '1'], true)) { return false; }
            if (($row['enabled'] ?? '0') !== '1') { continue; }
            if (!isset($candidates[$id])) { return false; }
            $selected[(int) $id] = null;
        }
        ksort($selected, SORT_NUMERIC);
        try {
            $value = json_encode(['version' => 1, 'default' => $default, 'products' => $selected], JSON_THROW_ON_ERROR);
            $setting = self::PREFIX . $serverId;
            $query = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', $setting);
            if ($query->exists()) { $query->update(['value' => $value]); }
            else { Capsule::table('tbladdonmodules')->insert(['module' => 'bladecp', 'setting' => $setting, 'value' => $value]); }
            return true;
        } catch (\Throwable) { return false; }
    }

    /** Revalidated configured choices for presentation; live eligibility must still be checked by the integration. */
    public static function forProduct(int $serverId, int $productId): ?array
    {
        return self::choices($serverId)[$productId]['policy'] ?? null;
    }

    public static function choices(int $serverId): array
    {
        $config = self::load($serverId);
        if ($config === null) { return []; }
        $choices = [];
        foreach (self::products($serverId) as $id => $product) {
            if ($product['visible'] && array_key_exists($id, $config['products'])) {
                $choices[$id] = ['id' => $id, 'planId' => $product['planId'], 'name' => $product['name'],
                    'policy' => $config['default']];
            }
        }
        return $choices;
    }
}
