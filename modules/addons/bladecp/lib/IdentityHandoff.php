<?php
declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp;
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
final class IdentityHandoff
{
    /** @return array{userId:int,clientId:int}|null */
    public static function currentIdentity(): ?array
    {
        return self::currentIdentityDecision()['identity'];
    }

    /** @return array{identity:array{userId:int,clientId:int}|null,status:string} */
    public static function currentIdentityDecision(): array
    {
        return self::identityDecision(false);
    }

    /** Native impersonation context, only for opening an existing support session. */
    public static function currentMasqueradedIdentity(): ?array
    {
        return self::identityDecision(true)['identity'];
    }

    private static function identityDecision(bool $support): array
    {
        if (!class_exists(\WHMCS\Authentication\CurrentUser::class)) {
            return self::decision('runtime_unavailable');
        }
        try {
            $current = new \WHMCS\Authentication\CurrentUser();
            if (!method_exists($current, 'isAuthenticatedUser')
                || !method_exists($current, 'user')
                || !method_exists($current, 'client')
                || !method_exists($current, 'isMasqueradingAdmin')) {
                return self::decision('runtime_unavailable');
            }
            if (!$current->isAuthenticatedUser()) {
                return self::decision('not_authenticated');
            }
            if ($current->isMasqueradingAdmin() !== $support) {
                return self::decision('masquerading_admin');
            }
            $user = $current->user(); $client = $current->client();
            if (!is_object($user) || !is_object($client)) {
                return self::decision('account_context_unavailable');
            }
            if (!method_exists($client, 'authedUserIsOwner')) {
                return self::decision('ownership_api_unavailable');
            }
            if (!$client->authedUserIsOwner()) {
                return self::decision('not_owner');
            }
            $userId = self::id($user); $clientId = self::id($client);
            if ($userId === null || $clientId === null) {
                return self::decision('invalid_identity');
            }
            return self::decision('ready', ['userId' => $userId, 'clientId' => $clientId]);
        } catch (\Throwable) {
            return self::decision('runtime_error');
        }
    }

    /**
     * @param array{userId:int,clientId:int}|null $identity
     * @return array{identity:array{userId:int,clientId:int}|null,status:string}
     */
    private static function decision(string $status, ?array $identity = null): array
    {
        return ['identity' => $identity, 'status' => $status];
    }

    private static function id(object $value): ?int
    {
        $id = $value->id ?? (method_exists($value, 'getAttribute') ? $value->getAttribute('id') : null);
        return filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0 ? (int) $id : null;
    }
}
