<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class VibePromptCard
{
    private static bool $registered = false;

    public static function registerHook(): void
    {
        if (self::$registered || !function_exists('add_hook')) {
            return;
        }
        self::$registered = true;
        add_hook('ClientAreaHomepage', 1, static function (): string {
            try {
                return self::render();
            } catch (\Throwable) {
                return '';
            }
        });
    }

    public static function render(): string
    {
        $context = VibePromptContext::current();
        if ($context === null) {
            return '';
        }
        $locale = $_SESSION['Language'] ?? $GLOBALS['CONFIG']['Language'] ?? 'english';
        $copy = self::copy(is_string($locale) ? $locale : 'english');
        $systemUrl = $GLOBALS['CONFIG']['SystemURL'] ?? '';
        $basePath = self::basePath(is_string($systemUrl) ? $systemUrl : '');
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $icon = static fn (string $name): string => self::icon($name);
        ob_start();
        try {
            require dirname(__DIR__) . '/templates/vibe-prompt.php';
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    /** @return array<string,string> */
    public static function copy(string $locale): array
    {
        $locale = match (strtolower(str_replace('_', '-', $locale))) {
            'pt', 'pt-br', 'pt-pt', 'portuguese', 'portuguese-br', 'portuguese-pt', 'portugues', 'portugues-br' => 'portuguese-br',
            'es', 'es-es', 'spanish' => 'spanish',
            default => 'english',
        };
        return require dirname(__DIR__) . '/lang/client/' . $locale . '.php';
    }

    /** Small inline icons, no external font or image request. */
    public static function icon(string $name): string
    {
        $paths = [
            'sparkles' => '<path d="m12 3 2.2 6.8L21 12l-6.8 2.2L12 21l-2.2-6.8L3 12l6.8-2.2Z"/><path d="m20 2 .5 1.5L22 4l-1.5.5L20 6l-.5-1.5L18 4l1.5-.5Z"/>',
            'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8" cy="9" r="1.5"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
            'enter' => '<path d="M20 5v7a3 3 0 0 1-3 3H4m5-5-5 5 5 5"/>',
            'brain' => '<path d="M12 4a3 3 0 0 0-5-1 3 3 0 0 0-3 5 4 4 0 0 0 0 7 3 3 0 0 0 3 5 3 3 0 0 0 5-1V4Zm0 0a3 3 0 0 1 5-1 3 3 0 0 1 3 5 4 4 0 0 1 0 7 3 3 0 0 1-3 5 3 3 0 0 1-5-1M7 7l2 2m8-2-2 2M7 16l2-2m8 2-2-2"/>',
            'mic' => '<rect x="9" y="2" width="6" height="13" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2M12 19v3m-4 0h8"/>',
            'bolt' => '<path d="m13 2-9 12h7l-1 8 10-13h-7l1-7Z"/>',
            'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
            'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
            'store' => '<path d="M5 8V4h14v4M4 8h16l1 5H3l1-5Zm1 5v8h14v-8M9 21v-5h6v5"/>',
            'camera' => '<path d="m8 5 2-2h4l2 2h4v15H4V5Z"/><circle cx="12" cy="12" r="4"/>',
            'article' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h4M8 18h8"/>',
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        ];
        return isset($paths[$name]) ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>' : '';
    }

    public static function basePath(string $systemUrl): string
    {
        $path = parse_url($systemUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return '';
        }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\\\\\x00-\x20?#]/', $path) === 1) {
            return '';
        }
        return rtrim($path, '/');
    }
}
