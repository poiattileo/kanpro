# Card de Manutenção — só o responsável (vinculado) ou Gerente/Criador edita

Data: 2026-10-14
Status: aguardando aprovação

## Problema

Hoje, em cards de **Manutenção** (`glpi_plugin_kanpro_cards.is_maintenance = 1`), depois
que uma pessoa clica em **"Pegar"** (`pegar_pending_card`), ela é gravada como membro do
card (`glpi_plugin_kanpro_cards_members`). Porém **qualquer membro do quadro** ainda
consegue finalizar/concluir/editar/excluir o card de outra pessoa — as ações de manutenção
só checam o cargo no quadro (`kanpro_my_board_role` / `kanpro_can_manage_members`), nunca
se a pessoa é a responsável pelaquele card.

## Objetivo

Depois que o card de manutenção tem alguém vinculado, **somente quem está vinculado ao
card** (ou o **Gerente do quadro** / **criador do quadro**) pode modificá-lo. Os demais
membros só **visualizam**.

## Não-objetivos

- Não muda cards que **não** são de manutenção.
- Não muda o botão **Pegar** (continua liberado a membro/admin do quadro enquanto o card
  está na lista Pendente — é o que vincula a pessoa).
- Não altera a lógica de cards do fluxo **Chamado/ticket**.

## Anti-objetivos (o que seria um fracasso)

- Trancar o card para quem deveria editar (falso positivo) — ex.: o próprio responsável,
  o gerente ou o dono do quadro não conseguirem mexer.
- Tornar um card de manutenção **sem membros** impossível de editar por membros do quadro.
- Permitir bypass pela API/servidor de quem só deveria ver.

## Decisões (do usuário)

1. **Escopo:** só cards de **Manutenção** (`is_maintenance = 1`).
2. **"Vinculado":** quem está em `glpi_plugin_kanpro_cards_members` **ou** é o criador do
   card — exatamente o que `kanpro_is_card_linked()` já resolve.
3. **Permissão total:** papel **Gerente** do quadro **+ criador do quadro**
   (`kanpro_can_see_all()` / `canSeeAll()`). Admin do quadro **não** ganha bypass novo.
4. **Ações cobertas:** fluxo manutenção (Finalizar / Concluir / Solicitar Exclusão /
   Editar), **mover/arrastar** o card, **editar campos/comentários/anexos/checklist** e
   **adicionar/remover membros**.
5. **UI para quem não pode:** só visualização + aviso (botões de ação somem).
6. **Card sem vínculo:** cai no comportamento atual (sem trava).
7. **Aprovações** (Liberar conclusão / exclusão / edição): quem tem acesso à lista de
   categoria `pend_chamado`.

## Decisões minhas (revisar)

- A trava só "liga" quando o card de manutenção tem **≥ 1 membro** em
  `cards_members` (consequência da decisão 6).
- **Excluir card direto** (`delete_card`, hard delete admin-only do quadro) **não** entra na
  trava — a exclusão do fluxo de manutenção é o **Solicitar Exclusão** (aprovação). A trava
  vale para o pedido de exclusão, não para a ferramenta de limpeza do quadro.
- Texto do aviso/servidor: ver seção "Mensagens".

## Como funciona hoje (referência)

- `inc/acting.php`:
  - `kanpro_is_card_linked($cards_id)` → criador do card **ou** membro de `cards_members`.
  - `kanpro_can_see_all($bid)` → criador do quadro **ou** papel `gerente`.
  - `kanpro_my_board_role($bid)` → observer < member < admin < gerente.
  - `kanpro_can_view_list($list_row, $boards_id)` → respeita `lists_viewers`.
