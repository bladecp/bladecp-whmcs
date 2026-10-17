<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Config;

use WHMCS\Module\Server\Bladecp\Helpers\ProductCapability;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Configura os campos de produto exibidos pelo WHMCS. */
final class ConfigOptions
{
    public static function build(array $params = []): array
    {
        return [
            'BladeCP Product Type' => [
                'Type' => 'dropdown',
                'Options' => implode(',', ProductCapability::configurableTypes()),
                'Default' => 'stack-container',
                'Description' => '<span hidden data-bcp-config-owner="bladecp"></span>'
                    . 'Discriminador — define qual produto BladeCP este produto WHMCS provisiona.',
            ],
            'BladeCP Plan' => [
                'Type' => 'dropdown',
                'Loader' => static fn (): array => Loaders::plans(),
                'SimpleMode' => true,
                'Description' => 'Plano do seu catálogo de parceiro '
                    . 'que este produto provisiona — carregado por '
                    . 'nome via API (o parceiro é resolvido pela key '
                    . 'bcp_* do Server record). É o partner_plan_id '
                    . 'enviado no provisionamento.',
            ],
            // A localização é escolhida pelo cliente no formulário de pedido.
            'BladeCP Components' => [
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Slugs de componentes separados por vírgula '
                    . '(ex.: php-8.2,mysql,redis). Validados contra o '
                    . 'catálogo no provisioning. WHMCS não tem multi-select '
                    . 'nativo; a interface de configuração oferece a seleção assistida.',
            ],
            'BladeCP Vibe Plan Tier' => [
                'Type' => 'dropdown',
                'Options' => 'unknown,free,pro',
                'Default' => 'unknown',
                'Description' => 'Usado somente para Vibe. Define Free ou Pro quando o catálogo informar o nível comercial.',
            ],
        ];
    }
}
