<?php
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
Session::checkRight('plugin_kanpro', READ);

Html::header('KanPro - Quadros', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');

$canedit = Session::haveRight('plugin_kanpro', CREATE);
$entities = $_SESSION['glpiactiveentities'] ?? [0];

global $DB;
$show_archived = isset($_GET['archived']) && $_GET['archived'] == 1;
$search = $_GET['search'] ?? '';

// Controle de acesso por membros (engrenagem): só criador/membros veem o quadro.
// Quadros legados sem nenhum membro seguem abertos até a primeira pessoa ser cadastrada.
$__me = (int)Session::getLoginUserID();
$__myBoards = [];
foreach ($DB->request(['SELECT' => 'plugin_kanpro_boards_id', 'FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['users_id' => kanpro_viewer_ids()]]) as $__r) {
    $__myBoards[(int)$__r['plugin_kanpro_boards_id']] = true;
}
$__restricted = [];
foreach ($DB->request(['SELECT' => 'plugin_kanpro_boards_id', 'FROM' => 'glpi_plugin_kanpro_boards_members', 'GROUPBY' => ['plugin_kanpro_boards_id']]) as $__r) {
    $__restricted[(int)$__r['plugin_kanpro_boards_id']] = true;
}
// quadros onde sou admin (criador ou papel admin) — Histórico só para admins
$__adminBoards = [];
foreach ($DB->request(['SELECT' => 'plugin_kanpro_boards_id', 'FROM' => 'glpi_plugin_kanpro_boards_members', 'WHERE' => ['users_id' => kanpro_viewer_ids(), 'role' => 'admin']]) as $__r) {
    $__adminBoards[(int)$__r['plugin_kanpro_boards_id']] = true;
}

$where = ['entities_id' => $entities];
if (!$show_archived) $where['is_archived'] = 0;
if (!empty($search)) $where['name'] = ['LIKE', "%{$search}%"];

$iterator = $DB->request([
    'FROM'  => 'glpi_plugin_kanpro_boards',
    'WHERE' => $where,
    'ORDER' => 'is_starred DESC, date_mod DESC',
]);

echo "<div class='kanpro-page' style='max-width:1400px;margin:0 auto;padding:20px'>";
echo "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px'>";
echo "<h1 style='margin:0;font-size:22px;display:flex;align-items:center;gap:10px'><i class='ti ti-layout-kanban' style='font-size:28px;color:#0079bf'></i> Seus Quadros</h1>";
echo "<div style='display:flex;gap:8px;align-items:center'>";
echo "<form method='get' style='display:flex;gap:6px'><input type='text' name='search' value='" . htmlspecialchars($search) . "' placeholder='Buscar quadros...' style='padding:8px 12px;border:1px solid #dfe1e6;border-radius:6px;min-width:220px'><button class='btn btn-outline-secondary btn-sm'><i class='ti ti-search'></i></button></form>";
if ($canedit) {
    echo "<a href='board.form.php' class='btn btn-primary' style='background:#0079bf;border-color:#0079bf'><i class='ti ti-plus'></i> Criar quadro</a>";
}
echo "</div></div>";

// filtros rápidos
echo "<div style='display:flex;gap:8px;margin-bottom:20px'>";
$active_all = !$show_archived ? 'background:#0079bf;color:#fff;border-color:#0079bf' : '';
$active_arc = $show_archived ? 'background:#0079bf;color:#fff;border-color:#0079bf' : '';
echo "<a href='?archived=0' class='btn btn-sm' style='border:1px solid #dfe1e6;{$active_all}'>Ativos</a>";
echo "<a href='?archived=1' class='btn btn-sm' style='border:1px solid #dfe1e6;{$active_arc}'>Arquivados</a>";
echo "</div>";

if (count($iterator) === 0) {
    echo "<div style='text-align:center;padding:60px 20px;background:#f4f5f7;border-radius:8px'>";
    echo "<i class='ti ti-layout-kanban' style='font-size:48px;color:#97a0af'></i>";
    echo "<h3 style='color:#172b4d;margin:16px 0 8px'>Nenhum quadro encontrado</h3>";
    echo "<p style='color:#6b778c'>Crie seu primeiro quadro para começar a organizar seus projetos no estilo Trello.</p>";
    if ($canedit) echo "<a href='board.form.php' class='btn btn-primary' style='margin-top:12px'><i class='ti ti-plus'></i> Criar quadro</a>";
    echo "</div>";
} else {
    echo "<div style='display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px'>";
    foreach ($iterator as $row) {
        $bid = (int)$row['id'];
        // trava de visibilidade: criador, membro ou quadro legado sem membros
        $__creator = (int)($row['users_id'] ?? 0);
        if ($__creator !== $__me && !isset($__myBoards[$bid]) && isset($__restricted[$bid])) {
            continue;
        }
        $kanban_url = "kanban.php?boards_id={$bid}";
        $edit_url   = "board.form.php?id={$bid}";
        $card_count = PluginKanproBoard::countCardsInBoard($bid);

        // conta membros
        $member_count = countElementsInTable('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid]);

        // listas preview
        $lists_preview = '';
        $lists = $DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'is_archived' => 0], 'ORDER' => 'rank ASC', 'LIMIT' => 3]);
        foreach ($lists as $l) {
            $lists_preview .= "<span style='background:rgba(255,255,255,.2);padding:2px 8px;border-radius:10px;font-size:11px;margin-right:4px'>" . htmlspecialchars(mb_strimwidth($l['name'], 0, 18, '…')) . "</span>";
        }

        $star = $row['is_starred'] ? '⭐' : '';
        $archived_badge = $row['is_archived'] ? "<span style='background:#ff5630;color:#fff;padding:2px 6px;border-radius:4px;font-size:11px'>Arquivado</span>" : '';

        echo "<div style='border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.15);background:#fff;display:flex;flex-direction:column;transition:transform .15s' onmouseover=\"this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,.15)'\" onmouseout=\"this.style.transform='none';this.style.boxShadow='0 1px 3px rgba(0,0,0,.15)'\">";
        // header com cor/degradê ou imagem de fundo — valida a cor (degradê truncado em installs antigos quebrava o CSS e deixava a capa branca)
        $bgColorRaw = $row['color'] ?? '#0079bf';
        $bgColor = '#0079bf';
        if (is_string($bgColorRaw)) {
            $bgColorRaw = trim($bgColorRaw);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $bgColorRaw)) {
                $bgColor = $bgColorRaw;
            } elseif (strpos($bgColorRaw, 'linear-gradient') === 0 && substr($bgColorRaw, -1) === ')') {
                $bgColor = $bgColorRaw;
            }
        }
        $bgColorEsc = htmlspecialchars($bgColor, ENT_QUOTES);
        $bgImg = $row['background'] ?? '';
        $bgImgTag = '';
        if (!empty($bgImg) && !empty($row['id'])) {
            $bgUrl = htmlspecialchars(PluginKanproBoard::getBackgroundImageUrl((int)$row['id'], $bgImg), ENT_QUOTES);
            // <img> com onerror: se a imagem falhar (arquivo sumido, 404), remove e revela a cor — capa nunca fica branca
            $bgImgTag = "<img src='{$bgUrl}' alt='' loading='lazy' onerror='this.remove()' style='position:absolute;inset:0;width:100%;height:100%;object-fit:cover'>";
        }
        $headerBg = $bgColorEsc;
        echo "<a href='{$kanban_url}' style='display:block;height:110px;background:{$headerBg};padding:12px;color:#fff;text-decoration:none;position:relative;overflow:hidden'>{$bgImgTag}";
        echo "<div style='font-weight:700;font-size:16px;line-height:1.2;display:flex;justify-content:space-between;align-items:flex-start;position:relative;z-index:1;'><span>" . htmlspecialchars($row['name']) . " {$star}</span> {$archived_badge}</div>";
        if (!empty($row['comment'])) echo "<div style='font-size:12px;opacity:.9;margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;position:relative;z-index:1;'>" . htmlspecialchars(mb_strimwidth($row['comment'], 0, 80, '…')) . "</div>";
        echo "<div style='position:absolute;bottom:10px;left:12px;right:12px;display:flex;gap:4px;flex-wrap:wrap;z-index:1;'>{$lists_preview}</div>";
        echo "</a>";
        echo "<div style='padding:12px;display:flex;justify-content:space-between;align-items:center;background:#fff'>";
        echo "<div style='display:flex;gap:12px;font-size:12px;color:#6b778c'>";
        echo "<span><i class='ti ti-layout-kanban'></i> {$card_count} cartões</span>";
        echo "<span id='kpb-count-{$bid}'><i class='ti ti-users'></i> {$member_count}</span>";
        echo "</div>";
        echo "<div style='display:flex;gap:6px'>";
        echo "<a href='{$kanban_url}' class='btn btn-sm btn-primary' style='padding:4px 10px;font-size:12px'>Abrir</a>";
        echo "<button onclick='KanproBoards.open({$bid})' title='Gerenciar acesso ao quadro' class='btn btn-sm btn-outline-secondary' style='padding:4px 8px'><i class='ti ti-settings'></i></button>";
        $__creatorRow = (int)($row['users_id'] ?? 0);
        if ($__creatorRow === $__me || isset($__adminBoards[$bid])) {
            echo "<button onclick='KanproHistory.open({$bid})' title='Histórico do quadro' class='btn btn-sm btn-outline-secondary' style='padding:4px 8px'><i class='ti ti-history'></i></button>";
        }
        if (Session::haveRight('plugin_kanpro', UPDATE)) {
            echo "<a href='{$edit_url}' title='Editar quadro' class='btn btn-sm btn-outline-secondary' style='padding:4px 8px'><i class='ti ti-pencil'></i></a>";
        }
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    // card "Criar novo quadro"
    if ($canedit) {
        echo "<a href='board.form.php' style='border:2px dashed #dfe1e6;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:160px;text-decoration:none;color:#6b778c;background:#fafbfc;transition:.15s' onmouseover=\"this.style.borderColor='#0079bf';this.style.color='#0079bf';this.style.background='#e6fcff'\" onmouseout=\"this.style.borderColor='#dfe1e6';this.style.color='#6b778c';this.style.background='#fafbfc'\">";
        echo "<i class='ti ti-plus' style='font-size:28px'></i><span style='margin-top:8px;font-weight:600'>Criar novo quadro</span></a>";
    }
    echo "</div>";
}

