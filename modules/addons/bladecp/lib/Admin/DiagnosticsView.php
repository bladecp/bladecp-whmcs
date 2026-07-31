<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Module\Addon\Bladecp\Diagnostics;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Renderização somente leitura da aba Diagnóstico. */
final class DiagnosticsView
{
    public static function render(string $base): string
    {
        $diagnostics = [];
        if (class_exists(Diagnostics::class)) {
            try {
                $diagnostics = Diagnostics::snapshot();
            } catch (\Throwable) {
                $diagnostics = [];
            }
        }
        $readiness = self::snapshot(
            'WHMCS\\Module\\Addon\\Bladecp\\IntegrationReadiness',
            ['snapshot', 'inspect', 'load']
        );
        $body = '<div class="bcp-diagnostic-hero"><span class="bcp-eyebrow">'
            . Layout::escape(I18n::t('diagnostics.hero.eyebrow')) . '</span>'
            . '<h4>' . Layout::escape(I18n::t('diagnostics.hero.title')) . '</h4><p>'
            . Layout::escape(I18n::t('diagnostics.hero.description')) . '</p></div>';
        $body .= self::checks($base, $diagnostics) . self::readiness($base, $readiness);
        $body .= '<section class="bcp-glossary" aria-labelledby="bcp-glossary-title"><h4 id="bcp-glossary-title">'
            . Layout::escape(I18n::t('diagnostics.glossary.title')) . '</h4>'
            . '<dl><dt>' . Layout::escape(I18n::t('diagnostics.state.ready')) . '</dt><dd>'
            . Layout::escape(I18n::t('diagnostics.glossary.ready')) . '</dd>'
            . '<dt>' . Layout::escape(I18n::t('diagnostics.state.attention')) . '</dt><dd>'
            . Layout::escape(I18n::t('diagnostics.glossary.attention')) . '</dd>'
            . '<dt>' . Layout::escape(I18n::t('diagnostics.state.unavailable')) . '</dt><dd>'
            . Layout::escape(I18n::t('diagnostics.glossary.unavailable')) . '</dd></dl></section>';
        $body .= '<p class="bcp-docs-help">' . Layout::escape(I18n::t('diagnostics.docs.prefix')) . ' '
            . Layout::docsLink(I18n::t('diagnostics.docs.link')) . '</p>';

        return Layout::card(
            Layout::header(I18n::t('diagnostics.header.title'), I18n::t('diagnostics.header.description')),
            Layout::tabs($base, 'diagnostics') . $body
        );
    }

    /** @param list<string> $methods @return array<string,mixed> */
    private static function snapshot(string $class, array $methods): array
    {
        if (!class_exists($class)) {
            return [];
        }
        foreach ($methods as $method) {
            if (!method_exists($class, $method)) {
                continue;
            }
            try {
                $value = $class::$method();
                if (is_array($value)) {
                    return $value;
                }
            } catch (\Throwable) {
                return [];
            }
        }
        return [];
    }

    /** @param array<string,mixed> $diagnostics */
    private static function checks(string $base, array $diagnostics): string
    {
        $checks = is_array($diagnostics['checks'] ?? null) ? $diagnostics['checks'] : [];
        $overall = (string) ($diagnostics['overall'] ?? 'unknown');
        $rows = '';
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $href = match ((string) ($check['id'] ?? '')) {
                'credential', 'api' => $base . '&action=setup',
                'receiver_url', 'webhook' => $base . '&action=webhooks',
                'catalog', 'integrity' => $base . '&action=planos',
                default => $base . '&action=operation',
            };
            $state = (string) ($check['state'] ?? 'unknown');
            $rows .= '<li><span class="bcp-diag-state is-' . Layout::escape($state) . '">' . Layout::escape(self::stateLabel($state)) . '</span><div><strong>'
                . Layout::escape((string) ($check['title'] ?? I18n::t('diagnostics.check.fallbackTitle'))) . '</strong><p>'
                . Layout::escape((string) ($check['detail'] ?? '')) . '</p><small>'
                . Layout::escape((string) ($check['checkedAt'] ?? '')) . '</small></div><a class="btn btn-default" href="' . Layout::escape($href) . '">'
                . Layout::escape((string) ($check['action'] ?? I18n::t('diagnostics.actions.open'))) . '</a></li>';
        }
        if ($rows === '') {
            $rows = '<li><span class="bcp-diag-state is-muted">'
                . Layout::escape(I18n::t('diagnostics.state.unavailable')) . '</span><div><strong>'
                . Layout::escape(I18n::t('diagnostics.checks.emptyTitle')) . '</strong><p>'
                . Layout::escape(I18n::t('diagnostics.checks.emptyDescription')) . '</p></div></li>';
        }
        return '<section class="bcp-diagnostic-section bcp-diagnostic-overview"><div class="bcp-section-heading"><div><span class="bcp-eyebrow">'
            . Layout::escape(I18n::t('diagnostics.checks.overall', ['state' => self::stateLabel($overall)]))
            . '</span><h4>' . Layout::escape(I18n::t('diagnostics.checks.title')) . '</h4><p>'
            . Layout::escape(I18n::t('diagnostics.checks.description')) . '</p></div></div><ul class="bcp-diagnostic-list">'
            . $rows . '</ul></section>';
    }

    /** @param array<string,mixed> $readiness */
    private static function readiness(string $base, array $readiness): string
    {
        $steps = is_array($readiness['steps'] ?? null) ? $readiness['steps'] : [];
        $rows = '';
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            $ready = !empty($step['ready']);
            $rows .= '<li><span class="bcp-diag-state ' . ($ready ? 'is-ok' : 'is-attention') . '">'
                . Layout::escape(I18n::t($ready ? 'diagnostics.state.ready' : 'diagnostics.state.pending'))
                . '</span><div><strong>' . Layout::escape((string) ($step['label'] ?? I18n::t('diagnostics.readiness.fallbackStep')))
                . '</strong></div></li>';
        }
        return $rows === '' ? '' : '<section class="bcp-diagnostic-section"><div class="bcp-section-heading"><div><h4>'
            . Layout::escape(I18n::t('diagnostics.readiness.title')) . '</h4><p>'
            . Layout::escape(I18n::t('diagnostics.readiness.description')) . '</p></div><a class="btn btn-default" href="'
            . Layout::escape($base . '&action=overview') . '">'
            . Layout::escape(I18n::t('diagnostics.actions.viewGuide')) . '</a></div><ul class="bcp-diagnostic-list">'
            . $rows . '</ul></section>';
    }

    private static function stateLabel(string $state): string
    {
        $key = match ($state) {
            'ok', 'ready' => 'diagnostics.state.ready',
            'attention', 'warning' => 'diagnostics.state.attention',
            'pending' => 'diagnostics.state.pending',
            'unknown', 'unavailable', 'muted' => 'diagnostics.state.unavailable',
            default => '',
        };
        return $key === '' ? $state : I18n::t($key);
    }
}
