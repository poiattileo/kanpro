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
  function attUrl(id){
    try {
      const root = (K.ajax_url || '').replace(/\/plugins\/kanpro\/front\/ajax\.php$/, '');
      return root + '/plugins/kanpro/front/attachment.php?id=' + Number(id);
    } catch(_) { return '/plugins/kanpro/front/attachment.php?id=' + Number(id); }
  }

  const C = {
    // Anexos pós-criação (registro: vale mesmo com o card travado no fluxo)
    attSection(d){
      const atts = d.attachments || [];
      const canEdit = d.can_edit !== false;
      let h = `<div><div style="font-size:11px;font-weight:800;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">📎 ANEXOS${atts.length ? ` (${atts.length})` : ''}</div>`;
      if (!atts.length) h += `<div style="font-size:12px;color:#5e6c84">Nenhum anexo.</div>`;
      else h += `<div style="display:grid;gap:6px">` + atts.map(a=>`
        <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:6px 10px;font-size:12px">
          <a href="${attUrl(a.id)}" target="_blank" rel="noopener" title="${esc(a.name || '')}" style="color:#0747a6;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📎 ${esc(a.name || ('anexo #' + a.id))}</a>
          ${canEdit ? `<button onclick="KanproChamado.attDel(${d.id}, ${a.id}, this)" title="Excluir anexo" style="background:none;border:none;cursor:pointer;flex-shrink:0">🗑️</button>` : ''}
        </div>`).join('') + `</div>`;
      if (canEdit) h += `<div style="display:flex;gap:8px;margin-top:8px;align-items:center"><input id="kc-att-${d.id}" type="file" multiple style="font-size:12px;flex:1;min-width:0"><button onclick="KanproChamado.attAdd(${d.id}, this)" style="background:#0079bf;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:800;font-size:12px;flex-shrink:0">Anexar</button></div><div id="kc-att-msg-${d.id}" style="font-size:11px;color:#5e6c84;min-height:14px"></div>`;
      return h + `</div>`;
    },
    attAdd(cardId, btn){
      const inp = document.getElementById('kc-att-' + cardId);
      const files = Array.from((inp && inp.files) || []);
      if (!files.length) { alert('Escolha ao menos um arquivo.'); return; }
      const msg = document.getElementById('kc-att-msg-' + cardId);
      if (btn) btn.disabled = true;
      let ok = 0, fail = 0, i = 0;
      const step = ()=>{
        if (i >= files.length) {
          if (msg) msg.textContent = fail ? `${ok}/${files.length} enviado(s), ${fail} falhou` : `${ok} anexo(s) enviado(s) ✓`;
          this.open(cardId);
          return;
        }
        const f = files[i++];
        if (msg) msg.textContent = `Enviando ${i}/${files.length}...`;
        const fd = new FormData();
        fd.append('cards_id', cardId);
        fd.append('file', f, f.name);
        K.ajax('upload_attachment', fd, true).then(res=>{ if (res && res.success) ok++; else fail++; step(); }).catch(()=>{ fail++; step(); });
      };
      step();
    },
    attDel(cardId, attId, btn){
      if (!confirm('Excluir este anexo?')) return;
      if (btn) btn.disabled = true;
      K.ajax('delete_attachment', {id: attId}).then(()=> this.open(cardId));
    },
    // Criação guiada: título + descrição + origem (URE ou Escola) + anexos.
    // flow 'pend_chamado' (completo, c/ clone) ou 'chamados_pendencia' (card+ticket, cai na Espera).
    create(listsId, flow){
      flow = (flow === 'chamados_pendencia') ? 'chamados_pendencia' : 'pend_chamado';
      C._flow = flow;
      picker('📞 Novo chamado', `
        <div style="display:grid;gap:10px">
          <div style="font-size:12px;color:#5e6c84">Cria o card, abre o <strong>ticket no GLPI</strong>, clona para <strong>Abrir chamado</strong> e move o original para <strong>Em Andamento Chamado</strong>.</div>
          <label style="font-size:12px;font-weight:700;color:#172b4d">Título
            <input id="kc-title" type="text" maxlength="255" placeholder="Ex: Impressora sala 3 sem imprimir" style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;box-sizing:border-box;font-size:14px"></label>
          <label style="font-size:12px;font-weight:700;color:#172b4d">Descrição
            <textarea id="kc-desc" rows="4" placeholder="Detalhe o problema..." style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;box-sizing:border-box;font-size:13px;font-family:inherit"></textarea></label>
          <div>
            <div style="font-size:12px;font-weight:700;color:#172b4d;margin-bottom:4px">Origem do chamado</div>
            <div style="display:flex;gap:8px">
              <label style="flex:1;display:flex;align-items:center;gap:6px;background:#f4f5f7;border:1px solid #dfe1e6;border-radius:6px;padding:8px 10px;font-size:13px;cursor:pointer"><input type="radio" name="kc-origin" value="ure" checked onchange="KanproChamado.toggleOrigin()"> 🏢 URE</label>
              <label style="flex:1;display:flex;align-items:center;gap:6px;background:#f4f5f7;border:1px solid #dfe1e6;border-radius:6px;padding:8px 10px;font-size:13px;cursor:pointer"><input type="radio" name="kc-origin" value="escola" onchange="KanproChamado.toggleOrigin()"> 🏫 Escola</label>
            </div>
          </div>
          <label id="kc-entity-wrap" style="display:none;font-size:12px;font-weight:700;color:#172b4d">Escola (entidade)
            <select id="kc-entity" style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;background:#fff;font-size:13px"><option value="">Carregando escolas...</option></select></label>
          <div>
            <div style="font-size:12px;font-weight:700;color:#172b4d;margin-bottom:4px">📎 Anexos <small style="font-weight:400">(opcional — quantos quiser)</small></div>
            <input id="kc-files" type="file" multiple onchange="KanproChamado.listFiles(this)" style="width:100%;font-size:12px;color:#5e6c84">
            <div id="kc-files-list" style="display:grid;gap:4px;margin-top:6px;font-size:12px;color:#5e6c84"></div>
          </div>
          <button id="kc-save" onclick="KanproChamado.confirmCreate(${Number(listsId)}, this, '${flow}')" style="background:#00875a;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;font-weight:800">Criar chamado</button>
        </div>`);
      const pkc = document.getElementById('kanpro-picker');
      if (pkc) { pkc.style.minWidth = '480px'; pkc.style.maxWidth = '600px'; pkc.style.width = 'min(560px, 94vw)'; }
      const pbc = document.getElementById('picker-body');
      if (pbc) { pbc.style.maxHeight = '88vh'; pbc.style.overflowY = 'auto'; }
      setTimeout(()=> document.getElementById('kc-title')?.focus(), 60);
    },
    toggleOrigin(){
      const wrap = document.getElementById('kc-entity-wrap');
      const sel = document.getElementById('kc-entity');
      const origin = document.querySelector('input[name="kc-origin"]:checked')?.value || 'ure';
      if (!wrap || !sel) return;
      if (origin !== 'escola') { wrap.style.display = 'none'; return; }
      wrap.style.display = '';
      if (sel.dataset.loaded === '1') return;
      sel.innerHTML = '<option value="">Carregando escolas...</option>';
      K.ajax('list_entities', {}).then(res=>{
        const s = document.getElementById('kc-entity');
        if (!s) return;
        const list = (res && res.success && res.entities) || [];
        if (!list.length) { s.innerHTML = '<option value="">Nenhuma escola encontrada</option>'; return; }
        s.innerHTML = '<option value="">Selecione a escola...</option>' + list.map(e=>`<option value="${Number(e.id)}">${esc(e.completename || e.name)}</option>`).join('');
        s.dataset.loaded = '1';
      });
    },
    listFiles(input){
      const box = document.getElementById('kc-files-list');
      if (!box) return;
      const files = Array.from((input && input.files) || []);
      box.innerHTML = files.map(f=>`<div>📎 ${esc(f.name)} <span style="color:#97a0af">(${(f.size/1024).toFixed(0)} KB)</span></div>`).join('');
    },
    confirmCreate(listsId, btn, flow){
      flow = (flow === 'chamados_pendencia') ? 'chamados_pendencia' : 'pend_chamado';
      const action = (flow === 'chamados_pendencia') ? 'add_chamados_pendencia_card' : 'add_chamado_card';
      const title = document.getElementById('kc-title')?.value.trim() || '';
      const desc = document.getElementById('kc-desc')?.value.trim() || '';
      const origin = document.querySelector('input[name="kc-origin"]:checked')?.value || 'ure';
      const entities_id = origin === 'escola' ? Number(document.getElementById('kc-entity')?.value || 0) : 0;
      if (!title) { alert('Título obrigatório'); return; }
      if (origin === 'escola' && !entities_id) { alert('Selecione a escola (entidade).'); return; }
      const files = Array.from(document.getElementById('kc-files')?.files || []);
      if (btn) { btn.disabled = true; btn.textContent = files.length ? `Criando + enviando ${files.length} anexo(s)...` : 'Criando...'; }
      const fd = new FormData();
      fd.append('lists_id', listsId);
      fd.append('name', title);
      fd.append('description', desc);
      fd.append('origin', origin);
      fd.append('entities_id', entities_id);
      files.forEach(f=> fd.append('files[]', f, f.name));
      K.ajax(action, fd, true).then(res=>{
        if (!res || !res.success) {
          alert((res && res.msg) || 'Erro');
          if (btn) { btn.disabled = false; btn.textContent = 'Criar chamado'; }
          return;
        }
        try { K.closePicker && K.closePicker(); } catch(_){}
        let msg = (flow === 'chamados_pendencia')
          ? ('📞 Chamado #' + res.tickets_id + ' criado → ' + (res.espera_list_name || 'Chamados Espera') + ' + cópia em Abrir chamado')
          : ('📞 Chamado #' + res.tickets_id + ' criado e distribuído' + (res.zap_ok ? '' : ' (zap pode ter falhado — ver atividade)'));
        if (files.length) msg += res.attachments_fail ? ` • 📎 ${res.attachments_ok || 0}/${files.length} anexo(s) (${res.attachments_fail} falhou)` : ` • 📎 ${files.length} anexo(s)`;
        K.showToast && K.showToast(msg);
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
      let ticketHtml = '<span>sem ticket vinculado</span>';
      if (d.tickets_id) {
        let tkUrl = '';
        try {
          const root = (K.ajax_url || '').replace(/\/plugins\/kanpro\/front\/ajax\.php$/, '');
          tkUrl = root + '/front/ticket.form.php?id=' + d.tickets_id;
        } catch(_) { tkUrl = '/front/ticket.form.php?id=' + d.tickets_id; }
        ticketHtml = `<a href="${tkUrl}" target="_blank" rel="noopener" title="Abrir chamado #${d.tickets_id} no GLPI em nova aba" style="background:#e6fcff;border:1px solid #00b8d9;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px">🎫 Ticket #${d.tickets_id} <span style="font-size:11px">↗</span></a>`;
      }
      let membersHtml = '';
      try {
        const mems = d.members || [];
        if (mems.length) {
          membersHtml = '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12px;color:#5e6c84"><span style="font-weight:700">👥</span>' + mems.map(function(m){
            let av = '';
            try { av = (K.avatarHtml ? K.avatarHtml(m.picture_url, m.initials, m.name, 'sm') : ''); } catch(_){ av = ''; }
            if (!av) av = '<span style="width:24px;height:24px;border-radius:50%;background:#0079bf;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700" title="' + esc(m.name || '') + '">' + esc(m.initials || '?') + '</span>';
            return '<span style="display:inline-flex;align-items:center;gap:4px">' + av + '<span>' + esc(m.name || '') + '</span></span>';
          }).join('') + '</div>';
        }
      } catch(_){ membersHtml = ''; }
      return `<div style="display:grid;gap:8px">
        <div style="font-size:15px;font-weight:800;color:#172b4d">${esc(d.name)}</div>
        ${d.description ? `<div style="font-size:13px;color:#172b4d;background:#f4f5f7;border-radius:6px;padding:10px;white-space:pre-wrap">${esc(d.description)}</div>` : ''}
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:#5e6c84">
          ${ticketHtml}
          ${d.entity_name ? `<span style="background:#e6f4ff;border:1px solid #91d5ff;color:#0050b3;padding:2px 10px;border-radius:10px;font-weight:700">🏫 ${esc(d.entity_name)}</span>` : ''}
          ${badge || ''}
        </div>
        ${membersHtml}`;
    },
    renderAbrir(d){
      const opened = d.chamado_status === 'liberado';
      const badge = opened
        ? '<span style="background:#e3fcef;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700">✅ Chamado aberto</span>'
        : '<span style="background:#fffae6;border:1px solid #ffab00;color:#975500;padding:2px 10px;border-radius:10px;font-weight:700">⏳ Aguardando abertura</span>';
      picker('📩 Abrir chamado', this.headHtml(d, badge) + `
        <div style="font-size:12px;color:#5e6c84">Abra o chamado e clique abaixo — isso libera o card em <strong>Em Andamento Chamado</strong> para ser realizado.</div>
        ${this.attSection(d)}
        ${opened ? '' : `<button onclick="KanproChamado.markOpen(${d.id}, this)" style="background:#00875a;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;font-weight:800">✔ Chamado aberto</button>`}
      </div>`);
      const pka = document.getElementById('kanpro-picker');
      if (pka) { pka.style.minWidth = '480px'; pka.style.maxWidth = '600px'; pka.style.width = 'min(560px, 94vw)'; }
      const pba = document.getElementById('picker-body');
      if (pba) { pba.style.maxHeight = '88vh'; pba.style.overflowY = 'auto'; }
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
      // Liberado se: clone liberado, OU original já carimbado, OU clone já auto-excluído (sibling null = fail-open igual backend).
      // Sem isso o cadeado voltava 30s depois do "Chamado aberto".
      const liberado = (sibling && sibling.status === 'liberado') || (!sibling) || (String(d.chamado_status || '') === 'liberado');
      this._andamentoLocked = !liberado;
      const badge = liberado
        ? '<span style="background:#e3fcef;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700">✅ Liberado</span>'
        : '<span style="background:#ffebe6;border:1px solid #eb5a46;color:#bf2600;padding:2px 10px;border-radius:10px;font-weight:800">🔒 Bloqueado — aguarde "Chamado aberto"</span>';
      const lockBanner = liberado ? '' : `
        <div style="display:flex;align-items:center;gap:10px;background:#ffebe6;border:1px solid #eb5a46;border-radius:8px;padding:10px 12px">
          <span style="font-size:22px">🔒</span>
          <div style="min-width:0">
            <div style="font-size:13px;font-weight:800;color:#bf2600">Card bloqueado</div>
            <div style="font-size:11px;color:#5e6c84;margin-top:2px">Só de bater o olho: está <strong>cadeado</strong> até alguém clicar em <strong>"Chamado aberto"</strong> no card de <strong>Abrir chamado</strong>. Nada aqui pode ser alterado por enquanto.</div>
          </div>
        </div>`;
      let hist = '<div style="font-size:11px;font-weight:800;color:#5e6c84;letter-spacing:.04em">ATUALIZAÇÕES</div>';
      if (!updates.length) hist += '<div style="font-size:12px;color:#5e6c84">Nenhuma ainda.</div>';
      else hist += updates.map(u=>
        `<div style="background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:8px 10px;font-size:12px">`
        + `<div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:4px"><b>${esc(userName(u.users_id))}</b><span style="color:#5e6c84">${esc(fmtDate(u.date))}</span></div>`
        + `<div style="white-space:pre-wrap">${esc(u.note)}</div>`
        + (u.status === 'finalizado' ? '<div style="margin-top:4px;color:#006644;font-weight:700">✅ finalizado</div>' : '')
        + `</div>`).join('');
      const dis = liberado ? '' : 'disabled';
      const disStyle = liberado ? '' : 'opacity:.55;background:#f4f5f7;cursor:not-allowed;';
      picker('🔄 Em Andamento Chamado', this.headHtml(d, badge) + `
        ${lockBanner}
        <div style="display:grid;gap:6px;max-height:220px;overflow-y:auto">${hist}</div>
        ${this.attSection(d)}
        <label style="font-size:12px;font-weight:700;color:#172b4d">O que foi realizado
          <textarea id="kc-note" rows="3" placeholder="${liberado ? 'Descreva...' : '🔒 Bloqueado — aguarde o Chamado aberto'}" ${dis} oninput="KanproChamado.draftSave(${d.id})" style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;box-sizing:border-box;font-size:13px;font-family:inherit;${disStyle}"></textarea></label>
        <div id="kc-draft-hint" style="font-size:11px;color:#8c8c8c;min-height:14px"></div>
        <label style="font-size:12px;font-weight:700;color:#172b4d">Status <span style="color:#eb5a46">*</span>
          <select id="kc-status" ${dis} style="width:100%;margin-top:4px;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;${disStyle}">
            <option value="" selected>Selecione o status...</option>
            <option value="pendente">🕐 Pendente (só anota)</option>
            <option value="finalizado">✅ Finalizado (encerra e move)</option>
          </select></label>
        ${liberado ? '' : '<div style="font-size:11px;color:#bf2600;font-weight:700">🔒 Finalizar e anotar exigem o "Chamado aberto" em Abrir chamado.</div>'}
        <button onclick="KanproChamado.saveUpdate(${d.id}, this)" ${dis} title="${liberado ? 'Registrar atualização' : 'Bloqueado — aguarde o Chamado aberto'}" style="background:${liberado ? '#403294' : '#97a0af'};color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:${liberado ? 'pointer' : 'not-allowed'};font-weight:800">${liberado ? 'Atualizar card' : '🔒 Bloqueado — aguarde Chamado aberto'}</button>
      </div>`);
      if (liberado) this.draftRestore(d.id);
    },
    // Rascunho do "O que foi realizado": salva sozinho a cada tecla (local, por card).
    // Se fechar e abrir de novo, o texto volta. Some ao registrar com sucesso.
    draftKey(cardId){ return 'kc-draft-' + Number(cardId); },
    draftSave(cardId){
      try {
        const ta = document.getElementById('kc-note');
        if (!ta || ta.disabled) return;
        clearTimeout(this._draftT);
        this._draftT = setTimeout(()=>{
          try {
            localStorage.setItem(this.draftKey(cardId), ta.value);
            const h = document.getElementById('kc-draft-hint');
            if (h && ta.value.trim()) h.textContent = '💾 Rascunho salvo automaticamente';
          } catch(_){}
        }, 400);
      } catch(_){}
    },
    draftRestore(cardId){
      try {
        const v = localStorage.getItem(this.draftKey(cardId)) || '';
        if (!v) return;
        const ta = document.getElementById('kc-note');
        if (ta && !ta.disabled) {
          ta.value = v;
          const h = document.getElementById('kc-draft-hint');
          if (h) h.textContent = '💾 Rascunho restaurado';
        }
      } catch(_){}
    },
    draftClear(cardId){ try { localStorage.removeItem(this.draftKey(cardId)); } catch(_){} },
    saveUpdate(cardId, btn){
      if (this._andamentoLocked) { alert('🔒 Card bloqueado — aguarde o "Chamado aberto" em Abrir chamado.'); return; }
      const note = document.getElementById('kc-note')?.value.trim() || '';
      const status = document.getElementById('kc-status')?.value || '';
      if (!note) { alert('Escreva o que foi realizado.'); return; }
      if (!status) { alert('Selecione o status (Pendente ou Finalizado).'); document.getElementById('kc-status')?.focus(); return; }
      const doSave = ()=>{
        if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }
        K.ajax('chamado_update', {cards_id: cardId, note, status}).then(res=>{
          if (!res || !res.success) {
            alert((res && res.msg) || 'Erro');
            if (btn) { btn.disabled = false; btn.textContent = 'Atualizar card'; }
            return;
          }
          this.draftClear(cardId);
          try { K.closePicker && K.closePicker(); } catch(_){}
          K.showToast && K.showToast(res.finished ? '✅ Chamado finalizado' : '📝 Atualização registrada');
          try { K.forceSync && K.forceSync(); } catch(_){}
        });
      };
      if (status === 'finalizado') {
        // padrão do plugin/GLPI (sem confirm nativo): mesmo visual do showConfirm do kanban
        try {
          if (K.showConfirm) {
            K.showConfirm('Encerra o ticket no GLPI e move para Chamado finalizado.', 'Finalizar chamado?', 'Finalizar').then(ok=>{ if (ok) doSave(); });
            return;
          }
        } catch(_){}
        if (!confirm('Finalizar? Encerra o ticket no GLPI e move para Chamado finalizado.')) return;
      }
      doSave();
    },
    renderFinalizado(d, updates){
      this._lastFinalizado = {d, updates};
      let hist = '';
      if (updates.length) hist = '<div style="display:grid;gap:6px;max-height:220px;overflow-y:auto">' + updates.map(u=>
        `<div style="background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:8px 10px;font-size:12px">`
        + `<div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:4px"><b>${esc(userName(u.users_id))}</b><span style="color:#5e6c84">${esc(fmtDate(u.date))}</span></div>`
        + `<div style="white-space:pre-wrap">${esc(u.note)}</div></div>`).join('') + '</div>';
      picker('✅ Chamado finalizado', this.headHtml(d, '<span style="background:#e3fcef;color:#006644;padding:2px 10px;border-radius:10px;font-weight:700">✅ Finalizado</span>') + hist
        + this.attSection(d)
        + `<button onclick="KanproChamado.copyFinalizado(this)" style="background:#0052cc;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;font-weight:800">📋 Copiar informações</button></div>`);
    },
    // Copia só o que foi lançado em "O que foi realizado", em ordem (um por linha)
    copyFinalizado(btn){
      const ref = this._lastFinalizado;
      if (!ref) return;
      const {updates} = ref;
      const txt = ((updates || []).map(u=> (u.note || '').trim()).filter(Boolean).join('\n') || '').trim();
      if (!txt) { alert('Nada para copiar.'); return; }
      const done = ()=> { try { K.showToast && K.showToast('📋 Copiado!'); } catch(_){} if (btn) { const o = btn.textContent; btn.textContent = '✓ Copiado!'; setTimeout(()=>{ btn.textContent = o; }, 2000); } };
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done).catch(()=> this.copyFallback(txt, done)); }
        else this.copyFallback(txt, done);
      } catch(_){ this.copyFallback(txt, done); }
    },
    copyFallback(txt, done){
      try {
        const ta = document.createElement('textarea');
        ta.value = txt;
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        done();
      } catch(_){ alert('Não foi possível copiar automaticamente.'); }
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
        const cat = catOf(lid);
        if (cat === 'pend_chamado') { if (eOrListId && eOrListId.stopPropagation) eOrListId.stopPropagation(); C.create(lid, 'pend_chamado'); return; }
        if (cat === 'chamados_pendencia') { if (eOrListId && eOrListId.stopPropagation) eOrListId.stopPropagation(); C.create(lid, 'chamados_pendencia'); return; }
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
