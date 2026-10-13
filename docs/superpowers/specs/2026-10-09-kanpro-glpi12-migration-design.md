# KanPro — Migração para GLPI 12 (com best-effort em GLPI 11)

Data: 2026-10-09
Branch de trabalho: `glpi12`
Repo: `git@github.com:poiattileo/kanpro.git`

## Contexto

O plugin KanPro (v1.5.3) declara suporte a GLPI `>= 11.0.0` e PHP `>= 8.1`, sem
namespaces/`use`, com a estrutura legada (`inc/`, `front/`). O GLPI 12.0.0
(stable, 2026-10-07) passou a exigir **PHP 8.3+** e adicionou **tipos nativos**
em assinaturas de classes do core, além de remover/deprecar APIs. Isso quebra a
instalação/ativação e o fluxo principal do plugin.

O usuário informou:
- Possui um servidor GLPI 12 e fará o deploy (sem acesso local/Docker aqui).
- **Alvo primário: GLPI 12.** GLPI 11 é **best-effort** (não quebrar de forma óbvia, sem teste dedicado).
- Critério de pronto: **instalar + ativar + fluxo principal** funcionando.

## Objetivo

Fazer o KanPro instalar, ativar e operar o fluxo principal no GLPI 12.0.x:

> quadros → listas → cartões → checklists → comentários → permissões

## Não-objetivos

- Rewrite PSR-4 / namespaces / controllers / Twig / Migration API.
- Reescrever WhatsApp / e-mail / cron (só mexer se travar a ativação).
- Suporte a GLPI 10 ou anterior.
- Garantia testada de GLPI 11 (apenas "não quebrar de forma óbvia").

## Surface de migração (confirmado no código)

1. **Hook `csrf_compliant`** — removido no GLPI 12. Definido em `setup.php` (linha ~8).
2. **`Plugin::getWebDir()`** — **removido no 12**. Usado em:
   - `inc/board.class.php` (2×)
   - `front/board.php` (302)
   - `front/kanban.php` (141)
   - `front/ajax.php` (4700, 6250, 6532, 6761 — refs ao plugin `assetmgrstatus`)
   - `Plugin::getPhpDir()` também usado em `inc/board.class.php`, `inc/maintenancemail.class.php`, `inc/maintenancezap.class.php`.
3. **CSRF** — `Session::getNewCSRFToken()` **deprecado no 12**; emissão de `_glpi_csrf_token` + header
   `X-Glpi-Csrf-Token` espalhada (`board.php`, `kanban.php`, `entitycontacts.php`,
   `config.form.php`, `profile.class.php`, `ajax.php`, `acting.php`). No 12 a validação
   passa a ser por `Sec-Fetch-Site`/`Origin`.
4. **Tipagem nativa nas assinaturas** de `CommonDBTM`/`CommonGLPI` — principal causa de
   fatal error. O KanPro sobrescreve `getMenuContent`, `defineTabs`, `prepareInputForAdd/Update`,
   `canView/canCreate/canUpdate/canDelete/...` (14 classes `extends CommonDBTM`), além de
   `inc/profile.class.php` e `inc/board.class.php`.
5. **Assinaturas com tipos estritos** — `countElementsInTable` (100+ usos),
   `Session::haveRight` (agora retorna `bool`).
6. **APIs variadas** — `Sanitizer` (`inc/acting.php:754-755`, já guardado por
   `class_exists`/`method_exists`); DDL via `$DB->doQuery` (mantido no 12);
   `include('../../../inc/includes.php')` (deprecado, ainda servido para front legado).
7. **Helper de versão faltando** — não há ponto único de detecção de versão do GLPI.

## Design da solução (Abordagem A + version-gates leves)

### 1. Novo: `inc/compat.php`

Helpers de compatibilidade em runtime. Carregado via `require_once __DIR__ . '/inc/compat.php'`
no topo de `setup.php` (antes de qualquer uso) e nos front controllers que usam os helpers
(`front/board.php`, `front/kanban.php`, `front/ajax.php`). Todas as funções são guardadas por
`function_exists()` para evitar redeclaração.

