// KanPro chamado — fluxo Pendência -> Abrir -> Em andamento -> Finalizado + ticket GLPI.
// Carrega DEPOIS de kanpro.js (ver setup.php). Intercepta showAddCard (pend_chamado)
// e openCard (abrir/andamento/finalizado) com visões simplificadas.
(function(){
  if (!window.Kanpro) return;
  const K = window.Kanpro;

  function esc(s){ try { return K.escape(s); } catch(_) { return String(s == null ? '' : s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); } }
  function listOf(listsId){
    try {
      if (K.findListAny) { const f = K.findListAny(listsId); if (f && f.list) return f.list; }
    } catch(_){}
    return (K.lists || []).find(l=> String(l.id) === String(listsId)) || null;
  }
  function catOf(listsId){
    try { const l = listOf(listsId); const t = l && K.listTypeOf ? K.listTypeOf(l) : null; return t ? t.code : ''; }
    catch(_) { return ''; }
  }
  function userName(uid){
    uid = Number(uid);
    try {
      const m = (K.members || []).find(x=> Number(x.users_id) === uid);
      if (m) return m.name;
      const u = (K.allUsers || []).find(x=> Number(x.id) === uid);
      if (u) return u.name;
    } catch(_){}
    return '#' + uid;
  }
  function fmtDate(s){
    try { if (K.formatDate && s) return K.formatDate(s); } catch(_){}
    return s || '';
  }
  function picker(title, html){
    try { K.showPicker({title, html}); } catch(e){ alert('Não foi possível abrir'); }
  }

  const C = {
    // Criação guiada na Pendência Chamado: título + descrição
    create(listsId){
      picker('📞 Novo chamado', `
        <div style="display:grid;gap:10px">
          <div style="font-size:12px;color:#5e6c84">Cria o card, abre o <strong>ticket no GLPI</strong>, clona para <strong>Abrir chamado</strong> e move o original para <strong>Em Andamento Chamado</strong>.</div>
          <label style="font-size:12px;font-weight:700;color:#172b4d">Título
            <input id="kc-title" type="text" maxlength="255" placeholder="Ex: Impressora sala 3 sem imprimir" style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;box-sizing:border-box;font-size:14px"></label>
          <label style="font-size:12px;font-weight:700;color:#172b4d">Descrição
            <textarea id="kc-desc" rows="4" placeholder="Detalhe o problema..." style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;box-sizing:border-box;font-size:13px;font-family:inherit"></textarea></label>
          <button id="kc-save" onclick="KanproChamado.confirmCreate(${Number(listsId)}, this)" style="background:#00875a;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;font-weight:800">Criar chamado</button>
        </div>`);
      setTimeout(()=> document.getElementById('kc-title')?.focus(), 60);
    },
    confirmCreate(listsId, btn){
      const title = document.getElementById('kc-title')?.value.trim() || '';
      const desc = document.getElementById('kc-desc')?.value.trim() || '';
      if (!title) { alert('Título obrigatório'); return; }
      if (btn) { btn.disabled = true; btn.textContent = 'Criando...'; }
      K.ajax('add_chamado_card', {lists_id: listsId, name: title, description: desc}).then(res=>{
        if (!res || !res.success) {
          alert((res && res.msg) || 'Erro');
          if (btn) { btn.disabled = false; btn.textContent = 'Criar chamado'; }
          return;
        }
        try { K.closePicker && K.closePicker(); } catch(_){}
        K.showToast && K.showToast('📞 Chamado #' + res.tickets_id + ' criado e distribuído' + (res.zap_ok ? '' : ' (zap pode ter falhado — ver atividade)'));
        try { K.forceSync && K.forceSync(); } catch(_){}
      });
    },
    // Visão simplificada (Abrir / Andamento / Finalizado)
    open(cardId){
      K.ajax('chamado_detail', {cards_id: cardId}).then(res=>{
        if (!res || !res.success) { alert((res && res.msg) || 'Erro'); return; }
        const d = res.card;
        if (d.category === 'abrir_chamado') this.renderAbrir(d);
        else if (d.category === 'andamento_chamado') this.renderAndamento(d, res.updates || [], res.sibling || null);
        else this.renderFinalizado(d, res.updates || []);
      });
    },
    headHtml(d, badge){
      return `<div style="display:grid;gap:8px">
        <div style="font-size:15px;font-weight:800;color:#172b4d">${esc(d.name)}</div>
        ${d.description ? `<div style="font-size:13px;color:#172b4d;background:#f4f5f7;border-radius:6px;padding:10px;white-space:pre-wrap">${esc(d.description)}</div>` : ''}
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:#5e6c84">
          ${d.tickets_id ? `<span style="background:#e6fcff;border:1px solid #00b8d9;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700">🎫 Ticket #${d.tickets_id}</span>` : '<span>sem ticket vinculado</span>'}
          ${badge || ''}
        </div>`;
    },
    renderAbrir(d){
      const opened = d.chamado_status === 'liberado';
      const badge = opened
        ? '<span style="background:#e3fcef;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700">✅ Chamado aberto</span>'
        : '<span style="background:#fffae6;border:1px solid #ffab00;color:#975500;padding:2px 10px;border-radius:10px;font-weight:700">⏳ Aguardando abertura</span>';
      picker('📩 Abrir chamado', this.headHtml(d, badge) + `
        <div style="font-size:12px;color:#5e6c84">Abra o chamado e clique abaixo — isso libera o card em <strong>Em Andamento Chamado</strong> para ser realizado.</div>
        ${opened ? '' : `<button onclick="KanproChamado.markOpen(${d.id}, this)" style="background:#00875a;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;font-weight:800">✔ Chamado aberto</button>`}
      </div>`);
    },
    markOpen(cardId){
      // autenticação por palavra-desafio (igual Manutenção) — sem confirm() nativo
      const words = (K.CHALLENGE_WORDS && K.CHALLENGE_WORDS.length ? K.CHALLENGE_WORDS : ['CHAMADO','ABRIR','LIBERAR','EXECUTAR','CONFIRMAR']);
      const specials = ['PAIVA','MASSON','FERRARI','MORANGO','SAWATA'];
      let challenge;
      if (Math.random() < 0.10) challenge = specials[Math.floor(Math.random()*specials.length)];
      else {
        const others = words.filter(w=> !specials.includes(w));
        challenge = (others.length ? others : words)[Math.floor(Math.random()*((others.length ? others : words).length))];
      }
      this._openCardId = cardId;
      this._openChallenge = challenge;
      picker('📩 Chamado aberto — confirmação', `
        <style>
          .kc-grid{display:grid;gap:14px;min-width:min(440px,82vw)}
          .kc-banner{background:linear-gradient(135deg,#00875a 0%,#006644 100%);border-radius:10px;padding:14px 16px;color:#fff;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.18)}
          .kc-label{font-size:11px;font-weight:800;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px}
          .kc-field{width:100%;padding:9px 12px;border:2px solid #00875a;border-radius:8px;box-sizing:border-box;font-size:15px;outline:none;background:#fff;text-transform:uppercase;letter-spacing:.06em;text-align:center;font-weight:700}
          .kc-field:focus{box-shadow:0 0 0 3px #00875a33}
          .kc-confirm{background:#00875a;color:#fff;border:none;padding:12px 16px;border-radius:8px;cursor:pointer;font-weight:800;font-size:14px;box-shadow:0 2px 6px rgba(0,0,0,.2);flex:1}
          .kc-confirm:hover{filter:brightness(1.08)}
          .kc-confirm:disabled{opacity:.6;cursor:wait}
          .kc-cancel{background:#fff;border:1px solid #dfe1e6;padding:12px 14px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;color:#172b4d;flex:0 0 110px}
        </style>
        <div class="kc-grid">
          <div class="kc-banner">
            <span style="font-size:26px">📩</span>
            <div style="min-width:0"><div style="font-size:15px;font-weight:800">Confirmar abertura</div>
            <div style="font-size:11px;opacity:.9;margin-top:2px">Libera a execução do card em <strong>Em Andamento Chamado</strong></div></div>
          </div>
          <div><div class="kc-label">DIGITE A PALAVRA ABAIXO</div>
            <div style="background:#091e42;color:#fff;padding:10px;border-radius:8px;text-align:center;letter-spacing:0.08em">
              <div style="font-size:22px;font-weight:800;margin-top:2px">${esc(challenge)}</div>
            </div>
            <input id="kc-open-input" type="text" placeholder="${esc(challenge)}" autocomplete="off" autocapitalize="characters" class="kc-field" style="margin-top:8px">
            <div id="kc-open-error" style="color:#eb5a46;font-size:12px;display:none;min-height:14px;margin-top:4px"></div>
          </div>
          <div style="display:flex;gap:8px">
            <button onclick="Kanpro.closePicker()" class="kc-cancel">Cancelar</button>
            <button id="kc-open-btn" onclick="KanproChamado.confirmOpenChallenge()" class="kc-confirm">✔ Chamado aberto</button>
          </div>
          <div style="text-align:center"><a href="#" onclick="KanproChamado.markOpen(${Number(cardId)});return false" style="font-size:11px;color:#5e6c84">Gerar outra palavra</a></div>
        </div>`);
      const pk = document.getElementById('kanpro-picker');
      if (pk) { pk.style.maxWidth = '500px'; pk.style.width = 'min(500px, 94vw)'; }
      setTimeout(()=>{
        const inp = document.getElementById('kc-open-input');
        if (inp) {
          inp.focus();
          inp.addEventListener('keydown', e=>{ if (e.key === 'Enter') KanproChamado.confirmOpenChallenge(); });
        }
      }, 30);
    },
    confirmOpenChallenge(){
      const inp = document.getElementById('kc-open-input');
      const err = document.getElementById('kc-open-error');
      const btn = document.getElementById('kc-open-btn');
      const norm = (s)=>{ try { return K.normText(s).toUpperCase(); } catch(e) { return String(s || '').trim().toUpperCase(); } };
      const val = norm(inp?.value);
      const challenge = norm(this._openChallenge);
      if (!val || val !== challenge) {
        if (err) { err.textContent = `Digite exatamente "${this._openChallenge}" para continuar.`; err.style.display = 'block'; }
        if (inp) { inp.style.borderColor = '#eb5a46'; inp.focus(); inp.select(); }
        return;
      }
      if (err) err.style.display = 'none';
      if (btn) { btn.disabled = true; btn.textContent = 'Abrindo...'; }
      const cardId = this._openCardId;
      K.ajax('chamado_mark_open', {cards_id: cardId, confirm_text: challenge}).then(res=>{
        if (!res || !res.success) { alert((res && res.msg) || 'Erro'); if (btn) { btn.disabled = false; btn.textContent = '✔ Chamado aberto'; } return; }
        K.showToast && K.showToast('✅ Liberado para execução');
        this.autoDeleteCountdown(cardId, 30);
        try { K.forceSync && K.forceSync(); } catch(_){}
      });
    },
    // Contagem 30s no picker e auto-exclusão do clone em Abrir chamado
    autoDeleteCountdown(cardId, seconds){
      picker('✅ Chamado aberto', `
        <div style="display:grid;gap:10px;text-align:center;padding:8px 0">
          <div style="font-size:15px;font-weight:800;color:#006644">Liberado para execução ✅</div>
          <div style="font-size:12px;color:#5e6c84">Este card se auto-exclui em <strong id="kc-autodel-count">${seconds}s</strong>.<br>O acompanhamento continua no card em <strong>Em Andamento Chamado</strong> e no ticket do GLPI.</div>
          <button onclick="KanproChamado.pruneNow(${Number(cardId)})" style="background:#f4f5f7;border:1px solid #dfe1e6;color:#5e6c84;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:12px">Excluir agora</button>
        </div>`);
      const tick = (remain)=>{
        const el = document.getElementById('kc-autodel-count');
        if (!el) return; // picker fechado/trocado: cancela
        if (remain <= 0) { this.pruneNow(cardId); return; }
        el.textContent = remain + 's';
        setTimeout(()=> tick(remain - 1), 1000);
      };
      setTimeout(()=> tick(seconds - 1), 1000);
    },
    pruneNow(cardId){
      K.ajax('chamado_prune_open', {cards_id: cardId}).then(()=>{
        try { K.closePicker && K.closePicker(); } catch(_){}
        try { K.forceSync && K.forceSync(); } catch(_){}
      });
    },
    renderAndamento(d, updates, sibling){
      const liberado = sibling && sibling.status === 'liberado';
      const badge = liberado
        ? '<span style="background:#e3fcef;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700">✅ Liberado</span>'
        : '<span style="background:#fffae6;border:1px solid #ffab00;color:#975500;padding:2px 10px;border-radius:10px;font-weight:700">⏳ Aguardando "Chamado aberto"</span>';
      let hist = '<div style="font-size:11px;font-weight:800;color:#5e6c84;letter-spacing:.04em">ATUALIZAÇÕES</div>';
      if (!updates.length) hist += '<div style="font-size:12px;color:#5e6c84">Nenhuma ainda.</div>';
      else hist += updates.map(u=>
        `<div style="background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:8px 10px;font-size:12px">`
        + `<div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:4px"><b>${esc(userName(u.users_id))}</b><span style="color:#5e6c84">${esc(fmtDate(u.date))}</span></div>`
        + `<div style="white-space:pre-wrap">${esc(u.note)}</div>`
        + (u.status === 'finalizado' ? '<div style="margin-top:4px;color:#006644;font-weight:700">✅ finalizado</div>' : '')
        + `</div>`).join('');
      picker('🔄 Em Andamento Chamado', this.headHtml(d, badge) + `
        <div style="display:grid;gap:6px;max-height:220px;overflow-y:auto">${hist}</div>
        <label style="font-size:12px;font-weight:700;color:#172b4d">O que foi realizado
          <textarea id="kc-note" rows="3" placeholder="Descreva..." style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;box-sizing:border-box;font-size:13px;font-family:inherit"></textarea></label>
        <label style="font-size:12px;font-weight:700;color:#172b4d">Status
          <select id="kc-status" style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px">
            <option value="pendente">🕐 Pendente (só anota)</option>
            <option value="finalizado">✅ Finalizado (encerra e move)</option>
          </select></label>
        ${liberado ? '' : '<div style="font-size:11px;color:#975500">⚠️ Finalizar exige o "Chamado aberto" em Abrir chamado. Pendente pode anotar à vontade.</div>'}
        <button onclick="KanproChamado.saveUpdate(${d.id}, this)" style="background:#403294;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;font-weight:800">Atualizar card</button>
      </div>`);
    },
    saveUpdate(cardId, btn){
      const note = document.getElementById('kc-note')?.value.trim() || '';
      const status = document.getElementById('kc-status')?.value || 'pendente';
      if (!note) { alert('Escreva o que foi realizado.'); return; }
      if (status === 'finalizado' && !confirm('Finalizar? Encerra o ticket no GLPI e move para Chamado finalizado.')) return;
      if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }
      K.ajax('chamado_update', {cards_id: cardId, note, status}).then(res=>{
        if (!res || !res.success) {
          alert((res && res.msg) || 'Erro');
          if (btn) { btn.disabled = false; btn.textContent = 'Atualizar card'; }
          return;
        }
        try { K.closePicker && K.closePicker(); } catch(_){}
        K.showToast && K.showToast(res.finished ? '✅ Chamado finalizado' : '📝 Atualização registrada');
        try { K.forceSync && K.forceSync(); } catch(_){}
      });
    },
    renderFinalizado(d, updates){
      let hist = '';
      if (updates.length) hist = '<div style="display:grid;gap:6px;max-height:220px;overflow-y:auto">' + updates.map(u=>
        `<div style="background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:8px 10px;font-size:12px">`
        + `<div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:4px"><b>${esc(userName(u.users_id))}</b><span style="color:#5e6c84">${esc(fmtDate(u.date))}</span></div>`
        + `<div style="white-space:pre-wrap">${esc(u.note)}</div></div>`).join('') + '</div>';
      picker('✅ Chamado finalizado', this.headHtml(d, '<span style="background:#e3fcef;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700">✅ Finalizado</span>') + hist + '</div>');
    },
  };

  window.KanproChamado = C;

  // Intercepta criação na Pendência Chamado e abertura dos cards do fluxo
  try {
    const __showAdd = K.showAddCard;
    K.showAddCard = function(eOrListId, listId){
      let lid = listId;
      if (typeof eOrListId === 'number') lid = eOrListId;
      else if (eOrListId && eOrListId.target) {
        try { const el = eOrListId.target.closest && eOrListId.target.closest('.kp-list'); if (el) lid = el.dataset.listId; } catch(_){}
      }
      try {
        if (catOf(lid) === 'pend_chamado') { if (eOrListId && eOrListId.stopPropagation) eOrListId.stopPropagation(); C.create(lid); return; }
      } catch(_){}
      return __showAdd.call(this, eOrListId, listId);
    };
    const __open = K.openCard;
    K.openCard = function(cardId){
      try {
        const c = (K.cards || []).find(x=> String(x.id) === String(cardId));
        if (c) {
          const cat = catOf(c.plugin_kanpro_lists_id);
          if (cat === 'abrir_chamado' || cat === 'andamento_chamado' || cat === 'chamado_finalizado') { C.open(cardId); return; }
        }
      } catch(_){}
      return __open.call(this, cardId);
    };
  } catch(_){}
})();
