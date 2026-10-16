# Central de Notificações por Quadro Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Regras de notificação por quadro/categoria/usuário/canais com aviso imediato e resumo 8/10/13/15h via WhatsApp e e-mail.

**Architecture:** Nova tabela de regras + seção na tela do quadro; gatilho imediato generaliza o hook atual da Pendente; resumo em 4 crons externos espelhando o lembrete; e-mail sai pela fila oficial do GLPI. Avisos globais atuais seguem intactos.

**Tech Stack:** PHP 8.2/8.3, GLPI 11/12 (via `inc/compat.php`), MySQL/MariaDB, Evolution API (WhatsApp), fila `glpi_queuednotifications` (e-mail), testes `tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-10-16-central-notificacoes-quadro-design.md`

## Global Constraints

- GLPI 11 (best-effort) / GLPI 12; PHP 8.2+ (GLPI 12 exige 8.3).
- Nenhuma dependência nova de composer.
- Nenhum e-mail institucional literal sem `LEGACY-SEED-ALLOW` na linha (`ci/check-secrets.php`).
- Nenhum envio quebra o fluxo principal: try/catch em tudo, falha vira log (`logCard` / activity).
- Milestones do zaplog têm limite de 30 chars.
- Resumo só em dias úteis (usa `lembreteBusinessCheck()` + overrides `lembrete_days`).
- Resumo só envia se contagem > 0; imediato 1x por (card, regra, canal).
- Testes no estilo `kanpro_test` + `assert_same`/`assert_true` em `tests/run.php`; ver com `php tests/run.php`; CI precisa ficar verde.

## Review Focus

- Regra cuja categoria não tem lista nem card no quadro → resumo pula sem erro; espera-se `['ok'=>false]` silencioso (teste na Task 5).
- Usuário sem telefone com canal whats marcado → canal pulado com log, e-mail da mesma regra ainda sai (teste na Task 3).
- Card movido entre duas listas da mesma categoria → imediato refire mas milestone barra reenvio; espera-se `duplicate` (teste na Task 3).
- Cron do digest disparado 2x no mesmo turno (cron SO + polling) → só um prossegue via lock + milestone (teste na Task 5).
- Login inexistente ao criar regra → rejeita com mensagem, nada é gravado (teste na Task 2).

---

### Task 1: Tabela de regras + modelo

**Files:**
- Modify: `hook.php` (DDL da tabela no `plugin_kanpro_install()`)
- Create: `inc/notifyrule.class.php` (`PluginKanproNotifyRule`)
- Modify: `tests/run.php` (novos testes)

**Interfaces:**
- Consumes: nada (primeira task).
- Produces:
  - `PluginKanproNotifyRule::getRulesForBoard(int $bid, bool $activeOnly = true): array` — linhas `['id','boards_id','list_category','users_id','login','notify_whats','notify_mail','notify_entry','notify_digest','is_active']`.
  - `PluginKanproNotifyRule::resolveUser(string $login): ?array` — `['users_id'=>int,'login'=>string,'name'=>string,'phone'=>string,'email'=>string]` ou `null` se não existir (login exato em `glpi_users.name`, senão e-mail em `glpi_useremails`/`glpi_users.email`; telefone = `phone|mobile` normalizado, e-mail idem).
  - `PluginKanproNotifyRule::addRule(int $bid, string $category, string $login, bool $whats, bool $mail): array` — `['ok'=>true,'id'=>N,'warnings'=>[...]]` ou `['ok'=>false,'error'=>...]`; recriar combinação existente reativa em vez de duplicar (unique `uniq_board_cat_user`).
  - `PluginKanproNotifyRule::deleteRule(int $id, int $bid): array`, `::setActive(int $id, int $bid, bool $on): array`.

