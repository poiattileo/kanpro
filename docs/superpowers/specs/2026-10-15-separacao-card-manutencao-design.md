# Separar card de Manutenção — split 1/2 e 2/2 com merge na Retirada

Data: 2026-10-15
Status: aprovado pelo usuário ("pode fazer e subir pro git")

## Problema

Um card de manutenção (ex. "389", escola com 20 máquinas) às vezes precisa ser
dividido entre dois técnicos: cada um pega parte das máquinas, trabalha em
paralelo e, no fim, tudo volta a ser UM card só na Retirada com UM termo de
assinatura.

## Fluxo aprovado

1. Técnico (vinculado ao card) clica **Separar** nas ações do modal, escolhe as
   máquinas que vai levar e confirma.
2. Sistema cria o **card filho** (`389/2`), move as máquinas escolhidas para ele,
   **trava pai + filho** (verde, borda verde) e abre pendência na lista de
   categoria **Pendência Chamados** (3º card, também verde).
3. Aprovador **aprova** a separação → destrava pai + filho; passam a exibir
   **1/2** (pai) e **2/2** (filho).
4. Técnico 1 finaliza o seu card → ele **espera** (não gera termo, não vai para
   Retirada).
5. Técnico 2 finaliza o dele → **merge**: máquinas do filho voltam para o pai,
   filho é arquivado, gera-se **1 termo só** e o pai vai para a Retirada.

## Decisões (minhas, aprovadas em bloco pelo usuário)

- **Nome do filho:** `{nome do pai}/2` (ex. pai "389" → filho "389/2"). Sem
  renomear o pai; os contadores 1/2 e 2/2 aparecem como badge.
- **Novas colunas em `glpi_plugin_kanpro_cards`** (via `kanpro_migrate_schema_once`,
  padrão `fieldExists` do plugin): `split_pending` (1 = aguardando aprovação),
  `split_group` (id do pai; 0 = fora de split), `split_index` (1 = pai, 2 = filho),
  `split_finalized` (1 = este lado já finalizou e aguarda o irmão).
- **Pendência usa o mecanismo existente:** card no topo da Pendência Chamados com
  `chamado_source_id` = pai e `chamado_status` = `separacao` (aprovado vira
  `separacao_ok`). Aprova/recusa com `kanpro_can_approve_pendencia` (mesma regra
  das demais aprovações).
- **Recusa:** máquinas voltam para o pai, filho é arquivado, pendência marcada
  como resolvida. Nada é apagado de verdade.
- **Merge sempre no pai:** quem finalizar por segundo dispara o merge (filho →
  pai), o termo único sai no id do pai e só o pai vai para a Retirada.
- **Sem split aninhado:** card que já está em split não pode separar de novo.
- **Concluir (request_conclusao) bloqueado** enquanto o split está ativo — o
  caminho de saída é o Finalizar com merge, para não furar o termo único.
- **Verde:** `split_pending = 1` (pai/filho) e `chamado_status = separacao`
  (pendência) usam o mesmo verde `#61bd4f` das pendências de conclusão.
- **Trava de edição vinculada continua valendo** em cada card (cada técnico edita
  o seu); durante a pendência de aprovação os dois ficam travados para todos.

## Anti-objetivos

- Não muda cards fora de manutenção nem o fluxo Chamado/ticket.
- Não gera termo por lado: termo duplicado é fracasso.
- Nenhum lado pode ir sozinho para a Retirada antes do merge.

## Testes

- Helpers puros `kanpro_is_split_pending()` e `kanpro_split_sibling()` em
  `tests/run.php` (mesmo padrão dos testes de vínculo).
