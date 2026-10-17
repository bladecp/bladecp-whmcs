<?php
declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp\Admin;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\FederationConfig;
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

final class FederationView
{
    public static function render(string $base, string $message = ''): string
    {
        $context = AdminServerContext::resolve();
        $serverId = (int) ($context['serverId'] ?? 0);
        if (($context['state'] ?? '') !== 'configured' || $serverId <= 0) { return ''; }
        $public = FederationConfig::registration($serverId);
        $body = '<p>' . Layout::escape(I18n::t('federation.description')) . '</p>';
        if ($message !== '') { $body .= '<p role="status">' . Layout::escape(I18n::t($message)) . '</p>'; }
        $body .= '<p><strong>' . Layout::escape(I18n::t(FederationConfig::enabled($serverId) ? 'federation.enabled' : 'federation.disabled')) . '</strong></p>';
        if ($public !== null) {
            $body .= '<label for="bcp-federation-registration">' . Layout::escape(I18n::t('federation.publicRegistration')) . '</label>'
                . '<textarea id="bcp-federation-registration" class="form-control" rows="10" readonly spellcheck="false">'
                . Layout::escape(json_encode($public, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</textarea>';
        }
        $actions = $public === null ? ['generate'] : ['enable', 'disable', 'rotate'];
        foreach ($actions as $action) {
            $body .= '<form method="post" action="' . Layout::escape($base) . '" class="bcp-actions">' . Layout::token()
                . '<input type="hidden" name="action" value="federation-' . $action . '">'
                . '<input type="hidden" name="federation_server_id" value="' . $serverId . '">';
            if ($action === 'rotate' || $action === 'enable') {
                $body .= '<label><input type="checkbox" name="federation_confirm" value="1" required> '
                    . Layout::escape(I18n::t('federation.confirm.' . $action)) . '</label> ';
            }
            $body .= '<button class="btn btn-default" type="submit">' . Layout::escape(I18n::t('federation.' . $action)) . '</button></form>';
        }
        return Layout::card(Layout::header(I18n::t('federation.title'), '', false), $body);
    }

    /** Existing admin controller validates the WHMCS token before calling. */
    public static function handle(string $action): bool
    {
        $context = AdminServerContext::resolve();
        $serverId = (int) ($context['serverId'] ?? 0);
        if (($context['state'] ?? '') !== 'configured' || $serverId <= 0 || $serverId !== (int) ($_POST['federation_server_id'] ?? 0)) { return false; }
        if (in_array($action, ['enable', 'rotate'], true) && ($_POST['federation_confirm'] ?? '') !== '1') { return false; }
        return match ($action) {
            'generate' => FederationConfig::generate($serverId), 'rotate' => FederationConfig::generate($serverId, true),
            'enable' => FederationConfig::setEnabled($serverId, true), 'disable' => FederationConfig::setEnabled($serverId, false), default => false,
        };
    }
}
