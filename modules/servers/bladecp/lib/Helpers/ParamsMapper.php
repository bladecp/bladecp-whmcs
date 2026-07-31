<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Traduz `$params` do WHMCS → body do `POST /customer/provisions/simple`
 * (create simplificado server-to-server — o backend resolve
 * product/license/partner/cloud_provider a partir do `partner_plan_id`).
 * Stack Container usa este payload. A seleção assistida de componentes é
 * resolvida antes da criação da provisão.
 *
 * `owner {email,name}` identifica o cliente final. A credencial representa
 * a conta parceira, não o cliente. `external_service_id` identifica o
 * serviço no WHMCS.
 */
final class ParamsMapper
{
    /**
     * @param string[] $componentTokens lista FINAL de tokens
     *   (`slug` ou `slug@versionId`) já resolvida pelo chamador —
     *   Um combo ativo substitui a seleção manual. NÃO mescla mais
     *   `componentsFromParams` aqui (o chamador decide override vs
     *   manual; evita re-adicionar avulsos quando o combo sobrepõe).
     * @param array<string,int> $defaultVersionBySlug slug → id da
     *   versão padrão do catálogo. Quando o token não fixa uma
     *   versão, manda-se a default EXPLICITAMENTE (BladeCP não usa
     *   `:latest`). Sem default conhecida → sem `component_version`
     *   (não há versão pra pinar — único caso de omissão legítima).
     * @param array<string,int> $locationLabelToId rótulo da
     *   location (Configurable Option Location escolhida pelo cliente)
     *   → id. Se `configoptions.Location` for numérico (ConfigOption
     *   de módulo legado) usa direto; senão resolve por label.
     * @param array<int,array{option_id:string,option_value_id:string,quantity:int}> $options
     *   Opções configuráveis já resolvidas (`OptionsMapper::toOptions`).
     *   Inclui somente seleções não padrão. Uma lista vazia omite a chave
     *   `options` do payload.
     * @param bool $includeEmptyComponents Quando o tipo do produto aceita
     *   uma lista vazia (Edge e Dedicated), preserva `components: []` no
     *   contrato público em vez de omiti-la.
     * @param bool $includeContractFields Inclui os dois campos contratuais
     *   do contrato Customer API, inclusive `null` e `false`.
     */
    public static function toStackCreatePayload(
        array $params,
        array $componentTokens = [],
        array $defaultVersionBySlug = [],
        array $locationLabelToId = [],
        array $options = [],
        bool $includeEmptyComponents = false,
        bool $includeContractFields = false
    ): array {
        $opts = $params['configoptions'] ?? [];
        $locRaw = $opts['Location'] ?? null;
        $locationId = self::locationId($locRaw, $locationLabelToId);
        $payload = [
            'partner_plan_id' => self::int(
                $opts['BladeCP Plan'] ?? $params['configoption2'] ?? null
            ),
            'location_id' => $locationId > 0 ? $locationId : null,
            'domain' => $params['domain'] ?? '',
            'owner' => self::buildOwner($params),
        ];
        $serviceId = $params['serviceid'] ?? null;
        if ($serviceId !== null && (string) $serviceId !== '') {
            $payload['external_service_id'] = (string) $serviceId;
        }
        $components = self::componentsPayload(
            $componentTokens,
            $defaultVersionBySlug
        );
        if ($components !== [] || $includeEmptyComponents) {
            $payload['components'] = $components;
        }
        if ($options !== []) {
            $payload['options'] = array_values($options);
        }
        if ($includeContractFields) {
            $contract = self::contractFieldsFromParams($params);
            $payload['contract_term_months'] = $contract['termMonths'];
            $payload['contract_accepted'] = $contract['accepted'];
        }
        // owner é array (não vazio) — array_filter com a callback
        // padrão removeria []; aqui owner sempre tem email/name.
        return array_filter(
            $payload,
            static fn ($v, $key) => $key === 'contract_term_months'
                || ($v !== null && $v !== ''),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Campos contratuais do contrato Customer API. Os aliases preservam
     * instalações WHMCS que já expõem esses valores fora de ConfigOptions.
     * A API continua sendo a autoridade para termos disponíveis e
     * obrigatoriedade do aceite.
     *
     * @return array{termMonths:?int,accepted:bool}
     */
    public static function contractFieldsFromParams(array $params): array
    {
        $opts = is_array($params['configoptions'] ?? null)
            ? $params['configoptions']
            : [];
        $term = $opts['BladeCP Contract Term Months']
            ?? $opts['Contract Term Months']
            ?? $opts['BladeCP Contract Term']
            ?? $opts['Contract Term']
            ?? $params['contract_term_months']
            ?? null;
        if (is_numeric($term)) {
            $termMonths = (int) $term;
        } elseif (
            preg_match(
                '/^([0-9]+)(?:\||\s*mes(?:es)?\b)/i',
                trim((string) $term),
                $match
            ) === 1
        ) {
            $termMonths = (int) $match[1];
        } else {
            $termMonths = null;
        }
        if ($termMonths !== null && $termMonths <= 0) {
            $termMonths = null;
        }

        $accepted = $opts['BladeCP Contract Accepted']
            ?? $opts['Contract Accepted']
            ?? $opts['BladeCP Contract Acceptance']
            ?? $opts['Contract Acceptance']
            ?? $params['contract_accepted']
            ?? false;

        return [
            'termMonths' => $termMonths,
            'accepted' => self::bool($accepted),
        ];
    }

    /** A API só considera sucesso de criação quando devolve UUID canônico. */
    public static function isCanonicalUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di',
            $value
        ) === 1;
    }