- [ ] **Step 1: Write the failing tests** em `tests/run.php` (segue o bloco dos recipients):
  - `resolveUser` com login inexistente devolve `null`.
  - `addRule` com categoria inválida (`'banana'`) devolve `['ok'=>false]`.
  - `addRule` com os dois canais desligados devolve `['ok'=>false]`.
  - `addRule` duplicado (mesmo quadro/categoria/usuário) reativa e devolve o mesmo `id`, sem criar linha nova.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php tests/run.php` — Expected: FAIL em "class not defined" (ou tabela ausente).
- [ ] **Step 3: Implement** DDL em `hook.php` (tabela `glpi_plugin_kanpro_notify_rules` com as colunas do spec + unique + extinct `ensure` se tabela já existir) e a classe em `inc/notifyrule.class.php` (categoria validada por `kanpro_valid_list_type()`; `users_id` resolvido na gravação; canais via `notify_whats`/`notify_mail`).
- [ ] **Step 4: Run tests to verify they pass** — Run: `php tests/run.php` — Expected: PASS (se `FakeDB`/stubs não tiverem a tabela nova, estenda-os nesta task).
- [ ] **Step 5: Commit** — `git add hook.php inc/notifyrule.class.php tests/run.php` + `git commit -m "feat(notify): tabela e modelo de regras por quadro"`.

### Task 2: Seção Notificações na tela do quadro + ajax

**Files:**
- Modify: `inc/board.class.php` (`showForm()`, após a linha do WhatsApp ~649)
- Modify: `front/ajax.php` (3 actions)
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: Task 1 (`addRule`, `deleteRule`, `setActive`, `getRulesForBoard`).
- Produces: actions ajax `notify_rule_add` (retorna `['success'=>true,'id'=>N,'warnings'=>[]]`), `notify_rule_del`, `notify_rule_toggle`; permissão via `kanpro_can_manage_members($bid)`; seção renderiza tabela de regras + mini-form (select de categorias de `kanpro_valid_list_type()`, input login, checks whats/mail/entry/digest).

- [ ] **Step 1: Write the failing tests**:
  - POST de `addRule` com login vazio → `['ok'=>false]` (validação pura reaproveitada).
  - Regra salva sem telefone com whats marcado → `warnings` contém `'sem telefone'`.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php tests/run.php` — Expected: FAIL.
- [ ] **Step 3: Implement** seção em `showForm()` (lista via `getRulesForBoard` + form que posta nos 3 actions; JS segue o padrão das demais telas do plugin) e os 3 handlers em `ajax.php` com whitelist de POST e `kanpro_require_board_edit`/`kanpro_can_manage_members`.
- [ ] **Step 4: Run tests to verify they pass** — Run: `php tests/run.php` — Expected: PASS. Ver também: `php -l inc/board.class.php front/ajax.php`.
- [ ] **Step 5: Commit** — `git commit -m "feat(notify): gerenciar regras na tela do quadro"`.

### Task 3: Imediato por regra (WhatsApp + e-mail)

**Files:**
- Modify: `front/ajax.php` (`kanpro_notify_pending_entry()` vira generalista)
- Modify: `inc/maintenancezap.class.php` (novos métodos)
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: Task 1 (`getRulesForBoard`); fila `enqueue()`/`alreadySent()`/`recipientMilestone()` existentes; e-mail via Task 4 (`kanpro_queue_email()` — se Task 4 ainda não existir quando esta for executada, o envio de e-mail fica atrás de `function_exists`, sem falhar).
- Produces:
  - `kanpro_notify_board_entry(int $bid, int $cid, int $lists_id): void` — mantém o aviso global da Pendente e soma as regras ativas do quadro/categoria com `notify_entry=1`.
  - `PluginKanproMaintenanceZap::sendRuleEntry(array $rule, int $cards_id, int $lists_id): array` — milestone base `'nb' . $ruleId` (+ sufixo por destinatário via `recipientMilestone()`); texto reaproveita `lista_pendente.txt` para `pending` e `chamado_abrir.txt` para `chamados_espera`, demais categorias usam `card_alerta.txt` com `{card_nome}{quadro}{lista}{data}`.

- [ ] **Step 1: Write the failing tests**:
  - Segunda chamada de `sendRuleEntry` p/ mesma regra+card → `['ok'=>false,'error'=>'duplicate']`.
  - Regra com whats marcado e usuário sem telefone → canal pulado (`errors` contém `'sem telefone'`) sem excepcionar.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php tests/run.php` — Expected: FAIL ("undefined method").
- [ ] **Step 3: Implement** `sendRuleEntry()` (loop de canais da regra; whats via `enqueue()`; mail via `kanpro_queue_email()` atrás de `function_exists`) e a generalização do helper em `ajax.php` (mantém os 3 pontos de chamada atuais).
- [ ] **Step 4: Run tests to verify they pass** — Run: `php tests/run.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(notify): aviso imediato por regra do quadro"`.

### Task 4: Canal e-mail (templates + fila do GLPI)

**Files:**
- Create: `templates_email/notify_entry.html`, `templates_email/notify_digest.html`
- Modify: `inc/maintenancemail.class.php` (`allowedTypes()` + render/subject)
- Modify: `inc/compat.php` (`kanpro_queue_email()`)
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: nada novo (render puro).
- Produces:
  - `PluginKanproMaintenanceMail::render('notify_entry'|'notify_digest', array $data): ?string` e `::subject(string $type, array $data): string`.
  - `kanpro_queue_email(string $to, string $subject, string $htmlBody): array` — `['ok'=>true]` ou `['ok'=>false,'error'=>...]`; usa `QueuedNotification` (GLPI 11/12 via `compat.php`); nunca excepciona.

