<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp {
    if (!defined('WHMCS')) {
        die('This file cannot be accessed directly');
    }

    /**
     * Registra os autoloaders nativos do pacote BladeCP sem Composer.
     *
     * Cada entrypoint do WHMCS pode ser carregado isoladamente. Por isso o
     * bootstrap é idempotente e o addon informa explicitamente a sua ponte
     * somente leitura para as classes do server module.
     */
    final class Bootstrap
    {
        /** @var array<string,true> */
        private static array $registered = [];

        public static function registerServerAutoloader(string $moduleRoot): void
        {
            self::registerPrefix(
                'WHMCS\\Module\\Server\\Bladecp\\',
                rtrim($moduleRoot, '/') . '/lib/'
            );
        }

        public static function registerAddonAutoloaders(
            string $addonRoot,
            string $serverRoot
        ): void {
            self::registerPrefix(
                'WHMCS\\Module\\Addon\\Bladecp\\',
                rtrim($addonRoot, '/') . '/lib/'
            );
            self::registerServerAutoloader($serverRoot);
        }

        private static function registerPrefix(string $prefix, string $base): void
        {
            $key = $prefix . "\0" . $base;
            if (isset(self::$registered[$key])) {
                return;
            }
            self::$registered[$key] = true;

            spl_autoload_register(static function (string $class) use ($prefix, $base): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                if ($relative === '') {
                    return;
                }
                $path = $base . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            });
        }
    }
}
