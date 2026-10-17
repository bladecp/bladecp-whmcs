<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'admin.vibe.details' => 'Disponibilidade e sincronização',
    'admin.vibe.sync' => 'Sincronizar ofertas Vibe',
    'admin.vibe.unknown' => 'Diagnóstico indisponível. Atualize o catálogo ou confira a integração no portal do parceiro.',
    'admin.vibe.ready' => 'Plano técnico disponível para importação.',
    'admin.vibe.plan_configuration_required' => 'Conclua a configuração técnica do plano no portal do parceiro.',
    'admin.vibe.plan_disabled' => 'Plano desativado pelo administrador da plataforma.',
    'admin.vibe.channel_disabled' => 'Canal WHMCS desativado para este plano.',
    'admin.vibe.enrollment_inactive' => 'Ative a revenda Vibe neste ambiente pelo administrador da plataforma.',
    'admin.vibe.ai_mode_not_allowed' => 'O modo de IA do plano não está autorizado para esta revenda.',
    'admin.vibe.credentials_not_ready' => 'Valide uma credencial de IA compatível em Vibe, no portal do parceiro deste ambiente.',
    'admin.vibe.managed_ai_dependencies' => 'Aguarde a liberação das dependências de Managed AI pelo administrador da plataforma.',
    'admin.vibe.profile' => 'Revise e ative o perfil comercial com o canal Addon WHMCS no administrador da plataforma. Salvar rascunho não ativa o perfil.',
    'admin.vibe.host' => 'O handoff comercial aponta para {host}; este WHMCS usa {local}. Revise as URLs e os hosts autorizados do perfil comercial deste ambiente.',
    'admin.vibe.mapping' => 'O catálogo remoto está vinculado ao produto {remote}; o produto local é {local}. Solicite a revisão do vínculo antes de sincronizar.',
    'admin.vibe.import' => 'Importe o plano neste WHMCS quando estiver disponível. Depois configure o preço e sincronize as ofertas.',
    'admin.vibe.import_free' => 'Importe o plano gratuito neste WHMCS quando estiver disponível. Não é necessário preço de oferta paga.',
    'admin.vibe.offer_disabled' => 'Oferta desativada administrativamente. A sincronização não a reativa.',
    'admin.vibe.price' => 'Preço ausente ou expirado. Configure um preço no produto visível deste WHMCS e sincronize as ofertas Vibe.',
    'admin.vibe.attempt.synced' => 'Última sincronização aceita pela API: {count} produtos. A publicação depende também da disponibilidade do plano e do perfil comercial ativo.',
    'admin.vibe.attempt.snapshot-incomplete' => 'Sincronização não enviada: catálogo incompleto. Confira a conexão e os vínculos e categorias dos produtos Vibe deste servidor.',
    'admin.vibe.attempt.mapping-conflict' => 'Sincronização recusada: vínculo de produto ou categoria incompatível. Revise os produtos locais e os vínculos do catálogo com o administrador da plataforma.',
    'admin.vibe.attempt.sync-failed' => 'A última sincronização falhou. Confira a conexão deste servidor com a API do mesmo ambiente e tente novamente.',
    'admin.vibe.attempt.invalid-server' => 'Selecione um servidor BladeCP válido para sincronizar.',
    'admin.vibe.attempt.server-unavailable' => 'Servidor indisponível. Confira a configuração da conexão BladeCP.',
    'admin.vibe.attempt.never' => 'Sem registro recente de sincronização neste servidor.',
];
