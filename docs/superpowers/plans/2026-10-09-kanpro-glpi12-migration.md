# KanPro — Migração para GLPI 12 (compat GLPI 11 best-effort) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o KanPro instalar, ativar e operar o fluxo principal no GLPI 12.0.x, sem quebrar o GLPI 11 (best-effort), tudo na branch `glpi12`.

**Architecture:** Camada mínima de compatibilidade (`inc/compat.php`) com helpers guardados por `function_exists`/`method_exists`; troca do único API removido que o plugin chama sem guard (`Plugin::getWebDir`), silenciamento das chamadas CSRF deprecadas no 12, e bump de versão/requisitos. Nenhuma reescrita de bootstrap (o init é automático via `/public/index.php`), nenhum override de core precisa mudar.

**Tech Stack:** PHP (sem framework/namespace), GLPI 11/12, plugin legado.

**Spec:** `docs/superpowers/specs/2026-10-09-kanpro-glpi12-migration-design.md`

## Global Constraints

- Branch de trabalho: `glpi12`. Nunca commitar fora dela.
- Alvo primário: GLPI **12.0.x**; GLPI 11 apenas best-effort (não quebrar de forma óbvia).
- `Plugin::getWebDir('plug')` (full=true) → `$CFG_GLPI['root_doc'] . '/plugins/plug/...'`.
- `include('../../../inc/includes.php')` fica como está (vira `Toolbox::deprecated` no 12; inofensivo).
- `Plugin::getPhpDir()` continua existindo no 12 → **não mexer** nos usos já guardados por `method_exists`.
- `$DB->doQuery()` mantido no 12 → DDL atual fica como está.
- Overrides de `CommonDBTM`/`CommonGLPI` são compatíveis nos dois → **não alterar assinaturas**.
- Estilo de commit do repo: `tipo(escopo): mensagem` (ex.: `fix(ci): ...`, `chore: ...`).
- Nenhum `as any`/supressão; código sem namespace, seguindo o padrão existente.

## Review Focus

1. `kanpro_web_dir()` deve devolver caminho correto quando `Plugin::getWebDir` **não existe** (12) — testar ambos os ramos.
2. Nenhuma chamada a `Plugin::getWebDir` deve sobrar (9 pontos) — grep de verificação.
3. `Session::getNewCSRFToken()`/`checkCSRF()` não devem ser chamados no 12 (evitar deprecation/flood de log), mas devem continuar sendo chamados no 11.
4. `Sanitizer` removido no 12 → o fallback de `inc/acting.php` deve continuar produzindo a string esperada.
5. `csrf_compliant` só pode ser setado no GLPI < 12 (evitar hook desconhecido no 12).

---

### Task 1: `inc/compat.php` (novo)

**Files:**
- Create: `D:\Projetos\kanpro\inc\compat.php`

**Interfaces (Produces):**
- `kanpro_glpi_version(): string`
- `kanpro_glpi_major(): int`
- `kanpro_is_glpi12_plus(): bool`
- `kanpro_web_dir(string $plugin = 'kanpro'): string`
- `kanpro_csrf_token(): string`
- `kanpro_check_csrf(array $data): void`

- [ ] **Step 1: Criar `inc/compat.php`** com todas as funções guardadas por `if (!function_exists('...'))`.
  - `kanpro_glpi_version()` → `defined('GLPI_VERSION') ? GLPI_VERSION : ''`.
  - `kanpro_glpi_major()` → `(int) explode('.', kanpro_glpi_version())[0]`.
  - `kanpro_is_glpi12_plus()` → `kanpro_glpi_major() >= 12`.
  - `kanpro_web_dir($plugin)` → `method_exists('Plugin','getWebDir') ? Plugin::getWebDir($plugin) : ((defined('CFG_GLPI') ? $GLOBALS['CFG_GLPI']['root_doc'] : '') . '/plugins/' . $plugin)`. Usar `global $CFG_GLPI;` em vez de `CFG_GLPI`.
  - `kanpro_csrf_token()` → `kanpro_is_glpi12_plus() ? '' : Session::getNewCSRFToken()`.
  - `kanpro_check_csrf(array $data)` → `if (!kanpro_is_glpi12_plus()) { Session::checkCSRF($data, true); }`.

