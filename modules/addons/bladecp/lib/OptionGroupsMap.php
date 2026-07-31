<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Camada de posse e mapa reverso das opções configuráveis sincronizadas.
 * Usa Capsule e opera de forma fail-soft.
 *
 * Tabelas (prefixo `mod_` da convenção de addon WHMCS):
 *  - `mod_bladecp_option_groups`     group_uuid(PK lógico) → whmcs_gid
 *      POSSE: só os `tblproductconfiggroups` cujo gid está aqui são
 *      NOSSOS — `OptionGroups::remove`/re-sync só mexem nesses, nunca
 *      num grupo do parceiro. (1 grupo da API ↔ 1 grupo WHMCS — sem
 *      materialização por produto.)
 *  - `mod_bladecp_option_value_map`  sub_id → {option_uuid, value_uuid}
 *      MAPA REVERSO: traduz a sub-option escolhida no order form de
 *      volta pros UUIDs da API. Documentado aqui como CONTRATO de nome
 *      de tabela (o módulo de provisionamento pode lê-la diretamente,
 *      pelo `ComboResolver`); o provisionamento resolve pelo catálogo
 *      (`OptionsMapper::valueMapFromGroups`), e esta tabela serve o
 *      preview admin + `remove()`.
 *
 * NÃO é derrubada no `_deactivate` (de propósito): dropar a posse
 * orfanaria os grupos WHMCS já criados. Remoção é explícita
 * (`OptionGroups::remove`). `ensureGroup` ainda se auto-cura por um
 * marcador na `description` se a posse for perdida.
 */
final class OptionGroupsMap
{
    public const T_GROUPS = 'mod_bladecp_option_groups';
    public const T_VALUES = 'mod_bladecp_option_value_map';

