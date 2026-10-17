<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\WordPress;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Bladecp\ClientCopy;
use WHMCS\Module\Addon\Bladecp\ClientExperienceSettings;
use WHMCS\Module\Addon\Bladecp\IdentityHandoff;
use WHMCS\Module\Addon\Bladecp\VibePromptCard;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Optional, bounded WordPress Toolkit client surface. */
final class ClientArea
{
    private static bool $registered = false;

    public static function registerHook(): void
    {
        if (self::$registered || !function_exists('add_hook')) return;
        self::$registered = true;
        add_hook('ClientAreaHomepage', 3, static function (): string {
            if (!self::available()) return '';
            return self::card(VibePromptCard::basePath((string) ($GLOBALS['CONFIG']['SystemURL'] ?? '')));
        });
    }

    /** Homepage card, in the customer's language. */
    public static function card(string $basePath, ?string $language = null): string
    {
        $copy = ClientCopy::domain('wordpress', $language);
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="panel panel-default"><div class="panel-body"><strong>' . $escape($copy['card_title']) . '</strong><p>'
            . $escape($copy['card_text']) . '</p><a href="' . $escape($basePath . '/index.php?m=bladecp&action=wordpress') . '">'
            . $escape($copy['card_open']) . '</a></div></div>';
    }

    public static function available(): bool
    {
        $identity = IdentityHandoff::currentIdentity();
        return $identity !== null && method_exists(ClientExperienceSettings::class, 'wordPressEnabled')
            && self::services($identity['clientId']) !== [];
    }

    /** @return array<string,mixed> */
    public static function render(): array
    {
        self::noStore();
        $identity = IdentityHandoff::currentIdentity();
        if (isset($_REQUEST['wordpress_ajax'])) {
            self::ajax($identity);
        }
        $services = $identity === null ? [] : self::services($identity['clientId']);
        $requested = filter_var($_GET['service'] ?? null, FILTER_VALIDATE_INT);
        $service = $requested !== false ? ($services[(int) $requested] ?? null) : (count($services) === 1 ? reset($services) : null);
        $state = $service === null ? null : self::status($service);
        $copy = ClientCopy::domain('wordpress');
        $csrf = self::csrfToken();
        $systemUrl = $GLOBALS['CONFIG']['SystemURL'] ?? '';
        $basePath = VibePromptCard::basePath(is_string($systemUrl) ? $systemUrl : '');
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        try {
            require dirname(__DIR__, 2) . '/templates/wordpress-page.php';
            $html = (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        return [
            'pagetitle' => $copy['title'],
            'breadcrumb' => ['index.php?m=bladecp&action=wordpress' => $copy['title']],
            'templatefile' => 'wordpress',
            'requirelogin' => true,
            'forcessl' => self::forceSsl(),
            'vars' => ['wordpressHtml' => $html, 'wordpressAvailable' => self::available()],
        ];
    }

    /** @return array<int,array{id:int,name:string,serverId:int,provisionUuid:string}> */
    private static function services(int $clientId): array
    {
        if ($clientId <= 0 || !class_exists(Capsule::class)) return [];
        try {
            $schema = Capsule::schema();
            foreach (['tblhosting', 'tblproducts', 'tblcustomfields', 'tblcustomfieldsvalues'] as $table) {
                if (!$schema->hasTable($table)) return [];
            }
            $rows = Capsule::table('tblhosting')->where('userid', $clientId)
                ->whereIn('domainstatus', ['Active'])->get();
            $out = [];
            foreach ($rows as $row) {
                $serviceId = (int) ($row->id ?? 0); $serverId = (int) ($row->server ?? 0); $productId = (int) ($row->packageid ?? 0);
                $product = $productId > 0 ? Capsule::table('tblproducts')->where('id', $productId)->first() : null;
                if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp'
                    || (string) ($product->configoption1 ?? '') !== 'stack-container') continue;
                $uuid = self::customField($serviceId, $productId, 'BladeCP Provision UUID');
                if ($serviceId <= 0 || $serverId <= 0 || !self::uuid($uuid)
                    || !ClientExperienceSettings::wordPressEnabled($serverId)) continue;
                $out[$serviceId] = ['id' => $serviceId, 'name' => trim((string) ($product->name ?? '')) ?: ClientCopy::text('wordpress', 'service_fallback', ['id' => $serviceId]), 'serverId' => $serverId, 'provisionUuid' => $uuid];
            }
            return $out;
        } catch (\Throwable) { return []; }
    }

    /** @param array{id:int,name:string,serverId:int,provisionUuid:string} $service @return array<string,mixed> */
    private static function status(array $service): array
    {
        try {
            $client = ServerConfig::clientForServerId($service['serverId']);
            if ($client === null) return ['available' => false, 'reason' => 'connection_unavailable'];
            $path = '/provisions/' . rawurlencode($service['provisionUuid']) . '/wordpress?external_service_id=' . rawurlencode((string) $service['id']);
            $data = $client->get($path);
            return is_array($data) ? $data : ['available' => false, 'reason' => 'connection_unavailable'];
        } catch (\Throwable) { return ['available' => false, 'reason' => 'connection_unavailable']; }
    }

    private static function customField(int $serviceId, int $productId, string $name): string
    {
        $field = Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $productId)->where('fieldname', $name)->first();
        if ($field === null || empty($field->adminonly)) return '';
        $row = Capsule::table('tblcustomfieldsvalues')->where('fieldid', (int) ($field->id ?? 0))->where('relid', $serviceId)->first();
        return trim((string) ($row->value ?? ''));
    }

