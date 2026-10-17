<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'addon.config.description' => 'Combos/bundles de componentes + sync de Configurable Options no order form. Não armazena credenciais: a API key fica no Server record (Setup → Products/Services → Servers).',
    'addon.activate.success' => 'Tabelas de combos e opções configuráveis criadas. Abra o addon para gerenciar bundles e sincronizar as opções configuráveis.',
    'addon.activate.failed' => 'Não foi possível preparar as tabelas do módulo.',
    'addon.deactivate.success' => 'O addon foi desativado. Catálogo, vínculos, combos e histórico foram preservados para uma reativação segura.',
    'addon.output.failed' => 'Não foi possível carregar esta tela do BladeCP. Tente novamente e consulte o diagnóstico do módulo.',
    'admin.vibe_support.title' => 'Atendimento Vibe',
    'admin.vibe_support.subtitle' => 'Acesso administrativo limitado ao serviço selecionado.',
    'admin.vibe_support.select' => 'Selecione um serviço Vibe ativo para iniciar o atendimento.',
    'admin.operation.default_action' => 'Ação BladeCP',
    'admin.operation.failure_recorded' => 'Falha registrada. Consulte o log do módulo para os detalhes técnicos.',
    'admin.operation.action_recorded' => 'Ação registrada pelo módulo.',
    'admin.combos.name_required' => 'Nome do combo é obrigatório.',
    'admin.combos.database_unavailable' => 'Banco indisponível.',
    'admin.webhooks.replay_invalid' => 'Entrega inválida.',
    'admin.webhooks.replay_unconfirmed' => 'A API não confirmou o reenvio.',
    'admin.setup.server_module_unavailable' => 'Módulo de servidor indisponível.',
    'admin.setup.credential_save_failed' => 'Não foi possível proteger e salvar a credencial. Nenhuma alteração foi aplicada.',
    'plans.import.database_unavailable' => 'Capsule indisponível.',
    'plans.import.missing_plan_id' => 'O catálogo não informou o id do plano.',
    'plans.import.server_required' => 'Selecione um servidor BladeCP exclusivo antes de importar planos.',
    'plans.import.failed' => 'Não foi possível concluir a importação agora. Nenhuma alteração parcial foi salva. Tente novamente.',
    'plans.import.lock_unavailable' => 'Não foi possível obter o lock de importação do catálogo. Tente novamente.',
    'plans.import.options_not_updated' => 'O produto existente foi preservado, mas suas opções operacionais não puderam ser atualizadas.',
    'plans.import.plan_unavailable' => 'Plano indisponível para este módulo.',
    'plans.import.invalid_plan_id' => 'O catálogo não informou um id numérico válido para o plano.',
    'plans.import.no_location' => 'O plano não possui location ativa e vinculada.',
    'admin.vibe_support.cta_group' => 'Acesso BladeCP',
    'admin.vibe_support.cta_main_title' => 'Abrir o Vibe deste cliente para atendimento',
    'admin.vibe_support.cta_more' => 'Outras formas de acesso BladeCP',
    'admin.vibe_support.cta_account' => 'Acessar via conta BladeCP',
    'admin.setup.select_server_first' => 'Selecione primeiro o servidor BladeCP usado pelas telas administrativas.',
    'admin.combos.component_required' => 'Selecione ao menos um componente.',
    'admin.setup.starter_server_required' => 'Selecione um servidor BladeCP exclusivo antes de criar o produto de teste.',
];
