<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\AuthException;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\RateLimitException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Cliente HTTP cURL para a API BladeCP. */
final class Client
{
    private const TIMEOUT = 30;
    /** Nunca deixe uma conexão sem resposta consumir todo o cron do WHMCS. */
    private const CONNECT_TIMEOUT = 8;
    /** Respostas que param de progredir são encerradas antes do timeout total. */
    private const LOW_SPEED_LIMIT = 1024;
    private const LOW_SPEED_TIME = 15;
    /** Margem pequena para drenar handles multi sem estender indefinidamente. */
    private const MULTI_DEADLINE_SECONDS = self::TIMEOUT + 5;
    private const MAX_PARALLEL_REQUESTS = 8;
    private const VERSION = '0.9.0-rc.4';

    private Endpoints $endpoints;

    public function __construct(
        private string $apiKey,
        ?string $baseUrl = null,
        private ?string $attributionSource = null
    ) {
        $this->endpoints = new Endpoints($baseUrl ?? Endpoints::DEFAULT_BASE);
    }

    /** Base URL resolvida que o Client vai usar (fonte única). */
    public function baseUrl(): string
    {
        return $this->endpoints->base();
    }

    /** Slug de atribuição default quando o ConfigOption está vazio. */
    public const DEFAULT_ATTRIBUTION = 'whmcs';

    /** Cria o cliente a partir da configuração de servidor do WHMCS. */
    public static function fromParams(array $params): self
    {
        $apiKey = trim((string) ($params['serveraccesshash'] ?? ''));

        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            $baseUrl = Endpoints::DEFAULT_BASE;
        } elseif (preg_match('#^https?://#i', $host) === 1) {
            // Aceita uma URL completa informada no campo Hostname.
            $baseUrl = rtrim($host, '/');
        } else {
            $secure = !empty($params['serversecure']);
            $scheme = $secure ? 'https' : 'http';
            $port = trim((string) ($params['serverport'] ?? ''));
            $defaultPort = $secure ? '443' : '80';
            $authority = $host
                . ($port !== '' && $port !== $defaultPort ? ':' . $port : '');
            $baseUrl = $scheme . '://' . $authority . Endpoints::API_PATH;
        }

        $slug = trim((string) (
            $params['configoptions']['partner_slug'] ?? ''
        ));
        if ($slug === '') {
            $slug = self::DEFAULT_ATTRIBUTION;
        }

