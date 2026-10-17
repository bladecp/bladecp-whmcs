<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Billing of cPanel Migrator migrations, per BladeCP server.
 *
 * It is a back office setting: it never shows anything to clients and does
 * not depend on the client area Fast Deploy. It is on unless an
 * administrator turns it off.
 */
final class BillingSettings
{
    private const PREFIX = 'migration_billing_server_';

    public static function enabled(int $serverId): bool
    {
        if ($serverId <= 0 || !class_exists(Capsule::class)) {
            return false;
        }
        try {
            $value = Capsule::table('tbladdonmodules')->where('module', 'bladecp')
                ->where('setting', self::PREFIX . $serverId)->value('value');
            return !is_string($value) || trim($value) !== '0';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Called only after the native admin permission, selected-server and CSRF checks. */
    public static function save(int $serverId, bool $enabled): bool
    {
        if (AdminServerContext::candidate($serverId) === null) {
            return false;
        }
        try {
            $setting = self::PREFIX . $serverId;
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
     * Usable BladeCP servers of this WHMCS, with or without the client area
     * Fast Deploy.
     *
     * @return list<int>
     */
    public static function bladecpServerIds(): array
    {
        $ids = [];
        foreach (AdminServerContext::candidates() as $candidate) {
            $id = (int) ($candidate['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return list<int> BladeCP servers that bill cPanel Migrator migrations */
    public static function serverIds(): array
    {
        return array_values(array_filter(self::bladecpServerIds(), static fn (int $id): bool => self::enabled($id)));
    }
}