- [ ] **Step 2: Verificar sintaxe**
  Run: `& "C:\php\php.exe" -l inc/compat.php`
  Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**
  `git add inc/compat.php && git commit -m "feat(compat): camada de compatibilidade GLPI 11/12"`

---

### Task 2: `setup.php` — versão/requisitos/hook

**Files:**
- Modify: `D:\Projetos\kanpro\setup.php`

**Interfaces:**
- Consumes: `kanpro_is_glpi12_plus()` (Task 1)

- [ ] **Step 1:** No topo, após `<?php`, adicionar `require_once __DIR__ . '/inc/compat.php';`.
- [ ] **Step 2:** Bump `PLUGIN_KANPRO_VERSION` para `1.6.0`. Adicionar `define('PLUGIN_KANPRO_MAX_GLPI', '12.0.99');`.
- [ ] **Step 3:** Em `plugin_version_kanpro()`, trocar `'requirements' => ['glpi' => ['min' => PLUGIN_KANPRO_MIN_GLPI]]` por `['glpi' => ['min' => PLUGIN_KANPRO_MIN_GLPI, 'max' => PLUGIN_KANPRO_MAX_GLPI], 'php' => ['min' => '8.2.0']]`.
- [ ] **Step 4:** Na linha 8, trocar `$PLUGIN_HOOKS['csrf_compliant']['kanpro'] = true;` por `if (!kanpro_is_glpi12_plus()) { $PLUGIN_HOOKS['csrf_compliant']['kanpro'] = true; }`.
- [ ] **Step 5:** Verificar sintaxe
  Run: `& "C:\php\php.exe" -l setup.php`
  Expected: `No syntax errors detected`
- [ ] **Step 6: Commit**
  `git add setup.php && git commit -m "feat(setup): suporte GLPI 12 (versao, requisitos, hook csrf)"`

---

### Task 3: Trocar `Plugin::getWebDir` por `kanpro_web_dir` (9 pontos)

**Files:**
- Modify: `D:\Projetos\kanpro\inc\board.class.php:323,656`
- Modify: `D:\Projetos\kanpro\front\kanban.php:141`
- Modify: `D:\Projetos\kanpro\front\board.php:302`
- Modify: `D:\Projetos\kanpro\front\ajax.php:4700,6250,6532,6761`

**Interfaces:**
- Consumes: `kanpro_web_dir()` (Task 1)

- [ ] **Step 1:** Em cada um dos 9 pontos, trocar `Plugin::getWebDir('kanpro')` → `kanpro_web_dir('kanpro')` e `Plugin::getWebDir('assetmgrstatus')` → `kanpro_web_dir('assetmgrstatus')`.
- [ ] **Step 2:** Garantir que `inc/compat.php` esteja carregado nesses contextos:
  - `inc/board.class.php`, `inc/acting.php`: `require_once __DIR__ . '/compat.php';` no topo (guardado por `require_once`, sem risco de dupla carga).
  - `front/kanban.php`, `front/board.php`, `front/ajax.php`: `require_once GLPI_ROOT . '/plugins/kanpro/inc/compat.php';` logo após o `include('../../../inc/includes.php');`.
- [ ] **Step 3: Verificação por grep** (não pode sobrar nenhuma chamada direta)
  Run: `Select-String -Path .\**\*.php -Pattern 'Plugin::getWebDir' -SimpleMatch`
  Expected: 0 resultados (fora de `inc/compat.php`, que usa `method_exists` e `Plugin::getWebDir` internamente).
  > Nota: a única ocorrência permitida de `Plugin::getWebDir` é dentro de `kanpro_web_dir()` em `inc/compat.php`.
- [ ] **Step 4:** Verificar sintaxe dos 5 arquivos com `php -l`.
- [ ] **Step 5: Commit**
  `git add -A && git commit -m "fix(glpi12): substitui Plugin::getWebDir por kanpro_web_dir"`

---

### Task 4: Silenciar CSRF deprecado no 12 (helper)

**Files:**
- Modify: `D:\Projetos\kanpro\front\board.php:303`, `D:\Projetos\kanpro\front\kanban.php:143`, `D:\Projetos\kanpro\front\entitycontacts.php:116`, `D:\Projetos\kanpro\front\config.form.php:75`, `D:\Projetos\kanpro\inc\profile.class.php:139`, `D:\Projetos\kanpro\front\ajax.php:60`

