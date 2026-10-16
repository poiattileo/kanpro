# Central de Notificações por Quadro — design

Data: 2026-10-16. Status: APROVADO (4/4 confirmações em 2026-10-16).

## Intenção e porquê

Hoje os avisos do KanPro são globais (configs `zap_*` + aprovador) ou pontuais
(botões manuais). A equipe quer assinar notificações **por quadro e por
categoria de lista**: escolhe a categoria, o usuário do GLPI e o canal
(WhatsApp, e-mail ou os dois). Card novo naquela categoria avisa na hora;
todo dia às 8h/10h/13h/15h chega um resumo com a quantidade, se houver cards.
A mesma tela passa a ser o ponto central onde se gerencia tudo que o plugin
envia.

## Glossário

- **Regra**: uma linha (quadro, categoria de lista, usuário, canais, imediato
  on/off, resumo on/off, ativa on/off).
- **Categoria**: `list_type` da lista (`pending`, `chamados_espera`,
  `chamados_pendencia`, `pend_chamado`, `awaiting`, `andamento`, `retirada`,
  `done`, `todo`, `doing`, `backlog`, `abrir_chamado`, `andamento_chamado`,
  `chamado_finalizado`) — mesmas de `kanpro_valid_list_type()`, com dedução
  pelo nome para listas legadas (`kanpro_list_category()`).

## Metas

1. Seção "Notificações" no form do quadro (`board.form.php?id=X`, via
   `PluginKanproBoard::showForm()`): lista as regras do quadro + formulário
   para adicionar (select de categoria, login do usuário, checkboxes
   WhatsApp/e-mail, flags imediato/resumo) + remover e ativar/desativar.
2. Disparo imediato: card entra em lista de categoria com regra ativa
   (criar, mover, aprovar — os 3 pontos já engatados) → 1 mensagem por regra
   nos canais dela. Reusa fila/cron do WhatsApp; e-mail entra em fila nova.
3. Resumo 8h/10h/13h/15h: cron por turno varre regras com resumo ativo;
   se o quadro tiver >0 cards não arquivados na categoria, envia contagem
   (+ detalhe por lista) nos canais da regra.
4. A central mostra também um resumo somente-leitura dos avisos globais que
   já existem (destinatários do chamado, aprovador, lembrete 8/10/13, fluxos
   de manutenção) com link para KanPro > Configurações.

## Não-metas

- Outros canais (SMS, Telegram — removido antes — push).
- Horário de silêncio por usuário, texto editável por regra, digest por
  e-mail com anexos.
- Migrar os avisos globais para regras (ficam como estão; migração futura).

## Anti-metas (falha mesmo funcionando)

- Quebrar fluxo principal: todo envio em try/catch, falha vira log, nunca
  excepciona (padrão atual do `MaintenanceZap`).
- Spam: anti-duplicado por (card, regra, canal) no imediato e por
  (dia, turno, regra, canal) no resumo; resumo só envia se >0.
- E-mail institucional hardcoded no código (guard `ci/check-secrets.php`).

## Restrições

- GLPI 11 (best-effort) / 12, PHP 8.2/8.3, MySQL 8 / MariaDB — sem dependência
  nova de composer.
- E-mail depende do GLPI com SMTP configurado + cron `queuednotification`
  ativo (fila oficial de notificações do GLPI).
- WhatsApp depende do que já existe (Evolution API via whatsappsimples +
  cron `zapqueue` externo).
- Testes no padrão `tests/run.php` (stubs + FakeDB); CI verde (lint, testes,
  check-secrets, PHPStan).

## Como (decidido)

- Nova tabela `glpi_plugin_kanpro_notify_rules`: `id`, `plugin_kanpro_boards_id`,
  `list_category` VARCHAR(30), `users_id` INT (resolvido do login na hora de
  salvar), `notify_whats` TINYINT, `notify_mail` TINYINT, `notify_entry`
  TINYINT default 1, `notify_digest` TINYINT default 1, `is_active` TINYINT
  default 1, `users_id_creator`, `date_creation`, `date_mod`. DDL em `hook.php`
  (padrão do plugin). Índice único `(boards_id, list_category, users_id)` —
  recriar a mesma combinação reativa em vez de duplicar.
- CRUD via ajax (`notify_rule_add`, `notify_rule_del`, `notify_rule_toggle`),
  só quem gerencia o quadro (`kanpro_can_manage_members`), com whitelist de
  POST como nos demais handlers.
- Usuário salvo por `users_id`; na tela mostra login + nome amigável. Se na
  criação faltar telefone (p/ whats) ou e-mail (p/ mail), salva mesmo assim
  com selo de aviso "sem telefone"/"sem e-mail" — no envio, canal impossível
  é pulado com log (não falha a regra).
- WhatsApp imediato e resumo: reusa `enqueue()`/`processQueue()` com milestone
  `nb<ruleid>` (imediato, por card) e `nbd<YMD><slot>r<ruleid>` (resumo).
  Telefone via `glpi_users.phone|mobile` por `users_id` (mesma normalização BR).
- E-mail: novos templates `templates_email/notify_entry.html` e
  `templates_email/notify_digest.html` (render em `MaintenanceMail`); envio
  pela fila oficial do GLPI (`QueuedNotification`, com camada de compat
  11/12 em `inc/compat.php`); destinatário = e-mail do usuário
  (`glpi_users`/`glpi_useremails`). Sem SMTP/cron no GLPI, o job falha com
  log — nunca quebra o fluxo.
- Gatilho imediato: generaliza `kanpro_notify_pending_entry()` para
  `kanpro_notify_board_entry($bid, $cid, $lists_id)` — mantém o aviso global
  da Pendente e soma as regras do quadro/categoria. Mesmos 3 pontos de
  chamada (criar, mover, aprovar); `move_all` (lote) não dispara.
- Resumo: 4 crons externos `zapdigest8/10/13/15` (mesmo padrão
  hourmin/hourmax + frequency 1800 do `registerCron()`), um `cronZapdigest()`
  que recebe o turno por `param`. trava distribuída via `$DB->getLock()`
  como no `sendLembrete()`.
- Resumo só em dias úteis (seg–sex, sem feriado, com exceções do calendário
  `lembrete_days`) — mesma regra do lembrete atual.
- Resumo conta cards não arquivados das listas do quadro na categoria
  (inclui legadas por nome); texto WhatsApp reaproveita o estilo do
  `lembrete.txt` com recorte por quadro/categoria.

## Aberto (respondido pelo dono em 2026-10-16 — vale o recomendado)

1. Regras por quadro **convivem** com os avisos globais atuais
   (chamado_abrir/lista_pendente p/ os 5 logins, pendência p/ o aprovador,
   lembrete 8/10/13); a central mostra os globais como resumo. ✅ Sim.
2. Resumo só em dias úteis (igual lembrete). ✅ Sim.
3. E-mail pela fila oficial do GLPI (exige SMTP + cron `queuednotification`
   ativos no ambiente). ✅ Sim.
4. Imediato vale para criar + mover + aprovar. ✅ Sim.
