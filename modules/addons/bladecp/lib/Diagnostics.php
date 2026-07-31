<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Exceptions\AuthException;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Diagnóstico administrativo somente leitura.
 */
final class Diagnostics
{
    /** @var list<string> */
    private const CATALOG_CACHE_NAMES = [
        'bladecp_components_catalog.json',
        'bladecp_components_catalog_full_v5.json',
    ];

    /**
     * @return array{overall:string,checks:list<array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string}>}
     */
    public static function snapshot(): array
    {
        $checks = [
            self::credential(),
            self::apiConnectivity(),
            self::receiverUrl(),
            self::webhookConfiguration(),
            self::catalogFreshness(),
            self::cronFreshness(),
            self::productIntegrity(),
        ];
        $states = array_column($checks, 'state');
        $overall = in_array('blocked', $states, true) ? 'blocked'
            : (in_array('attention', $states, true) ? 'attention'
                : (in_array('unknown', $states, true) ? 'unknown' : 'ok'));
        return ['overall' => $overall, 'checks' => $checks];
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function credential(): array
    {
        $configured = self::params() !== null;
        return self::check(
            'credential',
            $configured ? 'ok' : 'blocked',
            'Credencial da API',
            $configured
                ? 'Há uma credencial de servidor configurada. O valor nunca é exibido aqui.'
                : 'Não há uma credencial de servidor utilizável.',
            $configured
                ? 'Use o teste de conectividade para confirmar a autorização.'
                : 'Configure o Access Hash no servidor BladeCP do WHMCS.'
        );
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function apiConnectivity(): array
    {
        $client = self::client();
        if ($client === null) {
            return self::check('api', 'blocked', 'Conectividade com a API', 'A API não foi consultada porque o servidor não está configurado.', 'Configure a credencial do servidor e teste novamente.');
        }
        try {
            // Uma consulta pequena valida DNS, TLS e autorização sem escrever
            // nada nem carregar o catálogo completo.
            $client->get('/partner/plans?limit=1');
            return self::check('api', 'ok', 'Conectividade com a API', 'A API respondeu usando a credencial configurada.', 'Nenhuma ação necessária.');
        } catch (AuthException) {
            return self::check('api', 'blocked', 'Conectividade com a API', 'A API recusou a credencial configurada.', 'Revise o Access Hash e as permissões da conta Partner.');
        } catch (\Throwable) {
            return self::check('api', 'unknown', 'Conectividade com a API', 'Não foi possível confirmar a conectividade agora.', 'Tente novamente. Se persistir, verifique a saída HTTPS deste servidor.');
        }
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function receiverUrl(): array
    {
        if (!class_exists(WebhookConfig::class)) {
            return self::check('receiver_url', 'unknown', 'URL pública do receiver', 'A configuração do receiver não está disponível neste contexto.', 'Abra o diagnóstico dentro do addon BladeCP.');
        }
        $status = WebhookConfig::classifyPublicUrl();
        return self::check('receiver_url', $status['state'], 'URL pública do receiver', $status['detail'], $status['action']);
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function webhookConfiguration(): array
    {
        if (!class_exists(WebhookConfig::class)) {
            return self::check('webhook', 'unknown', 'Assinatura e endpoint', 'A configuração de webhook não está disponível neste contexto.', 'Abra o diagnóstico dentro do addon BladeCP.');
        }
        $endpoint = WebhookConfig::getEndpointId() !== '';
        $secret = WebhookConfig::hasSecret();
        if ($endpoint && $secret) {
            return self::check('webhook', 'ok', 'Assinatura e endpoint', 'O endpoint registrado e o segredo de assinatura estão presentes.', 'Nenhuma ação necessária.');
        }
        if (!$endpoint && !$secret) {
            return self::check('webhook', 'attention', 'Assinatura e endpoint', 'Os webhooks ainda não foram conectados.', 'Conecte os webhooks depois de confirmar uma URL HTTPS pública.');
        }
        return self::check('webhook', 'blocked', 'Assinatura e endpoint', 'A configuração do webhook está incompleta.', 'Reconecte os webhooks para registrar endpoint e assinatura novamente.');
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function catalogFreshness(): array
    {
        $client = self::client();
        if ($client === null || !class_exists(CacheStore::class)) {
            return self::check('catalog', 'unknown', 'Atualização do catálogo', 'Ainda não há uma atualização de catálogo observável neste servidor.', 'Abra Planos ou execute uma sincronização para carregar o catálogo.');
        }

        foreach (self::CATALOG_CACHE_NAMES as $name) {
            if (CacheStore::read($client, $name, 3600) !== null) {
                return self::check('catalog', 'ok', 'Atualização do catálogo', 'O cache local do catálogo foi atualizado há menos de uma hora.', 'Nenhuma ação necessária.');
            }
        }
        foreach (self::CATALOG_CACHE_NAMES as $name) {
            if (CacheStore::read($client, $name, PHP_INT_MAX) !== null) {
                return self::check('catalog', 'attention', 'Atualização do catálogo', 'Há um cache de catálogo registrado, mas ele está antigo.', 'Atualize o catálogo antes de revisar ou publicar novos produtos.');
            }
        }
        return self::check('catalog', 'unknown', 'Atualização do catálogo', 'Ainda não há uma atualização de catálogo observável neste servidor.', 'Abra Planos ou execute uma sincronização para carregar o catálogo.');
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function cronFreshness(): array
    {
        $serverId = self::managementServerId();
        if ($serverId === null) {
            return self::check('cron', 'unknown', 'Sincronização programada', 'A sincronização não pode ser associada a um servidor BladeCP administrativo.', 'Selecione um servidor BladeCP na Configuração antes de avaliar o cron.');
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return self::check('cron', 'unknown', 'Sincronização programada', 'O histórico do cron não está disponível neste contexto.', 'Confirme que o cron diário nativo do WHMCS está ativo.');
        }
        try {
            $row = \WHMCS\Database\Capsule::table('tblactivitylog')
                ->where('description', 'like', 'BladeCP cron sync:%')
                ->where('description', 'like', '%server_id=' . $serverId . ';%')
                ->orderByDesc('id')
                ->first();
            $date = $row === null ? '' : trim((string) ($row->date ?? ''));
            $timestamp = $date !== '' ? strtotime($date) : false;
            if ($timestamp === false) {
                return self::check('cron', 'unknown', 'Sincronização programada', 'Ainda não há execução observável da sincronização diária para este servidor.', 'Confirme o cron diário do WHMCS e aguarde a próxima execução.');
            }
            $description = trim((string) ($row->description ?? ''));
            if (str_starts_with($description, 'BladeCP cron sync: failed;')) {
                return self::check('cron', 'attention', 'Sincronização programada', 'A última sincronização registrada falhou.', 'Revise o log de atividades do WHMCS e tente novamente depois de corrigir a causa.');
            }
            if (str_starts_with($description, 'BladeCP cron sync: skipped;')) {
                return self::check('cron', 'attention', 'Sincronização programada', 'A última sincronização registrada foi ignorada por falta de contexto ou catálogo utilizável.', 'Revise a configuração do servidor e a conectividade com a API.');
            }
            if (time() - $timestamp <= 172800
                && str_starts_with($description, 'BladeCP cron sync: completed;')) {
                return self::check('cron', 'ok', 'Sincronização programada', 'A sincronização registrou conclusão para este servidor nos últimos dois dias.', 'Nenhuma ação necessária.');
            }
            if (str_starts_with($description, 'BladeCP cron sync: completed;')) {
                return self::check('cron', 'attention', 'Sincronização programada', 'A última sincronização concluída deste servidor é antiga.', 'Confirme se o cron diário do WHMCS está sendo executado.');
            }
            return self::check('cron', 'unknown', 'Sincronização programada', 'O último registro de cron não possui um resultado reconhecido.', 'Confira o log de atividades do WHMCS e execute uma reconciliação do catálogo.');
        } catch (\Throwable) {
            return self::check('cron', 'unknown', 'Sincronização programada', 'Não foi possível consultar o histórico do cron.', 'Confirme o cron diário do WHMCS.');
        }
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function productIntegrity(): array
    {
        $scope = class_exists(IntegrationReadiness::class)
            ? IntegrationReadiness::scope() : null;
        if ($scope === null) {
            return self::check('integrity', 'unknown', 'Integridade dos vínculos', 'Não foi possível associar os produtos a um único servidor e grupo BladeCP.', 'Selecione um servidor administrativo e revise os grupos de servidores.');
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return self::check('integrity', 'unknown', 'Integridade dos vínculos', 'A base do WHMCS não está disponível neste contexto.', 'Abra esta tela dentro do WHMCS para verificar os produtos importados.');
        }
        try {
            $products = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('servergroup', $scope['serverGroupId'])
                ->get(['id', 'configoption1', 'configoption2']);
            $total = count($products);
            if ($total === 0) {
                return self::check('integrity', 'unknown', 'Integridade dos vínculos', 'Ainda não há produtos BladeCP importados.', 'Importe o catálogo quando a configuração estiver pronta.');
            }
            $invalid = 0;
            foreach ($products as $product) {
                if (trim((string) ($product->configoption1 ?? '')) === '' || trim((string) ($product->configoption2 ?? '')) === '') {
                    $invalid++;
                }
            }
            if ($invalid === 0) {
                return self::check('integrity', 'ok', 'Integridade dos vínculos', $total . ' produto(s) BladeCP têm tipo e plano vinculados.', 'Nenhuma ação necessária.');
            }
            return self::check('integrity', 'attention', 'Integridade dos vínculos', $invalid . ' de ' . $total . ' produto(s) BladeCP precisam ter o vínculo com o plano revisado.', 'Abra Planos e execute a reconciliação do catálogo antes de disponibilizar vendas.');
        } catch (\Throwable) {
            return self::check('integrity', 'unknown', 'Integridade dos vínculos', 'Não foi possível validar os vínculos dos produtos agora.', 'Tente novamente dentro do WHMCS.');
        }
    }

    /** @return array<string,string> */
    private static function check(string $id, string $state, string $title, string $detail, string $action): array
    {
        return ['id' => $id, 'state' => $state, 'title' => $title, 'detail' => $detail, 'action' => $action, 'checkedAt' => gmdate('c')];
    }

    private static function params(): ?array
    {
        try {
            return class_exists(ServerConfig::class) ? ServerConfig::params() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function client(): ?\WHMCS\Module\Server\Bladecp\Api\Client
    {
        try {
            return class_exists(ServerConfig::class) ? ServerConfig::client() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Diagnóstico global não escolhe automaticamente o primeiro servidor. */
    private static function managementServerId(): ?int
    {
        if (!class_exists(AdminServerContext::class)) {
            return null;
        }
        try {
            $context = AdminServerContext::resolve();
            $serverId = (int) ($context['serverId'] ?? 0);
            return ($context['state'] ?? '') === 'configured' && $serverId > 0
                ? $serverId : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
