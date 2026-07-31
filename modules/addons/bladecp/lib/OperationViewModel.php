<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Adaptador pequeno entre os dados de operação e a interface administrativa.
 * A UI recebe rótulos e prioridades estáveis, sem conhecer tabelas do WHMCS.
 */
final class OperationViewModel
{
    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public static function overview(array $filters = []): array
    {
        $data = Operation::load($filters);
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $data['cards'] = [
            self::card('Serviços BladeCP', (int) ($summary['total'] ?? 0), 'total'),
            self::card('Ativos', (int) ($summary['active'] ?? 0), 'healthy'),
            self::card('Pendentes', (int) ($summary['pending'] ?? 0), 'attention'),
            self::card('Exigem atenção', (int) ($summary['attention'] ?? 0), 'attention'),
        ];
        $data['emptyState'] = [
            'services' => 'Nenhum serviço BladeCP foi encontrado com estes filtros.',
            'failures' => 'Não há falhas recentes disponíveis no log do módulo.',
            'webhooks' => 'Ainda não há eventos recebidos pelo receiver local.',
            'synchronizations' => 'Não há sincronizações recentes disponíveis no log do módulo.',
        ];
        return $data;
    }

    /** @return array<string,mixed> */
    public static function timeline(int $serviceId, int $limit = 25): array
    {
        $data = Timeline::forService($serviceId, $limit);
        $data['emptyState'] = 'Não há eventos correlacionáveis no log do módulo para este serviço.';
        return $data;
    }

    /** @return array{label:string,value:int,tone:string} */
    private static function card(string $label, int $value, string $tone): array
    {
        return ['label' => $label, 'value' => max(0, $value), 'tone' => $tone];
    }
}
