<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Adds a narrowly scoped administrative link on one Vibe service page. */
final class VibeSupportAdminCta
{
    private static bool $registered = false;

    public static function registerHook(): void
    {
        if (self::$registered || !function_exists('add_hook')) {
            return;
        }
        self::$registered = true;
        add_hook('AdminAreaFooterOutput', 1, static function (): string {
            try {
                return self::render();
            } catch (\Throwable) {
                return '';
            }
        });
    }

    public static function render(): string
    {
        $serviceId = self::serviceIdFromRequest();
        if ($serviceId === null || VibeSupportLaunch::contextForService($serviceId) === null) {
            return '';
        }
        $url = 'addonmodules.php?module=bladecp&action=vibe-support&serviceid=' . $serviceId;
        $urlJson = json_encode($url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // The admin area footer hook does not carry the administrator language.
        $label = static fn (string $key): string => json_encode(
            Admin\I18n::system($key),
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        $groupJson = $label('admin.vibe_support.cta_group');
        $mainJson = $label('admin.vibe_support.cta_main_title');
        $moreJson = $label('admin.vibe_support.cta_more');
        $accountJson = $label('admin.vibe_support.cta_account');
        return <<<HTML
<script>(function(){
  var id = 'bladecp-vibe-support-actions';
  if (document.getElementById(id)) return;
  var main = document.querySelector('button[onclick*="runModuleCommand"][onclick*="singlesignon"]');
  if (!main || !main.parentNode) return;
  var group = document.createElement('div');
  group.id = id;
  group.className = 'btn-group';
  group.setAttribute('role', 'group');
  group.setAttribute('aria-label', {$groupJson});
  main.parentNode.insertBefore(group, main);
  group.appendChild(main);
  main.title = {$mainJson};
  var toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'btn btn-primary dropdown-toggle';
  toggle.setAttribute('data-toggle', 'dropdown');
  toggle.setAttribute('aria-haspopup', 'true');
  toggle.setAttribute('aria-expanded', 'false');
  toggle.setAttribute('aria-label', {$moreJson});
  toggle.innerHTML = '<span class="caret" aria-hidden="true"></span>';
  group.appendChild(toggle);
  var menu = document.createElement('ul');
  menu.className = 'dropdown-menu dropdown-menu-right';
  var item = document.createElement('li');
  var link = document.createElement('a');
  link.id = 'bladecp-vibe-support-link';
  link.href = {$urlJson};
  link.textContent = {$accountJson};
  item.appendChild(link); menu.appendChild(item); group.appendChild(menu);
})();</script>
HTML;
    }

    private static function serviceIdFromRequest(): ?int
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, 'clientsservices.php') === false) {
            return null;
        }
        $value = $_GET['id'] ?? null;
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : null;
    }
}
