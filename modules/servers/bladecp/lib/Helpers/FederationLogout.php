<?php
declare(strict_types=1);
namespace WHMCS\Module\Server\Bladecp\Helpers;
use WHMCS\Database\Capsule;
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Durable, retryable logout delivery. Contains fingerprints, never cookies. */
final class FederationLogout
{
    private const TABLE = 'mod_bladecp_federation_sessions';
    private const SUPPORT_TABLE = 'mod_bladecp_whmcs_support_sessions';
    public static function ensureSchema(): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->create(self::TABLE, static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('server_id'); $table->unsignedInteger('user_id');
                $table->string('installation_id', 36); $table->string('session_fingerprint', 43);
                $table->boolean('revoked')->default(false); $table->boolean('delivered')->default(false);
                $table->unsignedInteger('expires_at');
                $table->unique(['installation_id', 'session_fingerprint'], 'bladecp_federation_session_uq');
                $table->index(['user_id', 'revoked']);
            });
        }
        if (!Capsule::schema()->hasTable(self::SUPPORT_TABLE)) {
            Capsule::schema()->create(self::SUPPORT_TABLE, static function ($table): void {
                $table->increments('id'); $table->unsignedInteger('server_id'); $table->unsignedInteger('user_id');
                $table->string('installation_id', 36); $table->string('session_fingerprint', 43);
                $table->boolean('revoked')->default(false); $table->boolean('delivered')->default(false); $table->unsignedInteger('expires_at');
                $table->unique(['installation_id', 'session_fingerprint'], 'bladecp_whmcs_support_session_uq'); $table->index(['user_id', 'revoked']);
            });
        }
    }
    public static function track(int $serverId, int $userId, string $installationId, string $fingerprint): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) { throw new \RuntimeException('federation_unavailable'); }
        $found = Capsule::table(self::TABLE)->where('installation_id', $installationId)->where('session_fingerprint', $fingerprint)->first();
        if ($found !== null) {
            if ((bool) $found->revoked || (int) $found->user_id !== $userId || (int) $found->server_id !== $serverId) { throw new \RuntimeException('federation_revoked'); }
            Capsule::table(self::TABLE)->where('id', $found->id)->update(['expires_at' => time() + 9 * 3600]);
            return;
        }
        Capsule::table(self::TABLE)->insert(['server_id' => $serverId, 'user_id' => $userId, 'installation_id' => $installationId,
            'session_fingerprint' => $fingerprint, 'revoked' => false, 'delivered' => false, 'expires_at' => time() + 9 * 3600]);
    }
    /** Track a native WHMCS Owner session under its real administrator actor. */
    public static function trackSupport(int $serverId, int $adminId, string $installationId, string $fingerprint): void
    {
        if ($adminId <= 0 || !Capsule::schema()->hasTable(self::SUPPORT_TABLE)) { throw new \RuntimeException('federation_unavailable'); }
        $found = Capsule::table(self::SUPPORT_TABLE)->where('installation_id', $installationId)->where('session_fingerprint', $fingerprint)->first();
        if ($found !== null) {
            if ((bool) $found->revoked || (int) $found->user_id !== $adminId || (int) $found->server_id !== $serverId) { throw new \RuntimeException('federation_revoked'); }
            Capsule::table(self::SUPPORT_TABLE)->where('id', $found->id)->update(['expires_at' => time() + 9 * 3600]); return;
        }
        Capsule::table(self::SUPPORT_TABLE)->insert(['server_id' => $serverId, 'user_id' => $adminId, 'installation_id' => $installationId,
            'session_fingerprint' => $fingerprint, 'revoked' => false, 'delivered' => false, 'expires_at' => time() + 9 * 3600]);
    }
    public static function onLogout(array $vars): void
    {
        try {
            // A return from Login as Owner may fire UserLogout while the
            // administrator session remains present, so revoke both actors.
            self::onAdminLogout($vars);
            $userId = (int) ($vars['user']->id ?? 0);
            if ($userId <= 0 || !Capsule::schema()->hasTable(self::TABLE)) { return; }
            $sessions = array_filter([session_id(), (string) ($_COOKIE[session_name()] ?? '')], static fn ($value) => strlen($value) >= 16);
            $fingerprints = array_map(static fn ($value) => FederationConfig::encode(hash('sha256', $value, true)), $sessions);
            $query = Capsule::table(self::TABLE)->where('user_id', $userId)->where('revoked', false);
            $exact = $fingerprints === [] ? null : (clone $query)->whereIn('session_fingerprint', $fingerprints);
            // WHMCS can clear/rotate its session before invoking the hook. In
            // that case revoke only this native user's tracked Vibe sessions.
            if ($exact !== null && $exact->exists()) { $query = $exact; }
            $query->update(['revoked' => true]);
            self::flush(10);
        } catch (\Throwable) { Logger::logCall('FederationLogout', [], ['error' => 'logout_delivery_pending']); }
    }
    public static function onAdminLogout(array $vars): void
    {
        FederationConfig::resetSupportSession();
        try {
            $adminId = filter_var($vars['adminid'] ?? ($_SESSION['adminid'] ?? null), FILTER_VALIDATE_INT);
            if ($adminId === false || $adminId <= 0 || !Capsule::schema()->hasTable(self::SUPPORT_TABLE)) { return; }
            Capsule::table(self::SUPPORT_TABLE)->where('user_id', (int) $adminId)->where('revoked', false)->update(['revoked' => true]);
            self::flush(10);
        } catch (\Throwable) { Logger::logCall('FederationLogout', [], ['error' => 'logout_delivery_pending']); }
    }
    public static function flush(int $limit = 50): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) { return; }
        foreach ([self::TABLE, self::SUPPORT_TABLE] as $table) {
          if (!Capsule::schema()->hasTable($table)) { continue; }
          $pending = Capsule::table($table)->where('revoked', true)->where('delivered', false)->where('expires_at', '>', time())->limit($limit)->get();
          foreach ($pending as $row) {
            try {
                $client = ServerConfig::clientForServerId((int) $row->server_id);
                if ($client === null) { continue; }
                $result = $client->post('/vibe/federation/logout', ['installationId' => $row->installation_id, 'sessionFingerprint' => $row->session_fingerprint]);
                if (($result['revoked'] ?? false) === true) { Capsule::table($table)->where('id', $row->id)->update(['delivered' => true]); }
            } catch (\Throwable) { /* Retained for the next native cron, no secrets in logs. */ }
          }
          Capsule::table($table)->where('expires_at', '<', time())->delete();
        }
    }
}
