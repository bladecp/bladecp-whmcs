<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Contexto administrativo da conta BladeCP usada pelas telas globais do
 * addon. A escolha guarda somente o ID nativo de `tblservers`; credenciais
 * continuam exclusivamente no Server record.
 *
 * Este contexto nunca participa do provisionamento. Os callbacks de módulo
 * continuam recebendo o servidor associado ao produto pelo próprio WHMCS.
 */
final class AdminServerContext
{
    public const SETTING = 'admin_server_id';

    /** @var list<string> */
    private const STATES = ['configured', 'missing', 'ambiguous', 'invalid'];

    /**
     * Resolve o servidor para operações administrativas globais.
     *
     * @return array{
     *   state:'configured'|'missing'|'ambiguous'|'invalid',
     *   serverId:?int,
     *   automatic:bool,
     *   server:?array{id:int,name:string,hostname:string,port:string,secure:bool},
     *   candidates:list<array{id:int,name:string,hostname:string,port:string,secure:bool}>
     * }
     */
    public static function resolve(): array
    {
        $candidates = self::candidates();
        $selection = self::selection();

        if ($selection['present']) {
            $id = $selection['id'];
            if ($id === null) {
                return self::context('invalid', null, false, null, $candidates);
            }

            foreach ($candidates as $candidate) {
                if ($candidate['id'] === $id) {
                    return self::context('configured', $id, false, $candidate, $candidates);
                }
            }

            // Um ID persistido jamais pode cair silenciosamente em outro
            // record, mesmo se agora existir apenas um candidato válido.
            return self::context('invalid', $id, false, null, $candidates);
        }

        if (count($candidates) === 1) {
            $candidate = $candidates[0];

            return self::context(
                'configured',
                $candidate['id'],
                true,
                $candidate,
                $candidates
            );
        }

        if ($candidates === []) {
            return self::context('missing', null, false, null, []);
        }

        return self::context('ambiguous', null, false, null, $candidates);
    }

    /**
     * Lista servidores BladeCP ativos e utilizáveis, sem API key ou outro
     * segredo. Pode ser exibida diretamente no seletor administrativo.
     *
     * @return list<array{id:int,name:string,hostname:string,port:string,secure:bool}>
     */
    public static function candidates(): array
    {
        $db = self::db();
        if ($db === null) {
            return [];
        }

        try {
            $rows = $db::table('tblservers')
                ->where('type', 'bladecp')
                ->where('disabled', 0)
                ->orderBy('id')
                ->get();
            $out = [];
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                // Sem Access Hash não há uma conta utilizável para as ações
                // globais, portanto não é candidato configurável.
                if ($id <= 0 || trim((string) ($row->accesshash ?? '')) === '') {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'name' => trim((string) ($row->name ?? '')),
                    'hostname' => trim((string) ($row->hostname ?? '')),
                    'port' => trim((string) ($row->port ?? '')),
                    'secure' => !empty($row->secure),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** ID selecionado quando o valor persistido é um inteiro positivo. */
    public static function selectedId(): ?int
    {
        return self::selection()['id'];
    }

    /**
     * Persiste exclusivamente o ID de um candidato atual. Nenhum segredo do
     * Server record é lido, copiado ou duplicado nesta operação.
     */
    public static function setSelectedId(?int $serverId): bool
    {
        if ($serverId === null) {
            return self::writeSelection('');
        }
        if ($serverId <= 0 || self::candidate($serverId) === null) {
            return false;
        }

        return self::writeSelection((string) $serverId);
    }

    /** Atalho explícito para remover a seleção administrativa. */
    public static function clearSelectedId(): bool
    {
        return self::setSelectedId(null);
    }

    /** @return array{id:int,name:string,hostname:string,port:string,secure:bool}|null */
    public static function candidate(int $serverId): ?array
    {
        foreach (self::candidates() as $candidate) {
            if ($candidate['id'] === $serverId) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return class-string<\WHMCS\Database\Capsule>|null */
    private static function db(): ?string
    {
        return class_exists(\WHMCS\Database\Capsule::class)
            ? \WHMCS\Database\Capsule::class
            : null;
    }

    /** @return array{present:bool,id:?int} */
    private static function selection(): array
    {
        $db = self::db();
        if ($db === null) {
            return ['present' => false, 'id' => null];
        }

        try {
            $row = $db::table('tbladdonmodules')
                ->where('module', 'bladecp')
                ->where('setting', self::SETTING)
                ->first();
            if ($row === null) {
                return ['present' => false, 'id' => null];
            }
            $value = trim((string) ($row->value ?? ''));
            if ($value === '') {
                return ['present' => false, 'id' => null];
            }
            if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
                return ['present' => true, 'id' => null];
            }

            return ['present' => true, 'id' => (int) $value];
        } catch (\Throwable) {
            return ['present' => false, 'id' => null];
        }
    }

    private static function writeSelection(string $value): bool
    {
        $db = self::db();
        if ($db === null) {
            return false;
        }

        try {
            $query = $db::table('tbladdonmodules')
                ->where('module', 'bladecp')
                ->where('setting', self::SETTING);
            if ($query->exists()) {
                $query->update(['value' => $value]);
            } else {
                $db::table('tbladdonmodules')->insert([
                    'module' => 'bladecp',
                    'setting' => self::SETTING,
                    'value' => $value,
                ]);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param 'configured'|'missing'|'ambiguous'|'invalid' $state
     * @param list<array{id:int,name:string,hostname:string,port:string,secure:bool}> $candidates
     * @param array{id:int,name:string,hostname:string,port:string,secure:bool}|null $server
     * @return array{state:'configured'|'missing'|'ambiguous'|'invalid',serverId:?int,automatic:bool,server:?array{id:int,name:string,hostname:string,port:string,secure:bool},candidates:list<array{id:int,name:string,hostname:string,port:string,secure:bool}>}
     */
    private static function context(
        string $state,
        ?int $serverId,
        bool $automatic,
        ?array $server,
        array $candidates
    ): array {
        if (!in_array($state, self::STATES, true)) {
            $state = 'missing';
        }

        return [
            'state' => $state,
            'serverId' => $serverId,
            'automatic' => $automatic,
            'server' => $server,
            'candidates' => $candidates,
        ];
    }
}
