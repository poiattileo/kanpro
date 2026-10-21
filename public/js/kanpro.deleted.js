// KanPro Excluídos — somente criador ou Super-Admin do quadro.
// Lista cards excluídos do quadro, mostra tudo + histórico
// (quem mudou, quem solicitou exclusão, quem excluiu/liberou) e recupera
// para a lista de origem.
(function(){
  var LABELS = {
    board_create:'criou o quadro', board_rename:'renomeou o quadro',
    list_create:'criou a lista', list_move_all:'moveu lista',
    card_create:'criou o cartão', card_move:'moveu o cartão',
    card_archive:'arquivou o cartão', card_restore:'restaurou o cartão',
    card_complete:'concluiu o cartão', card_reopen:'reabriu o cartão',
    card_maintenance_convert:'converteu para manutenção',
    maintenance_setup:'configurou máquinas', maintenance_update:'atualizou máquina',
    maintenance_diary:'atualizou o relatório', maintenance_finalize:'finalizou manutenção',
    maintenance_revert:'reverteu manutenção', maintenance_retirada:'retirou máquina',
    maintenance_pending_split:'separou pendentes',
    maintenance_tablet_split:'separou tablets',
    member_add:'adicionou membro', member_remove:'removeu membro', member_role:'trocou papel',
    card_create_ticket:'criou chamado', card_link_ticket:'vinculou chamado', card_unlink_ticket:'desvinculou chamado',
    card_approval_request:'pediu aprovação', card_approval_ok:'aprovou movimentação',
    exclusao_requested:'solicitou a exclusão', exclusao_created:'criou pendência de exclusão',
    exclusao_released:'liberou a exclusão', exclusao_cancelled:'cancelou a exclusão',
    conclusao_released:'liberou a conclusão', chamado_autodelete:'auto-excluiu pendência',
    card_copy:'copiou o cartão', attachment_add:'adicionou anexo',
    print_sheet:'imprimiu folha'
  };
  var DOTS = {card_create:'#61bd4f', card_move:'#0079bf', card_archive:'#ff5630', card_restore:'#006644',
    card_complete:'#006644', maintenance_finalize:'#00b8d9', member_add:'#6554c0', member_remove:'#ff5630',
    exclusao_requested:'#ff5630', exclusao_released:'#bf2600', exclusao_cancelled:'#97a0af'};

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
    });
  }
  function ajaxUrl(){
    if (window.KANPRO && window.KANPRO.ajax_url) return window.KANPRO.ajax_url;
    return '/plugins/kanpro/front/ajax.php';
  }
  function csrf(){
    if (window.KANPRO && window.KANPRO.csrf_token) return window.KANPRO.csrf_token;
    if (window.glpi_csrf_token) return window.glpi_csrf_token;
    var h = document.getElementById('kanpro-csrf');
    if (h) return h.value;
    var m = document.querySelector('meta[name="glpi-csrf-token"]');
    if (m) return m.content;
    var i = document.querySelector('input[name="_glpi_csrf_token"]');
    if (i) return i.value;
    return '';
  }
  function post(action, params){
    var fd = new FormData();
    fd.append('action', action);
    for (var k in params) { if (params[k] !== undefined && params[k] !== null && params[k] !== '') fd.append(k, params[k]); }
    var tok = csrf();
    if (tok && !fd.has('_glpi_csrf_token')) fd.append('_glpi_csrf_token', tok);
    return fetch(ajaxUrl(), {method: 'POST', body: fd, credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok}})
      .then(function(r){ return r.text(); })
      .then(function(t){ try { return JSON.parse(t); } catch(e){ return {success:false, msg:'Resposta inesperada'}; } })
      .catch(function(e){ return {success:false, msg:e.message}; });
  }
  function fmtDate(s){
    if(!s) return '—';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return s;
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
  }
  function fmtSize(b){
    b = parseInt(b, 10) || 0;
    if (b <= 0) return '';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(1) + ' MB';
  }
  if(!window.kpOverlayClose) window.kpOverlayClose = function(overlay, onClose){
    var downOn = false;
    overlay.addEventListener('mousedown', function(e){ downOn = (e.target===overlay); });
    overlay.addEventListener('click', function(e){
      if(e.target!==overlay || !downOn) return;
      downOn = false;
      try { var s=window.getSelection(); if(s && !s.isCollapsed && String(s.toString()).length>0) return; } catch(_){}
      onClose(e);
    });
  };

  var D = {
    boardId: 0,
    items: [],
    boardName: '',
    filter: '',
    detail: null,
    detailTrashId: 0,

    open: function(boardId){
      this.boardId = parseInt(boardId) || 0;
      if(!this.boardId){ alert('Quadro inválido'); return; }
      this.filter = '';
      this.detail = null;
      if(document.getElementById('kpd-overlay')){ this.reload(); return; }
      var ov = document.createElement('div');
      ov.id = 'kpd-overlay';
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:20000;display:flex;justify-content:center;align-items:flex-start;padding:4vh 16px;overflow-y:auto;box-sizing:border-box';
      ov.innerHTML =
        '<div style="background:#f4f5f7;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.35);width:100%;max-width:820px;display:flex;flex-direction:column;overflow:hidden;max-height:92vh">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:#fff;border-bottom:1px solid #dfe1e6">'
        + '<strong id="kpd-title" style="font-size:16px">🗑️ Excluídos</strong>'
        + '<button onclick="KanproDeleted.close()" style="background:none;border:none;cursor:pointer;font-size:18px">✕</button></div>'
        + '<div id="kpd-toolbar" style="padding:12px 18px;background:#fff;border-bottom:1px solid #dfe1e6;display:flex;gap:8px;flex-wrap:wrap;align-items:center"></div>'
        + '<div id="kpd-count" style="padding:8px 18px 0;font-size:12px;color:#5e6c84"></div>'
        + '<div id="kpd-body" style="padding:12px 18px;overflow-y:auto;min-height:120px"></div>'
        + '<div style="padding:12px 18px;border-top:1px solid #dfe1e6;background:#fff;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">'
        + '<span style="font-size:11px;color:#97a0af;margin-right:auto;align-self:center">Recuperar volta para a lista de origem.</span>'
        + '<button onclick="KanproDeleted.close()" style="background:#f4f5f7;border:1px solid #dfe1e6;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:12px">Fechar</button>'
        + '</div></div>';
      document.body.appendChild(ov);
      var self = this;
      window.kpOverlayClose(ov, function(){ self.close(); });
      this.reload();
    },
    close: function(){
      var ov = document.getElementById('kpd-overlay');
      if(ov) ov.remove();
      this.detail = null;
    },
    reload: function(){
      var self = this;
      var body = document.getElementById('kpd-body');
      if(body) body.innerHTML = '<div style="text-align:center;color:#5e6c84;padding:24px">Carregando excluídos...</div>';
      post('get_deleted_cards', {boards_id: this.boardId}).then(function(res){
        if(!res || !res.success){
          if(body) body.innerHTML = '<div style="color:#bf2600">' + esc((res && res.msg) || 'Erro') + '</div>';
          return;
        }
        self.items = res.items || [];
        self.boardName = res.board_name || '';
        var t = document.getElementById('kpd-title');
        if(t) t.textContent = '🗑️ Excluídos — ' + (self.boardName || ('Quadro #' + self.boardId));
        self.renderToolbar();
        self.renderList();
        self.updateCountBadge();
      });
    },
    updateCountBadge: function(){
      try {
        var el = document.getElementById('kanpro-deleted-count');
        if(el) el.textContent = this.items.length ? '(' + this.items.length + ')' : '';
      } catch(e){}
    },
    renderToolbar: function(){
      var box = document.getElementById('kpd-toolbar');
      if(!box) return;
      if(this.detail){
        box.innerHTML = '<button onclick="KanproDeleted.back()" style="padding:7px 12px;border:1px solid #dfe1e6;background:#fff;border-radius:6px;cursor:pointer;font-size:12px">← Voltar para a lista</button>'
          + '<span style="font-size:12px;color:#5e6c84">Visualizando card excluído com histórico completo</span>';
        return;
      }
      box.innerHTML = '<label style="font-size:11px;font-weight:600;color:#5e6c84">Buscar<br>'
        + '<input id="kpd-q" type="text" placeholder="Nome do card, lista, quem excluiu..." value="' + esc(this.filter) + '" oninput="KanproDeleted.setFilter(this.value)" style="padding:7px;border:1px solid #dfe1e6;border-radius:6px;min-width:260px;background:#fff"></label>'
        + '<button onclick="KanproDeleted.clearFilter()" style="padding:7px 12px;border:1px solid #dfe1e6;background:#fff;border-radius:6px;cursor:pointer;font-size:12px">Limpar</button>';
      var q = document.getElementById('kpd-q');
      if(q){ try { q.focus(); } catch(e){} }
    },
    setFilter: function(v){
      this.filter = String(v == null ? '' : v);
      this.renderList();
    },
    clearFilter: function(){
      this.filter = '';
      var q = document.getElementById('kpd-q');
      if(q) q.value = '';
      this.renderList();
    },
    filtered: function(){
      var nq = String(this.filter || '').toLowerCase();
      try { nq = nq.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch(e){}
      if(!nq) return this.items;
      return this.items.filter(function(it){
        var hay = [it.card_name, it.list_name, it.deleted_by_name, it.requested_by_name].join(' ').toLowerCase();
        try { hay = hay.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch(e){}
        return hay.indexOf(nq) !== -1;
      });
    },
    renderList: function(){
      var body = document.getElementById('kpd-body');
      var cnt = document.getElementById('kpd-count');
      if(!body) return;
      this.renderToolbar();
      var list = this.filtered();
      if(cnt) cnt.innerHTML = 'Mostrando <strong>' + list.length + '</strong> de <strong>' + this.items.length + '</strong> excluídos';
      if(!list.length){
        body.innerHTML = '<div style="text-align:center;color:#6b778c;padding:32px"><div style="font-size:36px">🗑️</div><div style="margin-top:8px">Nenhum card excluído' + (this.filter ? ' com esse filtro.' : '.') + '</div></div>';
        return;
      }
      body.innerHTML = '<div style="display:grid;gap:8px">' + list.map(function(it){
        var maint = it.is_maintenance
          ? '<span style="background:#fffae6;border:1px solid #ffab00;color:#172b4d;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">🔧 MANUTENÇÃO' + (it.machines_total ? ' • ' + it.machines_total + ' máq.' : '') + '</span>'
          : '';
        return '<div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #dfe1e6;padding:10px 12px;border-radius:8px;gap:10px">'
          + '<span style="min-width:0;flex:1">'
          + '<span style="display:block;font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(it.card_name || ('#' + it.orig_card_id)) + '</span>'
          + '<span style="display:block;font-size:11px;color:#5e6c84;margin-top:2px">📋 ' + esc(it.list_name || 'lista removida') + ' • excluído em ' + esc(fmtDate(it.date)) + '</span>'
          + '<span style="display:block;font-size:11px;color:#5e6c84;margin-top:2px">🗑️ por <strong>' + esc(it.deleted_by_name || '—') + '</strong>'
          + (it.requested_by ? ' • 🙋 solicitou: <strong>' + esc(it.requested_by_name || ('#' + it.requested_by)) + '</strong>' : '')
          + '</span>'
          + '<span style="display:flex;gap:4px;margin-top:4px;flex-wrap:wrap">' + maint + '</span>'
          + '</span>'
          + '<span style="display:flex;gap:6px;flex-shrink:0">'
          + '<button onclick="KanproDeleted.openDetail(' + it.trash_id + ')" style="background:#e6fcff;border:1px solid #0079bf;color:#0052cc;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700">Visualizar</button>'
          + '<button onclick="KanproDeleted.restore(' + it.trash_id + ')" title="Recuperar para a lista de origem" style="background:#e3fcef;border:1px solid #61bd4f;color:#006644;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700">Recuperar</button>'
          + '</span></div>';
      }).join('') + '</div>';
    },
    openDetail: function(trashId){
      var body = document.getElementById('kpd-body');
      if(body) body.innerHTML = '<div style="text-align:center;color:#5e6c84;padding:24px">Carregando card...</div>';
      var self = this;
      post('get_deleted_detail', {trash_id: trashId}).then(function(res){
        if(!res || !res.success){
          if(body) body.innerHTML = '<div style="color:#bf2600">' + esc((res && res.msg) || 'Erro') + '</div>';
          return;
        }
        self.detail = res;
        self.detailTrashId = trashId;
        self.renderDetail();
      });
    },
    back: function(){
      this.detail = null;
      this.renderToolbar();
      this.renderList();
    },
    machineNotes: function(card, mid){
      var map = card.maintenance_notes_by_machine || {};
      var arr = map[String(mid)] || map[mid] || [];
      return Array.isArray(arr) ? arr : [];
    },
    renderDetail: function(){
      var body = document.getElementById('kpd-body');
      var cnt = document.getElementById('kpd-count');
      if(!body || !this.detail) return;
      this.renderToolbar();
      if(cnt) cnt.innerHTML = '';
      var d = this.detail;
      var c = d.card || {};
      var trash = d.trash || {};
      var hist = d.history || [];
      var req = d.requested || null;
      var rel = d.released || null;
      var target = d.restore_target || {};
      var machines = Array.isArray(c.maintenance_machines) ? c.maintenance_machines : [];
      var self = this;

      var audit =
        '<div style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:12px;display:grid;gap:6px;font-size:12px">'
        + '<div><strong>📋 Estava na lista:</strong> ' + esc(trash.list_name || c.list_name || '—') + '</div>'
        + '<div><strong>🗑️ Excluído por:</strong> ' + esc(trash.deleted_by_name || '—') + ' em ' + esc(fmtDate(trash.date)) + '</div>'
        + '<div><strong>🙋 Solicitou exclusão:</strong> ' + esc(req ? (req.user || ('#' + req.user_id)) : (c.chamado_by_name || '—')) + (req ? ' em ' + esc(fmtDate(req.date)) : '') + '</div>'
        + '<div><strong>✅ Liberou/excluiu:</strong> ' + esc(rel ? (rel.user || ('#' + rel.user_id)) : (trash.deleted_by_name || '—')) + (rel ? ' em ' + esc(fmtDate(rel.date)) : '') + '</div>'
        + '<div><strong>↩️ Recuperar vai para:</strong> ' + esc(target.list_name || trash.list_name || '—') + (target.same_as_origin ? ' (mesma lista de origem)' : ' (lista origem sumiu — vai para a 1ª ativa)') + '</div>'
        + '</div>';

      var badges = '';
      if(c.is_maintenance) badges += '<span style="background:#fffae6;border:1px solid #ffab00;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700">🔧 MANUTENÇÃO</span>';
      if(c.entities_id) badges += '<span style="background:#eaecf0;padding:3px 10px;border-radius:12px;font-size:11px">🏫 entidade #' + esc(c.entities_id) + '</span>';
      if(c.due_date) badges += '<span style="background:#ffebe6;padding:3px 10px;border-radius:12px;font-size:11px">📅 ' + esc(fmtDate(c.due_date)) + '</span>';
      if(c.ticket && c.ticket.id) badges += '<span style="background:#e6fcff;padding:3px 10px;border-radius:12px;font-size:11px">🎫 chamado #' + esc(c.ticket.id) + '</span>';

      var members = '';
      if(Array.isArray(c.members) && c.members.length){
        members = '<div style="font-size:12px;font-weight:700;color:#172b4d;margin:12px 0 6px">MEMBROS</div>'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap">' + c.members.map(function(m){
            var nm = m.name || m.user_name || ('#' + (m.id || m.users_id || '?'));
            return '<span style="background:#fff;border:1px solid #dfe1e6;padding:4px 10px;border-radius:12px;font-size:12px">' + esc(nm) + '</span>';
          }).join('') + '</div>';
      }
      var labels = '';
      if(Array.isArray(c.labels) && c.labels.length){
        labels = '<div style="font-size:12px;font-weight:700;color:#172b4d;margin:12px 0 6px">ETIQUETAS</div>'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap">' + c.labels.map(function(l){
            var col = l.color || '#61bd4f';
            return '<span style="background:' + esc(col) + ';color:#fff;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700">' + esc(l.name || col) + '</span>';
          }).join('') + '</div>';
      }
      var machHtml = '';
      if(c.is_maintenance){
        machHtml = '<div style="font-size:12px;font-weight:700;color:#172b4d;margin:12px 0 6px">🔧 MÁQUINAS (' + machines.length + ')</div>';
        if(!machines.length){
          machHtml += '<div style="font-size:12px;color:#5e6c84">Nenhuma máquina no snapshot.</div>';
        } else {
          machHtml += '<div style="display:grid;gap:6px">' + machines.map(function(m){
            var notes = self.machineNotes(c, m.id);
            var st = (m.status && String(m.status) !== '') ? String(m.status) : (m.is_done ? 'concluída' : 'pendente');
            var urg = m.is_urgent ? ' <span style="background:#ff5630;color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700">URGENTE</span>' : '';
            var notesHtml = '';
            if(notes.length){
              notesHtml = '<div style="margin-top:6px;display:grid;gap:4px">' + notes.map(function(n){
                return '<div style="background:#f4f5f7;border-left:3px solid #0079bf;padding:6px 8px;border-radius:0 6px 6px 0;font-size:12px"><div>' + esc(n.note || '') + '</div>'
                  + '<div style="font-size:10px;color:#97a0af;margin-top:2px">' + esc(fmtDate(n.date_creation)) + '</div></div>';
              }).join('') + '</div>';
            }
            return '<div style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:10px">'
              + '<div style="font-size:13px;font-weight:700">#' + esc(m.seq) + ' ' + esc(m.model || m.label || 'Máquina') + urg + '</div>'
              + '<div style="font-size:11px;color:#5e6c84;margin-top:2px">Status: ' + esc(st) + (m.is_done ? ' • ✔ feita' : '') + (notes.length ? ' • 💬 ' + notes.length + ' anotação(ões)' : '') + '</div>'
              + (m.diary ? '<div style="font-size:12px;margin-top:6px;white-space:pre-wrap">' + esc(m.diary) + '</div>' : '')
              + notesHtml + '</div>';
          }).join('') + '</div>';
        }
      }
      var checks = '';
      if(Array.isArray(c.checklists) && c.checklists.length){
        checks = '<div style="font-size:12px;font-weight:700;color:#172b4d;margin:12px 0 6px">☑️ CHECKLISTS</div>'
          + '<div style="display:grid;gap:8px">' + c.checklists.map(function(cl){
            var its = Array.isArray(cl.items) ? cl.items : [];
            var done = its.filter(function(i){ return i.is_checked; }).length;
            return '<div style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:10px">'
              + '<div style="font-size:13px;font-weight:700">' + esc(cl.name || 'Checklist') + ' <span style="font-size:11px;color:#5e6c84">(' + done + '/' + its.length + ')</span></div>'
              + '<div style="display:grid;gap:4px;margin-top:6px">' + its.map(function(i){
                return '<div style="font-size:12px">' + (i.is_checked ? '☑' : '☐') + ' ' + esc(i.name || '') + '</div>';
              }).join('') + '</div></div>';
          }).join('') + '</div>';
      }
      var atts = '';
      if(Array.isArray(c.attachments) && c.attachments.length){
        atts = '<div style="font-size:12px;font-weight:700;color:#172b4d;margin:12px 0 6px">📎 ANEXOS (' + c.attachments.length + ')</div>'
          + '<div style="display:grid;gap:4px">' + c.attachments.map(function(a){
            return '<div style="background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:6px 10px;font-size:12px">📄 ' + esc(a.name || a.filename || 'anexo') + ' <span style="color:#97a0af">• ' + esc(fmtSize(a.filesize)) + ' • ' + esc(fmtDate(a.date_creation)) + '</span></div>';
          }).join('') + '</div>'
          + '<div style="font-size:11px;color:#97a0af;margin-top:4px">Arquivos físicos não voltam na recuperação (foram apagados).</div>';
      }
      var comments = '';
      if(Array.isArray(c.comments) && c.comments.length){
        comments = '<div style="font-size:12px;font-weight:700;color:#172b4d;margin:12px 0 6px">💬 COMENTÁRIOS (' + c.comments.length + ')</div>'
          + '<div style="display:grid;gap:6px">' + c.comments.map(function(cm){
            var who = cm.realname || cm.firstname ? ((cm.firstname || '') + ' ' + (cm.realname || '')).trim() : (cm.user_name || ('#' + (cm.users_id || '?')));
            return '<div style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:8px 10px"><div style="font-size:11px;color:#5e6c84"><strong>' + esc(who) + '</strong> • ' + esc(fmtDate(cm.date_creation)) + '</div>'
              + '<div style="font-size:12px;margin-top:4px;white-space:pre-wrap">' + esc(cm.content || '') + '</div></div>';
          }).join('') + '</div>';
      }
      var histHtml = '<div style="font-size:12px;font-weight:700;color:#172b4d;margin:12px 0 6px">📜 HISTÓRICO COMPLETO (' + hist.length + ')</div>';
      if(!hist.length){
        histHtml += '<div style="font-size:12px;color:#5e6c84">Sem histórico no snapshot.</div>';
      } else {
        histHtml += '<div style="display:grid">' + hist.map(function(a, i){
          var dot = DOTS[a.action] || '#0079bf';
          var verb = LABELS[a.action] || a.action;
          var last = (i === hist.length - 1);
          return '<div style="display:flex;gap:10px">'
            + '<div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:14px"><span style="width:10px;height:10px;border-radius:50%;background:' + dot + ';margin-top:4px;flex-shrink:0"></span>'
            + (last ? '' : '<span style="width:2px;flex:1;background:#dfe1e6;min-height:12px"></span>') + '</div>'
            + '<div style="padding-bottom:12px;min-width:0"><div style="font-size:13px"><strong>' + esc(a.user || 'Sistema') + '</strong> ' + esc(verb) + '</div>'
            + (a.details ? '<div style="font-size:12px;color:#5e6c84;margin-top:2px">' + esc(a.details) + '</div>' : '')
            + '<div style="font-size:11px;color:#97a0af;margin-top:2px">' + esc(fmtDate(a.date)) + '</div></div></div>';
        }).join('') + '</div>';
      }

      body.innerHTML =
        '<div style="display:grid;gap:10px">'
        + '<div style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:14px">'
        + '<div style="font-size:17px;font-weight:800;color:#172b4d">' + esc(c.name || '') + '</div>'
        + '<div style="font-size:12px;color:#5e6c84;margin-top:4px">era #' + esc(c.orig_card_id || c.id || '') + ' • ' + esc(fmtDate(c.date_creation)) + '</div>'
        + (badges ? '<div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">' + badges + '</div>' : '')
        + (c.description ? '<div style="font-size:13px;margin-top:10px;white-space:pre-wrap;background:#f4f5f7;padding:10px;border-radius:6px">' + esc(c.description) + '</div>' : '<div style="font-size:12px;color:#97a0af;margin-top:8px">Sem descrição.</div>')
        + members + labels
        + '</div>'
        + audit
        + '<div style="background:#e3fcef;border:1px solid #61bd4f;border-radius:8px;padding:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
        + '<span style="font-size:13px;flex:1;min-width:200px">↩️ Recuperar recria o card na lista <strong>' + esc(target.list_name || '') + '</strong> com etiquetas, membros, checklists, comentários, máquinas e anotações.</span>'
        + '<button onclick="KanproDeleted.restore(' + esc(String(this.detailTrashId)) + ')" style="background:#006644;color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:800">Recuperar</button>'
        + '</div>'
        + machHtml + checks + atts + comments + histHtml
        + '</div>';
      try { body.parentNode.scrollTop = 0; body.scrollTop = 0; } catch(e){}
    },
    restore: function(trashId){
      var self = this;
      var item = null;
      for(var i = 0; i < this.items.length; i++){ if(this.items[i].trash_id === parseInt(trashId, 10)){ item = this.items[i]; break; } }
      var nm = item ? item.card_name : ('#' + trashId);
      var ok = false;
      try {
        if(window.Kanpro && Kanpro.showConfirm){
          Kanpro.showConfirm('Recuperar "' + nm + '" para a lista de origem?', 'Recuperar excluído', 'Recuperar').then(function(v){
            if(v) self.doRestore(trashId);
          });
          return;
        }
      } catch(e){}
      ok = confirm('Recuperar "' + nm + '" para a lista de origem?');
      if(!ok) return;
      this.doRestore(trashId);
    },
    doRestore: function(trashId){
      var self = this;
      var body = document.getElementById('kpd-body');
      post('restore_deleted_card', {trash_id: trashId}).then(function(res){
        if(res && res.success){
          try { if(window.Kanpro && Kanpro.showToast) Kanpro.showToast('Card recuperado'); } catch(e){}
          self.detail = null;
          self.reload();
          setTimeout(function(){ try { location.reload(); } catch(e){} }, 600);
        } else {
          alert((res && res.msg) || 'Erro ao recuperar');
        }
      });
    },
    refreshCount: function(){
      if(!window.KANPRO || !window.KANPRO.board) return;
      if(window.KANPRO.canSeeDeleted === 0 || window.KANPRO.canSeeDeleted === '0') return;
      var self = this;
      post('get_deleted_cards', {boards_id: window.KANPRO.board.id}).then(function(res){
        if(res && res.success){
          self.items = res.items || [];
          self.updateCountBadge();
        }
      });
    }
  };

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      var ov = document.getElementById('kpd-overlay');
      if(ov) D.close();
    }
  });

  window.KanproDeleted = D;
  try {
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ D.refreshCount(); });
    else D.refreshCount();
  } catch(e){}
})();
