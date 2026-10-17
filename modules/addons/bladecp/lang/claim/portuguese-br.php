<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'title' => 'Confirme a sua conta',
    'intro' => 'A hospedagem de {domain} será ligada a esta conta, e as próximas cobranças dela virão por aqui.',
    'site' => 'Site',
    'account' => 'Conta',
    'confirm' => 'Confirmar',
    'note' => 'Nada é cobrado agora. A cobrança segue as condições informadas no painel da sua hospedagem.',
    'done_title' => 'Pronto.',
    'done' => 'Pode voltar ao seu painel.',
    'login' => 'Entre ou crie a sua conta para confirmar a migração.',
    'owner_only' => 'Entre com o usuário dono desta conta para confirmar a migração.',
    'expired' => 'Este link expirou. Abra a confirmação de novo no painel da sua hospedagem.',
    'invalid' => 'Este link não é válido. Abra a confirmação de novo no painel da sua hospedagem.',
    'used' => 'Esta migração já foi confirmada. Se foi você, pode voltar ao seu painel.',
    'ended' => 'Esta migração não aguarda mais confirmação.',
    'profile' => 'Complete o nome e o e-mail do seu cadastro e tente de novo.',
    'unavailable_provider' => 'Não foi possível confirmar agora. O seu provedor já foi avisado.',
    'unavailable' => 'Não foi possível confirmar agora. Tente de novo em alguns minutos.',
    'session' => 'A sessão expirou. Confirme de novo.',
    'failed' => 'Não foi possível confirmar agora. Tente de novo em alguns minutos.',
];