    private static function schema(): mixed
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        return \WHMCS\Database\Capsule::schema();
    }

    private static function db(): mixed
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        return \WHMCS\Database\Capsule::class;
    }

    public static function installSchema(): void
    {
        $s = self::schema();
        if ($s === null) {
            return;
        }
        if (!$s->hasTable(self::T_GROUPS)) {
            $s->create(self::T_GROUPS, static function ($t): void {
                $t->increments('id');
                $t->string('group_uuid')->unique();
                $t->integer('whmcs_gid');
                $t->string('name');
                $t->timestamp('updated_at')->nullable();
            });
        }
        if (!$s->hasTable(self::T_VALUES)) {
            $s->create(self::T_VALUES, static function ($t): void {
                $t->increments('id');
                $t->integer('sub_id')->unique();
                $t->integer('config_id');
                $t->string('group_uuid')->index();
                $t->string('option_uuid');
                $t->string('option_name');
                $t->integer('option_type')->default(1);
                $t->string('value_label');
                $t->string('option_value_uuid');
                $t->integer('is_default')->default(0);
                $t->string('resource_key')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }
    }

    public static function uninstallSchema(): void
    {
        $s = self::schema();
        if ($s === null) {
            return;
        }
        $s->dropIfExists(self::T_VALUES);
        $s->dropIfExists(self::T_GROUPS);
    }

    /** Confirma se as tabelas de opções estão instaladas. Fail-soft false. */
    public static function isInstalled(): bool
    {
        try {
            $s = self::schema();
            return $s !== null && $s->hasTable(self::T_GROUPS);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Posse de grupo ──

    /** WHMCS gid do grupo da API (ou null). */
    public static function gidForGroup(string $groupUuid): ?int
    {
        try {
            $db = self::db();
            if ($db === null) {
                return null;
            }
            $r = $db::table(self::T_GROUPS)
                ->where('group_uuid', $groupUuid)
                ->first();
            return $r === null ? null : (int) $r->whmcs_gid;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Grava/atualiza a posse `group_uuid → whmcs_gid`. Idempotente. */
    public static function rememberGroup(
        string $groupUuid,
        int $gid,
        string $name
    ): void {
        try {
            $db = self::db();
            if ($db === null || $groupUuid === '' || $gid <= 0) {
                return;
            }
            $payload = [
                'whmcs_gid' => $gid,
                'name' => $name,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $has = $db::table(self::T_GROUPS)
                ->where('group_uuid', $groupUuid)->first();
            if ($has !== null) {
                $db::table(self::T_GROUPS)
                    ->where('group_uuid', $groupUuid)->update($payload);
            } else {
                $db::table(self::T_GROUPS)->insert(
                    array_merge(['group_uuid' => $groupUuid], $payload)
                );
            }
        } catch (\Throwable) {
        }
    }

    /** @return array<string,int> group_uuid → whmcs_gid */
    public static function allGroups(): array
    {
        try {
            $db = self::db();
            if ($db === null) {
                return [];
            }
            $out = [];
            foreach ($db::table(self::T_GROUPS)->get() as $r) {
                $out[(string) $r->group_uuid] = (int) $r->whmcs_gid;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return int[] gids que são NOSSOS (posse). */
    public static function ownedGids(): array
    {
        return array_values(array_unique(array_map(
            'intval',
            self::allGroups()
        )));
    }

    // ── Mapa reverso de valores ──

    /**
     * Grava/atualiza o mapa reverso de uma sub-option. Idempotente
     * (chave `sub_id`).
     */
    public static function rememberValue(
        int $subId,
        int $configId,
        string $groupUuid,
        string $optionUuid,
        string $optionName,
        int $optionType,
        string $valueLabel,
        string $optionValueUuid,
        bool $isDefault,
        ?string $resourceKey
    ): void {
        try {
            $db = self::db();
            if ($db === null || $subId <= 0) {
                return;
            }
            $payload = [
                'config_id' => $configId,
                'group_uuid' => $groupUuid,
                'option_uuid' => $optionUuid,
                'option_name' => $optionName,
                'option_type' => $optionType,
                'value_label' => $valueLabel,
                'option_value_uuid' => $optionValueUuid,
                'is_default' => $isDefault ? 1 : 0,
                'resource_key' => $resourceKey,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $has = $db::table(self::T_VALUES)
                ->where('sub_id', $subId)->first();
            if ($has !== null) {
                $db::table(self::T_VALUES)
                    ->where('sub_id', $subId)->update($payload);
            } else {
                $db::table(self::T_VALUES)->insert(
                    array_merge(['sub_id' => $subId], $payload)
                );
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Resolve a config option pela identidade estável recebida da API.
     * Instalações antigas podem ainda não ter mapa, por isso callers devem
     * manter fallback pelo rótulo visível.
     */
    public static function configIdForOption(
        string $groupUuid,
        string $optionUuid
    ): ?int {
        try {
            $db = self::db();
            if ($db === null || $groupUuid === '' || $optionUuid === '') {
                return null;
            }
            $row = $db::table(self::T_VALUES)
                ->where('group_uuid', $groupUuid)
                ->where('option_uuid', $optionUuid)
                ->first();
            return $row === null ? null : (int) $row->config_id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve uma sub-option pela UUID do valor. Mantém o id WHMCS durante
     * renomes, preservando pedidos e o pricing já configurado.
     */
    public static function subIdForValue(
        string $groupUuid,
        string $optionUuid,
        string $valueUuid,
        int $configId
    ): ?int {
        try {
            $db = self::db();
            if (
                $db === null || $groupUuid === '' || $optionUuid === ''
                || $valueUuid === '' || $configId <= 0
            ) {
                return null;
            }
            $row = $db::table(self::T_VALUES)
                ->where('group_uuid', $groupUuid)
                ->where('option_uuid', $optionUuid)
                ->where('option_value_uuid', $valueUuid)
                ->where('config_id', $configId)
                ->first();
            return $row === null ? null : (int) $row->sub_id;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Esquece as sub-options de um config option (ids que sumiram). */
    public static function forgetValuesNotIn(int $configId, array $keepSubIds): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $q = $db::table(self::T_VALUES)->where('config_id', $configId);
            if ($keepSubIds !== []) {
                $q->whereNotIn('sub_id', array_map('intval', $keepSubIds));
            }
            $q->delete();
        } catch (\Throwable) {
        }
    }

    /** Esquece o grupo + todas as suas sub-options do mapa. */
    public static function forgetGroup(string $groupUuid): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $db::table(self::T_VALUES)
                ->where('group_uuid', $groupUuid)->delete();
            $db::table(self::T_GROUPS)
                ->where('group_uuid', $groupUuid)->delete();
        } catch (\Throwable) {
        }
    }

    /** Limpa todo o mapa (usado pelo `OptionGroups::remove`). */
    public static function clearAll(): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $db::table(self::T_VALUES)->delete();
            $db::table(self::T_GROUPS)->delete();
        } catch (\Throwable) {
        }
    }

    /**
     * Mapa de resolução persistido, no MESMO shape que
     * `OptionsMapper::valueMapFromGroups`: `optionName → {type, values:
     * {valueLabel → {option_id, option_value_id, is_default,
     * resource_key}}}`. Fail-soft `[]`.
     *
     * @return array<string,array{type:int,values:array<string,array<string,mixed>>}>
     */
    public static function valueMap(): array
    {
        try {
            $db = self::db();
            if ($db === null) {
                return [];
            }
            $out = [];
            foreach ($db::table(self::T_VALUES)->get() as $r) {
                $name = (string) $r->option_name;
                $label = (string) $r->value_label;
                if ($name === '' || $label === '') {
                    continue;
                }
                if (!isset($out[$name])) {
                    $out[$name] = [
                        'type' => (int) $r->option_type,
                        'values' => [],
                    ];
                }
                $out[$name]['values'][$label] = [
                    'option_id' => (string) $r->option_uuid,
                    'option_value_id' => (string) $r->option_value_uuid,
                    'is_default' => (int) $r->is_default === 1,
                    'resource_key' => $r->resource_key !== null
                        ? (string) $r->resource_key : null,
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
