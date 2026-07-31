<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Referência a componente com versão fixada pelo token `slug@versionId`.
 * BladeCP não usa `:latest`. Esta é a fonte única do formato, usada
 * pelo addon (Combos) e pelo server module (ParamsMapper) — o addon
 * acessa via a ponte de autoload.
 *
 * Retrocompat: token sem `@` (combo/ConfigOption antigo só com slug)
 * → `version = null`; o provisioning resolve a versão DEFAULT do
 * catálogo EXPLICITAMENTE (nunca omite contando com latest do backend).
 *
 * Slug é dns-1123 (sem `@`), então split no primeiro `@` é seguro.
 */
final class ComponentRef
{
    /** `(slug, 8) → "slug@8"`; versão nula/≤0 → só o slug. */
    public static function compose(string $slug, ?int $versionId): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        return $versionId !== null && $versionId > 0
            ? $slug . '@' . $versionId
            : $slug;
    }

    /**
     * `"slug@8" → ['slug'=>'slug','version'=>8]`;
     * `"slug" → ['slug'=>'slug','version'=>null]`.
     *
     * @return array{slug:string,version:?int}
     */
    public static function parse(string $token): array
    {
        $token = trim($token);
        $at = strpos($token, '@');
        if ($at === false) {
            return ['slug' => $token, 'version' => null];
        }
        $slug = substr($token, 0, $at);
        $ver = substr($token, $at + 1);
        return [
            'slug' => trim($slug),
            'version' => is_numeric($ver) && (int) $ver > 0
                ? (int) $ver
                : null,
        ];
    }

    /** Só o slug do token (descarta a versão). */
    public static function slug(string $token): string
    {
        return self::parse($token)['slug'];
    }

    /**
     * Rótulo da sub-option do order form (dropdown por
     * categoria). FONTE ÚNICA do mapeamento label↔(componente,versão):
     * usado por `ConfigurableOptions::desiredSpec` (addon, cria a
     * sub-option) e por `ParamsMapper::orderFormSlugs` (resolve a
     * escolha do cliente de volta). Exato e reversível — sem sufixo
     * "(default)" pra não complicar o match (a ordem das subs já
     * coloca a default 1ª).
     *
     * Recebe o rótulo legível da versão, derivado de `image`,
     * ex.: "8.4"), não mais o ordinal int. `("PHP","8.4") →
     * "PHP 8.4"`; `("ttyd",null|"") → "ttyd"`. Determinístico →
     * desiredSpec e orderFormSlugs geram o MESMO texto (match exato).
     */
    public static function optionLabel(string $name, ?string $version): string
    {
        $name = trim($name);
        $v = $version !== null ? trim($version) : '';
        return $v !== '' ? $name . ' ' . $v : $name;
    }
}
