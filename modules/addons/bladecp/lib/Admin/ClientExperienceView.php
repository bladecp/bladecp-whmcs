<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Module\Addon\Bladecp\ClientExperienceSettings;
use WHMCS\Module\Addon\Bladecp\FastDeploy\BillingSettings;
use WHMCS\Module\Addon\Bladecp\VibePromptContext;
use WHMCS\Module\Addon\Bladecp\FastDeployPolicy;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class ClientExperienceView
{
    public static function render(string $base, string $error = ''): string
    {
        $context = AdminServerContext::resolve();
        $body = '';
        if ($error !== '') {
            $body .= '<p class="bcp-flash bcp-flash-no" role="alert">' . Layout::escape($error) . '</p>';
        } elseif (($_GET['saved'] ?? '') === '1') {
            $body .= '<p class="bcp-flash bcp-flash-ok" role="status">' . Layout::escape(I18n::t('experience.saved')) . '</p>';
        }
        if ($context['state'] !== 'configured' || $context['serverId'] === null) {
            $body .= '<p>' . Layout::escape(I18n::t('experience.select_server')) . '</p><a href="'
                . Layout::escape($base . '&action=setup') . '">' . Layout::escape(I18n::t('layout.tabs.setup')) . '</a>';
        } else {
            $serverId = $context['serverId'];
            $server = $context['server'];
            $client = ServerConfig::clientForServerId($serverId);
            $knownEnvironment = $client !== null && VibePromptContext::endpoint($client->baseUrl()) !== null;
            $body .= '<div class="bcp-experience-context">' . Layout::icon('server')
                . '<strong>' . Layout::escape($server['name'] ?? '') . '</strong><span>'
                . Layout::escape($server['hostname'] ?? '') . '</span></div>';
            $details = '<details class="bcp-experience-details"><summary>'
                . Layout::escape(I18n::t('experience.requirements_title')) . '</summary><p>'
                . Layout::escape(I18n::t('experience.requirements')) . '</p><p>'
                . Layout::escape(I18n::t('experience.compatibility')) . '</p></details>';
            if (!$knownEnvironment) {
                $details .= '<p class="bcp-flash bcp-flash-no" role="status">'
                    . Layout::escape(I18n::t('experience.unknown_environment')) . '</p>';
            }
            $body .= '<div class="bcp-experience-features">'
                . self::feature($base, $serverId, 'vibe', 'vibe_prompt', 'BladeCP Vibe', 'layers',
                    'vibe', ClientExperienceSettings::vibePromptEnabled($serverId), $details)
                . self::feature($base, $serverId, 'fast-deploy', 'fast_deploy', 'Fast Deploy', 'download',
                    'deploy', ClientExperienceSettings::fastDeployEnabled($serverId))
                . self::feature($base, $serverId, 'wordpress', 'wordpress', 'WordPress Toolkit', 'package',
                    'wordpress', ClientExperienceSettings::wordPressEnabled($serverId))
                . self::feature($base, $serverId, 'migration-billing', 'migration_billing', I18n::t('experience.migration_billing_title'),
                    'receipt', 'migration_billing', BillingSettings::enabled($serverId))
                . '</div>' . FastDeployPolicyView::render($base, $serverId);

        }
        return Layout::tabs($base, 'client-experience')
            . Layout::card(Layout::header(I18n::t('layout.tabs.client_experience'), I18n::t('experience.description')),
                '<div class="bcp-experiences">' . $body . '</div>')
            . '<script src="../modules/addons/bladecp/assets/admin-client-experience.js?v=1" defer></script>';
    }

    private static function feature(string $base, int $serverId, string $feature, string $field,
        string $title, string $icon, string $key, bool $enabled, string $details = ''): string
    {
        return '<section class="bcp-experience-feature"><div class="bcp-experience-feature-head">'
            . '<span class="bcp-experience-icon">' . Layout::icon($icon) . '</span><h4>'
            . Layout::escape($title) . '</h4></div><p class="bcp-experience-description">'
            . Layout::escape(I18n::t('experience.' . $key . '_description')) . '</p>' . $details
            . '<form method="post" action="' . Layout::escape($base) . '" class="bcp-experience-feature-form">'
            . Layout::token()
            . '<input type="hidden" name="action" value="client-experience-save">'
            . '<input type="hidden" name="experience_feature" value="' . $feature . '">'
            . '<input type="hidden" name="server_id" value="' . $serverId . '">'
            . '<label class="bcp-experience-toggle"><input type="checkbox" name="' . $field . '" value="1"'
            . ($enabled ? ' checked' : '') . '><span>'
            . Layout::escape(I18n::t('experience.' . $key . '_label')) . '</span></label>'
            . '<button type="submit" class="btn btn-default">'
            . Layout::escape(I18n::t('experience.save')) . '</button></form></section>';
    }

    /** Returns an error message or an empty string on successful persistence. */
    public static function save(): string
    {
        if (!function_exists('check_token')) {
            return I18n::t('admin.common.invalid_token');
        }
        try {
            // Native WHMCS may return void on success and terminate on failure.
            if (check_token('WHMCS.admin.default') === false) {
                return I18n::t('admin.common.invalid_token');
            }
        } catch (\Throwable) {
            return I18n::t('admin.common.invalid_token');
        }
        $postedId = filter_var($_POST['server_id'] ?? null, FILTER_VALIDATE_INT);
        $feature = $_POST['experience_feature'] ?? 'vibe';
        if (!in_array($feature, ['vibe', 'fast-deploy', 'fast-deploy-policy', 'wordpress', 'migration-billing'], true)) {
            return I18n::t('experience.failed');
        }
        $flag = $_POST[match ($feature) {
            'fast-deploy' => 'fast_deploy', 'wordpress' => 'wordpress', 'migration-billing' => 'migration_billing', default => 'vibe_prompt',
        }] ?? '0';
        $context = AdminServerContext::resolve();
        if ($postedId === false || $postedId <= 0 || $context['state'] !== 'configured'
            || $context['serverId'] !== $postedId || !in_array($flag, ['0', '1'], true)) {
            return I18n::t('experience.failed');
        }
        if ($feature === 'fast-deploy-policy') {
            return is_array($_POST['deploy_policy'] ?? null) && FastDeployPolicy::save($postedId, $_POST['deploy_policy'])
                ? '' : I18n::t('experience.policy_failed');
        }
        $saved = match ($feature) {
            'fast-deploy' => ClientExperienceSettings::saveFastDeploy($postedId, $flag === '1'),
            'wordpress' => ClientExperienceSettings::saveWordPress($postedId, $flag === '1'),
            'migration-billing' => BillingSettings::save($postedId, $flag === '1'),
            default => ClientExperienceSettings::saveVibePrompt($postedId, $flag === '1'),
        };
        return $saved ? '' : I18n::t('experience.failed');
    }
}
