<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'diagnostics.header.title' => 'Diagnóstico',
    'diagnostics.header.description' => 'Verificações de leitura para facilitar a configuração e explicar o que precisa de atenção.',
    'diagnostics.hero.eyebrow' => 'VERIFICAÇÕES SEGURAS',
    'diagnostics.hero.title' => 'Entenda o estado antes de fazer uma alteração.',
    'diagnostics.hero.description' => 'Os indicadores não mudam produtos, pedidos ou credenciais. Quando algum dado não puder ser conferido, ele aparece como indisponível, sem assumir que está tudo certo.',
    'diagnostics.glossary.title' => 'Guia rápido',
    'diagnostics.glossary.ready' => 'A verificação necessária foi concluída e não há alerta conhecido.',
    'diagnostics.glossary.attention' => 'Há uma pendência ou uma informação que merece revisão antes do próximo passo.',
    'diagnostics.glossary.unavailable' => 'O módulo não conseguiu confirmar a informação agora. Verifique a conexão e tente novamente.',
    'diagnostics.docs.prefix' => 'Precisa de mais contexto?',
    'diagnostics.docs.link' => 'Consulte a documentação BladeCP',
    'diagnostics.check.fallbackTitle' => 'Verificação',
    'diagnostics.checks.emptyTitle' => 'As verificações não puderam ser carregadas.',
    'diagnostics.checks.emptyDescription' => 'Tente novamente dentro do WHMCS.',
    'diagnostics.checks.overall' => 'ESTADO GERAL: {state}',
    'diagnostics.checks.title' => 'Conexão, URL pública, catálogo e rotina',
    'diagnostics.checks.description' => 'Inclui credencial, conectividade, URL HTTPS do receiver, assinatura, atualização do catálogo, cron e integridade dos produtos.',
    'diagnostics.actions.open' => 'Abrir',
    'diagnostics.actions.viewGuide' => 'Ver roteiro',
    'diagnostics.readiness.title' => 'Prontidão da integração',
    'diagnostics.readiness.description' => 'Resumo do roteiro de configuração do módulo.',
    'diagnostics.readiness.fallbackStep' => 'Etapa',
    'diagnostics.state.ready' => 'pronto',
    'diagnostics.state.attention' => 'atenção',
    'diagnostics.state.pending' => 'pendente',
    'diagnostics.state.unavailable' => 'indisponível',
];
