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
        $body = '<div class="bcp-diagnostic-hero"><span class="bcp-eyebrow">VERIFICAÇÕES SEGURAS</span>'
            . '<h4>Entenda o estado antes de fazer uma alteração.</h4><p>Os indicadores não mudam produtos, pedidos ou credenciais. Quando algum dado não puder ser conferido, ele aparece como indisponível, sem assumir que está tudo certo.</p></div>';
        $body .= self::checks($base, $diagnostics) . self::readiness($base, $readiness);
        $body .= '<section class="bcp-glossary" aria-labelledby="bcp-glossary-title"><h4 id="bcp-glossary-title">Guia rápido</h4>'
            . '<dl><dt>Pronto</dt><dd>A verificação necessária foi concluída e não há alerta conhecido.</dd>'
            . '<dt>Atenção</dt><dd>Há uma pendência ou uma informação que merece revisão antes do próximo passo.</dd>'
            . '<dt>Indisponível</dt><dd>O módulo não conseguiu confirmar a informação agora. Verifique a conexão e tente novamente.</dd></dl></section>';
        $body .= '<p class="bcp-docs-help">Precisa de mais contexto? '
            . Layout::docsLink('Consulte a documentação BladeCP') . '</p>';

        return Layout::card(
            Layout::header('Diagnóstico', 'Verificações de leitura para facilitar a configuração e explicar o que precisa de atenção.'),
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
            $rows .= '<li><span class="bcp-diag-state is-' . Layout::escape($state) . '">' . Layout::escape($state) . '</span><div><strong>'
                . Layout::escape((string) ($check['title'] ?? 'Verificação')) . '</strong><p>'
                . Layout::escape((string) ($check['detail'] ?? '')) . '</p><small>'
                . Layout::escape((string) ($check['checkedAt'] ?? '')) . '</small></div><a class="btn btn-default" href="' . Layout::escape($href) . '">'
                . Layout::escape((string) ($check['action'] ?? 'Abrir')) . '</a></li>';
        }
        if ($rows === '') {
            $rows = '<li><span class="bcp-diag-state is-muted">indisponível</span><div><strong>As verificações não puderam ser carregadas.</strong><p>Tente novamente dentro do WHMCS.</p></div></li>';
        }
        return '<section class="bcp-diagnostic-section bcp-diagnostic-overview"><div class="bcp-section-heading"><div><span class="bcp-eyebrow">ESTADO GERAL: ' . Layout::escape($overall) . '</span><h4>Conexão, URL pública, catálogo e rotina</h4><p>Inclui credencial, conectividade, URL HTTPS do receiver, assinatura, atualização do catálogo, cron e integridade dos produtos.</p></div></div><ul class="bcp-diagnostic-list">' . $rows . '</ul></section>';
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
            $rows .= '<li><span class="bcp-diag-state ' . ($ready ? 'is-ok' : 'is-attention') . '">' . ($ready ? 'pronto' : 'pendente')
                . '</span><div><strong>' . Layout::escape((string) ($step['label'] ?? 'Etapa')) . '</strong></div></li>';
        }
        return $rows === '' ? '' : '<section class="bcp-diagnostic-section"><div class="bcp-section-heading"><div><h4>Prontidão da integração</h4><p>Resumo do roteiro de configuração do módulo.</p></div><a class="btn btn-default" href="' . Layout::escape($base . '&action=overview') . '">Ver roteiro</a></div><ul class="bcp-diagnostic-list">' . $rows . '</ul></section>';
    }
}
