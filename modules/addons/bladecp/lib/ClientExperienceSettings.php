<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Optional presentation settings. Credentials remain in the native server record. */
final class ClientExperienceSettings
{
    private const PREFIX = 'client_vibe_prompt_server_';
    private const FAST_DEPLOY_PREFIX = 'client_fast_deploy_server_';
    private const WORDPRESS_PREFIX = 'client_wordpress_server_';

    /** @return list<int> */
    public static function enabledServerIds(): array
    {
        return self::enabledIds(self::PREFIX);
    }

    /** @return list<int> */
    private static function enabledIds(string $prefix): array
    {
        try {
            $ids = [];
            foreach (Capsule::table('tbladdonmodules')->where('module', 'bladecp')->get() as $row) {
                $setting = (string) ($row->setting ?? '');
                if ((string) ($row->value ?? '') === '1'
                    && preg_match('/^' . $prefix . '([1-9][0-9]*)$/D', $setting, $match) === 1) {
                    $ids[] = (int) $match[1];
                }
            }
            return array_values(array_unique($ids));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function vibePromptEnabled(int $serverId): bool
    {
        return $serverId > 0 && in_array($serverId, self::enabledServerIds(), true);
    }

    /** Called only by the addon administration after its native ACL and CSRF gate. */
    public static function saveVibePrompt(int $serverId, bool $enabled): bool
    {
        return self::save(self::PREFIX, $serverId, $enabled);
    }

    public static function fastDeployEnabled(int $serverId): bool
    {
        return $serverId > 0 && in_array($serverId, self::enabledIds(self::FAST_DEPLOY_PREFIX), true);
    }

    public static function saveFastDeploy(int $serverId, bool $enabled): bool
    {
        return self::save(self::FAST_DEPLOY_PREFIX, $serverId, $enabled);
    }

    public static function fastDeployServerIds(): array
    {
        return self::enabledIds(self::FAST_DEPLOY_PREFIX);
    }

    public static function wordPressEnabled(int $serverId): bool
    {
        return $serverId > 0 && in_array($serverId, self::enabledIds(self::WORDPRESS_PREFIX), true);
    }

    public static function saveWordPress(int $serverId, bool $enabled): bool
    {
        return self::save(self::WORDPRESS_PREFIX, $serverId, $enabled);
    }

    private static function save(string $prefix, int $serverId, bool $enabled): bool
    {
        if (AdminServerContext::candidate($serverId) === null) {
            return false;
        }
        try {
            $setting = $prefix . $serverId;
            $query = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', $setting);
            if ($query->exists()) {
                $query->update(['value' => $enabled ? '1' : '0']);
            } else {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => 'bladecp', 'setting' => $setting, 'value' => $enabled ? '1' : '0',
                ]);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
