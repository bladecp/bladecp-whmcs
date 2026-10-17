<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Bladecp\FastDeployPolicy;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

final class FastDeployPolicyView
{
    public static function render(string $base, int $serverId): string
    {
        $config = FastDeployPolicy::load($serverId);
        $products = FastDeployPolicy::products($serverId);
        $default = $config['default'] ?? ['mode' => '', 'trial_days' => '', 'payment_days' => ''];
        $body = '<section class="bcp-experience-policy" aria-labelledby="bcp-policy-title">'
            . '<header class="bcp-experience-policy-head"><span class="bcp-experience-icon">' . Layout::icon('receipt')
            . '</span><div><h4 id="bcp-policy-title">' . self::t('policy_title') . '</h4><p>'
            . self::t('policy_description') . '</p></div></header>'
            . '<form method="post" action="' . Layout::escape($base) . '" data-bcp-commercial-form>'
            . Layout::token()
            . '<input type="hidden" name="action" value="client-experience-save">'
            . '<input type="hidden" name="experience_feature" value="fast-deploy-policy">'
            . '<input type="hidden" name="server_id" value="' . $serverId . '">'
            . self::fields('deploy_policy[default]', $default)
            . '<fieldset class="bcp-experience-section"><legend>' . self::t('policy_products') . '</legend>'
            . '<p class="bcp-experience-help">' . self::t('products_help') . '</p>';
        if ($products === []) {
            $body .= '<p class="bcp-experience-empty">' . self::t('policy_no_products') . '</p>';
        }
        $groupNames = [];
        try {
            foreach (Capsule::table('tblproductgroups')->get(['id', 'name']) as $group) {
                $groupNames[(int) $group->id] = (string) $group->name;
            }
        } catch (\Throwable) {
            // Optional group labels must not hide eligible products.
        }
        $groups = [];
        foreach ($products as $id => $product) {
            $groups[$product['productGroupId'] ?? 0][$id] = $product;
        }
        foreach ($groups as $groupId => $members) {
            $title = $groupNames[$groupId] ?? I18n::t('experience.products_other');
            $body .= '<section class="bcp-experience-product-group"><h5>' . Layout::escape($title)
                . '<span class="bcp-experience-count">' . count($members) . '</span></h5>'
                . '<div class="bcp-experience-products">';
            foreach ($members as $id => $product) {
                $selected = $config !== null && array_key_exists($id, $config['products']);
                $body .= '<label class="bcp-experience-product"><input type="checkbox" name="deploy_policy[products]['
                    . $id . '][enabled]" value="1"' . ($selected ? ' checked' : '') . '><span><strong>'
                    . Layout::escape($product['name']) . '</strong>'
                    . (!$product['visible'] ? '<small>' . self::t('policy_hidden') . '</small>' : '')
                    . '</span><span class="bcp-experience-product-id">#' . $id . '</span></label>';
            }
            $body .= '</div></section>';
        }
        return $body . '</fieldset><footer class="bcp-experience-policy-footer"><p>'
            . self::t('policy_integration_pending') . '</p><button type="submit" class="btn btn-primary">'
            . self::t('policy_save') . '</button></footer></form></section>';
    }

    private static function fields(string $name, array $rule): string
    {
        $select = '<select id="bcp-policy-mode" name="' . $name . '[mode]" data-bcp-policy-mode>'
            . '<option value="">' . self::t('policy_choose') . '</option>';
        foreach (FastDeployPolicy::MODES as $mode) {
            $select .= '<option value="' . $mode . '"' . (($rule['mode'] ?? '') === $mode ? ' selected' : '') . '>'
                . self::t('policy_' . $mode) . '</option>';
        }
        $body = '<fieldset class="bcp-experience-section"><legend>' . self::t('policy_default') . '</legend>'
            . '<div class="bcp-experience-fields">'
            . self::field('bcp-policy-mode', self::t('policy_mode'), $select . '</select>');
        foreach (['trial_days' => 'trial', 'payment_days' => 'payment_after'] as $key => $mode) {
            $body .= self::field('bcp-policy-' . $key, self::t('policy_' . $key),
                self::number('bcp-policy-' . $key, $name . '[' . $key . ']', $rule[$key] ?? '', 1),
                ' data-bcp-policy-show="' . $mode . '"');
        }
        $body .= '</div><p class="bcp-experience-help" data-bcp-policy-show="trial payment_after">'
            . self::t('policy_days_help') . '</p></fieldset>';
        $expiry = $rule['expiry'] ?? [];
        $select = '<select id="bcp-policy-expiry" name="' . $name . '[expiry][action]" data-bcp-policy-expiry>'
            . '<option value="">' . self::t('expiry_choose') . '</option>';
        foreach (FastDeployPolicy::EXPIRY_ACTIONS as $action) {
            $select .= '<option value="' . $action . '"' . (($expiry['action'] ?? '') === $action ? ' selected' : '') . '>'
                . self::t('expiry_' . $action) . '</option>';
        }
        $body .= '<fieldset class="bcp-experience-section" data-bcp-policy-show="trial payment_after"><legend>'
            . self::t('expiry_title') . '</legend><div class="bcp-experience-fields">'
            . self::field('bcp-policy-expiry', self::t('expiry_action'), $select . '</select>');
        foreach (['grace_days' => 0, 'retention_days' => 1] as $key => $minimum) {
            $body .= self::field('bcp-policy-' . $key, self::t('expiry_' . $key),
                self::number('bcp-policy-' . $key, $name . '[expiry][' . $key . ']', $expiry[$key] ?? '', $minimum),
                $key === 'retention_days' ? ' data-bcp-expiry-show="suspend_delete"' : '');
        }
        return $body . '</div><p class="bcp-experience-help">' . self::t('expiry_help') . '</p></fieldset>';
    }

    private static function number(string $id, string $name, mixed $value, int $minimum): string
    {
        return '<input id="' . $id . '" type="number" min="' . $minimum . '" max="365" step="1" name="'
            . $name . '" value="' . Layout::escape($value) . '">';
    }

    private static function field(string $id, string $label, string $control, string $attributes = ''): string
    {
        return '<div class="bcp-experience-field"' . $attributes . '><label class="bcp-fg-l" for="'
            . $id . '">' . $label . '</label><div class="bcp-fg-c">' . $control . '</div></div>';
    }

    private static function t(string $key): string
    {
        return Layout::escape(I18n::t('experience.' . $key));
    }
}
