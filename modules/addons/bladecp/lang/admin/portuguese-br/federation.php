<?php
declare(strict_types=1);
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
return [
    'federation.title' => 'Acesso automático ao Vibe',
    'federation.description' => 'Gere aqui uma chave de assinatura exclusiva e cadastre somente os dados públicos com o administrador da plataforma. Ative após a plataforma confirmar o suporte. O acesso atual permanece inalterado até a ativação.',
    'federation.enabled' => 'Federação ativada localmente',
    'federation.disabled' => 'Federação desativada localmente',
    'federation.publicRegistration' => 'Dados públicos de cadastro (não contêm a chave privada)',
    'federation.generate' => 'Gerar chave da instalação',
    'federation.rotate' => 'Rotacionar chave de assinatura',
    'federation.enable' => 'Verificar disponibilidade e ativar',
    'federation.disable' => 'Desativar novos acessos federados',
    'federation.confirm.rotate' => 'Entendo que a rotação desativa novos acessos até o cadastro da nova chave pública.',
    'federation.confirm.enable' => 'O administrador da plataforma cadastrou esta instalação.',
    'federation.saved' => 'Configuração salva.',
    'federation.failed' => 'Configuração não alterada. Verifique o servidor selecionado, o cadastro e a disponibilidade da plataforma.',
];

