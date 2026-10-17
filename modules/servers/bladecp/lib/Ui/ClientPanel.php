<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

use WHMCS\Module\Server\Bladecp\Helpers\ClientLocale;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Área do cliente BladeCP, com visão geral do serviço. Usa o mesmo sistema
 * visual administrativo, escopado em `.bcp-*`. Todo conteúdo é escapado e
 * falhas de consulta resultam em um estado indisponível, sem interromper a tela.
 *
 * `$d` (normalizado por ClientArea::run, todos opcionais):
 *  status, statusLabel, domain, plan, productName, location,
 *  components (array<{name,version,status}>), created,
 *  resources(assoc label=>valor), usageMetrics, combo, comboSource.
 */
final class ClientPanel
{
    /** Mapa status → variante de badge + chave de tradução. */
    private const STATUS = [
        'active' => ['is-ok', 'status_active'],
        'online' => ['is-ok', 'status_online'],
        'running' => ['is-ok', 'status_active'],
        'suspended' => ['is-warn', 'status_suspended'],
        'pending' => ['', 'status_pending'],
        'provisioning' => ['', 'status_provisioning'],
        'building' => ['', 'status_provisioning'],
        'pending_manual' => ['is-warn', 'status_pending_manual'],
        'terminated' => ['is-err', 'status_terminated'],
        'cancelled' => ['is-err', 'status_cancelled'],
        'failed' => ['is-err', 'status_failed'],
    ];