- `kanpro_glpi_version(): string` e `kanpro_glpi_major(): int` — parse de `GLPI_VERSION`.
- `kanpro_is_glpi12_plus(): bool`.
- `kanpro_web_dir(string $plugin = 'kanpro'): string` — `Plugin::getWebDir()` se existir, senão `$CFG_GLPI['root_doc'] . '/plugins/' . $plugin`.
- `kanpro_php_dir(string $plugin = 'kanpro'): string` — `Plugin::getPhpDir()` se existir, senão `GLPI_ROOT . '/plugins/' . $plugin`.

### 2. `setup.php`

- Bump `PLUGIN_KANPRO_VERSION`.
- `PLUGIN_KANPRO_MIN_GLPI = '11.0.0'`; novo `PLUGIN_KANPRO_MAX_GLPI = '12.0.99'`.
- `requirements`: `glpi.min = 11.0.0`, `glpi.max = 12.0.99`, `php.min = 8.2` (o GLPI 12 já
  aplica o próprio 8.3; assim o 11 em PHP 8.2 continua instalável).
- Hook `csrf_compliant` **só** definido quando `!kanpro_is_glpi12_plus()`.

### 3. Caminhos

Trocar `Plugin::getWebDir()` / `Plugin::getPhpDir()` pelos helpers em todos os pontos
listados na surface.

### 4. CSRF

- Manter a emissão dos tokens no JS/PHP (inofensiva se ignorada).
- **Não** checar CSRF explicitamente no GLPI 12: gate de `Session::checkCSRF()` para
  versões `< 12`; no 12 o listener de `Sec-Fetch-Site`/`Origin` cuida.

### 5. Assinaturas de override

Para cada override de `CommonDBTM`/`CommonGLPI`:
- Parâmetros **sem tipo** (o mais largo possível) → compatível com core 11 (sem tipo) e 12 (tipado).
- Retorno compatível com ambos (ex.: manter `: bool` onde o core 11/12 também o tem).
- Validar contra o core **11 e 12** (fontes oficiais no GitHub).

### 6. APIs removidas/alteradas

- Confirmar que não usamos `Html::ajaxFooter`, `displayErrorAndDie`, `Toolbox` removidos, etc.
- Ajustar `Html::header(...)` se a assinatura mudou no 12.

### 7. Testes / CI / docs

- Subir a matriz do CI para incluir **PHP 8.3**; manter a suíte (stubs).
- Atualizar `README.md` (GLPI 11/12) e notas de compatibilidade.

## Arquitetura (módulos tocados)

- `inc/compat.php` (novo) — camada de compatibilidade.
- `setup.php` — versão/requisitos/hook.
- `inc/*.class.php`, `front/*.php` — paths, CSRF, assinaturas.
- `.github/workflows/ci.yml`, `README.md`.

## Tratamento de erros

- Helpers degradam de forma segura (fallback quando o método do core não existe).
- Gates de versão evitam chamar APIs ausentes na versão em execução.

## Verificação

- `php -l` em todos os arquivos PHP alterados.
- Suíte de testes do plugin (`tests/`, baseada em stubs) passa.
- Diff estático de assinaturas de override vs core GLPI 11 e 12.
- Deploy pelo usuário na branch `glpi12`; validação por logs enviados pelo usuário.

## Riscos

- **Fatal error por assinatura de override** — mitigado pela auditoria item 5.
- **Sem ambiente de teste local** — mitigado por verificação estática + logs do usuário.
- **CSRF no 12** — se o gate for insuficiente, ajustar com base no log.

## Critério de aceite

1. Plugin instala e ativa no GLPI 12 sem erro fatal.
2. Fluxo principal (quadros/listas/cartões/checklists/comentários/permissões) opera.
3. GLPI 11 não quebra de forma óbvia (best-effort).
4. Branch `glpi12` publicada; usuário faz o deploy e reporta logs.