echo "</div>";

$__kpb_ajax = Plugin::getWebDir('kanpro') . '/front/ajax.php';
$__kpb_csrf = Session::getNewCSRFToken();
echo "<script>window.KANPRO_HISTORY_URL = " . json_encode($__kpb_ajax) . "; window.KANPRO_HISTORY_CSRF = " . json_encode($__kpb_csrf) . ";</script>";
?>
<!-- Modal: gerenciar acesso ao quadro (engrenagem) -->
<div id="kpb-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:20000;align-items:center;justify-content:center;padding:16px">
  <div style="background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.3);max-width:520px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #dfe1e6">
      <strong id="kpb-title">Acesso ao quadro</strong>
      <button onclick="KanproBoards.close()" style="background:none;border:none;cursor:pointer;font-size:18px">✕</button>
    </div>
    <div id="kpb-body" style="padding:16px;overflow-y:auto;display:grid;gap:12px"></div>
    <div style="padding:12px 16px;border-top:1px solid #dfe1e6;display:flex;justify-content:flex-end">
      <button onclick="KanproBoards.close()" class="btn btn-outline-secondary btn-sm">Fechar</button>
    </div>
  </div>
</div>
<script>
window.KanproBoards = (function(){
  var ajaxUrl = <?php echo json_encode($__kpb_ajax); ?>;
  var csrf = <?php echo json_encode($__kpb_csrf); ?>;
  var state = { boardId: 0, dirty: false, data: null };

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
    });
  }
  function post(action, params){
    var fd = new FormData();
    fd.append('action', action);
    for (var k in params) { if (params[k] !== undefined && params[k] !== null) fd.append(k, params[k]); }
    return fetch(ajaxUrl, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf }
    }).then(function(r){ return r.text(); }).then(function(txt){
      try { return JSON.parse(txt); }
      catch(e){ return {success:false, msg:'Resposta inesperada do servidor'}; }
    }).catch(function(e){ return {success:false, msg:e.message}; });
  }
  function roleBadge(m){
    if (m.is_creator) return '<small style="background:#0079bf;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">CRIADOR</small>';
    if (m.role === 'admin') return '<small style="background:#fffae6;border:1px solid #ffab00;color:#172b4d;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">⭐ ADMIN</small>';
    if (m.role === 'observer') return '<small style="background:#dfe1e6;color:#5e6c84;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">👁️ OBSERVADOR</small>';
    return '<small style="background:#eaecf0;color:#172b4d;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">👤 MEMBRO</small>';
  }
  function render(){
    var d = state.data;
    var body = document.getElementById('kpb-body');
    document.getElementById('kpb-title').textContent = 'Acesso — ' + (d.board_name || ('Quadro #' + state.boardId));
    var html = '<div style="font-size:12px;color:#5e6c84">Quem pode visualizar este quadro. O <strong>criador</strong> e os <strong>admins</strong> podem adicionar pessoas e trocar papéis. Membro comum só visualiza.</div>';
    html += '<div style="display:grid;gap:6px">' + d.members.map(function(m){
      var ctrl;
      if (m.is_creator) {
        ctrl = '<small style="color:#5e6c84;font-size:11px">acesso total</small>';
      } else if (d.can_manage) {
        ctrl = '<span style="display:flex;gap:6px;align-items:center">'
          + '<select onchange="KanproBoards.setRole(' + m.users_id + ', this.value)" style="padding:4px 8px;border:1px solid #dfe1e6;border-radius:6px;font-size:11px;background:#fff">'
          + '<option value="admin"' + (m.role==='admin'?' selected':'') + '>⭐ Admin</option>'
          + '<option value="member"' + (m.role==='member'?' selected':'') + '>👤 Membro</option>'
          + '</select>'
          + '<button onclick="KanproBoards.remove(' + m.users_id + ')" title="Remover" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;width:28px;height:28px;border-radius:50%;cursor:pointer">✕</button>'
          + '</span>';
      } else {
        ctrl = '';
      }
      return '<div style="display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:1px solid #dfe1e6;padding:8px 10px;border-radius:8px;gap:8px">'
        + '<span style="display:flex;align-items:center;gap:8px;min-width:0"><span style="width:28px;height:28px;border-radius:50%;background:#0079bf;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">' + esc(m.initials) + '</span>'
        + '<span style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(m.name) + '</span> ' + roleBadge(m) + '</span>'
        + ctrl + '</div>';
    }).join('') + '</div>';
    if (d.can_manage) {
      html += '<hr style="border:none;border-top:1px solid #dfe1e6">'
        + '<label style="font-size:12px;font-weight:600;color:#5e6c84">Papel de quem for adicionado '
        + '<select id="kpb-role" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px;background:#fff">'
        + '<option value="admin">⭐ Administrador — pode adicionar pessoas</option>'
        + '<option value="member" selected>👤 Membro — só visualiza</option>'
        + '</select></label>'
        + '<input id="kpb-search" type="text" placeholder="🔍 Buscar pessoa por nome ou login..." oninput="KanproBoards.filter(this.value)" style="padding:10px;border:1px solid #dfe1e6;border-radius:6px">'
        + '<div id="kpb-results" style="display:grid;gap:6px;max-height:220px;overflow-y:auto"></div>';
    } else {
      html += '<div style="font-size:11px;color:#5e6c84;text-align:center">Você não tem permissão para alterar o acesso.</div>';
    }
    body.innerHTML = html;
    if (d.can_manage) { renderResults(''); var s = document.getElementById('kpb-search'); if (s) s.focus(); }
  }
  function renderResults(q){
    var d = state.data;
    if (!d) return;
    q = (q || '').toLowerCase().trim();
    var list = d.available || [];
    if (q) list = list.filter(function(u){ return u.name.toLowerCase().indexOf(q) !== -1 || u.login.toLowerCase().indexOf(q) !== -1; });
    var box = document.getElementById('kpb-results');
    if (!box) return;
    if (!list.length) { box.innerHTML = '<div style="text-align:center;color:#5e6c84;font-size:12px;padding:12px">Nenhuma pessoa encontrada</div>'; return; }
    box.innerHTML = list.slice(0, 60).map(function(u){
      return '<div style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:8px 10px;gap:8px">'
        + '<span style="display:flex;align-items:center;gap:8px;min-width:0"><span style="width:28px;height:28px;border-radius:50%;background:#dfe1e6;color:#172b4d;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">' + esc(u.initials) + '</span>'
        + '<span style="min-width:0"><span style="display:block;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(u.name) + '</span>'
        + '<span style="display:block;font-size:11px;color:#5e6c84">@' + esc(u.login) + '</span></span></span>'
        + '<button onclick="KanproBoards.add(' + u.id + ', this)" style="background:#0079bf;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;flex-shrink:0">Adicionar</button></div>';
    }).join('') + (list.length > 60 ? '<div style="text-align:center;font-size:11px;color:#5e6c84">+' + (list.length - 60) + ' — refine a busca</div>' : '');
  }
  function reload(){
    post('get_board_members', {boards_id: state.boardId}).then(function(res){
      if (!res.success) { document.getElementById('kpb-body').innerHTML = '<div style="color:#bf2600">' + esc(res.msg || 'Erro') + '</div>'; return; }
      state.data = res;
      render();
    });
  }
  return {
    open: function(boardId){
      state.boardId = boardId; state.dirty = false; state.data = null;
      document.getElementById('kpb-body').innerHTML = '<div style="text-align:center;color:#5e6c84;padding:20px">Carregando...</div>';
      var ov = document.getElementById('kpb-overlay');
      ov.style.display = 'flex';
      reload();
    },
    close: function(){
      document.getElementById('kpb-overlay').style.display = 'none';
      if (state.dirty) location.reload();
    },
    filter: function(q){ renderResults(q); },
    add: function(uid, btn){
      var roleEl = document.getElementById('kpb-role');
      var role = roleEl ? roleEl.value : 'member';
      if (btn) { btn.disabled = true; btn.textContent = '...'; }
      post('invite_member', {boards_id: state.boardId, users_id: uid, role: role}).then(function(res){
        if (!res.success) { alert(res.msg || 'Erro'); if (btn) { btn.disabled = false; btn.textContent = 'Adicionar'; } return; }
        state.dirty = true;
        reload();
      });
    },
    setRole: function(uid, role){
      if (!confirm('Alterar papel desta pessoa?')) { reload(); return; }
      post('set_member_role', {boards_id: state.boardId, users_id: uid, role: role}).then(function(res){
        if (!res.success) alert(res.msg || 'Erro');
        else state.dirty = true;
        reload();
      });
    },
    remove: function(uid){
      if (!confirm('Remover esta pessoa do quadro?')) return;
      post('remove_member', {boards_id: state.boardId, users_id: uid}).then(function(res){
        if (!res.success) { alert(res.msg || 'Erro'); return; }
        state.dirty = true;
        reload();
      });
    }
  };
})();
document.getElementById('kpb-overlay').addEventListener('click', function(e){ if (e.target === this) KanproBoards.close(); });
document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && document.getElementById('kpb-overlay').style.display !== 'none') KanproBoards.close(); });
</script>
<?php
Html::footer();