**Interfaces:**
- Consumes: `kanpro_csrf_token()`, `kanpro_check_csrf()` (Task 1)

- [ ] **Step 1:** Trocar `Session::getNewCSRFToken()` → `kanpro_csrf_token()` nos 5 pontos (board, kanban, entitycontacts, config.form, profile.class).
- [ ] **Step 2:** Trocar `Session::checkCSRF($_POST, true);` → `kanpro_check_csrf($_POST);` em `front/ajax.php:60`.
- [ ] **Step 3:** Manter o JS e o `kanpro_csrf_bridge()` de `inc/acting.php` como estão (inofensivos; enviam token vazio no 12).
- [ ] **Step 4:** `php -l` nos 6 arquivos + grep de verificação
  Run: `Select-String -Path .\**\*.php -Pattern 'getNewCSRFToken' -SimpleMatch`
  Expected: só ocorrências dentro de `inc/compat.php`.
- [ ] **Step 5: Commit**
  `git add -A && git commit -m "fix(glpi12): gates de CSRF via compat (no-op no 12)"`

---

### Task 5: Verificar fallback do `Sanitizer`

**Files:**
- Modify (se necessário): `D:\Projetos\kanpro\inc\acting.php:750-760`

- [ ] **Step 1:** Ler `inc/acting.php` em volta de 750-760 e garantir que, quando `Sanitizer` não existe (12), a função retorna a string esperada (não `null`).
- [ ] **Step 2:** Se o fallback retornar algo incorreto, ajustar para retornar `$html` cru (sem sanitização) ou usar `htmlescape()` conforme o caso. Manter comportamento idêntico ao 11 quando `Sanitizer` existe.
- [ ] **Step 3:** `php -l inc/acting.php`
- [ ] **Step 4: Commit** (somente se houver mudança)
  `git add inc/acting.php && git commit -m "fix(glpi12): fallback quando Sanitizer ausente"`

---

### Task 6: CI (PHP 8.3) + README

**Files:**
- Modify: `D:\Projetos\kanpro\.github\workflows\ci.yml`
- Modify: `D:\Projetos\kanpro\README.md`

- [ ] **Step 1:** Adicionar `8.3` à matriz de PHP do CI (mantendo `8.1`/`8.2` existentes), sem quebrar jobs atuais.
- [ ] **Step 2:** Atualizar README: GLPI 11 → "GLPI 11 (best-effort) / GLPI 12 (suportado)"; PHP min.
- [ ] **Step 3: Commit**
  `git add .github/workflows/ci.yml README.md && git commit -m "ci/docs: adiciona PHP 8.3 e atualiza compat GLPI 12"`

---

### Task 7: Verificação final + push

- [ ] **Step 1:** `php -l` em todos os arquivos PHP alterados (esperado: sem erros).
- [ ] **Step 2:** Rodar a suíte de testes do plugin (baseada em stubs). Localizar o runner em `D:\Projetos\kanpro\tests\` (ex.: `tests/run.php`) e executar com `& "C:\php\php.exe"`. Esperado: passa (ou registrar falhas pré-existentes).
- [ ] **Step 3:** `git push -u origin glpi12`.
- [ ] **Step 4:** Handoff: instruir o usuário a atualizar a cópia do plugin na `glpi12` do servidor GLPI 12, reinstalar/atualizar e ativar; pedir o log de erro se algo falhar.

## Self-Review

- **Spec coverage:** surface 1 (hook) → Task 2/4; 2 (getWebDir/getPhpDir) → Task 3 (getPhpDir não muda, documentado); 3 (CSRF) → Task 4; 4 (overrides) → validado como seguro (Global Constraints, sem task de código); 5 (countElementsInTable/haveRight) → auditado, sem mudança; 6 (Sanitizer/doQuery/includes) → Task 5 + constraints; 7 (helper de versão) → Task 1. ✔
- **Type consistency:** nomes de helper consistentes (`kanpro_web_dir`, `kanpro_csrf_token`, `kanpro_check_csrf`, `kanpro_is_glpi12_plus`). ✔
- **Review Focus:** cada linha tem verificação associada (grep/php -l/leitura). ✔
