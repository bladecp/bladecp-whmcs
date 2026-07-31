<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Ícones Lucide (lucide.dev, MIT) em SVG inline, sem dependência externa.
 * `stroke=currentColor` acompanha a cor do contexto visual. A classe também
 * pode ser reutilizada pelo complemento administrativo.
 */
final class Icons
{
    /** Paths Lucide (viewBox 24, stroke-width 2, round). */
    private const PATHS = [
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 '
            . '2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 '
            . '1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
            . '<polyline points="7 10 12 15 17 10"/>'
            . '<line x1="12" x2="12" y1="15" y2="3"/>',
        'package' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1'
            . '-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 '
            . '1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>'
            . '<path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'server' => '<rect width="20" height="8" x="2" y="2" rx="2"/>'
            . '<rect width="20" height="8" x="2" y="14" rx="2"/>'
            . '<line x1="6" x2="6.01" y1="6" y2="6"/>'
            . '<line x1="6" x2="6.01" y1="18" y2="18"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5'
            . '-5.5Z"/><path d="m15 5 4 4"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 '
            . '2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
            . '<line x1="10" x2="10" y1="11" y2="17"/>'
            . '<line x1="14" x2="14" y1="11" y2="17"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'layers' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 '
            . '0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/>'
            . '<path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58'
            . '-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 '
            . '2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
        'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/>'
            . '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 '
            . '1 2-2h6"/>',
        'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"'
            . '/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 '
            . '1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 '
            . '4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'wallet' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 '
            . '0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2"/>'
            . '<path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'webhook' => '<path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 '
            . '0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53'
            . '-.97.43-2.22-.26-3.07A4 4 0 0 1 17.59 6"/><path d="m12 6 '
            . '3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/>',
    ];

    /** SVG inline pronto pra concatenar antes do texto do botão. */
    public static function get(string $name, int $size = 15): string
    {
        $p = self::PATHS[$name] ?? '';
        if ($p === '') {
            return '';
        }
        $s = (string) $size;
        return '<svg class="bcp-ico" xmlns="http://www.w3.org/2000/svg" '
            . 'width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" '
            . 'fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true">' . $p . '</svg>';
    }
}
