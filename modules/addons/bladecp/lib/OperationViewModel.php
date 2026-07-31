<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Addon\Bladecp\Admin\I18n;

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
            self::card(I18n::t('viewmodels.operation.cards.services'), (int) ($summary['total'] ?? 0), 'total'),
            self::card(I18n::t('viewmodels.operation.cards.active'), (int) ($summary['active'] ?? 0), 'healthy'),
            self::card(I18n::t('viewmodels.operation.cards.pending'), (int) ($summary['pending'] ?? 0), 'attention'),
            self::card(I18n::t('viewmodels.operation.cards.attention'), (int) ($summary['attention'] ?? 0), 'attention'),
        ];
        $data['emptyState'] = [
            'services' => I18n::t('viewmodels.operation.empty.services'),
            'failures' => I18n::t('viewmodels.operation.empty.failures'),
            'webhooks' => I18n::t('viewmodels.operation.empty.webhooks'),
            'synchronizations' => I18n::t('viewmodels.operation.empty.synchronizations'),
        ];
        return $data;
    }

    /** @return array<string,mixed> */
    public static function timeline(int $serviceId, int $limit = 25): array
    {
        $data = Timeline::forService($serviceId, $limit);
        $data['emptyState'] = I18n::t('viewmodels.operation.empty.timeline');
        return $data;
    }

    /** @return array{label:string,value:int,tone:string} */
    private static function card(string $label, int $value, string $tone): array
    {
        return ['label' => $label, 'value' => max(0, $value), 'tone' => $tone];
    }
}
