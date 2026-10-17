<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'cart_unavailable' => 'Este produto está temporariamente indisponível porque sua automação ainda não foi liberada. Nenhuma cobrança ou provisão será iniciada.',
    'cart_free_direct' => 'O BladeCP Vibe grátis é ativado diretamente, sem carrinho. Use o botão Criar meu app grátis para continuar.',
    'cart_offer_expired' => 'A oferta comercial selecionada expirou ou não corresponde ao plano solicitado. Volte ao BladeCP Vibe e tente novamente.',
    'cart_components_unconfirmed' => 'Não foi possível confirmar agora os componentes escolhidos. Tente novamente em instantes. Nenhuma cobrança ou provisão será iniciada.',
    'cart_availability_unconfirmed' => 'Este produto BladeCP não pôde ter sua disponibilidade confirmada. Nenhuma cobrança ou provisão será iniciada.',
    'cart_option_unavailable' => 'A opção {option} em {category} não está mais disponível. Volte à configuração do produto e escolha outra opção. Nenhuma cobrança ou provisão será iniciada.',
    'cart_combo_unavailable' => 'O combo {combo} não está disponível no momento. Volte à configuração do produto e escolha outro combo. Nenhuma cobrança ou provisão será iniciada.',
    'cart_combo_unavailable_generic' => 'O combo deste produto não está disponível no momento. Nenhuma cobrança ou provisão será iniciada.',
    'vibe_downgrade_unavailable' => 'O downgrade do BladeCP Vibe Pro para Free não está disponível.',
    'sso_dedicated_unavailable' => 'Este servidor não oferece login único pelo painel.',
    'sso_provision_missing' => 'Provision UUID ausente.',
    'sso_panel_link_invalid' => 'A API não retornou um link HTTPS válido para acesso ao painel.',
    'sso_vibe_inactive' => 'O serviço BladeCP Vibe precisa estar ativo para acesso.',
    'sso_admin_unavailable' => 'Acesso administrativo indisponível.',
    'sso_unavailable' => 'Acesso indisponível agora.',
    'sso_vibe_account_missing' => 'BladeCP Vibe Account ID ausente.',
    'sso_vibe_link_invalid' => 'A API não retornou um link HTTPS temporário válido para acesso ao Vibe.',
    'password_not_used' => 'Os serviços BladeCP não usam senha por serviço. O acesso é feito pelo login da sua conta BladeCP: entre no painel BladeCP para gerenciar o serviço e trocar ali as credenciais de banco de dados ou Redis.',
    'vibe_no_password' => 'BladeCP Vibe não usa senha por serviço. O acesso é feito pela conta BladeCP Vibe.',
    'vibe_pro_activation_text' => 'Conclua a ativação do Vibe Pro.',
    'vibe_pro_activation_button' => 'Ativar o BladeCP Vibe',
];