    public static function render(array $d, string $language = 'en'): string
    {
        $locale = ClientLocale::normalize($language);
        $t = static fn (string $key, array $replace = []): string =>
            ClientPanelCopy::text($locale, $key, $replace);
        $plural = static fn (
            string $singular,
            string $many,
            int $count
        ): string => ClientPanelCopy::plural(
            $locale,
            $singular,
            $many,
            $count
        );
        $h = static fn ($v): string => htmlspecialchars(
            (string) $v,
            ENT_QUOTES,
            'UTF-8'
        );

        $status = strtolower(trim((string) ($d['status'] ?? 'unknown')));
        [$bv, $statusKey] = self::STATUS[$status]
            ?? ['', 'status_unknown'];
        $statusLabel = isset(self::STATUS[$status])
            ? $t($statusKey)
            : ((string) ($d['statusLabel'] ?? '') ?: $t($statusKey));

        $domain = trim((string) ($d['domain'] ?? ''));
        $isVibe = ($d['productType'] ?? '') === 'vibe';
        $domainUrl = $domain !== ''
            ? 'https://' . preg_replace('#^https?://#i', '', $domain)
            : '';

        $i = 0;
        $row = static function (
            string $k,
            string $vHtml
        ) use (&$i): string {
            return '<div class="bcp-row" style="--bcp-i:' . $i++ . '">'
                . '<span class="bcp-row-k">' . $k . '</span>'
                . '<span class="bcp-row-v">' . $vHtml . '</span></div>';
        };
        $orDash = static fn (string $v): string =>
            $v !== '' ? $v : '<span class="muted">—</span>';

        $planLabel = trim(
            (string) ($d['plan'] ?? '')
            . ((string) ($d['productName'] ?? '') !== ''
                ? ' · ' . $d['productName'] : '')
        );
        $facts = $row($t('plan'), $orDash($h($planLabel)))
            . $row(
                $t('location'),
                $orDash($h((string) ($d['location'] ?? '')))
            );

        $combo = trim((string) ($d['combo'] ?? ''));
        if ($isVibe) {
            $facts = $row($t('plan'), $orDash($h($planLabel)))
                . $row($t('vibe_account'), $orDash($h((string) ($d['accountId'] ?? ''))))
                . $row($t('vibe_access'), $h($t('vibe_access_integrated')));
        }
        if ($combo !== '') {
            $comboSource = (string) ($d['comboSource'] ?? '') === 'customer'
                ? $t('combo_customer') : $t('combo_product');
            $facts .= $row(
                $t('combo_applied'),
                '<span class="bcp-pill on">' . $h($combo) . '</span>'
                    . '<small class="bcp-source">' . $h($comboSource) . '</small>'
            );
        }

        // Componentes: lista viva (nome + versão + saúde). Health:
        // healthy/online/active/running = verde; failed/degraded =
        // vermelho; resto = neutro.
        $comps = is_array($d['components'] ?? null) ? $d['components'] : [];
        $okSt = ['healthy', 'online', 'active', 'running'];
        $errSt = ['failed', 'degraded', 'error', 'unhealthy'];
        $items = '';
        $componentCount = 0;
        $healthyCount = 0;
        $errorCount = 0;
        foreach ($comps as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cn = $h(trim((string) ($c['name'] ?? '')));
            if ($cn === '') {
                continue;
            }
            $componentCount++;
            $cv = $h(trim((string) ($c['version'] ?? '')));
            $cs = strtolower(trim((string) ($c['status'] ?? '')));
            $isHealthy = in_array($cs, $okSt, true);
            $isError = in_array($cs, $errSt, true);
            $healthyCount += $isHealthy ? 1 : 0;
            $errorCount += $isError ? 1 : 0;
            $dotCls = $isHealthy ? 'is-ok' : ($isError ? 'is-err' : '');
            $stateLabel = $isHealthy ? $t('component_healthy')
                : ($isError
                    ? $t('component_attention')
                    : $t('component_unknown'));
            $items .= '<span class="bcp-component">'
                . '<span class="bcp-dot ' . $dotCls . '" aria-hidden="true"></span>'
                . '<span class="bcp-component-name">' . $cn . '</span>'
                . ($cv !== ''
                    ? '<span class="bcp-pill">' . $cv . '</span>' : '')
                . '<span class="bcp-sr">' . $stateLabel . '</span>'
                . '</span>';
        }

        if ($errorCount > 0) {
            $healthClass = 'is-err';
            $healthLabel = $plural(
                'health_attention_one',
                'health_attention_many',
                $errorCount
            );
        } elseif ($componentCount > 0 && $healthyCount === $componentCount) {
            $healthClass = 'is-ok';
            $healthLabel = $plural(
                'health_healthy_one',
                'health_healthy_many',
                $componentCount
            );
        } else {
            $healthClass = '';
            $healthLabel = $componentCount > 0
                ? $plural(
                    'health_configured_one',
                    'health_configured_many',
                    $componentCount
                )
                : $t('plan_default');
        }
        $componentsHtml = '<div class="bcp-section-heading">'
            . '<div><div class="bcp-sec">' . $t('active_configuration')
            . '</div><p>' . $t('components_description') . '</p></div>'
            . '<span class="bcp-health ' . $healthClass . '">'
            . $h($healthLabel) . '</span></div>'
            . ($items !== ''
                ? '<div class="bcp-component-grid">' . $items . '</div>'
                : '<div class="bcp-empty-state">'
                    . $t('plan_default') . '</div>');

        $created = self::localizedDate(
            $locale,
            trim((string) ($d['created'] ?? ''))
        );

        // Recursos (opcional) — 3 cards numa linha (pouco conteúdo).
        $res = is_array($d['resources'] ?? null) ? $d['resources'] : [];
        $resHtml = '';
        if ($res !== []) {
            $resHtml = '<div class="bcp-sec">' . $t('plan_resources') . '</div>'
                . '<div class="bcp-res">';
            foreach ($res as $k => $v) {
                $resourceLabel = self::localizedLabel(
                    $locale,
                    (string) $k,
                    true
                );
                $resHtml .= '<div class="bcp-res-c">'
                    . '<span class="bcp-res-v">' . $h((string) $v)
                    . '</span><span class="bcp-res-k">'
                    . $h($resourceLabel) . '</span></div>';
            }
            $resHtml .= '</div>';
        }

        $usage = is_array($d['usage'] ?? null) ? $d['usage'] : [];
        $usageMetrics = is_array($d['usageMetrics'] ?? null)
            ? $d['usageMetrics'] : [];
        $usageHtml = '';
        $usageStatus = strtolower(trim((string) ($d['usageStatus'] ?? '')));
        if ($usageMetrics !== []) {
            $usageHtml = '<div class="bcp-section-heading">'
                . '<div><div class="bcp-sec">' . $t('current_usage') . '</div>'
                . '<p>' . $t('usage_description') . '</p></div>'
                . '</div><div class="bcp-usage-grid">';
            foreach ($usageMetrics as $metric) {
                if (!is_array($metric)) {
                    continue;
                }
                $label = trim((string) ($metric['label'] ?? ''));
                $used = trim((string) ($metric['used'] ?? ''));
                $limit = trim((string) ($metric['limit'] ?? ''));
                $unit = trim((string) ($metric['unit'] ?? ''));
                if ($label === '' || ($used === '' && $limit === '')) {
                    continue;
                }
                $percent = is_numeric($metric['percent'] ?? null)
                    ? (float) $metric['percent'] : null;
                $barPercent = $percent !== null
                    ? max(0.0, min(100.0, $percent)) : null;
                $displayLabel = self::localizedLabel(
                    $locale,
                    $label,
                    false
                );
                $usageHtml .= '<article class="bcp-usage-card '
                    . ($usageStatus === 'critical' ? 'is-critical'
                        : ($usageStatus === 'warning' ? 'is-warning' : ''))
                    . '"><span class="bcp-usage-label">'
                    . $h($displayLabel) . '</span>'
                    . '<strong>'
                    . ($used !== '' ? $h($used . ' ' . $unit) : '—')
                    . ($limit !== '' ? '<small>' . $t('of') . ' '
                        . $h($limit . ' ' . $unit)
                        . '</small>' : '')
                    . '</strong>';
                if ($barPercent !== null) {
                    $decimal = ClientPanelCopy::decimalSeparator($locale);
                    $formattedPercent = number_format(
                        $percent,
                        1,
                        $decimal,
                        ''
                    );
                    $formattedPercent = rtrim(
                        rtrim($formattedPercent, '0'),
                        $decimal
                    );
                    $usageHtml .= '<span class="bcp-usage-track" aria-hidden="true">'
                        . '<span style="width:' . $h((string) $barPercent)
                        . '%"></span></span><small class="bcp-usage-percent">'
                        . $h($t('percent_used', [
                            'percent' => $formattedPercent,
                        ])) . '</small>';
                }
                $usageHtml .= '</article>';
            }
            if ($usageStatus === 'warning' || $usageStatus === 'critical') {
                $usageHtml .= '<div class="bcp-usage-alert">'
                    . $h($usageStatus === 'critical'
                        ? $t('usage_critical')
                        : $t('usage_warning')) . '</div>';
            }
            $usageHtml .= '</div>';
        } elseif ($usage !== []) {
            $usageHtml = '<div class="bcp-sec">' . $t('current_usage')
                . '</div><div class="bcp-grid">';
            foreach ($usage as $label => $value) {
                $usageHtml .= $row(
                    $h(self::localizedLabel(
                        $locale,
                        (string) $label,
                        false
                    )),
                    $h(self::localizedUsageValue(
                        $locale,
                        (string) $value
                    ))
                );
            }
            $usageHtml .= '</div>';
        }

        $operationHtml = '';
        $placement = trim((string) ($d['placement'] ?? ''));
        if ($placement !== '') {
            $placement = match ($placement) {
                'Aguardando definição' => $t('placement_pending'),
                'Definida' => $t('placement_assigned'),
                default => $placement,
            };
            $operationHtml .= $row($t('positioning'), $h($placement));
        }
        $penalty = trim((string) ($d['penalty'] ?? ''));
        if ($penalty !== '') {
            $operationHtml .= $row(
                $t('early_termination_penalty'),
                $h($penalty)
            );
        }
        if ($operationHtml !== '') {
            $operationHtml = '<div class="bcp-sec">' . $t('operation') . '</div>'
                . '<div class="bcp-grid">' . $operationHtml . '</div>';
        }

        $asOf = self::localizedDate(
            $locale,
            trim((string) ($d['usageAsOf'] ?? ''))
        );
        $activityHtml = '';
        if ($created !== '' || $asOf !== '') {
            $activityHtml = '<div class="bcp-sec">'
                . $t('service_activity') . '</div>'
                . '<div class="bcp-activity">'
                . ($created !== ''
                    ? '<div><span class="bcp-activity-dot is-ok"></span>'
                        . '<span>' . $t('provisioned') . '</span><strong>'
                        . $h($created)
                        . '</strong></div>' : '')
                . ($asOf !== ''
                    ? '<div><span class="bcp-activity-dot"></span>'
                        . '<span>' . $t('last_usage_read') . '</span><strong>'
                        . $h($asOf)
                        . '</strong></div>' : '')
                . '</div>';
        }

        // The provider issues an access link only for a running service;
        // the customer still signs in to the account that owns it.
        $panelServiceId = (int) ($d['serviceId'] ?? 0);
        $panelButton = !empty($d['panelAccess']) && $panelServiceId > 0
            && in_array($status, ['active', 'running', 'succeeded'], true)
            ? '<a class="bcp-btn" href="clientarea.php?action=productdetails&amp;id='
                . $panelServiceId . '&amp;dosinglesignon=1">'
                . Icons::get('server', 15) . $t('open_panel') . '</a>'
            : '';
        $siteButton = $domainUrl !== ''
            ? '<a class="bcp-btn" href="' . $h($domainUrl) . '" '
                . 'target="_blank" rel="noopener">'
                . Icons::get('external-link', 15) . $t('visit_site') . '</a>'
            : '';
        $actions = $panelButton . $siteButton !== ''
            ? '<div class="bcp-actions bcp-hero-actions">' . $panelButton . $siteButton . '</div>'
            : '';

        if ($isVibe) {
            $componentsHtml = '';
            $serviceId = (int) ($d['serviceId'] ?? 0);
            $actions = $serviceId > 0 && $status === 'active'
                ? '<div class="bcp-actions bcp-hero-actions"><a class="bcp-btn" data-bladecp-vibe-sso="true" href="clientarea.php?action=productdetails&amp;id='
                    . $serviceId . '&amp;dosinglesignon=1">'
                    . Icons::get('external-link', 15) . $t('vibe_open') . '</a></div>'
                : '';
        }

        return '<div class="bcp-shell"><style>'
            . Assets::css()
            . '</style>'
            . '<div class="bcp-head">'
            . ($isVibe ? Brand::vibeLogo(24) : Brand::logo(24))
            . '<span class="bcp-badge ' . $bv . '">'
            . $h($statusLabel) . '</span>'
            . '</div>'
            . '<div class="bcp-card">'
            . '<div class="bcp-hero">'
            . '<div class="bcp-hero-copy"><span>'
            . $t('provisioned_service') . '</span>'
            . '<strong class="bcp-hero-d">'
            . ($domain !== ''
                ? '<a href="' . $h($domainUrl) . '" target="_blank" '
                    . 'rel="noopener">' . $h($domain) . '</a>'
                : ($isVibe ? 'BladeCP Vibe' : $t('bladecp_service')))
            . '</strong>'
            . ($planLabel !== '' ? '<small>' . $h($planLabel) . '</small>' : '')
            . '</div>'
            . $actions
            . '</div>'
            . '<div class="bcp-card-bd">'
            . '<div class="bcp-sec">' . $t('service_context') . '</div>'
            . '<div class="bcp-grid bcp-context-grid">' . $facts . '</div>'
            . $componentsHtml
            . $resHtml
            . $usageHtml
            . $operationHtml
            . $activityHtml
            . '<div class="bcp-foot">'
            . Brand::logo(15)
            . '<span>' . $t('footer') . '</span>'
            . '</div>'
            . '</div></div></div>';
    }

