<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

use WHMCS\Module\Server\Bladecp\Helpers\ClientLocale;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Copy localizada do painel de serviço da área do cliente.
 *
 * O catálogo é autocontido para que a área do cliente não dependa de arquivos
 * de idioma do tema. Inglês é sempre o fallback seguro.
 */
final class ClientPanelCopy
{
    /** @var array<string,array<string,string>> */
    private const COPY = [
        'english' => [
            'status_active' => 'Active',
            'status_online' => 'Online',
            'status_suspended' => 'Suspended',
            'status_pending' => 'Pending',
            'status_provisioning' => 'Provisioning',
            'status_pending_manual' => 'Awaiting manual delivery',
            'status_terminated' => 'Terminated',
            'status_cancelled' => 'Cancelled',
            'status_failed' => 'Failed',
            'status_unknown' => 'Unknown',
            'plan' => 'Plan',
            'location' => 'Location',
            'combo_applied' => 'Applied bundle',
            'combo_customer' => 'Selected in the order',
            'combo_product' => 'Defined for the product',
            'component_healthy' => 'Healthy',
            'component_attention' => 'Needs attention',
            'component_unknown' => 'Status unavailable',
            'health_attention_one' => '{count} component needs attention',
            'health_attention_many' => '{count} components need attention',
            'health_healthy_one' => '{count} healthy component',
            'health_healthy_many' => '{count} healthy components',
            'health_configured_one' => '{count} configured component',
            'health_configured_many' => '{count} configured components',
            'plan_default' => 'Plan default configuration',
            'active_configuration' => 'Active configuration',
            'components_description' => 'Components applied to this service.',
            'plan_resources' => 'Plan resources',
            'current_usage' => 'Current usage',
            'usage_description' => 'Measured consumption in relation to plan limits.',
            'of' => 'of',
            'percent_used' => '{percent}% used',
            'usage_critical' => 'Usage close to the limit',
            'usage_warning' => 'High usage',
            'positioning' => 'Placement',
            'placement_pending' => 'Awaiting assignment',
            'placement_assigned' => 'Assigned',
            'early_termination_penalty' => 'Early termination penalty',
            'operation' => 'Operation',
            'service_activity' => 'Service activity',
            'provisioned' => 'Provisioned',
            'last_usage_read' => 'Last usage reading',
            'visit_site' => 'Visit site',
            'provisioned_service' => 'Provisioned service',
            'bladecp_service' => 'BladeCP service',
            'service_context' => 'Service context',
            'resource_disk' => 'Disk',
            'usage_disk' => 'Disk',
            'usage_bandwidth_monthly' => 'Monthly bandwidth',
            'footer' => 'Multi-tenant infrastructure for AI and multi-cloud operations',
        ],
        'portuguese-br' => [
            'status_active' => 'Ativo',
            'status_online' => 'Online',
            'status_suspended' => 'Suspenso',
            'status_pending' => 'Pendente',
            'status_provisioning' => 'Provisionando',
            'status_pending_manual' => 'Aguardando entrega manual',
            'status_terminated' => 'Encerrado',
            'status_cancelled' => 'Cancelado',
            'status_failed' => 'Falhou',
            'status_unknown' => 'Desconhecido',
            'plan' => 'Plano',
            'location' => 'Localização',
            'combo_applied' => 'Combo aplicado',
            'combo_customer' => 'Escolhido no pedido',
            'combo_product' => 'Definido no produto',
            'component_healthy' => 'Saudável',
            'component_attention' => 'Requer atenção',
            'component_unknown' => 'Estado não informado',
            'health_attention_one' => '{count} componente requer atenção',
            'health_attention_many' => '{count} componentes requerem atenção',
            'health_healthy_one' => '{count} componente saudável',
            'health_healthy_many' => '{count} componentes saudáveis',
            'health_configured_one' => '{count} componente configurado',
            'health_configured_many' => '{count} componentes configurados',
            'plan_default' => 'Configuração padrão do plano',
            'active_configuration' => 'Configuração ativa',
            'components_description' => 'Componentes aplicados a este serviço.',
            'plan_resources' => 'Recursos do plano',
            'current_usage' => 'Uso atual',
            'usage_description' => 'Consumo medido em relação aos limites do plano.',
            'of' => 'de',
            'percent_used' => '{percent}% utilizado',
            'usage_critical' => 'Uso próximo do limite',
            'usage_warning' => 'Uso elevado',
            'positioning' => 'Posicionamento',
            'placement_pending' => 'Aguardando definição',
            'placement_assigned' => 'Definida',
            'early_termination_penalty' => 'Multa por rescisão antecipada',
            'operation' => 'Operação',
            'service_activity' => 'Atividade do serviço',
            'provisioned' => 'Provisionado',
            'last_usage_read' => 'Última leitura de uso',
            'visit_site' => 'Visitar site',
            'provisioned_service' => 'Serviço provisionado',
            'bladecp_service' => 'Serviço BladeCP',
            'service_context' => 'Contexto do serviço',
            'resource_disk' => 'Disco',
            'usage_disk' => 'Disco',
            'usage_bandwidth_monthly' => 'Banda mensal',
            'footer' => 'Infraestrutura multi-tenant para IA e operações multi-cloud',
        ],
        'spanish' => [
            'status_active' => 'Activo',
            'status_online' => 'En línea',
            'status_suspended' => 'Suspendido',
            'status_pending' => 'Pendiente',
            'status_provisioning' => 'En aprovisionamiento',
            'status_pending_manual' => 'A la espera de entrega manual',
            'status_terminated' => 'Terminado',
            'status_cancelled' => 'Cancelado',
            'status_failed' => 'Falló',
            'status_unknown' => 'Desconocido',
            'plan' => 'Plan',
            'location' => 'Ubicación',
            'combo_applied' => 'Paquete aplicado',
            'combo_customer' => 'Seleccionado en el pedido',
            'combo_product' => 'Definido para el producto',
            'component_healthy' => 'En buen estado',
            'component_attention' => 'Requiere atención',
            'component_unknown' => 'Estado no disponible',
            'health_attention_one' => '{count} componente requiere atención',
            'health_attention_many' => '{count} componentes requieren atención',
            'health_healthy_one' => '{count} componente en buen estado',
            'health_healthy_many' => '{count} componentes en buen estado',
            'health_configured_one' => '{count} componente configurado',
            'health_configured_many' => '{count} componentes configurados',
            'plan_default' => 'Configuración predeterminada del plan',
            'active_configuration' => 'Configuración activa',
            'components_description' => 'Componentes aplicados a este servicio.',
            'plan_resources' => 'Recursos del plan',
            'current_usage' => 'Uso actual',
            'usage_description' => 'Consumo medido en relación con los límites del plan.',
            'of' => 'de',
            'percent_used' => '{percent}% utilizado',
            'usage_critical' => 'Uso próximo al límite',
            'usage_warning' => 'Uso elevado',
            'positioning' => 'Asignación',
            'placement_pending' => 'A la espera de asignación',
            'placement_assigned' => 'Asignada',
            'early_termination_penalty' => 'Penalización por cancelación anticipada',
            'operation' => 'Operación',
            'service_activity' => 'Actividad del servicio',
            'provisioned' => 'Aprovisionado',
            'last_usage_read' => 'Última lectura de uso',
            'visit_site' => 'Visitar sitio',
            'provisioned_service' => 'Servicio aprovisionado',
            'bladecp_service' => 'Servicio BladeCP',
            'service_context' => 'Contexto del servicio',
            'resource_disk' => 'Disco',
            'usage_disk' => 'Disco',
            'usage_bandwidth_monthly' => 'Ancho de banda mensual',
            'footer' => 'Infraestructura multi-tenant para IA y operaciones multi-cloud',
        ],
    ];

    public static function text(string $locale, string $key, array $replace = []): string
    {
        $copy = self::COPY[self::locale($locale)] ?? self::COPY['english'];
        $text = $copy[$key] ?? self::COPY['english'][$key] ?? $key;

        if ($replace === []) {
            return $text;
        }

        $values = [];
        foreach ($replace as $name => $value) {
            $values['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $values);
    }

    public static function plural(
        string $locale,
        string $singularKey,
        string $pluralKey,
        int $count
    ): string {
        return self::text(
            $locale,
            $count === 1 ? $singularKey : $pluralKey,
            ['count' => $count]
        );
    }

    public static function decimalSeparator(string $locale): string
    {
        return self::locale($locale) === 'english' ? '.' : ',';
    }

    private static function locale(string $locale): string
    {
        return match (ClientLocale::normalize($locale)) {
            'pt-BR' => 'portuguese-br',
            'es' => 'spanish',
            default => 'english',
        };
    }
}
