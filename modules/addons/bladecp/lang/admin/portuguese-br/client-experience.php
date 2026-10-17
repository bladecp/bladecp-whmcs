<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'experience.requirements_title' => 'Requisitos e compatibilidade',
    'experience.expiry_title' => 'Após o teste ou prazo para pagamento',
    'experience.products_help' => 'Selecione os produtos que o cliente poderá escolher. Todos os produtos selecionados seguem a mesma política comercial.',
    'experience.products_other' => 'Outros produtos',
    'experience.wordpress_label' => 'Exibir o WordPress Toolkit',
    'experience.wordpress_description' => 'Permite consultar e atualizar a instalação WordPress disponível nos serviços elegíveis. Cada ação é limitada ao serviço do cliente.',
    'experience.expiry_action' => 'Após a tolerância',
    'experience.expiry_choose' => 'Vencimento ainda não configurado',
    'experience.expiry_manual' => 'Encaminhar para acompanhamento manual',
    'experience.expiry_suspend' => 'Suspender sem exclusão automática',
    'experience.expiry_suspend_delete' => 'Suspender e excluir após a retenção',
    'experience.expiry_grace_days' => 'Tolerância após o vencimento (dias)',
    'experience.expiry_retention_days' => 'Retenção após a suspensão (dias)',
    'experience.expiry_help' => 'Aplica-se ao teste e à contratação posterior. Tolerância: de 0 a 365 dias (0 significa sem tolerância). Retenção: de 1 a 365 dias, somente para exclusão. A opção manual exige acompanhamento do partner. Estas condições são comuns a todos os planos e não se aplicam ao pagamento prévio.',
    'experience.policy_title' => 'Política comercial do Fast Deploy',
    'experience.policy_description' => 'Escolha uma modalidade para todos os planos deste servidor. O cliente escolhe apenas o plano e a localização. Os preços e ciclos continuam nos produtos do WHMCS.',
    'experience.policy_default' => 'Modalidade para todos os planos',
    'experience.policy_mode' => 'Modalidade',
    'experience.policy_choose' => 'Selecione uma modalidade',
    'experience.policy_payment_before' => 'Pagar antes de migrar',
    'experience.policy_payment_after' => 'Migrar e pagar para ativar',
    'experience.policy_trial' => 'Migrar e testar',
    'experience.policy_trial_days' => 'Duração do teste (dias)',
    'experience.policy_payment_days' => 'Prazo para contratar (dias)',
    'experience.policy_days_help' => 'Informe de 1 a 365 dias no campo da modalidade escolhida. Os demais campos são ignorados. O prazo de contratação corresponde à espera após a migração estar pronta para aprovação.',
    'experience.policy_products' => 'Produtos disponíveis no Fast Deploy',
    'experience.policy_no_products' => 'Nenhum produto de hospedagem recorrente está associado exclusivamente a este servidor.',
    'experience.policy_hidden' => 'Produto oculto ou retirado: pode ser configurado, mas não será exibido ao cliente.',
    'experience.policy_integration_pending' => 'As regras são congeladas em cada nova migração. Pagamentos são conferidos no WHMCS; prazos e ações dependem da política escolhida.',
    'experience.policy_save' => 'Salvar política comercial',
    'experience.policy_failed' => 'Não foi possível salvar a política. Confira a modalidade, o prazo correspondente e os produtos selecionados.',

    'experience.deploy_label' => 'Exibir o Fast Deploy',
    'experience.deploy_description' => 'Exibe a migração guiada na área de cliente, independentemente do Vibe e do WordPress Toolkit.',
    'layout.tabs.client_experience' => 'Área de cliente',
    'experience.description' => 'Recursos opcionais por servidor BladeCP.',
    'experience.vibe_label' => 'Exibir o card de prompt Vibe',
    'experience.vibe_description' => 'Adiciona o formulário de ideias à página inicial de clientes elegíveis. Desligar remove apenas o card.',
    'experience.requirements' => 'A primeira versão exige uma oferta Vibe Free única e um único servidor elegível no grupo do produto. O envio requer HTTPS e uma origem autorizada no Vibe. Habilitar o card não cadastra nem comprova essa autorização.',
    'experience.compatibility' => 'Compatibilidade: temas que renderizam o conteúdo nativo ClientAreaHomepage (addons_html). Nexus e o tema do demo usam esse ponto de extensão.',
    'experience.unknown_environment' => 'O ambiente deste servidor não possui um destino Vibe reconhecido. O card permanecerá oculto.',
    'experience.select_server' => 'Selecione um servidor BladeCP válido na configuração antes de habilitar recursos.',
    'experience.save' => 'Salvar configuração',
    'experience.saved' => 'Configuração salva.',
    'experience.failed' => 'Não foi possível salvar. Confira o servidor selecionado e tente novamente.',
    'experience.migration_billing_title' => 'Cobrança do cPanel Migrator',
    'experience.migration_billing_description' => 'Cria no WHMCS o serviço de cada migração feita pelo cPanel Migrator com a chave deste servidor, para você cobrar. Funciona com o Fast Deploy ligado ou desligado e não mostra nada aos clientes.',
    'experience.migration_billing_label' => 'Cobrar migrações do cPanel Migrator',
];