- `front/ajax.php`:
  - Gate central `kanpro_need_card_editable($cid)` (linha 1235) — chamado por update de
    card, membros, checklist, capa, datas, toggle completo e quase todas as ações de
    manutenção (máquinas). Faz as travas de Pendente/Chamado/Tablet.
  - `finalize_maintenance` (6697), `request_conclusao` (7337), `request_exclusao` (7412),
    `start_edicao` (7528) → checam cargo no quadro, **não** o vínculo com o card.
  - Aprovações `confirm_conclusao` (7383), `confirm_exclusao` (7456), `cancel_exclusao`
    (7496), `liberar_edicao` (7611) → exigem `kanpro_can_manage_members($bid)`.
  - Ações que **não** passam por `kanpro_need_card_editable`: `move_card` (3883),
    `add_comment`/`update_comment`/`delete_comment` (5461+), `upload_attachment`/
    `delete_attachment` (5516+), `toggle_notified` (5582), `toggle_comment_pin` (5503).
- Front `public/js/kanpro.js`: `isBoardAdmin()` (61), `canSeeAll()` (74), `myUserIds()`
  (86). `renderChamadoInModal` (2322) liga/desliga botões (Pegar/Concluir/Editar).
  Estado do quadro já carrega `members` (papéis) e `cardMembers` (membros por card).

## Como vai funcionar (mecanismo)

### 1. Novos helpers (server, `inc/acting.php`)

```php
// Card de manutenção "com dono": só vinculado, gerente ou criador do quadro editam.
// Não-manutenção OU card sem membros => sem trava (comportamento atual).
function kanpro_can_edit_linked_card(int $cards_id): bool {
    // false se o card não existe; true se não é manutenção;
    // true se kanpro_can_see_all(boards_id);
    // true se não há membros em cards_members;
    // senão kanpro_is_card_linked($cards_id)
}

function kanpro_need_linked_card_edit(int $cards_id): void {
    if (!kanpro_can_edit_linked_card($cards_id)) {
        jexit(['success'=>false,
               'msg'=>'Só quem pegou este cartão (ou o Gerente/dono do quadro) pode alterá-lo.',
               'linked_only'=>true]);
    }
}
```

> `jexit()` vive em `front/ajax.php`; o helper de guard fica em `ajax.php` (perto de
> `kanpro_need_card_editable`), e a função de checagem pura (`kanpro_can_edit_linked_card`)
> fica em `inc/acting.php` para ser testável e reusável.

### 2. Onde entra o guard (`front/ajax.php`)

Adicionar `kanpro_need_linked_card_edit($cid);` logo após o gate já existente, nos
handlers do fluxo:

| Handler | Linha aprox. |
|---|---|
| `update_card` | 3808 |
| `set_card_whatsapp` | 3833 |
| `move_card` | 3883 |
| `archive_card` | 4653 |
| `toggle_card_member` | 5361 |
| `add_checklist` / `rename_checklist` / `delete_checklist` / `add_checkitem` / `toggle_checkitem` / `rename_checkitem` / `delete_checkitem` / `reorder_checkitems` | 5376–5450 |
| `add_comment` / `update_comment` / `delete_comment` / `toggle_comment_pin` | 5461–5513 |
| `upload_attachment` / `delete_attachment` | 5516–5550 |
| `set_cover` / `set_dates` / `toggle_complete` / `toggle_notified` | 5552–5599 |
| máquinas: `setup_maintenance_machines`, `update_maintenance_machine`, `bulk_update_machines`, `set_all_needs_inventory`, `add_maintenance_machines`, `delete_maintenance_machine`, `add_machine_note`, `delete_machine_note`, `retirada_machine`, `revert_maintenance` | 5866–6658 |
| `finalize_maintenance` | 6707 |
| `request_conclusao` | 7337 |
| `request_exclusao` | 7412 |
| `start_edicao` | 7528 |

Nos handlers de fluxo (`request_conclusao` 7352, `request_exclusao` 7425, `start_edicao`
7542), **substituir** o bypass `kanpro_can_manage_members($bid) || in_array($role, [...])`
por `kanpro_need_linked_card_edit($cid)` (que já cobre gerente/criador). Admin comum do
quadro deixa de finalizar/concluir o card de outro.

`cancel_edicao` (7563) e `confirm_edicao` (7581): trocar `!kanpro_can_manage_members($bid)`
por `!kanpro_can_see_all($bid)` (quem abriu a edição continua podendo; gerente/criador tb).

### 3. Aprovações (decisão 7)