    private static function uuid(string $value): bool { return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1; }
    private static function csrfToken(): string
    {
        if (!function_exists('generate_token')) return '';
        $html = (string) generate_token('WHMCS.default');
        return preg_match('/\\bvalue=["\']([^"\']*)["\']/', $html, $match) === 1
            ? html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')
            : '';
    }
    private static function noStore(): void { if (!headers_sent()) { header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache'); header('Referrer-Policy: same-origin'); } }
    private static function forceSsl(): bool { $host = parse_url((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), PHP_URL_HOST); return !in_array($host, ['localhost', '127.0.0.1', 'whmcs9.test'], true); }
    private static function ajax(?array $identity): never
    {
        $reply = ['ok' => false, 'error' => 'unavailable'];
        try {
            $serviceId = filter_var($_REQUEST['service'] ?? null, FILTER_VALIDATE_INT);
            $services = $identity === null ? [] : self::services((int) $identity['clientId']);
            $service = $serviceId === false ? null : ($services[(int) $serviceId] ?? null);
            if ($service === null) throw new \RuntimeException('unavailable');
            $client = ServerConfig::clientForServerId($service['serverId']); if ($client === null) throw new \RuntimeException('unavailable');
            $base = '/provisions/' . rawurlencode($service['provisionUuid']) . '/wordpress'; $query = '?external_service_id=' . rawurlencode((string) $service['id']);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $command = (string) ($_GET['command'] ?? ''); if (!self::uuid($command)) throw new \RuntimeException('invalid');
                $reply = ['ok' => true, 'data' => $client->get($base . '/commands/' . rawurlencode($command) . $query)];
            } else {
                if (!function_exists('check_token') || check_token('WHMCS.default') === false) throw new \RuntimeException('csrf');
                $action = (string) ($_POST['wordpress_action'] ?? ''); $paths = ['dashboard' => '/dashboard', 'core' => '/core/update'];
                if (in_array($action, ['plugin', 'theme'], true)) { $slug = (string) ($_POST['slug'] ?? ''); if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) !== 1) throw new \RuntimeException('invalid'); $paths[$action] = '/' . $action . 's/' . rawurlencode($slug) . '/update'; }
                if (!isset($paths[$action])) throw new \RuntimeException('invalid');
                $reply = ['ok' => true, 'data' => $client->post($base . $paths[$action] . $query, [])];
            }
        } catch (\Throwable $error) { $reply = ['ok' => false, 'error' => $error->getMessage() === 'csrf' ? 'csrf' : 'unavailable']; }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode($reply, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); exit;
    }
}
