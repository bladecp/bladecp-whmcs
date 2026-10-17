<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\Catalog;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Decide whether the components a customer picked can still be sold.
 *
 * The component catalog only lists components (and versions) that are
 * offered right now. A choice that no longer resolves against it, whether
 * picked from a category dropdown, from a combo or pinned to the product,
 * must stop the sale before checkout instead of failing (or silently
 * dropping the component) at provisioning.
 *
 * The pure methods take the catalog rows from `Catalog::catalog()`; the
 * cart method reads the configurable option tables of the BladeCP group.
 */
final class ComponentAvailability
{
    public const GROUP_NAME = 'BladeCP';
    public const COMBO_OPTION = 'Combo';
    public const LOCATION_OPTION = 'Location';

    /** "Nothing selected" values of the order form (UI sentinels). */
    private const NONE = ['', '0', '— não incluir —', '— Nenhum —'];

    /**
     * Catalog index: slug => [versionId => true]. An empty version list
     * means the catalog publishes no versions for that component.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @return array<string,array<int,true>>
     */
    public static function index(array $catalog): array
    {
        $out = [];
        foreach ($catalog as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $versions = [];
            foreach ((array) ($row['versions'] ?? []) as $v) {
                $id = is_array($v) ? (int) ($v['id'] ?? 0) : 0;
                if ($id > 0) {
                    $versions[$id] = true;
                }
            }
            $out[$slug] = $versions;
        }
        return $out;
    }

