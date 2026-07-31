<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Config\Loaders;
use WHMCS\Module\Server\Bladecp\Helpers\ProvisionView;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Painel administrativo BladeCP com informações de configuração e status.
 * É renderizado pelo módulo por `_AdminServicesTabFields`, em modo somente
 * leitura. Erros de consulta não interrompem a tela administrativa.
 */
final class AdminPanel
{
    /** HTML completo (style escopado + markup) pra um campo da aba. */
    public static function render(array $params): string
    {
        $client = Client::fromParams($params);

        // Status de conexão (GET /me) — fail-soft.
        $ok = false;
        $statusMsg = 'Não verificado';
        $cfgErr = $client->configError();
        if ($cfgErr !== null) {
            $statusMsg = $cfgErr;
        } else {
            try {
                $client->get('/me');
                $ok = true;
                $statusMsg = 'Conectado';
            } catch (\Throwable $e) {
                $statusMsg = self::trim((string) $e->getMessage(), 70)
                    ?: 'Falha na conexão';
            }
        }

        $base = $client->baseUrl();
        $host = trim((string) ($params['serverhostname'] ?? ''));
        $secure = !empty($params['serversecure']);
        $keyMask = self::maskKey((string) ($params['serveraccesshash'] ?? ''));

        $co = is_array($params['configoptions'] ?? null)
            ? $params['configoptions'] : [];
        $ptype = (string) ($co['BladeCP Product Type'] ?? '—');
        $plan = (string) ($co['BladeCP Plan'] ?? '');
        $loc = (string) ($co['Location'] ?? '');
        $comps = trim((string) ($co['BladeCP Components'] ?? ''));

        // Contagens do catálogo (cache 1h) — fail-soft.
        $plansN = self::count(static fn () => Loaders::plans());
        $locsN = self::count(static fn () => Loaders::locations());

        $badge = $ok ? 'is-ok' : ($cfgErr !== null ? 'is-err' : '');
        $i = 0;

        $h = static fn ($v): string => htmlspecialchars(
            (string) $v,
            ENT_QUOTES,
            'UTF-8'
        );
        $row = static function (
            string $k,
            string $vHtml,
            string $tip = ''
        ) use (&$i): string {
            $t = $tip === '' ? '' :
                '<span class="bcp-tip"><b tabindex="0" role="img" '
                . 'aria-label="Ajuda">?</b>'
                . '<span class="bcp-tip-pop" role="tooltip">'
                . $tip . '</span></span>';

            return '<div class="bcp-row" style="--bcp-i:' . $i++ . '">'
                . '<span class="bcp-row-k">' . $k . $t . '</span>'
                . '<span class="bcp-row-v">' . $vHtml . '</span></div>';
        };
        $mono = static fn (string $v): string =>
            '<span class="mono">' . $v . '</span>';
        $val = static fn (string $v): string =>
            $v !== '' ? $v : '<span class="muted">—</span>';

        $sslPill = $secure
            ? '<span class="bcp-pill on">SSL ativo</span>'
            : '<span class="bcp-pill off">Sem SSL</span>';

        $catVal = static function (string $sel, int $n) use ($h): string {
            $tag = $n > 0
                ? '<span class="bcp-pill on">' . $n . ' no catálogo</span>'
                : '<span class="bcp-pill off">catálogo indisponível</span>';

            return ($sel !== '' ? $h($sel) . ' ' : '') . $tag;
        };

        // ── Provisão VIVA (fonte única ProvisionView; só renderiza se
        // resolveu uma provisão de verdade). Responde "o que está
        // rodando", complementando o "Catálogo" (meta de config). ──
        $pv = ProvisionView::resolve($params);
        $provSection = '';
        if (!empty($pv['resolved'])) {
            $st = strtolower((string) ($pv['status'] ?? ''));
            $stMap = [
                'active' => ['Ativo', 'is-ok'],
                'online' => ['Online', 'is-ok'],
                'running' => ['Ativo', 'is-ok'],
                'suspended' => ['Suspenso', 'is-warn'],
                'pending' => ['Pendente', ''],
                'provisioning' => ['Provisionando', ''],
                'building' => ['Provisionando', ''],
                'terminated' => ['Encerrado', 'is-err'],
                'cancelled' => ['Cancelado', 'is-err'],
                'failed' => ['Falhou', 'is-err'],
            ];
            [$stLabel, $stVar] = $stMap[$st]
                ?? [ucfirst($st ?: 'Desconhecido'), ''];

            $okSt = ['healthy', 'online', 'active', 'running'];
            $errSt = ['failed', 'degraded', 'error', 'unhealthy'];
            $compsHtml = '';
            foreach ((array) ($pv['components'] ?? []) as $c) {
                if (!is_array($c) || ($c['name'] ?? '') === '') {
                    continue;
                }
                $cs = strtolower((string) ($c['status'] ?? ''));
                $dot = in_array($cs, $okSt, true) ? 'is-ok'
                    : (in_array($cs, $errSt, true) ? 'is-err' : '');
                $cv = (string) ($c['version'] ?? '');
                $compsHtml .= '<span class="bcp-comp">'
                    . '<span class="bcp-dot ' . $dot . '"></span>'
                    . $h((string) $c['name'])
                    . ($cv !== ''
                        ? ' <span class="bcp-pill">' . $h($cv)
                            . '</span>' : '')
                    . '</span>';
            }
            $resHtml = '';
            foreach ((array) ($pv['resources'] ?? []) as $rk => $rvv) {
                $resHtml .= ($resHtml !== '' ? ' · ' : '')
                    . $h((string) $rvv) . ' ' . $h((string) $rk);
            }
            $planLabel = trim(
                (string) ($pv['plan'] ?? '')
                . ((string) ($pv['productName'] ?? '') !== ''
                    ? ' · ' . $pv['productName'] : '')
            );

            $provSection = '<div class="bcp-sec">Provisão</div>'
                . '<div class="bcp-grid">'
                . $row(
                    'Status',
                    '<span class="bcp-badge ' . $stVar . '">'
                        . $h($stLabel) . '</span>'
                )
                . $row('Plano', $val($h($planLabel)))
                . ($resHtml !== ''
                    ? $row('Recursos', $mono($resHtml)) : '')
                . $row(
                    'Domínio',
                    $val($mono($h((string) ($pv['domain'] ?? ''))))
                )
                . $row(
                    'Localização',
                    $val($h((string) ($pv['location'] ?? '')))
                )
                . $row(
                    'Componentes',
                    $compsHtml !== ''
                        ? '<span class="bcp-comps">' . $compsHtml
                            . '</span>'
                        : '<span class="muted">—</span>'
                )
                . ((string) ($pv['created'] ?? '') !== ''
                    ? $row('Criado em', $h((string) $pv['created']))
                    : '')
                . $row(
                    'Provision UUID',
                    $mono($h((string) ($pv['provisionUuid'] ?? '')))
                )
                . '</div>';
        }

        return '<div class="bcp-shell"><style>'
            . Assets::css()
            . '</style>'
            . '<div class="bcp-head">'
            . Brand::logo(26)
            . '<span class="bcp-badge ' . $badge . '">'
            . $h($statusMsg) . '</span>'
            . '</div>'
            . '<div class="bcp-card">'
            . '<div class="bcp-card-hd"><div>'
            . '<h3>Configuração &amp; Status</h3>'
            . '<p>Integração BladeCP deste produto — somente leitura. '
            . 'Credenciais vivem no Server record.</p>'
            . '</div></div>'
            . '<div class="bcp-card-bd">'

            . '<div class="bcp-sec">Conexão</div>'
            . '<div class="bcp-grid">'
            . $row(
                'API endpoint',
                $mono($h($base)),
                'Montado do Server record (Secure + Hostname + Port) + '
                . '/api/v1. Host vazio → default de produção.'
            )
            . $row('Hostname', $val($mono($h($host))))
            . $row('Transporte', $sslPill)
            . $row(
                'API key',
                $val($mono($h($keyMask))),
                'Access Hash do Server record. Identidade do partner '
                . 'é derivada server-side por esta key.'
            )
            . '</div>'

            . $provSection

            . '<div class="bcp-sec">Catálogo</div>'
            . '<div class="bcp-grid">'
            . $row('Product Type', $val($h($ptype)))
            . $row('Plano', $catVal($plan, $plansN))
            . $row('Location', $catVal($loc, $locsN))
            . $row(
                'Componentes',
                $comps !== ''
                    ? $mono($h($comps))
                    : '<span class="muted">padrão do produto</span>'
            )
            . '</div>'

            . '<div class="bcp-foot">'
            . Brand::logo(16)
            . '<span>· AI drive and multi-cloud infrastructure</span>'
            . '</div>'

            . '</div></div></div>';
    }

    /** Mascara a key: mantém o prefixo `xxx_env_`, oculta o segredo. */
    private static function maskKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (preg_match('/^([a-z]+_(?:live|sand)_)/', $key, $m) === 1) {
            return $m[1] . str_repeat('•', 10);
        }

        return str_repeat('•', 12);
    }

    /** @param callable():array<mixed> $fn */
    private static function count(callable $fn): int
    {
        try {
            return \count($fn());
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function trim(string $s, int $max): string
    {
        $s = trim($s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