        return new self($apiKey, $baseUrl, $slug);
    }

    /** Retorna uma mensagem quando a credencial de servidor não foi configurada. */
    public function configError(): ?string
    {
        if (trim($this->apiKey) === '') {
            return 'BladeCP: configure o BladeCP Server Access Hash '
                . '(Setup → Products/Services → Servers → o server '
                . 'BladeCP → campo Access Hash) com a sua API key bcp_*.';
        }
        return null;
    }

    public function get(string $path, array $headers = []): array
    {
        return self::dataFromEnvelope(
            $this->request('GET', $path, null, $headers)
        );
    }

    /** @return array{ok:bool,fetchedAt:string,plans:list<array<string,mixed>>,error:?string} */
    public function catalogSnapshot(): array
    {
        return CatalogSnapshot::fetch($this);
    }

    /** @return array<string,mixed> */
    public function getEnvelope(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array $body, array $headers = []): array
    {
        return self::dataFromEnvelope(
            $this->request('POST', $path, $body, $headers)
        );
    }

    public function patch(string $path, array $body, array $headers = []): array
    {
        return self::dataFromEnvelope(
            $this->request('PATCH', $path, $body, $headers)
        );
    }

    public function delete(
        string $path,
        array $body = [],
        array $headers = []
    ): array {
        return self::dataFromEnvelope(
            $this->request('DELETE', $path, $body ?: null, $headers)
        );
    }

    private function request(
        string $method,
        string $path,
        ?array $body,
        array $extraHeaders
    ): array {
        // Sem credencial configurada, não há uma solicitação a executar.
        $configError = $this->configError();
        if ($configError !== null) {
            throw new AuthException($configError, 401);
        }

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Request-Source' => 'whmcs-module/' . self::VERSION,
        ], $extraHeaders);
        if ($this->attributionSource !== null && $this->attributionSource !== '') {
            $headers['X-Attribution-Source'] = 'whmcs:' . $this->attributionSource;
        }

        $ch = curl_init($this->endpoints->url($path));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => array_map(
                static fn ($k, $v) => "$k: $v",
                array_keys($headers),
                array_values($headers)
            ),
        ]);
        if ($body !== null) {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode($body, JSON_THROW_ON_ERROR)
            );
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            // cURL pode incluir dados do ambiente na mensagem. A UI e os
            // logs do módulo não precisam desse detalhe para orientar uma
            // nova tentativa, portanto não o propagamos.
            throw new ApiException(
                'BladeCP: não foi possível contatar a API agora. Tente novamente em alguns minutos.'
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = $raw === '' ? [] : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $raw];
        }

        if ($status >= 400) {
            $message = self::safeErrorMessage($status, $decoded);
            if ($status === 401 || $status === 403) {
                throw new AuthException($message, $status, $decoded);
            }
            if ($status === 429) {
                throw new RateLimitException($message, $status, $decoded);
            }
            throw new ApiException($message, $status, $decoded);
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private static function dataFromEnvelope(array $decoded): array
    {
        $data = $decoded['data'] ?? $decoded;
        return is_array($data) ? $data : $decoded;
    }

    /** Converte erros da API em mensagens seguras para a interface. */
    private static function safeErrorMessage(int $status, array $body): string
    {
        $code = strtolower(trim((string) (
            $body['code'] ?? $body['error']['code'] ?? ''
        )));
        $byCode = [
            'contract_acceptance_required' => 'BladeCP: aceite os termos do contrato antes de continuar.',
            'contract_term_required' => 'BladeCP: selecione o prazo do contrato antes de continuar.',
            'contract_term_not_available' => 'BladeCP: o prazo escolhido não está disponível para este plano.',
            'location_not_available_for_plan' => 'BladeCP: selecione uma localização disponível para este plano.',
            'components_required' => 'BladeCP: selecione os componentes exigidos para este plano.',
            'plan_configuration_invalid' => 'BladeCP: a configuração deste plano precisa ser revisada.',
            'plan_not_found' => 'BladeCP: o plano selecionado não está disponível no catálogo atual.',
            'location_not_found' => 'BladeCP: selecione uma localização válida e disponível para este plano.',
            'automation_not_ready' => 'BladeCP: a automação deste plano ainda não está pronta para venda pelo WHMCS.',
            'manual_delivery_only' => 'BladeCP: este plano está disponível somente para entrega manual e não pode ser vendido pelo WHMCS.',
            'channel_not_supported' => 'BladeCP: este plano não está habilitado para provisionamento pelo WHMCS.',
            'provisioning_unavailable' => 'BladeCP: a confirmação de disponibilidade está temporariamente indisponível. Tente novamente em alguns minutos.',
        ];
        if (isset($byCode[$code])) {
            return $byCode[$code];
        }
        return match ($status) {
            400 => 'BladeCP: os dados enviados para este serviço são inválidos. Revise o plano e as opções.',
            401, 403 => 'BladeCP: a credencial do servidor não está autorizada. Revise o Access Hash.',
            404 => 'BladeCP: o serviço não foi encontrado ou não está disponível para esta conta.',
            409 => $code === 'contract_not_approved'
                ? 'BladeCP: este serviço exige um contrato aprovado antes de continuar.'
                : 'BladeCP: esta operação conflita com o estado atual do serviço. Atualize a página e tente novamente.',
            422 => 'BladeCP: alguns dados do serviço precisam ser corrigidos antes de continuar.',
            429 => 'BladeCP: muitas solicitações agora. Aguarde alguns minutos e tente novamente.',
            500, 502, 503, 504 => 'BladeCP: o serviço está temporariamente indisponível. Tente novamente em alguns minutos.',
            default => 'BladeCP: não foi possível concluir a solicitação agora. Tente novamente mais tarde.',
        };
    }

    /** @param list<string> $paths @return list<array|null> */
    public function getMany(array $paths): array
    {
        if ($paths === [] || $this->configError() !== null) {
            return array_fill(0, count($paths), null);
        }
        $out = [];
        foreach (array_chunk(array_values($paths), self::MAX_PARALLEL_REQUESTS, true) as $offset => $batch) {
            $batchResult = $this->getManyBatch($batch);
            foreach ($batchResult as $index => $result) {
                $out[($offset * self::MAX_PARALLEL_REQUESTS) + $index] = $result;
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** @param list<string> $paths @return list<array|null> */
    private function getManyBatch(array $paths): array
    {
        $headerLines = $this->headerLines();
        $mh = curl_multi_init();
        $handles = [];
        foreach ($paths as $i => $path) {
            $ch = curl_init($this->endpoints->url($path));
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT,
                CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HTTPHEADER => $headerLines,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $deadline = microtime(true) + self::MULTI_DEADLINE_SECONDS;
        $running = 0;
        do {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running <= 0 || $status !== CURLM_OK || microtime(true) >= $deadline) {
                break;
            }
            $remaining = max(0.0, $deadline - microtime(true));
            $selected = curl_multi_select($mh, min(1.0, $remaining));
            if ($selected === -1 || $selected === false) {
                usleep(100000);
            }
        } while (true);

        $out = [];
        foreach ($handles as $i => $ch) {
            $out[$i] = self::decodeOne(
                curl_multi_getcontent($ch),
                (int) curl_getinfo($ch, CURLINFO_HTTP_CODE)
            );
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        ksort($out);
        return array_values($out);
    }

    /** @return list<string> */
    private function headerLines(array $extra = []): array
    {
        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Request-Source' => 'whmcs-module/' . self::VERSION,
        ], $extra);
        if ($this->attributionSource !== null && $this->attributionSource !== '') {
            $headers['X-Attribution-Source'] = 'whmcs:' . $this->attributionSource;
        }

        return array_map(
            static fn ($k, $v) => "$k: $v",
            array_keys($headers),
            array_values($headers)
        );
    }

    /** @return array<string,mixed>|null */
    private static function decodeOne(mixed $raw, int $status): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return ($status >= 200 && $status < 300) ? [] : null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $status >= 400) {
            return null;
        }

        return $decoded['data'] ?? $decoded;
    }
}