    /**
     * Tokens (`slug` or `slug@versionId`) that the catalog no longer
     * offers: unknown slug, or a pinned version the catalog stopped
     * listing. A version is only judged when the catalog lists versions
     * for that component.
     *
     * @param string[] $tokens
     * @param array<string,array<int,true>> $index
     * @return string[]
     */
    public static function unavailableTokens(array $tokens, array $index): array
    {
        $bad = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $ref = ComponentRef::parse($token);
            $slug = $ref['slug'];
            if (!isset($index[$slug])) {
                $bad[] = $token;
                continue;
            }
            if (
                $ref['version'] !== null
                && $index[$slug] !== []
                && !isset($index[$slug][$ref['version']])
            ) {
                $bad[] = $token;
            }
        }
        return array_values(array_unique($bad));
    }

    /**
     * Category choices (`optionName => subOptionLabel`) of the BladeCP group
     * that no longer resolve to a catalog component and version. Combo and
     * Location are not categories and are ignored here.
     *
     * @param array<string,mixed> $configoptions
     * @param string[] $categoryNames option names of the group's categories
     * @param array<int,array<string,mixed>> $catalog
     * @return array<string,string> category => label that cannot be sold
     */
    public static function unavailableSelections(
        array $configoptions,
        array $categoryNames,
        array $catalog
    ): array {
        $labels = [];
        foreach ($catalog as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string) ($c['name'] ?? ''));
            $slug = trim((string) ($c['slug'] ?? ''));
            if ($name === '' || $slug === '') {
                continue;
            }
            $cat = strtolower(trim((string) ($c['category'] ?? '')) ?: 'outros');
            $versions = is_array($c['versions'] ?? null) ? $c['versions'] : [];
            if ($versions === []) {
                $labels[$cat][ComponentRef::optionLabel($name, null)] = true;
                continue;
            }
            foreach ($versions as $v) {
                $labels[$cat][ComponentRef::optionLabel($name, trim((string) ($v['label'] ?? '')))] = true;
            }
        }

        $categories = [];
        foreach ($categoryNames as $n) {
            $n = trim((string) $n);
            if ($n !== '' && $n !== self::COMBO_OPTION && $n !== self::LOCATION_OPTION) {
                $categories[strtolower($n)] = $n;
            }
        }

        $bad = [];
        foreach ($configoptions as $key => $value) {
            $catKey = strtolower(trim((string) $key));
            if (!isset($categories[$catKey])) {
                continue;
            }
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $label = trim((string) $value);
            if (in_array($label, self::NONE, true)) {
                continue;
            }
            if (!isset($labels[$catKey][$label])) {
                $bad[$categories[$catKey]] = $label;
            }
        }
        return $bad;
    }

    /**
     * Option names of the BladeCP group's categories (everything except
     * Combo and Location). `null` when the group or the database is absent.
     *
     * @return string[]|null
     */
    public static function groupCategoryNames(): ?array
    {
        $group = self::groupOptions();
        if ($group === null) {
            return null;
        }
        $out = [];
        foreach ($group as $option) {
            $name = $option['name'];
            if ($name !== self::COMBO_OPTION && $name !== self::LOCATION_OPTION) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Customer-facing reason to stop a cart item, or null when it can be
     * sold. Only BladeCP products with a component choice (category, combo
     * or product combo) are judged; everything else passes untouched.
     *
     * @param array<int|string,mixed> $cartConfigoptions configid => sub-option id
     * @param (\Closure(): ?array)|null $catalogLoader defaults to the server's catalog
     */
    public static function errorForCartItem(
        int $productId,
        array $cartConfigoptions,
        ?\Closure $catalogLoader = null
    ): ?string {
        if ($productId <= 0) {
            return null;
        }
        $group = self::groupOptions();
        $choices = $group !== null ? self::cartChoices($group, $cartConfigoptions) : [];

        $comboName = trim((string) ($choices[self::COMBO_OPTION] ?? ''));
        $comboChosen = $comboName !== '' && !in_array($comboName, self::NONE, true);
        $comboTokens = $comboChosen
            ? ComboResolver::slugsForComboName($comboName)
            : ComboResolver::slugsForProduct($productId);
        $categoryChoices = [];
        foreach ($choices as $name => $label) {
            if ($name !== self::COMBO_OPTION && $name !== self::LOCATION_OPTION
                && !in_array($label, self::NONE, true)) {
                $categoryChoices[$name] = $label;
            }
        }

        if (!$comboChosen && $comboTokens === [] && $categoryChoices === []) {
            return null;
        }
        if ($comboChosen && $comboTokens === []) {
            return self::comboMessage($comboName);
        }

        $catalog = $catalogLoader !== null ? $catalogLoader() : self::catalog();
        if (!is_array($catalog)) {
            return ClientText::get('cart_components_unconfirmed');
        }

        // An active combo replaces the category choices at provisioning.
        if ($comboTokens !== []) {
            if (self::unavailableTokens($comboTokens, self::index($catalog)) === []) {
                return null;
            }
            $name = $comboChosen ? $comboName : ComboResolver::nameForProduct($productId);
            return self::comboMessage($name);
        }

        $bad = self::unavailableSelections($categoryChoices, array_keys($categoryChoices), $catalog);
        if ($bad === []) {
            return null;
        }
        $category = (string) array_key_first($bad);
        return ClientText::get('cart_option_unavailable', ['option' => $bad[$category], 'category' => $category]);
    }

    private static function comboMessage(string $name): string
    {
        $name = trim($name);
        return $name !== ''
            ? ClientText::get('cart_combo_unavailable', ['combo' => $name])
            : ClientText::get('cart_combo_unavailable_generic');
    }

    /** @return array<int,array<string,mixed>>|null */
    private static function catalog(): ?array
    {
        try {
            $client = ServerConfig::client();
            return $client === null ? null : Catalog::catalog($client);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Options of the BladeCP group: optionId => [name, subs: subId => label].
     *
     * @return array<int,array{name:string,subs:array<int,string>}>|null
     */
    private static function groupOptions(): ?array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $db = \WHMCS\Database\Capsule::class;
            $group = $db::table('tblproductconfiggroups')
                ->where('name', self::GROUP_NAME)
                ->first();
            if ($group === null) {
                return null;
            }
            $out = [];
            foreach ($db::table('tblproductconfigoptions')->where('gid', (int) $group->id)->get() as $o) {
                $subs = [];
                foreach ($db::table('tblproductconfigoptionssub')->where('configid', (int) $o->id)->get() as $s) {
                    $subs[(int) $s->id] = (string) $s->optionname;
                }
                $out[(int) $o->id] = ['name' => (string) $o->optionname, 'subs' => $subs];
            }
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cart configoptions (configid => sub-option id) restricted to the
     * BladeCP group, as optionName => sub-option label.
     *
     * @param array<int,array{name:string,subs:array<int,string>}> $group
     * @param array<int|string,mixed> $cartConfigoptions
     * @return array<string,string>
     */
    private static function cartChoices(array $group, array $cartConfigoptions): array
    {
        $out = [];
        foreach ($cartConfigoptions as $configId => $value) {
            $configId = (int) $configId;
            if (!isset($group[$configId]) || (!is_int($value) && !is_string($value))) {
                continue;
            }
            $subId = (int) $value;
            $label = $group[$configId]['subs'][$subId] ?? null;
            if ($label !== null) {
                $out[$group[$configId]['name']] = $label;
            }
        }
        return $out;
    }
}
