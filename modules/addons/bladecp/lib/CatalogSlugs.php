<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Mantém as URLs amigáveis dos grupos e produtos criados pelo catálogo.
 *
 * Prefere os models nativos do WHMCS para que as projeções internas de rota
 * sejam atualizadas. Instalações antigas continuam no fallback por Capsule.
 */
final class CatalogSlugs
{
    /** Cria ou reaproveita um grupo e preenche somente o slug que estiver vazio. */
    public static function ensureGroup(string $name, int $hidden): int
    {
        $name = trim($name);
        if ($name === '' || !class_exists(\WHMCS\Database\Capsule::class)) {
            return 0;
        }
        try {
            $id = self::ensureGroupThroughModel($name, $hidden);
            if ($id > 0) {
                return $id;
            }
            $columns = self::columns('tblproductgroups');
            if ($columns === []) {
                return 0;
            }
            $group = \WHMCS\Database\Capsule::table('tblproductgroups')
                ->where('name', $name)
                ->first();
            if ($group !== null) {
                $id = (int) $group->id;
                self::healGroupSlug($id, (string) ($group->slug ?? ''), $name, $columns);
                return $id;
            }

            $payload = ['name' => $name, 'hidden' => $hidden];
            if (isset($columns['slug'])) {
                $payload['slug'] = self::uniqueGroupSlug(self::slugify($name), null);
            }
            $id = (int) \WHMCS\Database\Capsule::table('tblproductgroups')
                ->insertGetId(array_intersect_key($payload, $columns));
            if ($id > 0 && isset($columns['slug'])) {
                self::healGroupSlug($id, '', $name, $columns);
            }
            return $id;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Repara o slug de um produto BladeCP criado diretamente pelo importador.
     * Em WHMCS que usa `tblproducts_slugs`, a reconciliação usa os models
     * nativos para manter a tabela auxiliar consistente.
     */
    public static function healProduct(int $productId, string $name): void
    {
        if ($productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if ($schema->hasTable('tblproducts_slugs')) {
                self::reconcileProductRoute($productId, null, $name);
                return;
            }
            $columns = self::columns('tblproducts');
            if (!isset($columns['slug'])) {
                return;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->first(['id', 'slug']);
            if ($product === null || trim((string) ($product->slug ?? '')) !== '') {
                return;
            }
            \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->update(['slug' => self::slugify($name, 'bladecp-product-' . $productId)]);
        } catch (\Throwable) {
        }
    }

    /**
     * Garante a rota canônica de um produto com o slug do seu grupo.
     * Rotas existentes, inclusive URLs amigáveis configuradas manualmente,
     * nunca são desativadas pelo importador.
     */
    public static function reconcileProductRoute(int $productId, ?int $groupId, string $name): void
    {
        if ($productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblproducts_slugs')) {
                self::healProduct($productId, $name);
                return;
            }
            $product = self::productModel($productId);
            if ($product === null) {
                return;
            }
            $groupId = $groupId !== null && $groupId > 0
                ? $groupId
                : (int) self::attribute($product, ['gid', 'group_id']);
            $groupSlug = self::groupSlug($groupId);
            $productSlug = trim((string) self::attribute($product, ['slug']));
            if ($productSlug === '') {
                $productSlug = self::ensureProductModelSlug($product, $productId, $name);
                $product = self::productModel($productId) ?? $product;
            }
            if ($groupSlug === '' || $productSlug === '') {
                return;
            }
            $routes = self::productRoutes($product);
            $plan = self::routePlan(array_map(static fn (mixed $route): array => [
                'id' => (int) self::attribute($route, ['id']),
                'active' => self::isRouteActive($route),
                'group_slug' => (string) self::attribute($route, ['group_slug', 'groupSlug']),
                'slug' => (string) self::attribute($route, ['slug', 'product_slug', 'productSlug']),
            ], $routes), $groupSlug, $productSlug);
            if ($plan['create']) {
                self::createProductSlug($product, $groupSlug, $productSlug);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Plano puro para a projeção de URL. A primeira rota ativa correta vence.
     * Não há limpeza automática, pois uma rota aparentemente divergente pode
     * ter sido criada manualmente pelo operador do WHMCS.
     *
     * @param list<array{id:int,active:bool,group_slug:string,slug:string}> $routes
     * @return array{keep:?int,deactivate:list<int>,create:bool}
     */
    public static function routePlan(array $routes, string $groupSlug, string $productSlug): array
    {
        $keep = null;
        foreach ($routes as $route) {
            if (!$route['active']) {
                continue;
            }
            $correct = $route['group_slug'] === $groupSlug && $route['slug'] === $productSlug;
            if ($correct && $keep === null) {
                $keep = $route['id'];
            }
        }
        return ['keep' => $keep, 'deactivate' => [], 'create' => $keep === null];
    }

    /** @return array<string,bool> */
    private static function columns(string $table): array
    {
        $columns = [];
        foreach (\WHMCS\Database\Capsule::schema()->getColumnListing($table) as $column) {
            $columns[(string) $column] = true;
        }
        return $columns;
    }

    /** @param array<string,bool> $columns */
    private static function healGroupSlug(int $id, string $current, string $name, array $columns): void
    {
        if ($id <= 0 || !isset($columns['slug']) || trim($current) !== '') {
            return;
        }
        \WHMCS\Database\Capsule::table('tblproductgroups')
            ->where('id', $id)
            ->update(['slug' => self::uniqueGroupSlug(self::slugify($name), $id)]);
    }

    private static function uniqueGroupSlug(string $base, ?int $exceptId): string
    {
        $base = self::slugify($base, 'bladecp-products');
        $slug = $base;
        $suffix = 2;
        while (true) {
            $query = \WHMCS\Database\Capsule::table('tblproductgroups')->where('slug', $slug);
            if ($exceptId !== null) {
                $query->where('id', '!=', $exceptId);
            }
            if ($query->first(['id']) === null) {
                return $slug;
            }
            $slug = $base . '-' . $suffix++;
        }
    }

    private static function ensureGroupThroughModel(string $name, int $hidden): int
    {
        $class = '\\WHMCS\\Product\\Group';
        if (!class_exists($class)) {
            return 0;
        }
        try {
            $group = $class::where('name', $name)->first();
            $isNew = $group === null;
            if ($group === null) {
                $group = new $class();
                self::setAttribute($group, ['name'], $name);
                self::setAttribute($group, ['isHidden', 'hidden'], $hidden !== 0);
            }
            $current = trim((string) self::attribute($group, ['slug']));
            if ($current === '') {
                self::setAttribute($group, ['slug'], self::uniqueGroupSlug(self::slugify($name), $isNew ? null : (int) self::attribute($group, ['id'])));
            }
            $group->save();
            return (int) self::attribute($group, ['id']);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function productModel(int $productId): mixed
    {
        $class = '\\WHMCS\\Product\\Product';
        return class_exists($class) ? $class::find($productId) : null;
    }

    private static function ensureProductModelSlug(mixed $product, int $productId, string $name): string
    {
        try {
            $slug = '';
            if (method_exists($product, 'autoGenerateUniqueSlug')) {
                $generated = $product->autoGenerateUniqueSlug();
                if (is_string($generated)) {
                    $slug = trim($generated);
                }
                $slug = $slug !== '' ? $slug : trim((string) self::attribute($product, ['slug']));
            }
            if ($slug === '') {
                $slug = self::slugify($name, 'bladecp-product-' . $productId);
            }
            self::setAttribute($product, ['slug'], $slug);
            $product->save();
            return trim((string) self::attribute($product, ['slug']));
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return list<mixed> */
    private static function productRoutes(mixed $product): array
    {
        try {
            if (method_exists($product, 'slugs')) {
                $relation = $product->slugs();
                $rows = method_exists($relation, 'get') ? $relation->get() : $relation;
            } else {
                $rows = $product->slugs ?? [];
            }
            if ($rows instanceof \Traversable) {
                return array_values(iterator_to_array($rows));
            }
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function createProductSlug(mixed $product, string $groupSlug, string $productSlug): void
    {
        if (!method_exists($product, 'createSlug')) {
            return;
        }
        $method = new \ReflectionMethod($product, 'createSlug');
        $count = $method->getNumberOfParameters();
        if ($count >= 2) {
            $product->createSlug($groupSlug, $productSlug);
        } elseif ($count === 1) {
            $product->createSlug($groupSlug);
        } else {
            $product->createSlug();
        }
    }

    private static function groupSlug(int $groupId): string
    {
        if ($groupId <= 0) {
            return '';
        }
        try {
            $class = '\\WHMCS\\Product\\Group';
            if (class_exists($class)) {
                $group = $class::find($groupId);
                if ($group !== null) {
                    $slug = trim((string) self::attribute($group, ['slug']));
                    if ($slug === '') {
                        self::setAttribute($group, ['slug'], self::uniqueGroupSlug(
                            self::slugify((string) self::attribute($group, ['name'])),
                            $groupId
                        ));
                        $group->save();
                        $slug = trim((string) self::attribute($group, ['slug']));
                    }
                    return $slug;
                }
            }
            $columns = self::columns('tblproductgroups');
            if (!isset($columns['slug'])) {
                return '';
            }
            $group = \WHMCS\Database\Capsule::table('tblproductgroups')
                ->where('id', $groupId)
                ->first(['name', 'slug']);
            if ($group === null) {
                return '';
            }
            $slug = trim((string) ($group->slug ?? ''));
            if ($slug === '') {
                self::healGroupSlug($groupId, '', (string) ($group->name ?? ''), $columns);
                $slug = trim((string) \WHMCS\Database\Capsule::table('tblproductgroups')
                    ->where('id', $groupId)
                    ->value('slug'));
            }
            return $slug;
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isRouteActive(mixed $route): bool
    {
        $value = self::attribute($route, ['active', 'is_active', 'isActive']);
        return $value === null ? true : (bool) $value;
    }

    /** @param list<string> $names */
    private static function attribute(mixed $model, array $names): mixed
    {
        foreach ($names as $name) {
            try {
                if (is_object($model) && method_exists($model, 'getAttribute')) {
                    $value = $model->getAttribute($name);
                } elseif (is_object($model)) {
                    $value = $model->{$name} ?? null;
                } else {
                    $value = null;
                }
                if ($value !== null) {
                    return $value;
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    /** @param list<string> $names */
    private static function setAttribute(mixed $model, array $names, mixed $value): void
    {
        $name = $names[0];
        foreach ($names as $candidate) {
            try {
                if (!is_object($model) || !method_exists($model, 'getAttribute') || $model->getAttribute($candidate) !== null) {
                    $name = $candidate;
                    break;
                }
            } catch (\Throwable) {
            }
        }
        if (method_exists($model, 'setAttribute')) {
            $model->setAttribute($name, $value);
            return;
        }
        $model->{$name} = $value;
    }

    /** Normaliza um nome legível para um slug ASCII determinístico. */
    public static function slugify(string $value, string $fallback = 'bladecp-products'): string
    {
        $map = [];
        foreach ([
            'a' => ['à', 'á', 'â', 'ã', 'ä', 'å', 'ā', 'À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Ā'],
            'c' => ['ç', 'ć', 'č', 'Ç', 'Ć', 'Č'],
            'e' => ['è', 'é', 'ê', 'ë', 'ē', 'ė', 'ę', 'È', 'É', 'Ê', 'Ë', 'Ē', 'Ė', 'Ę'],
            'i' => ['ì', 'í', 'î', 'ï', 'ī', 'į', 'Ì', 'Í', 'Î', 'Ï', 'Ī', 'Į'],
            'n' => ['ñ', 'ń', 'Ñ', 'Ń'],
            'o' => ['ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'ō', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø', 'Ō'],
            'u' => ['ù', 'ú', 'û', 'ü', 'ū', 'Ù', 'Ú', 'Û', 'Ü', 'Ū'],
            'y' => ['ÿ', 'ý', 'Ÿ', 'Ý'],
            'z' => ['ž', 'Ž'],
        ] as $ascii => $chars) {
            foreach ($chars as $char) {
                $map[$char] = $ascii;
            }
        }
        $value = strtr($value, $map);
        $value = preg_replace('/[^\x00-\x7F]+/', '', $value) ?? '';
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : $fallback;
    }
}
