// KanPro butler — automações por quadro (quando entrar na lista X -> ação).
// Carrega DEPOIS de kanpro.js (ver setup.php). UI abre via botão ⚡ no kanban.
// Usa K.ajax (com CSRF) + K.showPicker/K.escape do core/modal.
(function(){
  if (!window.Kanpro) return;
  const K = window.Kanpro;

  function esc(s){ try { return K.escape(s); } catch(_) { return String(s == null ? '' : s); } }
  function listName(id){
    const l = (K.lists || []).find(x=> String(x.id) === String(id));
    return l ? l.name : ('Lista #' + id);
  }
  function labelName(id){
    const l = (K.labels || []).find(x=> String(x.id) === String(id));
    return l ? (l.name || l.color || ('#' + id)) : ('#' + id);
  }
  function memberName(id){
    const m = (K.members || []).find(x=> String(x.users_id) === String(id));
    return m ? m.name : ('#' + id);
  }
  function describe(r){
    const ln = listName(r.lists_id);
    if (r.action === 'add_label') return `entrar em <b>${esc(ln)}</b> → etiqueta <b>${esc(labelName(r.params))}</b>`;
    if (r.action === 'assign_member') return `entrar em <b>${esc(ln)}</b> → membro <b>${esc(memberName(r.params))}</b>`;
    if (r.action === 'set_due_days') return `entrar em <b>${esc(ln)}</b> → prazo <b>${esc(r.params)} dia(s)</b>`;
    return esc(r.action);
  }

  const B = {
    open(){
      if (!K.board || !K.board.id) return;
      K.ajax('rule_list', {boards_id: K.board.id}).then(res=>{
        if (!res || !res.success) { K.showAlert && K.showAlert(res.msg || 'Erro', '❌ Automações'); return; }
        this.render(res.rules || [], !!res.can_manage);
      });
    },
    render(rules, canManage){
      const lists = (K.lists || []).filter(l=> !l.is_archived);
      const labels = (K.labels || []);
      const members = (K.members || []);
      let html = '<div style="display:grid;gap:6px">';
      html += '<div style="font-size:12px;color:#5e6c84">Quando um cartão <strong>entrar na lista</strong>, executa a ação sozinho. Ex: entrou em Concluído → ganha etiqueta verde.</div>';
      if (!rules.length) {
        html += '<div style="font-size:12px;color:#5e6c84;background:#f4f5f7;border-radius:6px;padding:10px">Nenhuma automação ainda.</div>';
      } else {
        html += rules.map(r=>
          '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;background:#f9fafb;border:1px solid #dfe1e6;border-radius:8px;padding:8px 10px;font-size:12px">'
          + '<span>⚡ ' + describe(r) + '</span>'
          + (canManage ? `<button onclick="KanproButler.del(${r.id})" title="Excluir" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;border-radius:6px;padding:2px 8px;cursor:pointer">✕</button>` : '')
          + '</div>'
        ).join('');
      }
      if (canManage) {
        const listOpts = lists.map(l=> `<option value="${l.id}">${esc(l.name)}</option>`).join('');
        const labelOpts = labels.map(l=> `<option value="${l.id}">${esc(l.name || l.color)}</option>`).join('');
        const memberOpts = members.map(m=> `<option value="${m.users_id}">${esc(m.name)}</option>`).join('');
        html += `<hr style="border:none;border-top:1px solid #dfe1e6">
          <div style="font-size:12px;font-weight:700">Nova automação</div>
          <label style="font-size:12px;color:#5e6c84">Quando entrar em
            <select id="kb-list" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px">${listOpts}</select></label>
          <label style="font-size:12px;color:#5e6c84">Ação
            <select id="kb-action" onchange="KanproButler.swapParam()" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px">
              <option value="add_label">＋ Adicionar etiqueta</option>
              <option value="assign_member">👤 Atribuir membro</option>
              <option value="set_due_days">📅 Definir prazo (dias)</option>
            </select></label>
          <div id="kb-param-label" style="font-size:12px;color:#5e6c84">Etiqueta
            <select id="kb-param" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px">${labelOpts}</select></div>
          <div id="kb-param-member" style="display:none;font-size:12px;color:#5e6c84">Membro
            <select id="kb-member" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px">${memberOpts}</select></div>
          <div id="kb-param-days" style="display:none;font-size:12px;color:#5e6c84">Dias (0 = hoje)
            <input id="kb-days" type="number" min="0" max="365" value="3" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px"></div>
          <button onclick="KanproButler.add(this)" style="background:#6554c0;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:700">Criar automação</button>`;
      } else {
        html += '<div style="font-size:11px;color:#5e6c84">Somente gestores criam automações.</div>';
      }
      html += '</div>';
      try { K.showPicker({title: '⚡ Automações', html}); } catch(e){ alert('Não foi possível abrir automações'); }
    },
    swapParam(){
      const a = document.getElementById('kb-action');
      const v = a ? a.value : 'add_label';
      const show = (id, on)=>{ const el = document.getElementById(id); if (el) el.style.display = on ? '' : 'none'; };
      show('kb-param-label', v === 'add_label');
      show('kb-param-member', v === 'assign_member');
      show('kb-param-days', v === 'set_due_days');
    },
    add(btn){
      const lists_id = document.getElementById('kb-list')?.value;
      const action = document.getElementById('kb-action')?.value;
      let params = '';
      if (action === 'add_label') params = document.getElementById('kb-param')?.value || '';
      else if (action === 'assign_member') params = document.getElementById('kb-member')?.value || '';
      else if (action === 'set_due_days') params = document.getElementById('kb-days')?.value || '0';
      if (!lists_id || !params) return;
      if (btn) { btn.disabled = true; btn.textContent = '...'; }
      K.ajax('rule_add', {boards_id: K.board.id, lists_id, rule_action: action, params}).then(res=>{
        if (!res || !res.success) { alert((res && res.msg) || 'Erro'); if (btn) { btn.disabled = false; btn.textContent = 'Criar automação'; } return; }
        this.open();
      });
    },
    del(id){
      if (!confirm('Excluir esta automação?')) return;
      K.ajax('rule_delete', {id}).then(res=>{
        if (!res || !res.success) { alert((res && res.msg) || 'Erro'); return; }
        this.open();
      });
    },
  };

  window.KanproButler = B;
  try { K.openButler = ()=> B.open(); } catch(_){}
})();
