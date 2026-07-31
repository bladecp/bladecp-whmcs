<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Helpers\ClientLocale;
use WHMCS\Module\Server\Bladecp\Helpers\ProvisionView;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Renders the client service area. */
final class ClientArea
{
    public static function run(array $params): array
    {
        $data = ProvisionView::resolve($params);

        // Keep the client area available when the optional panel cannot render.
        $html = '';
        try {
            $session = is_array($_SESSION ?? null) ? $_SESSION : [];
            $html = \WHMCS\Module\Server\Bladecp\Ui\ClientPanel::render(
                $data,
                ClientLocale::resolve($params, $session)
            );
        } catch (\Throwable) {
            $html = '';
        }

        // Supply both WHMCS-compatible variable keys.
        $vars = $data + [
            'bladecpHtml' => $html,
            'primaryDomain' => $data['domain'],
        ];

        return [
            'templatefile' => 'clientarea',
            'vars' => $vars,
            'templateVariables' => $vars,
        ];
    }
}
