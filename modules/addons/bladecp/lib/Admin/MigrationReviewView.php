<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Bladecp\FastDeploy\BillingImport;
use WHMCS\Module\Addon\Bladecp\FastDeploy\BillingSettings;
use WHMCS\Module\Addon\Bladecp\FastDeploy\BillingStore;
use WHMCS\Module\Addon\Bladecp\FastDeployGateway;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Migrations the importer could not bill on its own. The administrator picks
 * an existing client, or creates one, and the service and its link are made
 * by the same path the importer uses.
 */
final class MigrationReviewView
{
    private const REASONS = [
        'no_cpanel_service', 'multiple_clients', 'reseller_account', 'no_product', 'multiple_products',
        'link_conflict', 'invalid_item', 'client_incomplete', 'service_failed', 'client_area_unavailable',
    ];
    private const ERRORS = [
        'server', 'unavailable', 'disabled', 'review_closed', 'client_missing', 'client_invalid', 'client_exists',
        'client_create_failed', 'not_linkable', 'link_pending', 'link_conflict', 'client_incomplete',
        'no_product', 'multiple_products', 'payment_method_unavailable', 'native_commerce_unavailable', 'failed',
    ];

    public static function render(string $base): string
    {
        $head = Layout::header(I18n::t('review.title'), I18n::t('review.description'));
        $serverId = self::serverId();
        if ($serverId === null) {
            return Layout::card($head, Layout::tabs($base, 'migration-review')
                . '<p class="bcp-row-v">' . Layout::escape(I18n::t('review.error.server')) . '</p>');
        }
        $rows = BillingStore::open($serverId);
        $body = self::flash() . self::settings($base, $serverId);
        if ($rows === []) {
            $body .= '<div class="bcp-empty-state bcp-review-empty"><strong>' . Layout::escape(I18n::t('review.empty')) . '</strong></div>';
            return Layout::card($head, Layout::tabs($base, 'migration-review') . $body . self::linked($serverId));
        }
        $selected = filter_var($_GET['review'] ?? null, FILTER_VALIDATE_INT);
        $body .= '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.domain')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.account')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.server')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.reason')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.since')) . '</th>'
            . '<th scope="col"><span class="sr-only">' . Layout::escape(I18n::t('admin.common.actions')) . '</span></th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $reason = in_array($row['reason'], self::REASONS, true) ? $row['reason'] : 'service_failed';
            $body .= '<tr><td>' . Layout::escape($row['domain'] ?? '') . '</td>'
                . '<td><code>' . Layout::escape($row['account'] ?? '') . '</code>'
                . (($row['sourceOwner'] ?? null) !== null && $row['sourceOwner'] !== 'root'
                    ? '<br><small>' . Layout::escape(I18n::t('review.owner', ['owner' => (string) $row['sourceOwner']])) . '</small>' : '')
                . '</td><td>' . Layout::escape($row['sourceServer'] ?? '') . '</td>'
                . '<td>' . Layout::escape(I18n::t('review.reason.' . $reason)) . '</td>'
                . '<td>' . Layout::escape($row['firstSeenAt']) . '</td>'
                . '<td><a class="btn btn-default btn-sm" href="' . Layout::escape($base . '&action=migration-review&review=' . $row['id']) . '#bcp-review-' . $row['id'] . '">'
                . Layout::escape(I18n::t('review.choose')) . '</a></td></tr>';
            if ($selected !== false && (int) $selected === $row['id']) {
                $body .= '<tr id="bcp-review-' . $row['id'] . '"><td colspan="6">' . self::resolver($base, $serverId, $row) . '</td></tr>';
            }
        }
        return Layout::card($head, Layout::tabs($base, 'migration-review') . $body . '</tbody></table></div>' . self::linked($serverId));
    }

    /** Linked migrations with the latest commercial bridge decision of each one. */
    private static function linked(int $serverId): string
    {
        $rows = BillingStore::linked($serverId);
        $html = '<section class="bcp-diagnostic-section" id="bcp-linked-migrations"><div class="bcp-section-heading"><div><h4>'
            . Layout::escape(I18n::t('review.linked_title')) . '</h4><p>' . Layout::escape(I18n::t('review.linked_description')) . '</p></div></div>';
        if ($rows === []) {
            return $html . '<p class="bcp-section-empty">' . Layout::escape(I18n::t('review.linked_empty')) . '</p></section>';
        }
        $html .= '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.domain')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.linked_service')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.linked_phase')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.linked_state')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('review.linked_decision')) . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $serviceId = (int) $row['serviceId'];
            $service = $serviceId > 0
                ? '<a href="' . Layout::escape('clientsservices.php?userid=' . (int) $row['clientId'] . '&id=' . $serviceId) . '">#' . $serviceId . '</a>'
                : Layout::escape(I18n::t('review.linked_never'));
            $decision = $row['bridgeDecision'] !== ''
                ? '<code>' . Layout::escape($row['bridgeDecision']) . '</code><br><small>' . Layout::escape($row['bridgeAt']) . ' UTC</small>'
                : Layout::escape(I18n::t('review.linked_never'));
            $html .= '<tr><td>' . Layout::escape($row['domain'] ?? $row['account'] ?? substr((string) $row['requestUuid'], 0, 8)) . '</td>'
                . '<td>' . $service . '</td><td>' . Layout::escape($row['bridgePhase']) . '</td>'
                . '<td>' . Layout::escape($row['bridgeState']) . '</td><td>' . $decision . '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    /** Called after the native admin permission and CSRF checks. Returns the redirect query. */
    public static function handle(string $action): string
    {
        $serverId = self::serverId();
        if ($serverId === null) {
            return '&error=server';
        }
        if ($action === 'migration-review-settings') {
            return BillingImport::saveResellerLookup($serverId, (string) ($_POST['reseller_lookup'] ?? '') === '1')
                ? '&result=saved' : '&error=failed';
        }
        $reviewId = filter_var($_POST['review'] ?? null, FILTER_VALIDATE_INT);
        $row = $reviewId === false ? null : BillingStore::find($serverId, (int) $reviewId);
        $back = $row === null ? '' : '&review=' . $row['id'];
        if ($row === null || $row['state'] !== BillingStore::REVIEW) {
            return '&error=review_closed';
        }
        if (!BillingSettings::enabled($serverId)) {
            return '&error=disabled' . $back;
        }
        $gateway = FastDeployGateway::forBilling($serverId);
        if ($gateway === null) {
            return '&error=unavailable' . $back;
        }
        if ($action === 'migration-review-create') {
            $created = BillingImport::createClient(array_intersect_key($_POST, array_flip(BillingImport::CLIENT_FIELDS)));
            if (($created['ok'] ?? false) !== true) {
                return '&error=' . self::error((string) ($created['error'] ?? 'failed')) . $back;
            }
            $clientId = (int) $created['clientId'];
        } else {
            $clientId = (int) (filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
            if ($clientId <= 0 || BillingImport::owner($clientId) === null) {
                return '&error=' . ($clientId > 0 && self::clientExists($clientId) ? 'client_incomplete' : 'client_missing') . $back;
            }
        }
        $result = BillingImport::resolve($gateway, $row, $clientId);
        return ($result['ok'] ?? false) === true
            ? '&result=linked&service=' . (int) ($result['serviceId'] ?? 0)
            : '&error=' . self::error((string) ($result['error'] ?? 'failed')) . $back;
    }

    /**
     * Clients matching an id, an email or a name, for the one-click choice.
     *
     * @return list<array{id:int,name:string,email:string,company:string}>
     */
    public static function searchClients(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 100 || !class_exists(Capsule::class)) {
            return [];
        }
        try {
            $found = [];
            if (ctype_digit($query)) {
                $row = Capsule::table('tblclients')->where('id', (int) $query)->first();
                if ($row !== null) {
                    $found[(int) $row->id] = $row;
                }
            }
            $like = '%' . addcslashes($query, '%_\\') . '%';
            foreach (['email', 'firstname', 'lastname', 'companyname'] as $column) {
                foreach (Capsule::table('tblclients')->where($column, 'like', $like)->limit($limit)->get() as $row) {
                    $found[(int) $row->id] = $row;
                }
                if (count($found) >= $limit) {
                    break;
                }
            }
            ksort($found, SORT_NUMERIC);
            $result = [];
            foreach (array_slice($found, 0, $limit, true) as $id => $row) {
                $result[] = [
                    'id' => (int) $id,
                    'name' => trim((string) ($row->firstname ?? '') . ' ' . (string) ($row->lastname ?? '')),
                    'email' => (string) ($row->email ?? ''),
                    'company' => (string) ($row->companyname ?? ''),
                ];
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function resolver(string $base, int $serverId, array $row): string
    {
        $query = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
        [$path, $params] = self::splitBase($base);
        $html = '<div class="bcp-review-resolve">';
        foreach (BillingImport::resellerClients($serverId, $row) as $clientId) {
            $client = self::client($clientId);
            if ($client !== null) {
                $html .= '<p>' . Layout::escape(I18n::t('review.reseller_suggestion')) . ' '
                    . self::linkButton($base, $row, $client) . '</p>';
            }
        }
        $html .= '<form method="get" action="' . Layout::escape($path) . '" class="bcp-review-search">';
        foreach ($params + ['action' => 'migration-review', 'review' => (string) $row['id']] as $name => $value) {
            $html .= '<input type="hidden" name="' . Layout::escape($name) . '" value="' . Layout::escape($value) . '">';
        }
        $html .= '<label class="bcp-fg-l" for="bcp-review-q-' . $row['id'] . '">' . Layout::escape(I18n::t('review.search')) . '</label> '
            . '<input id="bcp-review-q-' . $row['id'] . '" type="search" name="q" maxlength="100" value="' . Layout::escape($query) . '"> '
            . '<button type="submit" class="btn btn-default">' . Layout::escape(I18n::t('review.search_button')) . '</button></form>';
        if ($query !== '') {
            $clients = self::searchClients($query);
            if ($clients === []) {
                $html .= '<p class="bcp-row-v">' . Layout::escape(I18n::t('review.no_clients')) . '</p>';
            }
            foreach ($clients as $client) {
                $html .= '<p>' . self::linkButton($base, $row, $client) . '</p>';
            }
        }
        $html .= '<form method="post" action="' . Layout::escape($base) . '" class="bcp-review-create">' . Layout::token()
            . '<input type="hidden" name="action" value="migration-review-create">'
            . '<input type="hidden" name="review" value="' . $row['id'] . '">'
            . '<fieldset><legend>' . Layout::escape(I18n::t('review.create_title')) . '</legend>'
            . self::input('firstname', I18n::t('review.first_name'), $row['id'], 'text')
            . self::input('lastname', I18n::t('review.last_name'), $row['id'], 'text')
            . self::input('email', I18n::t('review.email'), $row['id'], 'email')
            . self::input('phonenumber', I18n::t('review.phone'), $row['id'], 'tel')
            . self::input('address1', I18n::t('review.address'), $row['id'], 'text')
            . self::input('city', I18n::t('review.city'), $row['id'], 'text')
            . self::input('state', I18n::t('review.state'), $row['id'], 'text')
            . self::input('postcode', I18n::t('review.postcode'), $row['id'], 'text')
            . self::input('country', I18n::t('review.country'), $row['id'], 'text')
            . self::input('companyname', I18n::t('review.company'), $row['id'], 'text')
            . '<button type="submit" class="btn btn-primary">' . Layout::escape(I18n::t('review.create_button')) . '</button>'
            . '<p class="bcp-experience-help">' . Layout::escape(I18n::t('review.create_help')) . ' <a href="clientsadd.php">'
            . Layout::escape(I18n::t('review.create_native')) . '</a></p></fieldset></form>';
        return $html . '</div>';
    }

    /** @param array{id:int,name:string,email:string,company:string} $client */
    private static function linkButton(string $base, array $row, array $client): string
    {
        $label = I18n::t('review.link_to', ['client' => '#' . $client['id'] . ' ' . $client['name'] . ' (' . $client['email'] . ')']);
        return '<form method="post" action="' . Layout::escape($base) . '" style="display:inline">' . Layout::token()
            . '<input type="hidden" name="action" value="migration-review-link">'
            . '<input type="hidden" name="review" value="' . $row['id'] . '">'
            . '<input type="hidden" name="client_id" value="' . $client['id'] . '">'
            . '<button type="submit" class="btn btn-primary btn-sm">' . Layout::escape($label) . '</button></form>';
    }

    private static function settings(string $base, int $serverId): string
    {
        $enabled = BillingImport::resellerLookupEnabled($serverId);
        return '<form method="post" action="' . Layout::escape($base) . '" class="bcp-review-settings">' . Layout::token()
            . '<input type="hidden" name="action" value="migration-review-settings">'
            . '<label class="bcp-experience-toggle"><input type="checkbox" name="reseller_lookup" value="1"' . ($enabled ? ' checked' : '') . '><span>'
            . Layout::escape(I18n::t('review.reseller_lookup')) . '</span></label>'
            . '<button type="submit" class="btn btn-default">' . Layout::escape(I18n::t('review.save')) . '</button>'
            . '<p class="bcp-experience-help">' . Layout::escape(I18n::t('review.reseller_lookup_help')) . '</p></form>';
    }

    private static function flash(): string
    {
        if (($_GET['result'] ?? '') === 'linked') {
            return '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check') . '<div>'
                . Layout::escape(I18n::t('review.linked', ['service' => (string) (int) ($_GET['service'] ?? 0)])) . '</div></div>';
        }
        if (($_GET['result'] ?? '') === 'saved') {
            return '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check') . '<div>'
                . Layout::escape(I18n::t('review.saved')) . '</div></div>';
        }
        $error = (string) ($_GET['error'] ?? '');
        if ($error === '') {
            return '';
        }
        return '<div class="bcp-flash bcp-flash-no"><span class="bcp-pf-x">!</span><div>'
            . Layout::escape(I18n::t('review.error.' . self::error($error))) . '</div></div>';
    }

    private static function input(string $name, string $label, int $rowId, string $type): string
    {
        $id = 'bcp-review-' . $name . '-' . $rowId;
        return '<div class="bcp-experience-field"><label class="bcp-fg-l" for="' . $id . '">' . Layout::escape($label) . '</label>'
            . '<div class="bcp-fg-c"><input id="' . $id . '" type="' . $type . '" name="' . $name . '" maxlength="'
            . ($name === 'email' ? 254 : ($name === 'country' ? 2 : 100)) . '"'
            . ($name === 'country' ? ' pattern="[A-Za-z]{2}" placeholder="BR"' : '')
            . ($name === 'companyname' ? '' : ' required') . '></div></div>';
    }

    /** @return array{id:int,name:string,email:string,company:string}|null */
    private static function client(int $clientId): ?array
    {
        try {
            $row = Capsule::table('tblclients')->where('id', $clientId)->first();
            return $row === null ? null : [
                'id' => $clientId,
                'name' => trim((string) ($row->firstname ?? '') . ' ' . (string) ($row->lastname ?? '')),
                'email' => (string) ($row->email ?? ''),
                'company' => (string) ($row->companyname ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function clientExists(int $clientId): bool
    {
        return self::client($clientId) !== null;
    }

    private static function error(string $code): string
    {
        return in_array($code, self::ERRORS, true) ? $code : 'failed';
    }

    private static function serverId(): ?int
    {
        $context = AdminServerContext::resolve();
        $serverId = filter_var($context['serverId'] ?? null, FILTER_VALIDATE_INT);
        return ($context['state'] ?? '') === 'configured' && $serverId !== false && (int) $serverId > 0 ? (int) $serverId : null;
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function splitBase(string $base): array
    {
        $path = (string) strtok($base, '?');
        parse_str((string) parse_url($base, PHP_URL_QUERY), $params);
        $clean = [];
        foreach ($params as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[$key] = $value;
            }
        }
        return [$path === '' ? 'addonmodules.php' : $path, $clean];
    }
}
