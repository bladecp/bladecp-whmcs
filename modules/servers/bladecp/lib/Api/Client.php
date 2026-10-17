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
    /** Lightweight optional UI data must not hold a WHMCS client page open. */
    private const PARTNER_BRANDING_TIMEOUT = 3;
    /** Respostas que param de progredir são encerradas antes do timeout total. */
    private const LOW_SPEED_LIMIT = 1024;
    private const LOW_SPEED_TIME = 15;
    /** Margem pequena para drenar handles multi sem estender indefinidamente. */
    private const MULTI_DEADLINE_SECONDS = self::TIMEOUT + 5;
    private const MAX_PARALLEL_REQUESTS = 8;
    private const VERSION = '0.9.0-rc.9';

    private Endpoints $endpoints;
    private array $lastRequestDiagnostics = [];

    /** Safe transport metadata only; never includes request or response bodies. */
    public function lastRequestDiagnostics(): array { return $this->lastRequestDiagnostics; }


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
        $apiKey = self::apiKeyFromParams($params);

        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            $baseUrl = Endpoints::DEFAULT_BASE;
        } elseif (preg_match('#^https?://#i', $host) === 1) {
            // Aceita uma URL completa informada no campo Hostname.
            $baseUrl = rtrim($host, '/');
        } else {
            $secure = !empty($params['serversecure']);
            $scheme = $secure ? 'https' : 'http';
            $rawPort = trim((string) ($params['serverport'] ?? ''));
            $validatedPort = filter_var($rawPort, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            $port = $validatedPort === false ? '' : (string) $validatedPort;
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

    /**
     * O WHMCS pode entregar o Access Hash do Server record em texto puro ou
     * no formato criptografado persistido em tblservers. Normalize os dois
     * formatos na fronteira canônica usada por todo provisionamento.
     */
    private static function apiKeyFromParams(array $params): string
    {
        $raw = trim((string) ($params['serveraccesshash'] ?? ''));
        if ($raw === '' || preg_match('/^(bcp|vh)_/', $raw) === 1) {
            return $raw;
        }
        if (!function_exists('localAPI')) {
            return '';
        }

        try {
            $decrypted = localAPI('DecryptPassword', ['password2' => $raw]);
            $apiKey = trim((string) ($decrypted['password'] ?? ''));

            return preg_match('/^(bcp|vh)_/', $apiKey) === 1 ? $apiKey : '';
        } catch (\Throwable) {
            return '';
        }
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

    /**
     * Minimal public partner branding. The API derives the partner from this
     * Server record's Access Hash, never from browser or module input.
     *
     * @return array{publicName:string,logoUrl:string,logoDarkUrl:string}
     */
    public function partnerBrandingAssets(): array
    {
        return PartnerBranding::normalize($this->getWithTimeout(
            Endpoints::PARTNER_BRANDING_ASSETS,
            self::PARTNER_BRANDING_TIMEOUT
        ));
    }

    /** @return array<string,mixed> */
    private function getWithTimeout(string $path, int $timeoutSeconds): array
    {
        return self::dataFromEnvelope(
            $this->request('GET', $path, null, [], $timeoutSeconds)
        );
    }

    public function post(string $path, array $body, array $headers = []): array
    {
        return self::dataFromEnvelope(
            $this->request('POST', $path, $body, $headers)
        );
    }

    /**
     * Starts an administrative Vibe support handoff. The Server Access Hash
     * authenticates only this WHMCS installation; the returned authorize URL
     * still requires the operator's own BladeCP session.
     *
     * @return array{authorizeUrl:string,expiresAt?:string}
     */
    public function issueVibeSupportIntent(
        int $externalServiceId,
        string $accountId,
        int $bindingVersion,
        ?string $launchToken = null
    ): array {
        if ($externalServiceId <= 0 || $bindingVersion <= 0) {
            throw new \InvalidArgumentException('Contexto de suporte Vibe inválido.');
        }
        $accountId = trim($accountId);
        if ($accountId === '' || strlen($accountId) > 512 || preg_match('/[\x00-\x1F\x7F]/', $accountId) === 1) {
            throw new \InvalidArgumentException('Conta Vibe inválida para suporte.');
        }
        if ($launchToken !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $launchToken) !== 1) {
            throw new \InvalidArgumentException('Solicitação de criação Vibe inválida.');
        }
        $payload = [
            'externalServiceId' => (string) $externalServiceId,
            'accountId' => $accountId,
            'bindingVersion' => $bindingVersion,
        ];
        if ($launchToken !== null) {
            $payload['launchToken'] = $launchToken;
        }
        $response = $this->post(
            Endpoints::VIBE_SUPPORT_INTENTS,
            $payload,
            ['Idempotency-Key' => 'whmcs-vibe-support-' . $externalServiceId . '-' . bin2hex(random_bytes(12))]
        );
        $authorizeUrl = $response['authorizeUrl'] ?? null;
        if (!is_string($authorizeUrl) || trim($authorizeUrl) === '') {
            throw new ApiException('BladeCP: não foi possível preparar o acesso de suporte agora.');
        }
        return [
            'authorizeUrl' => trim($authorizeUrl),
            ...isset($response['expiresAt']) && is_string($response['expiresAt']) ? ['expiresAt' => $response['expiresAt']] : [],
        ];
    }

    /** @return array<string,mixed> */
    public function issueVibeIdentityLinkIntent(
        string $externalUserSubject,
        string $externalClientAccountId,
        string $externalServiceId,
        string $partnerPlanUuid,
        string $partnerAuthAt,
        string $codeChallenge,
        string $stateHash,
        string $idempotencyKey
    ): array {
        return VibeIdentityLink::normalizeIssueResponse($this->post(
            Endpoints::VIBE_IDENTITY_LINK_INTENTS,
            VibeIdentityLink::issuePayload($externalUserSubject, $externalClientAccountId, $externalServiceId, $partnerPlanUuid, $partnerAuthAt, $codeChallenge, $stateHash),
            ['Idempotency-Key' => self::requireIdempotencyKey($idempotencyKey)]
        ));
    }

    /** @return array<string,mixed> */
    public function exchangeVibeIdentityLinkIntent(
        string $intentId,
        string $authorizationCode,
        string $codeVerifier,
        string $idempotencyKey
    ): array {
        $intentId = trim($intentId);
        if ($intentId === '' || strlen($intentId) > 512 || preg_match('/[\x00-\x1F\x7F]/', $intentId) === 1) {
            throw new \InvalidArgumentException('intentId inválido para vínculo de identidade.');
        }
        return VibeIdentityLink::normalizeExchangeResponse($this->post(
            Endpoints::vibeIdentityLinkIntentExchange($intentId),
            VibeIdentityLink::exchangePayload($authorizationCode, $codeVerifier),
            ['Idempotency-Key' => self::requireIdempotencyKey($idempotencyKey)]
        ));
    }

    /**
     * Redeems an opaque, one-time commercial handoff after the addon has
     * resolved the WHMCS owner and Client Account locally. The API attests the
     * Vibe service from the ticket, which the addon revalidates afterwards.
     * No browser-controlled URL or destination is accepted here.
     *
     * @return array<string,mixed>
     */
    public function redeemVibeCommercialActionTicket(
        string $ticket,
        string $externalUserSubject,
        string $externalClientAccountId
    ): array {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $ticket) !== 1) {
            throw new \InvalidArgumentException('ticket comercial inválido.');
        }
        foreach ([$externalUserSubject, $externalClientAccountId] as $value) {
            if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('contexto comercial inválido.');
            }
        }

        return $this->post(Endpoints::VIBE_COMMERCIAL_ACTION_TICKETS_REDEEM, [
            'ticket' => $ticket,
            'externalUserSubject' => $externalUserSubject,
            'externalClientAccountId' => $externalClientAccountId,
        ]);
    }

    /** @return array{recoveryId:string,externalServiceId:int,accountId:string,bindingVersion:int,action:string} */
    public function redeemVibeContextRecoveryTicket(
        string $ticket,
        string $externalUserSubject,
        string $externalClientAccountId
    ): array {
        return VibeContextRecovery::normalizeRedeemResponse($this->post(
            Endpoints::VIBE_CONTEXT_RECOVERY_REDEEM,
            VibeContextRecovery::redeemPayload($ticket, $externalUserSubject, $externalClientAccountId)
        ));
    }

    /** @return array{callbackUrl:string,resultCode:string,expiresAt:string} */
    public function confirmVibeContextRecovery(
        string $recoveryId,
        string $externalUserSubject,
        string $externalClientAccountId
    ): array {
        return VibeContextRecovery::normalizeConfirmResponse($this->post(
            Endpoints::VIBE_CONTEXT_RECOVERY_CONFIRM,
            VibeContextRecovery::confirmPayload($recoveryId, $externalUserSubject, $externalClientAccountId)
        ));
    }

    /**
     * Publishes only the partner's local WHMCS Vibe product snapshot. This is
     * server-to-server and authenticated by the Server Access Hash; no browser
     * identifier or redirect is involved.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function syncVibeCommercialCatalog(array $snapshot): array
    {
        $revision = trim((string) ($snapshot['catalogRevision'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('revisão comercial inválida.');
        }
        return $this->post(Endpoints::VIBE_COMMERCIAL_CATALOG_SYNC, $snapshot, [
            'Idempotency-Key' => self::requireIdempotencyKey(
                'whmcs-vibe-commercial-catalog-'
                . $revision
                . '-' . bin2hex(random_bytes(12))
            ),
        ]);
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

    private static function newCorrelationId(): ?string
    {
        try {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        } catch (\Throwable) { return null; }
    }

    private function request(
        string $method,
        string $path,
        ?array $body,
        array $extraHeaders,
        ?int $timeoutSeconds = null
    ): array {
        $this->lastRequestDiagnostics = [];
        // Sem credencial configurada, não há uma solicitação a executar.
        $configError = $this->configError();
        if ($configError !== null) {
            throw new AuthException($configError, 401);
        }

        $correlationId = self::newCorrelationId();
        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Request-Source' => 'whmcs-module/' . self::VERSION,
        ], $correlationId === null ? [] : ['X-Correlation-Id' => $correlationId], $extraHeaders);
        if ($this->attributionSource !== null && $this->attributionSource !== '') {
            $headers['X-Attribution-Source'] = 'whmcs:' . $this->attributionSource;
        }

        $timeout = $timeoutSeconds === null
            ? self::TIMEOUT : max(1, min(self::TIMEOUT, $timeoutSeconds));
        $connectTimeout = min(self::CONNECT_TIMEOUT, $timeout);
        $ch = curl_init($this->endpoints->url($path));
        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$correlationId): int {
                if (preg_match('/^X-Correlation-Id:\s*([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\s*$/i', trim($line), $match)) {
                    $correlationId = strtolower($match[1]);
                }
                return strlen($line);
            },
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
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
        $this->lastRequestDiagnostics = [
            'http_status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'curl_errno' => curl_errno($ch),
            'http_duration_ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        ] + ($correlationId === null ? [] : ['correlation_id' => $correlationId]);
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

    private static function requireIdempotencyKey(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Idempotency-Key inválida para vínculo de identidade.');
        }
        return $value;
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
            'plan_not_available' => 'BladeCP: este plano Vibe não está disponível para esta conta de partner.',
            'mode_dependency_not_ready' => 'BladeCP: a configuração de IA deste plano Vibe ainda não está pronta.',
            'email_verification_required' => 'BladeCP: confirme o e-mail da sua conta antes de ativar o BladeCP Vibe Free.',
            'free_activation_context_invalid' => 'BladeCP: não foi possível confirmar a elegibilidade deste serviço Free. Confirme o e-mail e tente novamente.',
            'free_grant_already_used' => 'BladeCP: esta conta já utilizou a concessão Free disponível neste contexto comercial.',
            'free_grant_not_eligible' => 'BladeCP: este serviço Free não está elegível para uma nova ativação.',
            'downgrade_not_available' => 'BladeCP: a conversão do Vibe Pro para Free não está disponível.',
            'plan_transition_not_allowed' => 'BladeCP: esta alteração de plano não está disponível para a conta Vibe.',
            'external_service_conflict' => 'BladeCP: este serviço WHMCS já está vinculado a outra conta Vibe. Revise a correlação antes de continuar.',
            'provisioning_unavailable' => 'BladeCP: a confirmação de disponibilidade está temporariamente indisponível. Tente novamente em alguns minutos.',
            'identity_link_invalid_request' => 'BladeCP: não foi possível preparar este acesso. Tente novamente.',
            'identity_link_required' => 'BladeCP: o proprietário precisa abrir o Vibe pela área de cliente para confirmar o vínculo deste serviço. Não crie outro pedido.',
            'customer_identity_provider_unavailable' => 'BladeCP: a configuração de identidade da integração precisa ser revisada pelo administrador.',
            'customer_identity_service_unavailable' => 'BladeCP: a confirmação de identidade está temporariamente indisponível. O serviço ainda não foi confirmado.',
            'customer_identity_invalid' => 'BladeCP: os dados de identidade do cliente foram rejeitados. Revise o cadastro antes de tentar novamente.',
            'customer_identity_conflict' => 'BladeCP: o vínculo de identidade do cliente conflita com o cadastro existente. Revise o vínculo sem criar outra conta ou pedido.',
            'identity_link_not_allowed' => 'BladeCP: o proprietário desta conta precisa concluir a ativação.',
            'identity_link_owner_required' => 'BladeCP: o proprietário desta conta precisa concluir a ativação.',
            'identity_link_intent_not_found' => 'BladeCP: esta confirmação não está mais disponível. Tente novamente.',
            'identity_link_expired' => 'BladeCP: esta confirmação não está mais disponível. Tente novamente.',
            'identity_binding_conflict' => 'BladeCP: precisamos revisar este acesso antes de continuar.',
            'commercial_context_conflict' => 'BladeCP: precisamos revisar este acesso antes de continuar.',
            'service_account_conflict' => 'BladeCP: precisamos revisar este acesso antes de continuar.',
            'plan_product_conflict' => 'BladeCP: precisamos revisar este acesso antes de continuar.',
            'legacy_reconciliation_required' => 'BladeCP: precisamos revisar este acesso antes de continuar.',
            'secure_reauthentication_required' => 'BladeCP: confirme sua conta BladeCP para continuar.',
            'identity_link_in_progress' => 'BladeCP: não foi possível concluir agora. Tente novamente.',
            'identity_link_proof_invalid' => 'BladeCP: não foi possível concluir agora. Tente novamente.',
            'identity_link_temporarily_unavailable' => 'BladeCP: não foi possível concluir agora. Tente novamente.',
            'global_identity_unavailable' => 'BladeCP: não foi possível concluir agora. Tente novamente.',
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