Novo helper:

```php
function kanpro_can_approve_pendencia(int $boards_id, ?array $pendRow = null): bool {
    // true se kanpro_can_manage_members($boards_id) OU kanpro_can_view_list(lista pend_chamado)
}
```

- Usar em `confirm_conclusao` (7397), `confirm_exclusao` (7470), `cancel_exclusao` (7510),
  `liberar_edicao` (7625) — no lugar de `!kanpro_can_manage_members($bidC)`.
- Resolver a lista com `kanpro_find_list_by_type($bidC, 'pend_chamado')`; sem lista, cai no
  comportamento atual.
- Front: mostrar os botões "Liberar …" quando o usuário vê a lista (ou seja, quando vê o
  card da pendência) — na prática, todo mundo que abre a pendência vê o botão; o servidor
  decide.

### 4. Front-end — modo somente leitura (`public/js/kanpro.js`)

Novo helper:

```js
canEditLinkedCard(data){
  if(!data || !(data.is_maintenance == 1)) return true;   // não é manutenção
  if(this.canSeeAll()) return true;                        // gerente ou criador do quadro
  const cardMembers = data.members || [];
  if(cardMembers.length === 0) return true;                // card órfão => comportamento atual
  const ids = this.myUserIds();
  if(ids.includes(parseInt(data.users_id))) return true;   // criador do card
  return cardMembers.some(m => ids.includes(parseInt(m.id))); // vinculado
}
```

Aplicar em:

- Barra lateral / topo do modal (`renderChamadoInModal`, ~2322): quando
  `!canEditLinkedCard(data)`, esconder **Finalizar/Gerenciar Manutenção/Concluir/Editar/
  Mover/Copiar/Arquivar/Fixar/Membros/Etiquetas/Checklist/Anexo/Datas/Capa/Excluir** e
  mostrar um banner de aviso no topo do card.
- Mini-card: impedir drag (`isCardLocked`/dnd) de card de manutenção que o usuário não pode
  editar, usando `cardMembers` do estado do quadro.
- Botões de escrita dentro do modal: desabilitação já é reforçada pelo servidor.

### 5. Mensagens

- Aviso no card (front):
  `🔒 Somente quem pegou este cartão (ou o Gerente/dono do quadro) pode alterá-lo. Você está no modo somente leitura.`
- Erro no servidor (bypass via API):
  `Só quem pegou este cartão (ou o Gerente/dono do quadro) pode alterá-lo.`

## Casos de borda

- Card de manutenção **sem membros** → sem trava (decisão 6).
- Card **não-manutenção** → inalterado.
- **Pendente** (card de manutenção não pego) → já é travado por categoria; Pegar continua.
- **Fluxo Chamado** (cards de `chamado_*`) → não são `is_maintenance`; inalterado.
- **Aprovações** → passam a respeitar acesso à lista `pend_chamado` (+ admin/gerente/criador).
- Membro vinculado não pode remover o **gerente/criador** da edição (eles ignoram a trava).
- Login compartilhado (`acting_map`) → usar `kanpro_viewer_ids()` (sessão + pessoa), como no
  resto do plugin.

## Testes (`tests/run.php` + `tests/stubs.php`)

- `kanpro_can_edit_linked_card()`:
  - não-manutenção → true;
  - manutenção sem membros → true;
  - manutenção com membro e usuário não vinculado → false;
  - usuário vinculado → true;
  - gerente → true; criador do quadro → true; admin do quadro não-vinculado → false.
- Guard nos handlers: `update_card`/`finalize_maintenance`/`move_card`/`add_comment`
  retornam `linked_only` para não-vinculado e passam para vinculado.

## Riscos

- **Falso positivo** bloqueando quem deveria editar → mitigado pelo bypass gerente/criador e
  pelo fallback de card sem membros.
- **Falso negativo** (bypass) → guard no servidor em todos os handlers de escrita; front é
  só cosmético.
- **Aprovações** mudando de "admin" para "quem vê a lista" pode afrouxar se a lista
  `pend_chamado` estiver aberta a todos → revisar config da lista no quadro.
