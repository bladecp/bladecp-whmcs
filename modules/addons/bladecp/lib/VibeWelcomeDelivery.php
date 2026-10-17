<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Helpers\VibeServiceOperationLock;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Sends the configured native WHMCS product welcome template once a Vibe
 * service is active. Delivery is deliberately opt-in: callers must invoke
 * deliver() after their own commercial transition has completed.
 *
 * The native email log has no durable template identifier. Existing delivery
 * can therefore be proven only with the exact rendered product-template
 * subject and the exact service URL in the stored body. Only the native
 * {$service_product_name} merge field is rendered here; other Smarty remains
 * intentionally unexecuted and requires operator review when history exists.
 */
final class VibeWelcomeDelivery
{
    public const TABLE = 'mod_bladecp_vibe_welcome_delivery';

    /**
     * Read-only eligibility and delivery status for one service.
     *
     * @return array{status:string,serviceId:int,templateId?:int,reason?:string}
     */
    public static function inspect(int $serviceId): array
    {
        $context = self::context($serviceId);
        if ($context === null) {
            return self::result('not_eligible', $serviceId);
        }

        $ledger = self::ledger($context['serviceId'], $context['templateId']);
        if ($ledger['status'] === 'unavailable') {
            return self::result('unavailable', $context['serviceId'], $context['templateId']);
        }
        if ($ledger['status'] === 'found') {
            return self::result((string) ($ledger['row']->status ?? 'uncertain'), $context['serviceId'], $context['templateId']);
        }

        $history = self::nativeHistory($context);
        if ($history === 'confirmed_sent') {
            return self::result('native_sent', $context['serviceId'], $context['templateId']);
        }
        if ($history !== 'none') {
            return self::result('needs_review', $context['serviceId'], $context['templateId'], 'native_history_ambiguous');
        }

        return self::result('ready', $context['serviceId'], $context['templateId']);
    }

    /**
     * Claims and sends the product's configured native welcome template.
     * A malformed, failed or interrupted SendEmail response is uncertain: it
     * is permanently held for manual review and is never retried by this API.
     *
     * @return array{status:string,serviceId:int,templateId?:int,reason?:string}
     */
    public static function deliver(int $serviceId): array
    {
        $initialContext = self::context($serviceId);
        if ($initialContext === null) {
            return self::result('not_eligible', $serviceId);
        }
        if (!function_exists('localAPI') || !self::installSchema()) {
            return self::result('unavailable', $initialContext['serviceId'], $initialContext['templateId']);
        }
        if (!class_exists(VibeServiceOperationLock::class)) {
            return self::result('unavailable', $initialContext['serviceId'], $initialContext['templateId']);
        }
        $release = VibeServiceOperationLock::acquire($serviceId);
        if ($release === null) {
            return self::result('unavailable', $initialContext['serviceId'], $initialContext['templateId'], 'service_lock_unavailable');
        }
        try {
            // Re-read under the same service operation lock used by lifecycle
            // changes. A service suspended between a recovery scan and this
            // point must never receive a welcome message.
            $context = self::context($serviceId);
            if ($context === null) {
                return self::result('not_eligible', $serviceId, null, 'service_state_changed');
            }
            $history = self::nativeHistory($context);
            if ($history === 'confirmed_sent') {
                self::recordNativeHistory($context);
                return self::result('native_sent', $context['serviceId'], $context['templateId']);
            }
            if ($history !== 'none') {
                return self::result('needs_review', $context['serviceId'], $context['templateId'], 'native_history_ambiguous');
            }
            $claim = self::claim($context);
            if (($claim['status'] ?? '') !== 'claimed') {
                return self::result((string) ($claim['status'] ?? 'unavailable'), $context['serviceId'], $context['templateId']);
            }
            // SendEmail resolves product merge fields from the service ID when
            // the selected template has type "product". Do not supply a custom
            // body or subject, otherwise the product template is bypassed.
            try {
                $sent = localAPI('SendEmail', [
                    'messagename' => $context['templateName'],
                    'id' => $context['serviceId'],
                ]);
                if (is_array($sent) && strtolower(trim((string) ($sent['result'] ?? ''))) === 'success') {
                    self::finish((int) $claim['id'], 'sent', '');
                    return self::result('sent', $context['serviceId'], $context['templateId']);
                }
            } catch (\Throwable) {
                // A local API failure cannot prove that the SMTP handoff did
                // not occur. It must not be retried automatically.
            }
            self::finish((int) $claim['id'], 'uncertain', 'send_outcome_unknown');
            return self::result('uncertain', $context['serviceId'], $context['templateId'], 'send_outcome_unknown');
        } finally {
            $release();
        }
    }

