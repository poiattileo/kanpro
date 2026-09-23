// KanPro Histórico — modal compartilhado (quadro e listagem).
// Sem dependência do Kanpro principal: só precisa do endpoint get_history.
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
    member_add:'adicionou membro', member_remove:'removeu membro', member_role:'trocou papel',
    card_create_ticket:'criou chamado', card_link_ticket:'vinculou chamado', card_unlink_ticket:'desvinculou chamado',
    card_approval_request:'pediu aprovação', card_approval_ok:'aprovou movimentação', print_sheet:'imprimiu folha'
  };
  var DOTS = {card_create:'#61bd4f', card_move:'#0079bf', card_archive:'#ff5630', card_restore:'#006644',
    card_complete:'#006644', maintenance_finalize:'#00b8d9', member_add:'#6554c0', member_remove:'#ff5630'};

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
    });
  }
  function ajaxUrl(){
    if (window.KANPRO && window.KANPRO.ajax_url) return window.KANPRO.ajax_url;
    if (window.KANPRO_HISTORY_URL) return window.KANPRO_HISTORY_URL;
    return '/plugins/kanpro/front/ajax.php';
  }
  function csrf(){
    if (window.KANPRO && window.KANPRO.csrf_token) return window.KANPRO.csrf_token;
    if (window.KANPRO_HISTORY_CSRF) return window.KANPRO_HISTORY_CSRF;
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
    return fetch(ajaxUrl(), {method:'POST', body:fd, credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf()}})
      .then(function(r){ return r.text(); })
      .then(function(t){ try { return JSON.parse(t); } catch(e){ return {success:false, msg:'Resposta inesperada'}; } })
      .catch(function(e){ return {success:false, msg:e.message}; });
  }
  function fmtDate(s){
    if(!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return s;
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
  }

  var H = {
    boardId: 0,
    people: [],
    rows: [],
    lastFetch: null,

    open: function(boardId){
      this.boardId = parseInt(boardId) || 0;
      if(!this.boardId){ alert('Quadro inválido'); return; }
      if(document.getElementById('kph-overlay')){ this.reload(); return; }
      var ov = document.createElement('div');
      ov.id = 'kph-overlay';
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:20000;display:flex;justify-content:center;align-items:flex-start;padding:4vh 16px;overflow-y:auto;box-sizing:border-box';
      ov.innerHTML =
        '<div style="background:#f4f5f7;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.35);width:100%;max-width:760px;display:flex;flex-direction:column;overflow:hidden;max-height:92vh">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:#fff;border-bottom:1px solid #dfe1e6">'
        + '<strong id="kph-title" style="font-size:16px">📜 Histórico</strong>'
        + '<button onclick="KanproHistory.close()" style="background:none;border:none;cursor:pointer;font-size:18px">✕</button></div>'
        + '<div id="kph-filters" style="padding:12px 18px;background:#fff;border-bottom:1px solid #dfe1e6;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"></div>'
        + '<div id="kph-count" style="padding:8px 18px 0;font-size:12px;color:#5e6c84"></div>'
        + '<div id="kph-body" style="padding:12px 18px;overflow-y:auto;min-height:120px"></div>'
        + '<div style="padding:12px 18px;border-top:1px solid #dfe1e6;background:#fff;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">'
        + '<button onclick="KanproHistory.exportCSV()" style="background:#006644;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:700;font-size:12px">Exportar CSV</button>'
        + '<button onclick="KanproHistory.exportPDF()" style="background:#6554c0;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:700;font-size:12px">Exportar PDF</button>'
        + '<button onclick="KanproHistory.close()" style="background:#f4f5f7;border:1px solid #dfe1e6;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:12px">Fechar</button>'
        + '</div></div>';
      document.body.appendChild(ov);
      var self = this;
      ov.addEventListener('click', function(e){ if(e.target === ov) self.close(); });
      this.reload();
    },
    close: function(){
      var ov = document.getElementById('kph-overlay');
      if(ov) ov.remove();
    },
    filters: function(){
      var g = function(id){ var el = document.getElementById(id); return el ? el.value : ''; };
      return {users_id: g('kph-f-user'), type: g('kph-f-type'), date_from: g('kph-f-from'),
        date_to: g('kph-f-to'), card_id: g('kph-f-card')};
    },
    reload: function(){
      var self = this;
      var body = document.getElementById('kph-body');
      if(body) body.innerHTML = '<div style="text-align:center;color:#5e6c84;padding:24px">Carregando...</div>';
      var f = this.filters();
      post('get_history', {boards_id: this.boardId, users_id: f.users_id, faction: f.type,
        date_from: f.date_from, date_to: f.date_to, card_id: f.card_id}).then(function(res){
        if(!res.success){ if(body) body.innerHTML = '<div style="color:#bf2600">' + esc(res.msg||'Erro') + '</div>'; return; }
        self.people = res.people || [];
        self.rows = res.rows || [];
        self.lastFetch = res;
        var t = document.getElementById('kph-title');
        if(t) t.textContent = '📜 Histórico — ' + (res.board_name || ('Quadro #' + self.boardId));
        self.renderFilters(f);
        self.render();
      });
    },
    renderFilters: function(f){
      f = f || {};
      var box = document.getElementById('kph-filters');
      if(!box) return;
      var peopleOpts = '<option value="">Todas as pessoas</option>' + this.people.map(function(p){
        return '<option value="' + p.id + '"' + (String(p.id)===String(f.users_id||'')?' selected':'') + '>' + esc(p.name) + (p.extra ? ' (' + esc(p.extra) + ')' : '') + '</option>';
      }).join('');
      var html = '<label style="font-size:11px;font-weight:600;color:#5e6c84">Pessoa<br><select id="kph-f-user" onchange="KanproHistory.reload()" style="padding:7px;border:1px solid #dfe1e6;border-radius:6px;min-width:150px;background:#fff">' + peopleOpts + '</select></label>'
        + '<label style="font-size:11px;font-weight:600;color:#5e6c84">Tipo<br><select id="kph-f-type" onchange="KanproHistory.reload()" style="padding:7px;border:1px solid #dfe1e6;border-radius:6px;min-width:150px;background:#fff">'
        + '<option value="">Todos os tipos</option>' + this.typeOptions(f.type) + '</select></label>'
        + '<label style="font-size:11px;font-weight:600;color:#5e6c84">De<br><input id="kph-f-from" type="date" value="' + esc(f.date_from||'') + '" onchange="KanproHistory.reload()" style="padding:6px;border:1px solid #dfe1e6;border-radius:6px;background:#fff"></label>'
        + '<label style="font-size:11px;font-weight:600;color:#5e6c84">Até<br><input id="kph-f-to" type="date" value="' + esc(f.date_to||'') + '" onchange="KanproHistory.reload()" style="padding:6px;border:1px solid #dfe1e6;border-radius:6px;background:#fff"></label>'
        + '<label style="font-size:11px;font-weight:600;color:#5e6c84">Cartão #<br><input id="kph-f-card" type="number" min="1" placeholder="#" value="' + esc(f.card_id||'') + '" onchange="KanproHistory.reload()" style="padding:7px;border:1px solid #dfe1e6;border-radius:6px;width:90px;background:#fff"></label>'
        + '<button onclick="KanproHistory.clearFilters()" style="padding:7px 12px;border:1px solid #dfe1e6;background:#fff;border-radius:6px;cursor:pointer;font-size:12px">Limpar</button>';
      box.innerHTML = html;
    },
    typeOptions: function(selected){
      // tipos vindos das linhas + catálogo conhecido
      var present = {};
      this.rows.forEach(function(r){ present[r.action] = true; });
      Object.keys(LABELS).forEach(function(a){ present[a] = present[a] || false; });
      var keys = Object.keys(present).sort(function(a,b){
        return (LABELS[a]||a).localeCompare(LABELS[b]||b);
      });
      return keys.map(function(a){
        return '<option value="' + esc(a) + '"' + (a===selected?' selected':'') + '>' + esc(LABELS[a]||a) + '</option>';
      }).join('');
    },
    clearFilters: function(){ this.reloadFresh(); },
    reloadFresh: function(){
      var box = document.getElementById('kph-filters');
      if(box) box.innerHTML = '';
      this.people = [];
      this.rows = [];
      this.reload();
    },
    cardUrl: function(cardId){
      var bid = this.boardId;
      if(window.KANPRO && window.KANPRO.board && parseInt(window.KANPRO.board.id) === parseInt(bid)){
        return null; // mesmo quadro: abre direto
      }
      return 'kanban.php?boards_id=' + bid + '&open_card=' + cardId;
    },
    openCard: function(cardId){
      if(window.KANPRO && window.KANPRO.board && parseInt(window.KANPRO.board.id) === parseInt(this.boardId)
        && typeof window.Kanpro !== 'undefined' && window.Kanpro.openCard){
        this.close();
        window.Kanpro.openCard(parseInt(cardId));
      } else {
        window.location.href = this.cardUrl(cardId);
      }
    },
    render: function(){
      var self = this;
      var box = document.getElementById('kph-body');
      var cnt = document.getElementById('kph-count');
      if(!box) return;
      if(cnt) cnt.innerHTML = 'Mostrando <strong>' + this.rows.length + '</strong> modificações' + (this.rows.length >= 500 ? ' (limite de 500 — refine os filtros)' : '');
      if(!this.rows.length){
        box.innerHTML = '<div style="text-align:center;color:#6b778c;padding:32px"><div style="font-size:36px">📭</div><div style="margin-top:8px">Nenhuma modificação com esses filtros.</div></div>';
        return;
      }
      box.innerHTML = '<div style="display:grid">' + this.rows.map(function(a, i){
        var dot = DOTS[a.action] || '#0079bf';
        var verb = LABELS[a.action] || a.action;
        var cardRef = a.card_id > 0
          ? ' <a href="#" onclick="KanproHistory.openCard(' + a.card_id + ');return false;" style="color:#0747a6">#' + a.card_id + ' ' + esc(a.card_name||'') + '</a>' : '';
        var last = (i === self.rows.length - 1);
        return '<div style="display:flex;gap:10px">'
          + '<div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:14px"><span style="width:10px;height:10px;border-radius:50%;background:' + dot + ';margin-top:4px;flex-shrink:0"></span>'
          + (last ? '' : '<span style="width:2px;flex:1;background:#dfe1e6;min-height:12px"></span>') + '</div>'
          + '<div style="padding-bottom:14px;min-width:0"><div style="font-size:13px"><strong>' + esc(a.user) + '</strong> ' + esc(verb) + cardRef + '</div>'
          + (a.details ? '<div style="font-size:12px;color:#5e6c84;margin-top:2px">' + esc(a.details) + '</div>' : '')
          + '<div style="font-size:11px;color:#97a0af;margin-top:2px">' + esc(fmtDate(a.date)) + '</div></div></div>';
      }).join('') + '</div>';
    },
    tableRows: function(){
      return this.rows.map(function(a){
        return [a.date, a.user, LABELS[a.action]||a.action,
          a.card_id > 0 ? ('#' + a.card_id + ' ' + (a.card_name||'')) : '', a.details||''];
      });
    },
    download: function(name, mime, content){
      var blob = new Blob([content], {type: mime});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name;
      document.body.appendChild(a);
      a.click();
      setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500);
    },
    exportCSV: function(){
      var q = function(v){ return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; };
      var lines = ['Data;Pessoa;Tipo;Cartão;Detalhe'];
      this.tableRows().forEach(function(r){ lines.push(r.map(q).join(';')); });
      this.download('historico-quadro-' + this.boardId + '.csv', 'text/csv;charset=utf-8', "﻿" + lines.join("\r\n"));
    },
    exportPDF: function(){
      var self = this;
      var rows = this.tableRows().map(function(r){
        return '<tr>' + r.map(function(c, i){ return '<td' + (i===0?' style="white-space:nowrap"':'') + '>' + esc(c) + '</td>'; }).join('') + '</tr>';
      }).join('');
      var title = document.getElementById('kph-title');
      var w = window.open('', '_blank');
      if(!w){ alert('Permita pop-ups para exportar o PDF.'); return; }
      w.document.write('<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Histórico do quadro</title>'
        + '<style>body{font-family:Arial,sans-serif;color:#172b4d;margin:24px}h1{font-size:20px}.meta{font-size:12px;color:#5e6c84}table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}th,td{border:1px solid #dfe1e6;padding:6px 8px;text-align:left;vertical-align:top}th{background:#f4f5f7}.no-print{margin:16px 0}@media print{.no-print{display:none}}</style>'
        + '</head><body><h1>📜 ' + esc(title ? title.textContent : 'Histórico') + '</h1>'
        + '<div class="meta">Gerado em ' + esc(new Date().toLocaleString('pt-BR')) + ' • ' + self.rows.length + ' modificações</div>'
        + '<div class="no-print"><button onclick="window.print()" style="background:#0079bf;color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:700">Imprimir / Salvar PDF</button></div>'
        + '<table><thead><tr><th>Data</th><th>Pessoa</th><th>Tipo</th><th>Cartão</th><th>Detalhe</th></tr></thead><tbody>' + rows + '</tbody></table>'
        + '</body></html>');
      w.document.close();
      w.focus();
      setTimeout(function(){ try{ w.print(); }catch(e){} }, 400);
    }
  };

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      var ov = document.getElementById('kph-overlay');
      if(ov) H.close();
    }
  });

  window.KanproHistory = H;
})();