    private static function localizedLabel(
        string $locale,
        string $label,
        bool $resource
    ): string {
        $normalized = strtolower(trim($label));
        if (in_array($normalized, ['disco', 'disk'], true)) {
            return ClientPanelCopy::text(
                $locale,
                $resource ? 'resource_disk' : 'usage_disk'
            );
        }
        if (in_array(
            $normalized,
            ['banda mensal', 'monthly bandwidth', 'bandwidth'],
            true
        )) {
            return ClientPanelCopy::text(
                $locale,
                'usage_bandwidth_monthly'
            );
        }
        return $label;
    }

    private static function localizedUsageValue(
        string $locale,
        string $value
    ): string {
        if (ClientLocale::normalize($locale) !== 'en') {
            return $value;
        }
        return preg_replace('/\s+de\s+/iu', ' of ', $value) ?? $value;
    }

    private static function localizedDate(string $locale, string $value): string
    {
        if ($value === '') {
            return '';
        }

        $date = null;
        if (preg_match(
            '/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?$/',
            $value,
            $parts
        ) === 1) {
            $date = \DateTimeImmutable::createFromFormat(
                '!d/m/Y H:i',
                sprintf(
                    '%s/%s/%s %s:%s',
                    $parts[1],
                    $parts[2],
                    $parts[3],
                    $parts[4] ?? '00',
                    $parts[5] ?? '00'
                )
            );
        } else {
            try {
                $date = new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return $value;
            }
        }

        if (!$date instanceof \DateTimeImmutable) {
            return $value;
        }
        return ClientLocale::normalize($locale) === 'en'
            ? $date->format('m/d/Y H:i')
            : $date->format('d/m/Y H:i');
    }
}