    /** @return array{serviceId:int,clientId:int,templateId:int,templateName:string,templateSubjects:list<string>,hasUnknownTemplateSubject:bool,serviceUrl:string}|null */
    private static function context(int $serviceId): ?array
    {
        if ($serviceId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $schema = Capsule::schema();
            foreach (['tblhosting', 'tblproducts', 'tblemailtemplates'] as $table) {
                if (!$schema->hasTable($table)) {
                    return null;
                }
            }
            $hostingColumns = array_flip($schema->getColumnListing('tblhosting'));
            $productColumns = array_flip($schema->getColumnListing('tblproducts'));
            $templateColumns = array_flip($schema->getColumnListing('tblemailtemplates'));
            foreach (['id', 'userid', 'packageid', 'domainstatus'] as $column) {
                if (!isset($hostingColumns[$column])) {
                    return null;
                }
            }
            foreach (['id', 'name', 'servertype', 'configoption1', 'welcomeemail'] as $column) {
                if (!isset($productColumns[$column])) {
                    return null;
                }
            }
            foreach (['id', 'type', 'name', 'subject'] as $column) {
                if (!isset($templateColumns[$column])) {
                    return null;
                }
            }

            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null || strtolower(trim((string) ($service->domainstatus ?? ''))) !== 'active') {
                return null;
            }
            $clientId = self::positiveId($service->userid ?? null);
            $productId = self::positiveId($service->packageid ?? null);
            if ($clientId === null || $productId === null) {
                return null;
            }
            $product = Capsule::table('tblproducts')->where('id', $productId)->first();
            if ($product === null
                || strtolower(trim((string) ($product->servertype ?? ''))) !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe') {
                return null;
            }
            $templateId = self::positiveId($product->welcomeemail ?? null);
            if ($templateId === null) {
                return null;
            }
            $template = Capsule::table('tblemailtemplates')->where('id', $templateId)->first();
            if ($template === null || strtolower(trim((string) ($template->type ?? ''))) !== 'product') {
                return null;
            }
            if (isset($templateColumns['disabled']) && (int) ($template->disabled ?? 0) === 1) {
                return null;
            }
            $templateName = trim((string) ($template->name ?? ''));
            if ($templateName === '') {
                return null;
            }
            $subjects = self::templateSubjects($templateName, (string) ($product->name ?? ''));
            if ($subjects === null) {
                return null;
            }
            $accessState = self::accessState($serviceId, $productId);
            if ($accessState === null || !in_array($accessState, ['', 'active'], true)) {
                return null;
            }
            return [
                'serviceId' => $serviceId,
                'clientId' => $clientId,
                'templateId' => $templateId,
                'templateName' => $templateName,
                'templateSubjects' => $subjects['subjects'],
                'hasUnknownTemplateSubject' => $subjects['hasUnknown'],
                'serviceUrl' => self::serviceUrl($serviceId),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns null when the persisted service fields cannot be read. Missing
     * access state itself is allowed for pre-marker services, but a known deny
     * state closes the small window before WHMCS updates domainstatus.
     */
    private static function accessState(int $serviceId, int $productId): ?string
    {
        try {
            $schema = Capsule::schema();
            foreach (['tblcustomfields', 'tblcustomfieldsvalues'] as $table) {
                if (!$schema->hasTable($table)) {
                    return null;
                }
            }
            $fieldColumns = array_flip($schema->getColumnListing('tblcustomfields'));
            $valueColumns = array_flip($schema->getColumnListing('tblcustomfieldsvalues'));
            foreach (['id', 'type', 'relid', 'fieldname'] as $column) {
                if (!isset($fieldColumns[$column])) {
                    return null;
                }
            }
            foreach (['fieldid', 'relid', 'value'] as $column) {
                if (!isset($valueColumns[$column])) {
                    return null;
                }
            }
            $field = Capsule::table('tblcustomfields')
                ->where('type', 'product')->where('relid', $productId)
                ->where('fieldname', 'BladeCP Vibe Access State')->first();
            if ($field === null) {
                return '';
            }
            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', (int) $field->id)->where('relid', $serviceId)->first();
            return $value === null ? '' : strtolower(trim((string) ($value->value ?? '')));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{serviceId:int,clientId:int,templateId:int,templateName:string,templateSubjects:list<string>,hasUnknownTemplateSubject:bool,serviceUrl:string} $context @return 'none'|'confirmed_sent'|'ambiguous' */
    private static function nativeHistory(array $context): string
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable('tblemails')) {
                return 'ambiguous';
            }
            $columns = array_flip($schema->getColumnListing('tblemails'));
            if (!isset($columns['userid'], $columns['message'], $columns['subject'])) {
                return 'ambiguous';
            }
            $messages = Capsule::table('tblemails')->where('userid', $context['clientId'])->get(['message', 'subject']);
            $seen = false;
            $ambiguous = false;
            foreach ($messages as $row) {
                $seen = true;
                $subject = self::normalizedSubject((string) ($row->subject ?? ''));
                $hasExactUrl = self::containsExactServiceUrl((string) ($row->message ?? ''), $context['serviceUrl']);
                $subjectMatches = in_array($subject, $context['templateSubjects'], true);
                if ($subjectMatches && $hasExactUrl) {
                    return 'confirmed_sent';
                }
                // The template subject without the exact service URL, or the
                // exact URL under a different template, cannot prove which
                // welcome message was emitted. Do not infer either outcome.
                if ($subjectMatches || $hasExactUrl || $context['hasUnknownTemplateSubject']) {
                    $ambiguous = true;
                }
            }
            if (!$seen) {
                return 'none';
            }
            return $ambiguous ? 'ambiguous' : 'none';
        } catch (\Throwable) {
            return 'ambiguous';
        }
    }

    /** @return array{status:'absent'|'found'|'unavailable',row?:object} */
    private static function ledger(int $serviceId, int $templateId): array
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE)) {
                return ['status' => 'absent'];
            }
            $row = Capsule::table(self::TABLE)
                ->where('service_id', $serviceId)->where('template_id', $templateId)->first();
            return $row === null ? ['status' => 'absent'] : ['status' => 'found', 'row' => $row];
        } catch (\Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    /** @param array{serviceId:int,clientId:int,templateId:int,templateName:string,templateSubjects:list<string>,hasUnknownTemplateSubject:bool,serviceUrl:string} $context @return array{status:string,id?:int} */
    private static function claim(array $context): array
    {
        try {
            return Capsule::connection()->transaction(static function () use ($context): array {
                $row = Capsule::table(self::TABLE)
                    ->where('service_id', $context['serviceId'])
                    ->where('template_id', $context['templateId'])
                    ->lockForUpdate()
                    ->first();
                if ($row !== null) {
                    return ['status' => (string) ($row->status ?? 'uncertain')];
                }
                $now = date('Y-m-d H:i:s');
                $id = (int) Capsule::table(self::TABLE)->insertGetId([
                    'service_id' => $context['serviceId'],
                    'template_id' => $context['templateId'],
                    'status' => 'in_flight',
                    'attempts' => 1,
                    'claimed_at' => $now,
                    'completed_at' => null,
                    'reason' => null,
                ]);
                return ['status' => 'claimed', 'id' => $id];
            });
        } catch (\Throwable) {
            // A unique-key race is indistinguishable from an uncertain claim
            // to this caller. Do not issue another SendEmail request.
            return ['status' => 'unavailable'];
        }
    }

    /** @param array{serviceId:int,clientId:int,templateId:int,templateName:string,templateSubjects:list<string>,hasUnknownTemplateSubject:bool,serviceUrl:string} $context */
    private static function recordNativeHistory(array $context): void
    {
        if (!self::installSchema()) {
            return;
        }
        try {
            Capsule::connection()->transaction(static function () use ($context): void {
                $existing = Capsule::table(self::TABLE)
                    ->where('service_id', $context['serviceId'])->where('template_id', $context['templateId'])
                    ->lockForUpdate()->first();
                if ($existing === null) {
                    $now = date('Y-m-d H:i:s');
                    Capsule::table(self::TABLE)->insert([
                        'service_id' => $context['serviceId'], 'template_id' => $context['templateId'],
                        'status' => 'sent', 'attempts' => 0, 'claimed_at' => $now,
                        'completed_at' => $now, 'reason' => 'native_history_confirmed',
                    ]);
                }
            });
        } catch (\Throwable) {
        }
    }

    private static function finish(int $id, string $status, string $reason): void
    {
        try {
            Capsule::table(self::TABLE)->where('id', $id)->update([
                'status' => $status,
                'completed_at' => date('Y-m-d H:i:s'),
                'reason' => $reason === '' ? null : $reason,
            ]);
        } catch (\Throwable) {
        }
    }

    private static function installSchema(): bool
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, static function ($table): void {
                    $table->increments('id');
                    $table->integer('service_id')->unsigned();
                    $table->integer('template_id')->unsigned();
                    $table->string('status', 16);
                    $table->integer('attempts')->unsigned()->default(1);
                    $table->timestamp('claimed_at')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->string('reason', 64)->nullable();
                    $table->unique(['service_id', 'template_id'], 'bladecp_vibe_welcome_service_template_unique');
                    $table->index(['status', 'claimed_at'], 'bladecp_vibe_welcome_status_claimed_index');
                });
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function serviceUrl(int $serviceId): string
    {
        $base = '';
        try {
            if (class_exists(\WHMCS\Config\Setting::class)) {
                $base = trim((string) \WHMCS\Config\Setting::getValue('SystemURL'));
            }
        } catch (\Throwable) {
        }
        if ($base === '') {
            $config = $GLOBALS['CONFIG'] ?? [];
            $base = is_array($config) ? trim((string) ($config['SystemURL'] ?? '')) : '';
        }
        return rtrim($base, '/') . '/clientarea.php?action=productdetails&id=' . $serviceId;
    }

    private static function positiveId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : null;
    }

    private static function normalizedSubject(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    /** @return array{subjects:list<string>,hasUnknown:bool}|null */
    private static function templateSubjects(string $templateName, string $productName): ?array
    {
        $productName = self::normalizedSubject($productName);
        if ($templateName === '' || $productName === '') {
            return null;
        }
        try {
            $rows = Capsule::table('tblemailtemplates')
                ->where('type', 'product')->where('name', $templateName)->get();
            $subjects = [];
            $hasUnknown = false;
            foreach ($rows as $row) {
                if ((int) ($row->disabled ?? 0) === 1) {
                    continue;
                }
                $rendered = self::renderKnownTemplateSubject((string) ($row->subject ?? ''), $productName);
                if ($rendered === null) {
                    $hasUnknown = true;
                    continue;
                }
                if (!in_array($rendered, $subjects, true)) {
                    $subjects[] = $rendered;
                }
            }
            return $subjects === [] && !$hasUnknown ? null : ['subjects' => $subjects, 'hasUnknown' => $hasUnknown];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Renders only the one native product merge field needed for audit matching. */
    private static function renderKnownTemplateSubject(string $subject, string $productName): ?string
    {
        $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rendered = preg_replace_callback(
            '/\{\$\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}/',
            static function (array $match) use ($productName): string {
                return $match[1] === 'service_product_name' ? $productName : $match[0];
            },
            $subject
        );
        if ($rendered === null || str_contains($rendered, '{') || str_contains($rendered, '}')) {
            return null;
        }
        $rendered = self::normalizedSubject($rendered);
        return $rendered === '' ? null : $rendered;
    }

    private static function containsExactServiceUrl(string $message, string $serviceUrl): bool
    {
        $body = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($body === '') {
            return false;
        }
        $quoted = preg_quote($serviceUrl, '~');
        return preg_match('~(?<![A-Za-z0-9_])' . $quoted . '(?=$|[\\s"\'<>.,;:!?&])~u', $body) === 1;
    }

    /** @return array{status:string,serviceId:int,templateId?:int,reason?:string} */
    private static function result(string $status, int $serviceId, ?int $templateId = null, ?string $reason = null): array
    {
        $result = ['status' => $status, 'serviceId' => $serviceId];
        if ($templateId !== null) {
            $result['templateId'] = $templateId;
        }
        if ($reason !== null) {
            $result['reason'] = $reason;
        }
        return $result;
    }
}
