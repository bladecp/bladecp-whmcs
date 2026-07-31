<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
  <img alt="BladeCP" src="assets/logo-light.svg" width="268" height="48">
</picture>

# BladeCP para WHMCS

**Provisione e gerencie serviços BladeCP diretamente pelo WHMCS.**

Versão candidata: **0.9.0-rc.3**

[![Release candidate](https://img.shields.io/badge/release-0.9.0--rc.3-4f46e5)](https://github.com/bladecp/bladecp-whmcs/releases)
[![Licença](https://img.shields.io/badge/licen%C3%A7a-MIT-4f46e5)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-4f46e5?logo=php&logoColor=white)
![WHMCS 8.13.3 e 9.0.6](https://img.shields.io/badge/WHMCS-8.13.3%20%26%209.0.6-4f46e5)

[Baixar a versão mais recente](https://github.com/bladecp/bladecp-whmcs/releases) · [Instalar](#instalação) · [Configurar](#configuração) · [Segurança](#segurança) · [Processo de release](docs/release-process.md) · [English](README.md)

[![CI público](https://github.com/bladecp/bladecp-whmcs/actions/workflows/ci.yml/badge.svg)](https://github.com/bladecp/bladecp-whmcs/actions/workflows/ci.yml)

</div>

## Feito para parceiros BladeCP

BladeCP para WHMCS é o módulo oficial e open source de provisionamento para provedores que vendem serviços BladeCP a partir da própria instalação WHMCS. Ele conecta um servidor do WHMCS à Customer API da BladeCP, mantendo pedidos e ações de serviço no fluxo de cobrança já utilizado pelo provedor.

O módulo oferece suporte a produtos **Stack Container**, **Edge Cloud (VPS)** e **Dedicated** em um único pacote instalável. Serviços Dedicated usam um ciclo de vida manual explícito quando a plataforma o exige. Vibe não é provisionado por este módulo.

## Visão geral

| Um módulo | Controle operacional | Administração opcional |
| --- | --- | --- |
| Direcione produtos Stack, Edge e Dedicated por um único módulo WHMCS. | Crie, suspenda, reative, altere plano, encerre e abra o single sign-on quando a operação for suportada pelo produto escolhido. | Ative o addon complementar para setup guiado, importação de catálogo, opções configuráveis, webhooks e diagnósticos. |

## Veja no WHMCS

<p align="center">
  <img src="assets/screenshots/overview.jpg" alt="Visão geral de serviço BladeCP no WHMCS" width="900">
</p>

| Configuração | Planos e catálogo |
| --- | --- |
| <img src="assets/screenshots/configuration.jpg" alt="Configuração BladeCP no WHMCS" width="440"> | <img src="assets/screenshots/plans.jpg" alt="Planos BladeCP e importação de catálogo no WHMCS" width="440"> |

<p align="center">
  <img src="assets/screenshots/operations-diagnostics.jpg" alt="Operações e diagnósticos BladeCP no WHMCS" width="900">
</p>

## Recursos

### Provisionamento e gestão de serviços

- Provisione serviços Stack, Edge e Dedicated elegíveis a partir de pedidos WHMCS.
- Suspenda, reative, altere plano e encerre serviços por ações padrão do WHMCS quando a operação for suportada pelo produto escolhido.
- Abra o painel BladeCP com single sign-on.
- Exiba um painel de serviço com a marca BladeCP na área do cliente do WHMCS.
- Sincronize uso do serviço para fluxos de cobrança do WHMCS.
- Registre a intenção de cancelamento com as ações **Cancelar agora** e **Cancelar no fim do ciclo**.

### Addon BladeCP opcional

O provisionador funciona sem o addon. Quando ativado, o addon acrescenta uma área administrativa nativa do WHMCS para:

- Setup guiado do servidor e validação de conexão.
- Importação idempotente de catálogo, dos planos BladeCP para grupos e produtos WHMCS.
- Produtos simples ou compostos, combinações de componentes e sincronização de opções configuráveis.
- Registro de webhooks, diagnósticos e uma visão somente leitura da conta Marketplace.

## Compatibilidade

| Componente | Base de suporte desta release |
| --- | --- |
| WHMCS | 8.13.3 e 9.0.6, testados |
| PHP | 8.1 ou superior, testado com PHP 8.2.31 |
| Banco de dados | MariaDB 12.3.2 |
| Temas WHMCS | Administração Blend; temas de cliente Twenty-One e Six |
| Navegador | Google Chrome 150.0.7871.187 |
| Idiomas | Módulo voltado ao cliente: inglês, português (Brasil) e espanhol. Administração do addon: português (Brasil). |
| Dependências de runtime | Extensões PHP cURL, JSON e OpenSSL |

Esta base de compatibilidade é intencionalmente restrita: ela informa as versões do WHMCS testadas nesta release candidate e o ambiente local usado para os demais componentes. Teste a release em uma instalação WHMCS não produtiva antes de disponibilizá-la aos clientes.

## Requisitos

- Acesso administrativo a uma instalação WHMCS compatível.
- Conta partner BladeCP.
- Chave da Customer API BladeCP.
- PHP com a extensão cURL habilitada.
- Endpoint HTTPS público caso você habilite webhooks de entrada.

## Instalação

Instale a partir de uma [GitHub Release](https://github.com/bladecp/bladecp-whmcs/releases), não de um snapshot do código-fonte.

1. Baixe o ZIP da release e o arquivo `SHA256SUMS` correspondente.
2. Verifique o artefato no diretório que contém os dois arquivos:

   ```bash
   shasum -a 256 -c SHA256SUMS
   ```

3. Extraia o ZIP na raiz da instalação WHMCS. Ao fim, estes diretórios devem existir:

   ```text
   modules/servers/bladecp/
   modules/addons/bladecp/
   ```

   O ZIP instalável contém somente `modules/`. Ele não sobrescreve README,
   licença nem documentação da raiz do WHMCS.

4. No WHMCS, crie ou selecione um servidor em **Configurações do Sistema → Produtos/Serviços → Servidores**.
5. Configure a conexão BladeCP e execute **Test Connection** antes de atribuir o módulo a um produto.
6. Para usar setup guiado, ferramentas de catálogo, opções configuráveis ou webhooks, ative **BladeCP** em **Configurações do Sistema → Módulos de Addon**.

## Configuração

### Recomendado: setup guiado

Ative o addon BladeCP, abra o fluxo **Quick Setup**, informe a chave da Customer API e valide a conexão. O assistente cria o registro de servidor BladeCP usando os campos padrão do WHMCS.

### Configuração manual do servidor

Crie um servidor WHMCS com estes valores:

| Campo | Valor |
| --- | --- |
| Módulo | `bladecp` |
| Hostname, produção | `api.bladecp.com` |
| Hostname, sandbox | `api.bladecp.cloud` |
| Secure | Habilitado |
| Access Hash | Sua chave da Customer API BladeCP |

A chave de API deve ficar no campo **Access Hash** do registro de servidor no WHMCS. O addon opcional não requer uma segunda cópia dessa credencial.

Em seguida, crie um produto WHMCS, selecione o módulo `bladecp` e escolha **BladeCP Product Type** e **BladeCP Plan**. O addon pode importar entradas do catálogo e sincronizar opções configuráveis para o cliente quando esse fluxo se adequar à sua loja.

> [!WARNING]
> Defina o hostname explicitamente. Um hostname vazio usa o endpoint de produção.

## Observações sobre o ciclo de vida dos produtos

Stack e Edge foram desenhados para o ciclo regular de provisionamento do WHMCS. Produtos Dedicated estão disponíveis pelo mesmo módulo, mas seu provisionamento e ciclo de vida podem exigir tratamento manual explícito conforme o serviço oferecido pela plataforma. Confirme as operações disponíveis para o plano que você vende antes de habilitar fluxos automáticos para clientes.

## Segurança

- Armazene a chave da Customer API somente no registro de servidor do WHMCS.
- Trate chaves de API, segredos de assinatura de webhook e logs do módulo como dados operacionais sensíveis.
- Webhooks de entrada verificam suas assinaturas antes de aplicar uma atualização de serviço.
- Mantenha WHMCS, PHP e este módulo atualizados com a release que você validou.

Para relatar uma vulnerabilidade de forma privada, siga [SECURITY.md](SECURITY.md). Não inclua credenciais, payloads de webhook ou dados de clientes em uma issue pública.

## Releases e suporte

Toda release publicada disponibiliza o ZIP instalável e seu checksum SHA-256. Leia as notas da release antes de atualizar: [releases do BladeCP para WHMCS](https://github.com/bladecp/bladecp-whmcs/releases).

- Suporte técnico e de produto: [dev@bladecp.com](mailto:dev@bladecp.com)
- Bugs e solicitações de produto: [GitHub Issues](https://github.com/bladecp/bladecp-whmcs/issues)

## Contribuindo

Contribuições são bem-vindas. Leia [CONTRIBUTING.md](CONTRIBUTING.md) antes de
abrir uma issue ou pull request, mantenha as alterações focadas, adicione
cobertura relevante e nunca faça commit de credenciais ou dados de clientes.
O repositório privado no Bitbucket é a fonte primária de desenvolvimento:
contribuições aprovadas no GitHub são importadas ali antes de retornarem em uma
exportação pública posterior.

## Licença

BladeCP para WHMCS é distribuído sob a [Licença MIT](LICENSE).