    /**
     * Valida uma URL recebida da API antes de encaminhá-la ao navegador
     * do cliente. SSO só pode redirecionar para HTTPS absoluto: uma
     * resposta 2xx sem URL utilizável não representa sucesso funcional.
     */
    public static function isAbsoluteHttpsUrl(mixed $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
        $parts = parse_url(trim($value));
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    /**
     * Confirma que a location escolhida ainda aparece na lista atual do
     * plano de destino. Uma lista vazia também bloqueia: não é seguro
     * assumir que uma resposta indisponível autoriza a provisão.
     *
     * @param array<string|int,string> $locations
     */
    public static function validateLocationForPlan(
        mixed $locationId,
        array $locations
    ): ?string {
        $id = is_numeric($locationId) ? (int) $locationId : 0;
        if ($id <= 0) {
            return 'BladeCP: selecione uma localização válida para este plano antes de provisionar.';
        }
        foreach (array_keys($locations) as $availableId) {
            if (is_numeric($availableId) && (int) $availableId === $id) {
                return null;
            }
        }
        return 'BladeCP: não foi possível confirmar que a localização escolhida está disponível para este plano. Atualize a seleção e tente novamente.';
    }

    /**
     * Valida os campos contratuais contra o plano confirmado no preflight.
     * A Customer API segue sendo a autoridade final, mas esta barreira evita
     * enviar PATCH/POST que já é sabidamente incompatível com o destino.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $plan
     */
    public static function validateContractForPlan(array $payload, ?array $plan): ?string
    {
        $term = $payload['contract_term_months'] ?? null;
        $term = is_numeric($term) && (int) $term > 0 ? (int) $term : null;
        $accepted = self::bool($payload['contract_accepted'] ?? false);
        $requires = self::bool($plan['requiresContract'] ?? $plan['requires_contract'] ?? false);
        $available = self::contractTermsFromPlan($plan);

        if ($requires && $term === null) {
            return 'BladeCP: selecione o prazo do contrato antes de provisionar este serviço.';
        }
        if ($term !== null && !$accepted) {
            return 'BladeCP: aceite os termos do contrato antes de provisionar este serviço.';
        }
        if ($term !== null && $available !== [] && !in_array($term, $available, true)) {
            return 'BladeCP: o prazo escolhido não está disponível para este plano.';
        }
        return null;
    }

    /** @param array<string,mixed>|null $plan @return int[] */
    private static function contractTermsFromPlan(?array $plan): array
    {
        if (!is_array($plan)) {
            return [];
        }
        $raw = $plan['contractTerms'] ?? $plan['contract_terms'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (is_array($value)) {
                $value = $value['months'] ?? $value['termMonths'] ?? null;
            }
            if (is_numeric($value) && (int) $value > 0) {
                $out[] = (int) $value;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Identidade do CLIENTE FINAL (cliente WHMCS do partner). O
     * backend find-or-create o customer global BladeCP por email.
     *
     * @return array{name: string, email: string}
     */
    public static function buildOwner(array $params): array
    {
        $cd = $params['clientsdetails'] ?? [];
        return [
            'name' => trim(
                ($cd['firstname'] ?? '') . ' ' . ($cd['lastname'] ?? '')
            ),
            'email' => (string) ($cd['email'] ?? ''),
        ];
    }

    /**
     * Slugs de componentes escolhidos no produto WHMCS. Lê o
     * ConfigOption `BladeCP Components` (lista separada por vírgula —
     * WHMCS não tem multi-select nativo em ConfigOptions). Trim + dedupe + drop vazio.
     *
     * @return string[]
     */
    public static function componentsFromParams(array $params): array
    {
        $raw = $params['configoptions']['BladeCP Components'] ?? '';
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $slugs = [];
        foreach (explode(',', $raw) as $part) {
            $slug = trim($part);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Mescla slugs do combo do produto e itens avulsos. Ordem
     * determinística (combo primeiro, depois avulsos), trim, dedupe
     * e remoção de entradas vazias.
     *
     * @param string[] $combo
     * @param string[] $direct
     * @return string[]
     */
    public static function mergeComponentSlugs(array $combo, array $direct): array
    {
        $out = [];
        foreach (array_merge($combo, $direct) as $s) {
            $s = trim((string) $s);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Tokens (`slug` | `slug@versionId`) → payload
     * `[{slug, component_version?}]` do `/provisions/simple`.
     * Versão = a pinada no token; senão a DEFAULT do catálogo
     * (`$defaultVersionBySlug`) — pin EXPLÍCITO (BladeCP não usa
     * `:latest`). Sem default conhecida → sem `component_version`
     * (nada pra pinar). Dedupe por slug; entrada COM versão tem
     * precedência sobre a mesma slug sem versão. Pura.
     *
     * @param string[] $tokens
     * @param array<string,int> $defaultVersionBySlug
     * @return array<int,array{slug:string,component_version?:int}>
     */
    public static function componentsPayload(
        array $tokens,
        array $defaultVersionBySlug = []
    ): array {
        $bySlug = [];
        $order = [];
        foreach ($tokens as $tok) {
            $r = ComponentRef::parse((string) $tok);
            $slug = $r['slug'];
            if ($slug === '') {
                continue;
            }
            $ver = $r['version'];
            if ($ver === null && isset($defaultVersionBySlug[$slug])) {
                $ver = (int) $defaultVersionBySlug[$slug];
                $ver = $ver > 0 ? $ver : null;
            }
            if (!array_key_exists($slug, $bySlug)) {
                $order[] = $slug;
                $bySlug[$slug] = $ver;
            } elseif ($bySlug[$slug] === null && $ver !== null) {
                $bySlug[$slug] = $ver; // versão tem precedência
            }
        }
        $out = [];
        foreach ($order as $slug) {
            $item = ['slug' => $slug];
            if ($bySlug[$slug] !== null) {
                $item['component_version'] = $bySlug[$slug];
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Slugs escolhidos pelo cliente no order form (WHMCS Configurable
     * Options sincronizadas).
     *
     * O cliente recebe em `$params['configoptions']` keyed pelo
     * `optionname`:
     *  - componente com versões: dropdown → valor = rótulo da
     *    sub-option escolhida (`v{n}` / `v{n} (default)` / sentinela
     *    "— não incluir —"). Resolve `v{n}` → id da versão via
     *    catálogo → TOKEN `slug@id` (pin explícito).
     *  - componente SEM versões: yes/no → `== '1'` → slug puro
     *    (a versão padrão é resolvida depois).
     *  - combo (dropdown): `['Combo'] == '<Nome do combo>'`.
     *
     * Casa só chaves que são nome de componente do catálogo ou
     * 'Combo' — ConfigOptions de módulo ('BladeCP Plan', etc.) não
     * casam → ignorados. Pura (catálogo passado, sem rede/DB).
     *
     * @param array<string,mixed> $configoptions  $params['configoptions']
     * @param array<int,array{slug:string,name:string,versions?:array}> $catalog
     * @param array<string,string[]> $comboNameToSlugs  nome do combo → tokens
     * @return string[] tokens (`slug` ou `slug@versionId`)
     */
    public static function orderFormSlugs(
        array $configoptions,
        array $catalog,
        array $comboNameToSlugs
    ): array {
        // sentinelas de "não selecionado" (rótulos de UI).
        $none = ['', '0', '— não incluir —', '— Nenhum —'];
        // mapa por CATEGORIA: rótulo da sub-option → token. O rótulo
        // vem de ComponentRef::optionLabel (mesma fonte do desiredSpec
        // → match exato, sem parsing frágil).
        $byCat = []; // catLower => [label => token]
        foreach ($catalog as $c) {
            $n = trim((string) ($c['name'] ?? ''));
            $s = trim((string) ($c['slug'] ?? ''));
            if ($n === '' || $s === '') {
                continue;
            }
            $cat = strtolower(trim((string) ($c['category'] ?? '')) ?: 'outros');
            $vs = is_array($c['versions'] ?? null) ? $c['versions'] : [];
            if ($vs === []) {
                $byCat[$cat][ComponentRef::optionLabel($n, null)] = $s;
                continue;
            }
            foreach ($vs as $v) {
                $vl = trim((string) ($v['label'] ?? ''));
                $vid = (int) ($v['id'] ?? 0);
                $byCat[$cat][ComponentRef::optionLabel($n, $vl)] =
                    ComponentRef::compose($s, $vid > 0 ? $vid : null);
            }
        }
        $out = [];
        foreach ($configoptions as $key => $val) {
            $key = (string) $key;
            if ($key === 'Combo') {
                $cn = trim((string) $val);
                if ($cn !== '' && isset($comboNameToSlugs[$cn])) {
                    foreach ($comboNameToSlugs[$cn] as $s) {
                        $out[] = (string) $s;
                    }
                }
                continue;
            }
            if ($key === 'Location') {
                continue; // location vira location_id (toStackCreatePayload)
            }
            $catKey = strtolower(trim($key));
            if (!isset($byCat[$catKey])) {
                continue; // não é uma categoria do catálogo
            }
            $v = is_string($val) ? trim($val) : $val;
            if (in_array($v, $none, true) || $v === false || $v === null) {
                continue;
            }
            if (is_string($v) && isset($byCat[$catKey][$v])) {
                $out[] = $byCat[$catKey][$v]; // token resolvido (exato)
            }
            // não casou um rótulo conhecido → ignora (não
            // inventam slug; o cliente não selecionou nada válido).
        }
        // dedupe + drop vazio, ordem preservada (tokens, não stripa @).
        $clean = [];
        foreach ($out as $s) {
            $s = trim((string) $s);
            if ($s !== '' && !in_array($s, $clean, true)) {
                $clean[] = $s;
            }
        }
        return $clean;
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true)
            || str_starts_with($normalized, '1|');
    }

    /** @param array<string,int> $locationLabelToId */
    private static function locationId(
        mixed $value,
        array $locationLabelToId
    ): int {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }
        $raw = trim((string) $value);
        // WHMCS Configurable Options usa `valor|rótulo` para separar a
        // identidade persistida do texto apresentado ao cliente.
        if (preg_match('/^([1-9][0-9]*)\|/', $raw, $match) === 1) {
            return (int) $match[1];
        }
        return (int) ($locationLabelToId[$raw] ?? 0);
    }
}
