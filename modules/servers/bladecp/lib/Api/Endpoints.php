<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Valores de endpoint usados pelo cliente BladeCP. */
final class Endpoints
{
    /** Caminho da API anexado pelo Client ao host do Server record. */
    public const API_PATH = '/api/v1';

    public const DEFAULT_BASE = 'https://api.bladecp.com' . self::API_PATH;

    public function __construct(private string $base = self::DEFAULT_BASE)
    {
        $this->base = rtrim($this->base, '/');
    }

    /** Base URL resolvida (ex.: `https://api.bladecp.cloud/api/v1`). */
    public function base(): string
    {
        return $this->base;
    }

    public function url(string $path): string
    {
        return $this->base . '/customer/' . ltrim($path, '/');
    }
}