- [ ] **Step 1: Write the failing tests**:
  - `render('notify_entry', [...])` contém o nome do card e o nome da lista (valores do spec).
  - `render()` com tipo desconhecido devolve `null`.
  - `kanpro_queue_email()` com e-mail inválido devolve `['ok'=>false]` sem excepcionar.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php tests/run.php` — Expected: FAIL.
- [ ] **Step 3: Implement** os 2 templates (mesmo layout dos `*.html` existentes), os tipos no `allowedTypes()` + dados em `baseData()`, e `kanpro_queue_email()` (resolve a API de fila conforme a versão do GLPI; sem SMTP/cron devolve erro limpo).
- [ ] **Step 4: Run tests to verify they pass** — Run: `php tests/run.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(notify): canal e-mail via fila do GLPI"`.

### Task 5: Resumo 8/10/13/15h

**Files:**
- Modify: `inc/maintenancezap.class.php` (`cronZapdigest()`, `sendRuleDigest()`, `registerCron()`)
- Modify: `hook.php` (chama `registerCron()` — já chama; só garantir os 4 novos nomes) e `unregisterCron()` se listar nomes
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: Task 1 (`getRulesForBoard` com `notify_digest=1`); `lembreteBusinessCheck()`/`lembreteDayOverride()`; fila whats (Task 3) e `kanpro_queue_email()` (Task 4, atrás de `function_exists`).
- Produces: crons externos `zapdigest8/10/13/15` (frequency 1800, janela 1h, `param`=turno) e `PluginKanproMaintenanceZap::sendRuleDigest(array $rule, int $slot, bool $force=false): array` — conta cards não arquivados das listas do quadro na categoria da regra (dedução por nome p/ legadas); zero → `['ok'=>false,'error'=>'nada pendente']`; milestone `nbd<YMD><slot>r<ruleid>` (+ sufixo por destinatário); lock via `$DB->getLock()`.

- [ ] **Step 1: Write the failing tests**:
  - Regra cuja categoria zerou no quadro → `sendRuleDigest()` devolve erro "nada pendente", sem envio.
  - Segunda chamada mesmo dia/turno/regra → `duplicate`.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php tests/run.php` — Expected: FAIL.
- [ ] **Step 3: Implement** `sendRuleDigest()` (contagem espelhando `sendLembrete()`, filtrada por quadro+categoria; texto whats no estilo `lembrete.txt`; e-mail com o template da Task 4) + `cronZapdigest()` + registro dos 4 crons.
- [ ] **Step 4: Run tests to verify they pass** — Run: `php tests/run.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(notify): resumo 8/10/13/15h por regra"`.

### Task 6: Resumo dos globais + verificação final

**Files:**
- Modify: `inc/board.class.php` (bloco somente-leitura na seção Notificações)
- Verificação: CI

**Interfaces:**
- Consumes: configs `zap_chamado_recipients`, `zap_approver`, `zap_reminder_recipients` (leitura via `kanpro_config_get()`).
- Produces: bloco "Avisos globais ativos" com links p/ `config.form.php`; nenhum comportamento alterado.

- [ ] **Step 1: Implement** o bloco somente-leitura (destinatários atuais + turnos do lembrete + nota de que convivem com as regras).
- [ ] **Step 2: Run full verification** — Run: `php ci/lint.php`, `php tests/run.php`, `php tests/queue.php`, `php tests/cron-context.php` — Expected: tudo PASS (ou rode e reporte; o CI do push cobre o resto).
- [ ] **Step 3: Commit + push** — `git commit -m "feat(notify): central mostra avisos globais"` e `git push`.

## Self-Review

1. **Spec coverage:** metas 1→Task 2, 2→Tasks 3+4, 3→Task 5, 4→Task 6; tabela→Task 1; restrições→Global Constraints. Sem gaps.
2. **Step scan:** cada step gera exatamente uma ação (teste com asserts, comando com saída esperada, método com assinatura exata, commit com arquivos). Sem "TBD"/generalidades.
3. **Type consistency:** `getRulesForBoard()` → `array` consumido por Tasks 2/3/5 com as mesmas chaves; `sendRuleEntry(array $rule, int, int)` e `sendRuleDigest(array $rule, int, bool)`; `kanpro_queue_email(string,string,string): array`; milestones `nb<id>` / `nbd<YMD><slot>r<id>` dentro de 30 chars.
4. **Review Focus:** 5 itens, cada um com teste na task dona (Tasks 2, 3, 3, 5, 5 na ordem da lista).
5. **Proportion:** plano traz assinaturas/valores, não corpos — mais curto que o código que descreve.
