<?php
declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Read-only inventory. A local correlation is never proof of account ownership. */
final class FederationRecovery
{
    public static function inspect(int $serverId, int $after = 0, int $limit = 25): array
    {
        if ($serverId <= 0 || $after < 0 || $limit < 1 || $limit > 100) { throw new \InvalidArgumentException('invalid_inventory_scope'); }
        if (ServerConfig::paramsForServerId($serverId) === null) { throw new \RuntimeException('server_unavailable'); }
        $rows = [];
        $scanned = 0;
        $cursor = $after;
        foreach (Capsule::table('tblhosting')->where('server', $serverId)->where('id', '>', $after)->orderBy('id')->limit($limit)->get() as $service) {
            $scanned++;
            $cursor = (int) $service->id;
            $product = Capsule::table('tblproducts')->where('id', (int) $service->packageid)->first();
            if ($product === null || $product->servertype !== 'bladecp' || $product->configoption1 !== 'vibe') { continue; }
            $accountPresent = false;
            $bindingPresent = false;
            if (Capsule::schema()->hasTable('tblcustomfields') && Capsule::schema()->hasTable('tblcustomfieldsvalues')) {
                foreach (['BladeCP Vibe Account ID', 'BladeCP Identity Binding Version'] as $name) {
                    $field = Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', (int) $service->packageid)->where('fieldname', $name)->first();
                    $value = $field === null ? '' : (string) Capsule::table('tblcustomfieldsvalues')->where('fieldid', (int) $field->id)->where('relid', (int) $service->id)->value('value');
                    if ($name === 'BladeCP Vibe Account ID') { $accountPresent = trim($value) !== ''; }
                    else { $bindingPresent = filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0; }
                }
            }
            $rows[] = ['serviceId' => (int) $service->id, 'orderId' => (int) ($service->orderid ?? 0),
                'classification' => self::classify((array) $service, (array) $product, $accountPresent && $bindingPresent)];
        }
        return ['dryRun' => true, 'writes' => 0, 'serverId' => $serverId, 'scanned' => $scanned,
            'nextCursor' => $scanned === $limit ? $cursor : null, 'rows' => $rows];
    }

    public static function classify(array $service, array $product, bool $localCorrelation): string
    {
        $status = strtolower((string) ($service['domainstatus'] ?? ''));
        if (!in_array($status, ['pending', 'active'], true)) { return 'excluded_status'; }
        if (strtolower((string) ($product['paytype'] ?? '')) !== 'free'
            || !is_numeric($service['amount'] ?? null) || (float) $service['amount'] !== 0.0
            || !is_numeric($service['firstpaymentamount'] ?? null) || (float) $service['firstpaymentamount'] !== 0.0) { return 'excluded_financial'; }
        if ($localCorrelation) { return $status === 'active' ? 'linked_active' : 'linked_review_required'; }
        return 'owner_access_required';
    }
}
