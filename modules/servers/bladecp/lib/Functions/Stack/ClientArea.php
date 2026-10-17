<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Helpers\ClientLocale;
use WHMCS\Module\Server\Bladecp\Helpers\ProductType;
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
                $data + self::panelAccess($params),
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

    /**
     * Hosting services open the control panel through the native single
     * sign-on action; other types share this area without it. An unknown
     * type only hides the button.
     *
     * @return array{serviceId?:int,panelAccess?:bool}
     */
    private static function panelAccess(array $params): array
    {
        try {
            return ProductType::getFromParams($params) === ProductType::STACK_CONTAINER
                ? ['serviceId' => (int) ($params['serviceid'] ?? 0), 'panelAccess' => true]
                : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
