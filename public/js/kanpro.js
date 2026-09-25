// KanPro - Trello Clone JS
(function(){
  if (!window.KANPRO) return;
  const K = window.KANPRO;
  const $ = (s, el=document) => el.querySelector(s);
  const $$ = (s, el=document) => [...el.querySelectorAll(s)];
  const MAINT_CHALLENGE_WORDS = ["PAIVA","MASSON","FERRARI","MORANGO","SAWATA","TECNICO","SUPORTE","MANUTENCAO","REPARO","DIAGNOSTICO","HARDWARE","SOFTWARE","NOTEBOOK","DESKTOP","MONITOR","TECLADO","MOUSE","IMPRESSORA","REDE","SERVIDOR","BACKUP","SEGURANCA","ATUALIZACAO","LIMPEZA","FORMATACAO","INSTALACAO","CONFIGURACAO","ATENDIMENTO","CHAMADO","TICKET","PROTOCOLO","SISTEMA","PROCESSADOR","MEMORIA","SSD","HD","PLACA","FONTE","COOLER","GABINETE","BATERIA","CARREGADOR","CABO","CONECTOR","DRIVER","FIRMWARE","BIOS","WINDOWS","LINUX","OFFICE","ANTIVIRUS","FIREWALL","VPN","WIFI","ETHERNET","SWITCH","ROTEADOR","PATCH","CABEAMENTO","ESTRUTURADO","VOIP","TELEFONIA","RAMAL","NOBREAK","ESTABILIZADOR","PROJETOR","WEBCAM","HEADSET","SCANNER","PLOTTER","TABLET","CELULAR","SMARTPHONE","CHIP","BROWSER","NAVEGADOR","EMAIL","SENHA","LOGIN","USUARIO","PERFIL","PERMISSAO","BANCO","DADOS","RELATORIO","INVENTARIO","PATRIMONIO","ATIVO","GARANTIA","CONTRATO","FORNECEDOR","CLIENTE","DEPARTAMENTO","SETOR","ALMOXARIFADO","ESTOQUE","COMPRA","LICENCA","ATIVACAO","VALIDACAO","AUTENTICACAO","CONFIRMACAO"];

  const Kanpro = {
    board: K.board,
    lists: K.lists || [],
    cards: K.cards || [],
    labels: K.labels || [],
    cardLabels: K.cardLabels || {},
    cardMembers: K.cardMembers || {},
    checkProgress: K.checkProgress || {},
    maintenanceProgress: K.maintenanceProgress || {},
    commentCounts: K.commentCounts || {},
    attCounts: K.attCounts || {},
    transferStatus: K.transferStatus || {},
    ticketMap: K.ticketMap || {},
    members: K.members || [],
    ajax_url: K.ajax_url || '/plugins/kanpro/front/ajax.php',
    openCardId: K.openCardId || null,
    canEdit: K.canEdit,
    currentCardId: null,
    dragCard: null,
    dragList: null,
    filterText: '',
    labelFilter: new Set(),
    memberFilter: new Set(),
    _diaryTimers: {},
    _diarySaving: {},
    _lastDiarySaved: {},

    /* ---------- helpers ---------- */
    isBoardAdmin(){
      // vale sessão e pessoa (login compartilhado)
      const ids = [parseInt(K.currentUserId)];
      const a = parseInt((K && K.actingUserId) || 0);
      if(a > 0 && !ids.includes(a)) ids.push(a);
      if(this.board && ids.includes(parseInt(this.board.users_id))) return true;
      const m = (this.members||[]).find(x=> ids.includes(parseInt(x.users_id)));
      if(m && m.role==='admin') return true;
      if(this.canEdit) return true; // UPDATE global
      return false;
    },
    csrf() {

      let t = document.getElementById('kanpro-csrf')?.value
           || document.querySelector('meta[name="glpi-csrf-token"]')?.content
           || document.querySelector('input[name="_glpi_csrf_token"]')?.value
           || window.glpi_csrf_token
           || K.csrf_token
           || '';
      if (!t) {
        const m = document.documentElement.innerHTML.match(/_glpi_csrf_token['"]?\s*[:=]\s*['"]([^'"]+)['"]/);
        if (m) t = m[1];
      }
      return t;
    },

    ajax(action, data={}, isFormData=false) {
      const fd = isFormData ? data : new FormData();
      // csrf_compliant=true no setup.php, então NÃO enviamos token (token de uso único quebrava 2º clique)
      if (!isFormData) {
        fd.append('action', action);
        for (let k in data) {
          if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]);
        }
      } else {
        data.append('action', action);
      }
            return fetch(this.ajax_url, {
        method:'POST',
        body: fd,
        credentials:'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-Glpi-Csrf-Token': this.csrf()
        }
      })
        .then(async r=>{
          const txt = await r.text();
          try { return JSON.parse(txt); }
          catch(e){
            console.error('KanPro ajax non-JSON', r.status, txt.substring(0,600));
            if (r.status===403) return {success:false, msg:'403 Forbidden - sem permissão. Faça logout/login e verifique Perfil > KanPro.'};
            if (r.status===404) return {success:false, msg:'404 - ajax.php não encontrado: '+this.ajax_url};
            return {success:false, msg:'Resposta inesperada (HTTP '+r.status+')'};
          }
        }).catch(e=>({success:false,msg:e.message}));
    },

    init(){
      this.applyBoardBackground();
      this.renderBoard();
      this.renderMemberAvatars();
      this.renderBoardMenuDetails();
      this.updateStats();
      this.applyDarkMode();
      if(this.openCardId){ this.openCard(this.openCardId); }
      this.startPolling();
      // clicar fora fecha picker, board-menu e card-modal
      document.addEventListener('click', e=>{
        // se o elemento clicado foi removido do DOM (ex: removeMaintenanceRow remove a linha), não fecha o picker
        if(!document.contains(e.target)) return;
        const picker = document.getElementById('kanpro-picker');
        if(picker && picker.style.display!=='none' && !picker.contains(e.target) && !e.target.closest('[onclick*="open"]') && !e.target.closest('[onclick*="Picker"]') && !e.target.closest('.kp-sidebar-btn')){
          // evita fechar se clique é no botão que abriu (já tratado por showPicker)
          const isPickerBtn = e.target.closest('button');
          if(!isPickerBtn || !isPickerBtn.textContent.match(/Membros|Etiquetas|Datas|Capa|Mover|Filtrar/)){
            // só fecha se não for dentro do picker
            if(!picker.contains(e.target)) this.closePicker();
          }
        }
        const bmenu = document.getElementById('kanpro-board-menu');
        if(bmenu && bmenu.style.display!=='none' && !bmenu.contains(e.target) && !e.target.closest('[onclick*="openBoardMenu"]') && !e.target.closest('[onclick*="BoardMenu"]')){
          if(!e.target.closest('#kanpro-board-menu')) this.closeBoardMenu();
        }
      });
      // ESC fecha tudo + atalhos (N novo cartão, F filtrar, setas navegar, Enter abrir, Ctrl+K busca)
      document.addEventListener('keydown', e=>{
        if (e.key==='Escape') { this.closeCardModal(); this.closePicker(); this.closeBoardMenu(); this.closeCalendarView(); this.clearCardSelection(); return; }
        if ((e.ctrlKey || e.metaKey) && (e.key==='k' || e.key==='K')) {
          e.preventDefault();
          if(document.getElementById('kp-quickfind')) this.closeQuickFind();
          else this.openQuickFind();
          return;
        }
        const tag = (document.activeElement && document.activeElement.tagName) || '';
        if (['INPUT','TEXTAREA','SELECT'].includes(tag) || (document.activeElement && document.activeElement.isContentEditable)) return;
        const modalOpen = (document.getElementById('kanpro-card-modal')?.style.display === 'block');
        if (modalOpen) return;
        if (e.key==='n' || e.key==='N') {
          const first = this.lists.find(l=> l.is_archived==0);
          if(first){
            const el = document.querySelector(`.kp-list[data-list-id="${first.id}"]`);
            if(el) el.scrollIntoView({behavior:'smooth', inline:'center', block:'nearest'});
            this.showAddCard(first.id);
          }
        } else if (e.key==='f' || e.key==='F') {
          const f = document.getElementById('kanpro-filter');
          if(f){ e.preventDefault(); f.focus(); }
        } else if (e.key==='ArrowDown' || e.key==='ArrowUp') {
          e.preventDefault();
          this.stepCardSelection(e.key==='ArrowDown' ? 1 : -1);
        } else if (e.key==='ArrowLeft' || e.key==='ArrowRight') {
          e.preventDefault();
          this.stepCardSelectionH(e.key==='ArrowRight' ? 1 : -1);
        } else if (e.key==='Enter') {
          const sel = document.querySelector('.kp-card.kp-selected');
          if(sel) this.openCard(parseInt(sel.dataset.cardId));
        }
      });
      // clique fora do card-modal (overlay) fecha
      const m = document.getElementById('kanpro-card-modal');
      if(m) m.addEventListener('click', e=>{ if(e.target.id==='kanpro-card-modal') this.closeCardModal(); });
      // clique fora do picker também fecha (captura)
      document.addEventListener('mousedown', e=>{
        const picker = document.getElementById('kanpro-picker');
        if(picker && picker.style.display!=='none' && !picker.contains(e.target) && !e.target.closest('#kanpro-picker')){
          // não fecha se clicou no botão que abriu picker (evita fechar imediato)
          if(e.target.closest('button') && e.target.closest('button').onclick && String(e.target.closest('button').onclick).includes('Picker')) return;
        }
      });
    },

    // ---------- ATUALIZAÇÃO EM TEMPO REAL (polling inteligente) ----------
    // Selo leve a cada 8s; snapshot pesado só se o selo mudou. Sem selo (backend antigo) faz fallback p/ snapshot.
    startPolling(){
      this._lastSnapshotJson = JSON.stringify({
        lists: this.lists, cards: this.cards, labels: this.labels,
        cardLabels: this.cardLabels, cardMembers: this.cardMembers,
        checkProgress: this.checkProgress, maintenanceProgress: this.maintenanceProgress, commentCounts: this.commentCounts,
        attCounts: this.attCounts, members: this.members, transferStatus: this.transferStatus
      });
      this._lastStamp = null;
      this._unchangedRounds = 0;
      this._pollIntervalMs = 8000;
      this.ajax('presence_heartbeat', {boards_id: this.board.id});
      if(this._pollTimer) clearTimeout(this._pollTimer);
      this._pollingStartedAt = Date.now();
      const loop = ()=>{
        this.pollBoardUpdates().finally(()=>{
          // backoff adaptativo: quadro parado poll a cada 15s, com mudança volta p/ 8s
          const idle = (this._unchangedRounds||0) >= 5;
          this._pollIntervalMs = idle ? 15000 : 8000;
          this._pollTimer = setTimeout(loop, this._pollIntervalMs);
        });
      };
      this._pollTimer = setTimeout(loop, this._pollIntervalMs);
      // volta de aba/foco atualiza na hora (sem esperar o intervalo)
      if(!this._pollFocusBound){
        this._pollFocusBound = true;
        document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) this.pollBoardUpdates(); });
        window.addEventListener('focus', ()=> this.pollBoardUpdates());
      }
    },
    pollBoardUpdates(){
      if(document.hidden) return Promise.resolve(); // economiza requisição em aba não visível
      if(this.dragCard || this.dragList) return Promise.resolve(); // não atrapalha um arraste em andamento
      // passo 1 (leve): selo + presence. Só baixa snapshot pesado se o selo mudou.
      return this.ajax('get_board_stamp', {boards_id: this.board.id}).then(stampRes=>{
        if(stampRes && stampRes.success && stampRes.stamp){
          this.renderViewerAvatars(stampRes.viewers || []);
          if(this._lastStamp && stampRes.stamp === this._lastStamp){
            this._unchangedRounds = (this._unchangedRounds||0) + 1;
            return;
          }
          this._lastStamp = stampRes.stamp;
          this._unchangedRounds = 0;
          return this.fetchBoardSnapshot();
        }
        // backend antigo sem get_board_stamp: heartbeat + snapshot direto (comportamento anterior)
        this.ajax('presence_heartbeat', {boards_id: this.board.id});
        return this.fetchBoardSnapshot();
      }).catch(()=>{});
    },
    fetchBoardSnapshot(){
      return this.ajax('get_board_snapshot', {boards_id: this.board.id}).then(res=>{
        if(!res || !res.success) return;

        this.renderViewerAvatars(res.viewers || []);

        const snapshot = {
          lists: res.lists, cards: res.cards, labels: res.labels,
          cardLabels: res.cardLabels, cardMembers: res.cardMembers,
          checkProgress: res.checkProgress, maintenanceProgress: res.maintenanceProgress, commentCounts: res.commentCounts,
          attCounts: res.attCounts, members: res.members, transferStatus: res.transferStatus || {}
        };
        const snapshotJson = JSON.stringify(snapshot);
        if(snapshotJson === this._lastSnapshotJson) return; // nada mudou no quadro em si
        console.log('[KANPRO DEBUG] snapshot mudou, prosseguindo...');

        const boardEl = document.getElementById('kanpro-board');
        const activeInBoard = boardEl && document.activeElement && boardEl.contains(document.activeElement) &&
          ['INPUT','TEXTAREA'].includes(document.activeElement.tagName);
        console.log('[KANPRO DEBUG] activeInBoard=', activeInBoard, 'activeElement=', document.activeElement);
        if(activeInBoard) return;

        const oldCards = this.cards || [];
        const oldById = {};
        oldCards.forEach(c=> oldById[c.id] = c);
        const changedCardIds = [];
        let newCount = 0;
        (res.cards||[]).forEach(c=>{
          const prev = oldById[c.id];
          if(!prev){ newCount++; changedCardIds.push(c.id); }
          else if(prev.plugin_kanpro_lists_id != c.plugin_kanpro_lists_id || prev.name !== c.name){ changedCardIds.push(c.id); }
        });
        const isFirstLoad = (Date.now() - (this._pollingStartedAt||0)) < 3000;
        console.log('[KANPRO DEBUG] oldCards.length=', oldCards.length, 'newCards.length=', (res.cards||[]).length, 'changedCardIds=', changedCardIds, 'newCount=', newCount, 'isFirstLoad=', isFirstLoad);

        this._lastSnapshotJson = snapshotJson;
        this.lists = res.lists || [];
        this.cards = res.cards || [];
        this.labels = res.labels || [];
        this.cardLabels = res.cardLabels || {};
        this.cardMembers = res.cardMembers || {};
        this.checkProgress = res.checkProgress || {};
        this.maintenanceProgress = res.maintenanceProgress || {};
        this.commentCounts = res.commentCounts || {};
        this.attCounts = res.attCounts || {};
        this.members = res.members || [];
        this.transferStatus = res.transferStatus || {};

        this.renderBoard();
        this.renderMemberAvatars();

        if(!isFirstLoad && changedCardIds.length){
          this.showToast(newCount>0 ? 'Quadro atualizado — novo cartão adicionado' : 'Quadro atualizado');
          changedCardIds.forEach(id=>{
            const el = document.querySelector(`.kp-card[data-card-id="${id}"]`);
            if(el){
              el.classList.add('kp-card-updated');
              setTimeout(()=> el.classList.remove('kp-card-updated'), 2200);
            }
          });
        }

        if(this.currentCardId){
          const focused = document.activeElement;
          const isTyping = focused && (focused.tagName==='TEXTAREA' || focused.tagName==='INPUT');
          if(!isTyping) this.refreshCardModal();
        }
      }).catch(()=>{});
    },
    renderViewerAvatars(viewers){
      const wrap = document.getElementById('board-viewers-avatars');
      if(!wrap) return;
      const myId = K.currentUserId;
      const others = viewers.filter(v=> v.users_id != myId);
      wrap.innerHTML = others.slice(0,5).map(v=> `<span class="kp-avatar kp-avatar-online" style="margin-left:-6px" title="${this.escape(v.name)} — vendo agora">${this.escape(v.initials)}</span>`).join('');
    },
    showToast(message){
      let box = document.getElementById('kp-toast-box');
      if(!box){
        box = document.createElement('div');
        box.id = 'kp-toast-box';
        box.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:30000;display:flex;flex-direction:column;gap:8px;align-items:center';
        document.body.appendChild(box);
      }
      const toast = document.createElement('div');
      toast.style.cssText = 'background:#172b4d;color:#fff;padding:10px 18px;border-radius:20px;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.25);opacity:0;transform:translateY(8px);transition:opacity .2s,transform .2s;display:flex;align-items:center;gap:8px;max-width:90vw';
      toast.innerHTML = `<i class="ti ti-refresh"></i> ${this.escape(message)}`;
      box.appendChild(toast);
      requestAnimationFrame(()=>{ toast.style.opacity='1'; toast.style.transform='translateY(0)'; });
      setTimeout(()=>{
        toast.style.opacity='0';
        toast.style.transform='translateY(8px)';
        setTimeout(()=> toast.remove(), 250);
      }, 3500);
    },
    // Alerta customizado SEMPRE acima do card-modal (z 9999) — substitui alert() que ficava atrás
    showAlert(message, title){
      document.getElementById('kp-alert-overlay')?.remove();
      const ov = document.createElement('div');
      ov.id = 'kp-alert-overlay';
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:30000;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box';
      ov.innerHTML = `
        <div style="background:#fff;border-radius:10px;box-shadow:0 16px 48px rgba(0,0,0,.35);max-width:460px;width:100%;overflow:hidden">
          <div style="padding:14px 16px;border-bottom:1px solid #dfe1e6;font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px">
            <span style="font-size:18px">${(title||'').includes('✅')?'✅':(title||'').includes('❌')?'❌':'ℹ️'}</span>
            <span>${this.escape(title||'Informação')}</span>
          </div>
          <div style="padding:16px;font-size:13px;color:#172b4d;white-space:pre-line;line-height:1.5">${this.escape(message||'')}</div>
          <div style="padding:12px 16px;background:#f4f5f7;text-align:right">
            <button id="kp-alert-ok" style="background:#0052cc;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:700">OK</button>
          </div>
        </div>`;
      document.body.appendChild(ov);
      const close = ()=> ov.remove();
      ov.addEventListener('click', e=>{ if(e.target===ov) close(); });
      ov.querySelector('#kp-alert-ok').addEventListener('click', close);
      document.addEventListener('keydown', function esc(e){ if(e.key==='Escape'){ close(); document.removeEventListener('keydown', esc); } });
    },

    /* ---------- BUSCA RÁPIDA (Ctrl+K) ---------- */
    openQuickFind(){
      if(document.getElementById('kp-quickfind')){
        document.getElementById('kp-qf-input')?.focus();
        return;
      }
      const overlay = document.createElement('div');
      overlay.id = 'kp-quickfind';
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:20000;display:flex;justify-content:center;align-items:flex-start;padding:10vh 16px 16px';
      overlay.innerHTML = `
        <div style="background:#fff;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.35);width:100%;max-width:560px;overflow:hidden">
          <div style="display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #dfe1e6">
            <i class="ti ti-search" style="color:#5e6c84"></i>
            <input id="kp-qf-input" type="text" placeholder="Buscar cartão... (Enter abre, Esc fecha)" style="flex:1;border:none;outline:none;font-size:15px;background:transparent">
          </div>
          <div id="kp-qf-results" style="max-height:50vh;overflow-y:auto;padding:6px"></div>
          <div style="padding:6px 14px;border-top:1px solid #dfe1e6;font-size:11px;color:#97a0af">↑↓ navegar • Enter abrir • Esc fechar</div>
        </div>`;
      document.body.appendChild(overlay);
      this._qfIndex = 0;
      const input = overlay.querySelector('#kp-qf-input');
      input.addEventListener('input', ()=> this.renderQuickFind(input.value));
      input.addEventListener('keydown', e=>{
        const items = [...overlay.querySelectorAll('.kp-qf-item')];
        if(e.key==='ArrowDown'){ e.preventDefault(); this._qfIndex = Math.min(items.length-1, this._qfIndex+1); this.markQuickFind(items); }
        else if(e.key==='ArrowUp'){ e.preventDefault(); this._qfIndex = Math.max(0, this._qfIndex-1); this.markQuickFind(items); }
        else if(e.key==='Enter'){ const it = items[this._qfIndex]; if(it){ this.closeQuickFind(); this.openCard(parseInt(it.dataset.cardId)); } }
        else if(e.key==='Escape'){ e.stopPropagation(); this.closeQuickFind(); }
      });
      overlay.addEventListener('click', e=>{ if(e.target===overlay) this.closeQuickFind(); });
      this.renderQuickFind('');
      setTimeout(()=> input.focus(), 30);
    },
    closeQuickFind(){ document.getElementById('kp-quickfind')?.remove(); },
    markQuickFind(items){
      items.forEach((el,i)=> el.style.background = i===this._qfIndex ? '#e6fcff' : '#fff');
      const sel = items[this._qfIndex];
      if(sel) sel.scrollIntoView({block:'nearest'});
    },
    renderQuickFind(q){
      q = (q||'').toLowerCase().trim();
      const box = document.getElementById('kp-qf-results');
      if(!box) return;
      let list = (this.cards||[]).filter(c=> c.is_archived==0 && this.isCardVisible(c));
      if(q){
        list = list.map(c=>{
          const name = (c.name||'').toLowerCase(), desc = (c.description||'').toLowerCase();
          let s = -1;
          if(name.startsWith(q)) s = 0;
          else if(name.includes(q)) s = 1;
          else if(desc.includes(q)) s = 2;
          return {c, s};
        }).filter(x=> x.s>=0).sort((a,b)=> a.s-b.s).map(x=> x.c);
      } else {
        list = [...list].sort((a,b)=> String(b.date_mod||'').localeCompare(String(a.date_mod||''))).slice(0,8);
      }
      this._qfIndex = 0;
      const items = list.slice(0,20);
      box.innerHTML = items.map(c=>{
        const l = (this.lists||[]).find(x=> x.id==c.plugin_kanpro_lists_id);
        const due = c.due_date ? ` • ${this.formatDateShort(c.due_date)}` : '';
        return `<div class="kp-qf-item" data-card-id="${c.id}" onclick="Kanpro.closeQuickFind();Kanpro.openCard(${c.id})" style="display:flex;justify-content:space-between;gap:8px;padding:9px 10px;border-radius:6px;cursor:pointer;background:#fff">
          <span style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">#${c.id} ${this.escape(c.name)}</span>
          <span style="font-size:11px;color:#5e6c84;flex-shrink:0">${this.escape((l&&l.name)||'')}${due}</span>
        </div>`;
      }).join('') || '<div style="padding:20px;text-align:center;color:#5e6c84;font-size:13px">Nenhum cartão encontrado</div>';
      this.markQuickFind([...box.querySelectorAll('.kp-qf-item')]);
    },
    /* ---------- refresh serializado do modal (evita render velho ganhar de novo) ---------- */
    refreshCardModal(after){
      const cardId = this.currentCardId;
      if(!cardId) return Promise.resolve();
      // sequência: se dois refreshes concorrem, só o mais recente pode renderizar
      // (sem isso uma resposta velha chegava por último e "desmarcava" o Feito)
      const seq = (this._modalSeq = (this._modalSeq||0)+1);
      this._modalChain = (this._modalChain || Promise.resolve()).catch(()=>{}).then(()=>
        this.ajax('get_card', {cards_id: cardId}).then(r=>{
          if(seq !== this._modalSeq) return r; // resposta velha: descarta
          if(r && r.success && this.currentCardId===cardId) this.renderCardModal(r.data);
          this.renderBoard();
          if(typeof after === 'function'){ try{ after(r); }catch(e){} }
        }).catch(()=>{})
      );
      return this._modalChain;
    },
    scheduleModalRefresh(delay){
      // coalesce: cliques rápidos (Feito + Status) viram UM refresh só, com folga
      // p/ não matar o <select> que o usuário acabou de abrir nem piscar a tela
      clearTimeout(this._modalRefreshTimer);
      const d = (delay==null ? 800 : delay);
      this._modalRefreshTimer = setTimeout(()=> this.refreshCardModal(), d);
    },
    /* ---------- seleção por teclado ---------- */
    clearCardSelection(){
      document.querySelectorAll('.kp-card.kp-selected').forEach(el=> el.classList.remove('kp-selected'));
    },
    stepCardSelection(dir){
      const cards = [...document.querySelectorAll('.kp-list-cards .kp-card')].filter(el=> el.offsetParent !== null);
      if(!cards.length) return;
      let idx = cards.findIndex(el=> el.classList.contains('kp-selected'));
      idx = idx < 0 ? (dir > 0 ? 0 : cards.length - 1) : Math.min(cards.length - 1, Math.max(0, idx + dir));
      this.clearCardSelection();
      cards[idx].classList.add('kp-selected');
      cards[idx].scrollIntoView({block:'nearest'});
    },
    stepCardSelectionH(dir){
      const lists = [...document.querySelectorAll('#kanpro-board .kp-list')].filter(l=> l.offsetParent!==null && !l.classList.contains('collapsed'));
      if(!lists.length) return;
      const sel = document.querySelector('.kp-card.kp-selected');
      let listIdx = 0, cardIdx = 0;
      if(sel){
        const curList = sel.closest('.kp-list');
        listIdx = Math.max(0, lists.indexOf(curList));
        const cards = [...curList.querySelectorAll('.kp-card')].filter(el=> el.offsetParent!==null);
        cardIdx = Math.max(0, cards.indexOf(sel));
      }
      const targetList = lists[Math.min(lists.length - 1, Math.max(0, listIdx + dir))];
      targetList.scrollIntoView({inline:'center', block:'nearest', behavior:'smooth'});
      const newCards = [...targetList.querySelectorAll('.kp-card')].filter(el=> el.offsetParent!==null);
      if(!newCards.length) return;
      const target = newCards[Math.min(cardIdx, newCards.length - 1)];
      this.clearCardSelection();
      target.classList.add('kp-selected');
      target.scrollIntoView({block:'nearest'});
    },
    /* ---------- visibilidade ---------- */
    isCardVisible(card){
      if(!card) return true;
      // aguardando aprovação: invisível para não-admins
      if((card.approval_from||0) > 0 && !this.isBoardAdmin()) return false;
      return true;
    },
    // ---------- BOARD ----------
    renderBoard(){
      const board = $('#kanpro-board');
      if(!board) return;
      // preserva scroll de cada lista (evita pulo pro topo a cada render/polling)
      const scrolls = {};
      board.querySelectorAll('.kp-list-cards').forEach(el=>{
        const lid = el.dataset.listId || (el.closest('.kp-list')?.dataset.listId);
        if(lid) scrolls[lid] = el.scrollTop;
      });
      board.innerHTML = '';
      // ordena listas por rank
      this.lists.sort((a,b)=> parseFloat(a.rank)-parseFloat(b.rank));
      // ordem dos cartões: urgência primeiro (A-Z), depois fixados, depois rank
      const urgentOf = (c)=> ((this.maintenanceProgress && this.maintenanceProgress[c.id] && this.maintenanceProgress[c.id].urgent>0) ? 1 : 0);
      this.cards.sort((a,b)=>{
        const ua = urgentOf(a), ub = urgentOf(b);
        if(ua!==ub) return ub-ua;
        if(ua && ub){
          const na = this.normText(a.name||''), nb = this.normText(b.name||'');
          if(na!==nb) return na<nb?-1:1;
          return (a.id||0)-(b.id||0);
        }
        return ((b.is_pinned||0)-(a.is_pinned||0)) || (parseFloat(a.rank)-parseFloat(b.rank));
      });

      this.lists.forEach(list=>{
        if(list.is_archived==1) return;
        const cardsInList = this.cards.filter(c=> c.plugin_kanpro_lists_id==list.id && c.is_archived==0 && this.isCardVisible(c));
        const el = this.createListEl(list, cardsInList);
        board.appendChild(el);
      });

      // botão adicionar lista
      const addListWrap = document.createElement('div');
      addListWrap.className = 'kp-add-list';
      addListWrap.innerHTML = `
        <button class="kp-add-list-btn" onclick="Kanpro.showAddList()"><i class="ti ti-plus"></i> Adicionar outra lista</button>
        <div class="kp-list-composer" style="display:none">
          <input type="text" placeholder="Digite o título da lista..." maxlength="100">
          <div class="kp-composer-actions">
            <button class="kp-btn-primary" onclick="Kanpro.confirmAddList(this)">Adicionar lista</button>
            <button class="kp-btn-ghost" onclick="Kanpro.hideAddList()">✕</button>
          </div>
        </div>`;
      board.appendChild(addListWrap);

      // restaura scroll das listas
      board.querySelectorAll('.kp-list').forEach(listEl=>{
        const lid = listEl.dataset.listId;
        if(lid && scrolls[lid] !== undefined){
          const box = listEl.querySelector('.kp-list-cards');
          if(box) box.scrollTop = scrolls[lid];
        }
      });

      this.enableDragAndDrop();
      this.updateAssinaturaButton();
      this.updateStats();
    },

    createListEl(list, cardsInList){
      const div = document.createElement('div');
      div.className = 'kp-list';
      div.dataset.listId = list.id;
      div.draggable = true;
      const collapsed = this.isListCollapsed(list.id);
      if(collapsed) div.classList.add('collapsed');
      div.innerHTML = `
        <div class="kp-list-header">
          <div class="kp-list-title" onclick="Kanpro.editListTitle(${list.id})" title="Clique para editar">${this.escape(list.name)}</div>
          <input class="kp-list-title-input" style="display:none" onkeydown="if(event.key==='Enter') Kanpro.saveListTitle(${list.id}, this)" onblur="Kanpro.saveListTitle(${list.id}, this)">
          <span class="kp-list-count">${cardsInList.length}</span>
          <button class="kp-list-actions-btn" onclick="Kanpro.toggleCollapse(${list.id})" title="${collapsed?'Expandir lista':'Recolher lista'}"><i class="ti ${collapsed?'ti-chevrons-down':'ti-chevrons-up'}"></i></button>
          <button class="kp-list-actions-btn" onclick="Kanpro.openListMenu(event, ${list.id})"><i class="ti ti-dots"></i></button>
        </div>
        <div class="kp-list-cards" data-list-id="${list.id}">
        </div>
        <button class="kp-add-card" onclick="Kanpro.showAddCard(${list.id})"><i class="ti ti-plus"></i> Adicionar um cartão</button>
        <div class="kp-card-composer" style="display:none">
          <textarea placeholder="Digite um título para este cartão..." rows="3"></textarea>
          <div class="kp-composer-actions">
            <button class="kp-btn-primary" onclick="Kanpro.confirmAddCard(${list.id}, this)">Adicionar cartão</button>
            <button class="kp-btn-ghost" onclick="Kanpro.hideAddCard(${list.id})">✕</button>
          </div>
          <div style="margin-top:6px;font-size:12px;color:#5e6c84"><i class="ti ti-info-circle"></i> Pressione Enter para adicionar rapidamente</div>
        </div>
      `;
      const cardsContainer = div.querySelector('.kp-list-cards');
      cardsInList.forEach(card=>{
        const cardEl = this.createCardEl(card);
        cardsContainer.appendChild(cardEl);
      });
      // drag handle só no header: no dragstart o e.target é a própria lista,
      // então registra no mousedown onde o arrasto começou
      div.addEventListener('mousedown', e=>{
        div._fromHeader = !!e.target.closest('.kp-list-header');
      });
      div.addEventListener('dragstart', e=>{
        if(!div._fromHeader) { e.preventDefault(); return; }
        this.dragList = div;
        div.style.opacity='0.5';
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/plain', 'kp-list:'+list.id);
      });
      div.addEventListener('dragend', ()=>{ div.style.opacity='1'; div._fromHeader=false; this.dragList=null; });
      return div;
    },

    createCardEl(card){
      const labels = this.cardLabels[card.id] || [];
      const members = this.cardMembers[card.id] || [];
      const prog = this.checkProgress[card.id];
      const comments = this.commentCounts[card.id] || 0;
      const atts = this.attCounts[card.id] || 0;

      const div = document.createElement('div');
      div.className = 'kp-card';
      div.dataset.cardId = card.id;
      div.draggable = true;

      // aplica filtro
      if (this.isCardFilteredOut(card)) div.classList.add('filtered-out');

      let coverHtml = '';
      if (card.cover_color) {
        coverHtml = `<div class="kp-card-cover" style="background:${this.escape(card.cover_color)}"></div>`;
      } else if (card.cover_attachment_id) {
        // capa por imagem (anexo marcado como capa)
        const curl = K.ajax_url.replace('ajax.php','attachment.php?id='+card.cover_attachment_id);
        coverHtml = `<div class="kp-card-cover img" style="background-image:url('${curl}')"></div>`;
      }

      let labelsHtml = '';
      if(labels.length){
        labelsHtml = `<div class="kp-card-labels">${labels.map(l=> `<span class="kp-label" style="background:${this.escape(l.color)}" title="${this.escape(l.name)}"></span>`).join('')}</div>`;
      }

      // badges
      let badges = [];
      // Manutenção badge
      if (card.is_maintenance == 1) {
        const mProg = this.maintenanceProgress && this.maintenanceProgress[card.id];
        if (mProg && mProg.total>0) {
          const mDone = mProg.done===mProg.total ? "check-done" : "";
          badges.push(`<span class="kp-badge" style="background:#ffab00;color:#172b4d;font-weight:700"><i class="ti ti-tool"></i> Manutenção ${mProg.done}/${mProg.total}</span>`);
        } else {
          badges.push(`<span class="kp-badge" style="background:#fffae6;color:#172b4d;border:1px solid #ffab00;font-weight:700"><i class="ti ti-tool"></i> Manutenção</span>`);
        }
        // Urgência badge (se tem máquina com urgência)
        const mProgUrgent = this.maintenanceProgress && this.maintenanceProgress[card.id];
        if (mProgUrgent && mProgUrgent.urgent > 0) {
          badges.push(`<span class="kp-badge" style="background:#eb5a46;color:#fff;font-weight:700;border:1px solid #eb5a46"><i class="ti ti-alert-triangle"></i> URGÊNCIA ${mProgUrgent.urgent}</span>`);
        }
        // Anotações badge (ícone quando há anotações nas máquinas do card)
        if (mProg && parseInt(mProg.notes||0) > 0) {
          badges.push(`<span class="kp-badge" title="Este card possui anotações nas máquinas" style="background:#e6f4ff;color:#0050b3;font-weight:700;border:1px solid #91d5ff"><i class="ti ti-notes"></i></span>`);
        }
      }
      // borda vermelha se tem urgência dentro do card (destaque na lista)
      const mProgBorder = this.maintenanceProgress && this.maintenanceProgress[card.id];
      if (mProgBorder && mProgBorder.urgent > 0) {
        div.classList.add('kp-urgent');
        div.dataset.urgent = "1";
        div.style.borderColor = '#eb5a46';
        div.style.borderWidth = '2px';
        div.style.boxShadow = '0 0 0 2px rgba(235,90,70,.18), 0 1px 3px rgba(0,0,0,.12)';
        div.style.background = '#fff5f5';
      }
      // Transfer status badge Retirada (amarelo) / Concluído (verde) — após Finalizar
      const tStat = this.transferStatus && this.transferStatus[card.id];
      if (tStat) {
        const isConcluido = tStat.status === 'concluido';
        const bg = isConcluido ? '#61bd4f' : '#f2d600';
        const fg = isConcluido ? '#fff' : '#172b4d';
        const icon = isConcluido ? 'ti ti-check' : 'ti ti-clock';
        const label = tStat.label || (isConcluido ? 'Concluído' : 'Retirada');
        badges.push(`<span class="kp-badge" style="background:${bg};color:${fg};font-weight:700;border:1px solid ${bg}"><i class="${icon}"></i> ${label}</span>`);
      }
      if (card.due_date) {
        const due = new Date(card.due_date);
        const now = new Date();
        const isOverdue = due < now && !card.is_completed;
        const isSoon = !isOverdue && (due - now) < 24*60*60*1000 && !card.is_completed;
        let cls = '';
        if (card.is_completed) cls='due-done';
        else if (isOverdue) cls='due-overdue';
        else if (isSoon) cls='due-soon';
        const iconDue = card.is_completed ? 'ti ti-check' : 'ti ti-clock';
        badges.push(`<span class="kp-badge ${cls}"><i class="${iconDue}"></i> ${this.formatDateShort(card.due_date)}</span>`);
      }
      // etiqueta com prazo vencido pinta o cartão de vermelho
      const overdueLabel = labels.find(l=> l.due_date && !card.is_completed && new Date(l.due_date) < new Date());
      if (overdueLabel) {
        div.style.borderColor = '#eb5a46';
        div.style.borderWidth = '2px';
        div.style.boxShadow = '0 0 0 2px rgba(235,90,70,.18), 0 1px 3px rgba(0,0,0,.12)';
        div.style.background = '#fff5f5';
        badges.push(`<span class="kp-badge" title="Etiqueta vencida em ${this.escape(overdueLabel.due_date)}" style="background:#eb5a46;color:#fff;font-weight:700"><i class="ti ti-tag"></i> ${this.escape(overdueLabel.name||'Etiqueta')} vencida</span>`);
      }
      let checkBarHtml = '';
      if (prog && prog.total>0) {
        const doneClass = prog.done===prog.total ? 'check-done' : '';
        const pct = Math.round(prog.done/prog.total*100);
        badges.push(`<span class="kp-badge ${doneClass}"><i class="ti ti-checkbox"></i> ${prog.done}/${prog.total}</span>`);
        checkBarHtml = `<div class="kp-card-progress" title="Checklist ${prog.done}/${prog.total}"><div style="width:${pct}%"></div></div>`;
      }
      // barra de prazo — só aparece quando há vencimento
      let dueBarHtml = '';
      if (card.due_date) {
        const due = new Date(card.due_date).getTime();
        const start = card.start_date ? new Date(card.start_date).getTime()
          : (card.date_creation ? new Date(card.date_creation).getTime() : due);
        const now = Date.now();
        if(!isNaN(due) && !isNaN(start)){
          let pct = 0, color = '#0079bf', label = '';
          const days = Math.ceil((due - now) / 86400000);
          if (card.is_completed) {
            pct = 100; color = '#61bd4f';
            label = 'Concluído • vencimento ' + this.formatDateShort(card.due_date);
          } else if (now >= due) {
            pct = 100; color = '#eb5a46';
            label = 'Vencido há ' + Math.max(1, Math.abs(days)) + ' dia(s) • ' + this.formatDateShort(card.due_date);
          } else {
            pct = Math.min(100, Math.max(0, Math.round((now - start) / Math.max(due - start, 1) * 100)));
            color = pct >= 80 ? '#ff991f' : '#0079bf';
            label = (days <= 0 ? 'Vence hoje' : 'Vence em ' + days + ' dia(s)') + ' • ' + this.formatDateShort(card.due_date);
          }
          dueBarHtml = `<div class="kp-card-progress" title="${this.escape(label)}"><div style="width:${pct}%;background:${color}"></div></div>`;
        }
      }
      // fixado no topo
      if (card.is_pinned==1) {
        badges.push(`<span class="kp-badge" title="Fixado no topo da lista" style="background:#091e42;color:#fff;font-weight:700">📌 Fixado</span>`);
      }
      // aguardando aprovação do admin
      if (card.approval_from>0) {
        badges.push(`<span class="kp-badge" title="Movimentação aguardando aprovação de um admin do quadro" style="background:#fffae6;color:#975500;font-weight:700;border:1px solid #ffab00">⏳ Aguardando aprovação</span>`);
      }
      if (card.description && card.description.trim()) badges.push(`<span class="kp-badge"><i class="ti ti-align-left"></i></span>`);
      // Chamado GLPI vinculado
      const tkMap = this.ticketMap && this.ticketMap[card.id];
      if (tkMap) {
        const tkTitle = 'Chamado #' + tkMap.id + (tkMap.name ? ' — ' + tkMap.name : '') + (tkMap.status_label ? ' (' + tkMap.status_label + ')' : '');
        badges.push(`<span class="kp-badge" title="${this.escape(tkTitle)}" style="background:#e6fcff;color:#0747a6;font-weight:700;border:1px solid #4c9aff"><i class="ti ti-ticket"></i> #${tkMap.id}</span>`);
      }
      if (comments>0) badges.push(`<span class="kp-badge"><i class="ti ti-message"></i> ${comments}</span>`);
      if (atts>0) badges.push(`<span class="kp-badge"><i class="ti ti-paperclip"></i> ${atts}</span>`);
      if (members.length) {
        // members avatars handled separately
      }

      let membersHtml = '';
      if(members.length){
        membersHtml = `<div class="kp-card-members">${members.slice(0,4).map(m=>this.avatarHtml(m.picture_url, m.initials, m.name, 'sm')).join('')}${members.length>4?`<span class="kp-avatar sm" style="background:#091e42;color:#fff">+${members.length-4}</span>`:''}</div>`;
      }

      // data de criação — pequenininha no canto inferior direito
      let createdHtml = '';
      if(card.date_creation){
        createdHtml = `<span title="Criado em ${this.formatDate(card.date_creation)}" style="font-size:10px;color:#97a0af;white-space:nowrap;display:inline-flex;align-items:center;gap:3px"><i class="ti ti-clock" style="font-size:11px"></i>${this.formatDateTiny(card.date_creation)}</span>`;
      }

      div.innerHTML = `
        ${coverHtml}
        ${labelsHtml}
        <div class="kp-card-title"><span style="color:#5e6c84;font-weight:700;margin-right:4px">#${card.id}</span>${this.escape(card.name)}</div>
        ${badges.length?`<div class="kp-card-badges">${badges.join('')}</div>`:''}
        ${checkBarHtml}
        ${dueBarHtml}
        ${membersHtml}
        ${createdHtml?`<div style="display:flex;justify-content:flex-end;margin-top:4px">${createdHtml}</div>`:''}
        <button class="kp-card-edit" onclick="event.stopPropagation(); Kanpro.quickEditCard(${card.id}, event)"><i class="ti ti-pencil" style="font-size:14px"></i></button>
      `;
      div.addEventListener('click', ()=> this.openCard(card.id));
      div.addEventListener('dragstart', e=>{
        e.stopPropagation();
        this.dragCard = div;
        div.classList.add('dragging');
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/plain', 'kp-card:'+card.id);
      });
      div.addEventListener('dragend', e=>{
        e.stopPropagation();
        div.classList.remove('dragging');
        div.style.display='';
        this.dragCard=null;
        $$('.kp-list-cards').forEach(c=>c.classList.remove('drag-over'));
      });
      return div;
    },

    enableDragAndDrop(){
      // Cartões
      $$('.kp-list-cards').forEach(container=>{
        container.addEventListener('dragover', e=>{
          e.preventDefault();
          container.classList.add('drag-over');
          const dragging = $('.kp-card.dragging');
          if (!dragging) return;
          const after = this.getDragAfterElement(container, e.clientY);
          // só mexe no DOM se a posição realmente mudou (evita piscar)
          const currentNext = dragging.nextElementSibling;
          if (!after){
            if (dragging.parentElement !== container || currentNext !== null) container.appendChild(dragging);
          } else if (after !== dragging && currentNext !== after) {
            container.insertBefore(dragging, after);
          }
        });
        container.addEventListener('dragleave', e=>{
          if (!container.contains(e.relatedTarget)) container.classList.remove('drag-over');
        });
        container.addEventListener('drop', e=>{
          e.preventDefault();
          container.classList.remove('drag-over');
          const raw = e.dataTransfer.getData('text/plain') || '';
          // aceita só cartão (ignora lista solta sobre os cartões — ids podem colidir)
          const m = /^kp-card:(\d+)$/.exec(raw);
          if (!m) return;
          const cardId = m[1];
          const isCard = this.cards.some(c=> c.id==cardId);
          if (!isCard) return;
          const targetListId = parseInt(container.dataset.listId);
          // calcula posição
          const cardsEls = [...container.querySelectorAll('.kp-card')];
          const pos = cardsEls.findIndex(el=> el.dataset.cardId==cardId);
          this.moveCardTo(cardId, targetListId, pos);
        });
      });

      // Listas (drag no header) — listeners do quadro ligados uma única vez
      // (renderBoard roda a cada polling; sem a trava eles acumulavam)
      const board = $('#kanpro-board');
      if(!this._boardDndBound){
      this._boardDndBound = true;
      board.addEventListener('dragover', e=>{
        if(!this.dragList) return;
        e.preventDefault();
        const after = this.getDragAfterElementBoard(board, e.clientX);
        const currentNext = this.dragList.nextElementSibling;
        if (!after){
          const addListBtn = board.querySelector('.kp-add-list');
          if (currentNext !== addListBtn) board.insertBefore(this.dragList, addListBtn);
        } else if (after !== this.dragList && currentNext !== after) {
          board.insertBefore(this.dragList, after);
        }
      });
      board.addEventListener('drop', e=>{
        if(!this.dragList) return;
        e.preventDefault();
        const ordered = [...board.querySelectorAll('.kp-list')].map(el=> el.dataset.listId);
        this.ajax('reorder_lists', {boards_id: this.board.id, order: JSON.stringify(ordered)}).then(res=>{
          if(!res.success) this.renderBoard();
          else {
            // atualiza ranks local
            ordered.forEach((id,i)=> {
              const l = this.lists.find(x=> x.id==id);
              if(l) l.rank = (i+1)*1024;
            });
          }
        });
      });
      } // _boardDndBound
    },

    getDragAfterElement(container, y){
      const els = [...container.querySelectorAll('.kp-card:not(.dragging)')];
      return els.reduce((closest, child)=>{
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height/2;
        if (offset < 0 && offset > closest.offset) return {offset, element: child};
        return closest;
      }, {offset: Number.NEGATIVE_INFINITY}).element;
    },
    getDragAfterElementBoard(board, x){
      const els = [...board.querySelectorAll('.kp-list:not([style*="opacity"])')];
      return els.reduce((closest, child)=>{
        const box = child.getBoundingClientRect();
        const offset = x - box.left - box.width/2;
        if (offset < 0 && offset > closest.offset) return {offset, element: child};
        return closest;
      }, {offset: Number.NEGATIVE_INFINITY}).element;
    },

    moveCardTo(cardId, targetListId, position){
      // otimista: atualiza local
      const card = this.cards.find(c=> c.id==cardId);
      if(!card) return;
      const oldList = card.plugin_kanpro_lists_id;
      card.plugin_kanpro_lists_id = targetListId;
      // reordena local array para refletir posição
      // remove e reinsere ordenado por visual
      const container = document.querySelector(`.kp-list-cards[data-list-id="${targetListId}"]`);
      const orderedIds = [...container.querySelectorAll('.kp-card')].map(el=> parseInt(el.dataset.cardId));
      // atualiza ranks
      orderedIds.forEach((id,i)=>{
        const c = this.cards.find(x=> x.id==id);
        if(c) c.rank = (i+1)*1024;
      });
      this.ajax('move_card', {cards_id: cardId, target_lists_id: targetListId, position: position}).then(res=>{
        if(!res.success) { // revert
          card.plugin_kanpro_lists_id = oldList;
          this.renderBoard();
        } else {
          if(res.pending_approval){
            card.approval_from = oldList;
            this.renderBoard();
            this.showToast('Movido — invisível até aprovação do admin');
          }
          else this.updateStats();
        }
      });
    },

    /* ---------- listas colapsáveis ---------- */
    collapsedKey(){ return 'kanpro_collapsed_' + (this.board && this.board.id); },
    isListCollapsed(listId){
      try{
        const a = JSON.parse(localStorage.getItem(this.collapsedKey()) || '[]');
        return a.map(String).includes(String(listId));
      }catch(e){ return false; }
    },
    toggleCollapse(listId){
      let a = [];
      try{ a = JSON.parse(localStorage.getItem(this.collapsedKey()) || '[]').map(String); }catch(e){ a = []; }
      const k = String(listId);
      a = a.includes(k) ? a.filter(x=> x!==k) : [...a, k];
      try{ localStorage.setItem(this.collapsedKey(), JSON.stringify(a)); }catch(e){}
      this.renderBoard();
    },
    // ---------- LIST OPERATIONS ----------
    showAddList(){
      const wrap = $('.kp-add-list');
      wrap.querySelector('.kp-add-list-btn').style.display='none';
      const comp = wrap.querySelector('.kp-list-composer');
      comp.style.display='block';
      comp.querySelector('input').focus();
    },
    hideAddList(){
      const wrap = $('.kp-add-list');
      wrap.querySelector('.kp-add-list-btn').style.display='flex';
      wrap.querySelector('.kp-list-composer').style.display='none';
      wrap.querySelector('input').value='';
    },
    confirmAddList(btn){
      const input = btn.closest('.kp-list-composer').querySelector('input');
      const name = input.value.trim() || 'Nova Lista';
      btn.disabled=true;
      this.ajax('add_list', {boards_id: this.board.id, name}).then(res=>{
        btn.disabled=false;
        if(res.success){
          // adiciona local
          this.lists.push({id: res.id, plugin_kanpro_boards_id: this.board.id, name, rank: (this.lists.length+1)*1024, is_archived:0});
          this.renderBoard();
          this.ajax('get_board_activity', {boards_id: this.board.id});
        } else alert(res.msg||'Erro');
      });
    },
    editListTitle(listId){
      if(!this.canEdit) return;
      const el = document.querySelector(`.kp-list[data-list-id="${listId}"]`);
      const title = el.querySelector('.kp-list-title');
      const input = el.querySelector('.kp-list-title-input');
      title.style.display='none';
      input.style.display='block';
      input.value = title.textContent.trim();
      input.focus();
      input.select();
    },
    saveListTitle(listId, input){
      const title = input.previousElementSibling;
      const newName = input.value.trim();
      input.style.display='none';
      title.style.display='block';
      if(!newName || newName===title.textContent.trim()) return;
      title.textContent = newName;
      const list = this.lists.find(l=> l.id==listId);
      if(list) list.name=newName;
      this.ajax('rename_list', {id: listId, name: newName});
    },
    openListMenu(e, listId){
      e.stopPropagation();
      const list = this.lists.find(l=> l.id==listId);
      const activeCount = this.cards.filter(c=> c.plugin_kanpro_lists_id==listId && c.is_archived==0).length;
      const rect = e.target.getBoundingClientRect();
      const apprBtn = this.isBoardAdmin()
        ? `<button class="kp-picker-item" onclick="Kanpro.setListApproval(${listId}, ${list.require_approval?0:1})"><i class="ti ti-shield-check"></i> ${list.require_approval?'Desativar aprovação do admin':'Exigir aprovação do admin'}</button>` : '';
      this.showPicker({
        title: `Ações da lista: ${list.name}`,
        x: rect.left - 280,
        y: rect.top + 28,
        html: `
          <div style="display:grid;gap:4px">
            <button class="kp-picker-item" onclick="Kanpro.editListTitle(${listId}); Kanpro.closePicker()"><i class="ti ti-pencil"></i> Renomear lista</button>
            <button class="kp-picker-item" onclick="Kanpro.copyList(${listId})"><i class="ti ti-copy"></i> Copiar lista</button>
            <button class="kp-picker-item" onclick="Kanpro.moveAllCardsPicker(${listId})"><i class="ti ti-arrow-right"></i> Mover todos os cartões (${activeCount})</button>
            <button class="kp-picker-item" onclick="Kanpro.archiveList(${listId})"><i class="ti ti-archive"></i> Arquivar lista</button>
            ${apprBtn}
            <hr style="margin:4px 0;border:none;border-top:1px solid #dfe1e6">
            <button class="kp-picker-item" style="color:#eb5a46" onclick="Kanpro.archiveAllCards(${listId}, ${activeCount})"><i class="ti ti-box"></i> Arquivar todos os cartões (${activeCount})</button>
            <button class="kp-picker-item" style="color:#eb5a46" onclick="Kanpro.askDeleteList(${listId})"><i class="ti ti-trash"></i> Excluir lista</button>
          </div>`
      });
    },
    moveAllCardsPicker(listId){
      const from = this.lists.find(l=> l.id==listId);
      const dests = this.lists.filter(l=> l.id!=listId && l.is_archived==0);
      if(!dests.length){ alert('Não há outra lista para receber os cartões.'); return; }
      const n = this.cards.filter(c=> c.plugin_kanpro_lists_id==listId && c.is_archived==0).length;
      if(!n){ alert('Esta lista não tem cartões ativos.'); return; }
      this.showPicker({
        title: `Mover ${n} cartão(ões) de "${from.name}"`,
        html: `<div style="display:grid;gap:6px">` + dests.map(l=>
          `<button class="kp-picker-item" onclick="Kanpro.doMoveAllCards(${listId}, ${l.id})"><i class="ti ti-arrow-right"></i> ${this.escape(l.name)}</button>`
        ).join('') + `</div>`
      });
    },
    doMoveAllCards(fromId, toId){
      this.ajax('move_all_cards', {lists_id: fromId, target_lists_id: toId}).then(res=>{
        this.closePicker();
        if(res.success){
          this.showToast(`${res.moved||0} cartão(ões) movidos`);
          location.reload();
        } else alert(res.msg||'Erro');
      });
    },
    async archiveAllCards(listId, count){
      const n = (count !== undefined) ? count : this.cards.filter(c=> c.plugin_kanpro_lists_id==listId && c.is_archived==0).length;
      if(!n){ alert('Esta lista não tem cartões ativos.'); return; }
      if(!await this.kpConfirm(`Arquivar os ${n} cartões desta lista? Eles saem do quadro (podem ser restaurados um a um).`)) return;
      this.ajax('archive_all_cards', {lists_id: listId}).then(res=>{
        this.closePicker();
        if(res.success){ this.showToast(`${res.archived||0} cartão(ões) arquivados`); location.reload(); }
        else alert(res.msg||'Erro');
      });
    },
    setListApproval(listId, val){
      this.ajax('set_list_approval', {lists_id: listId, require: val}).then(res=>{
        this.closePicker();
        if(res.success){
          const l = this.lists.find(x=> x.id==listId);
          if(l) l.require_approval = val;
          this.showToast(val ? 'Aprovação ativada nesta lista' : 'Aprovação desativada');
        } else alert(res.msg||'Erro');
      });
    },
    togglePin(){
      this.ajax('toggle_pin', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){
          const c = this.cards.find(x=> x.id==this.currentCardId);
          if(c) c.is_pinned = res.is_pinned;
          this.refreshCardModal();
        }
      });
    },
    approveCard(){
      this.ajax('approve_card', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){
          const c = this.cards.find(x=> x.id==this.currentCardId);
          if(c) c.approval_from = 0;
          this.showToast('Movimentação aprovada');
          this.refreshCardModal();
        } else alert(res.msg||'Erro');
      });
    },
    devolveCard(){
      if(!confirm('Devolver o cartão para a lista de origem?')) return;
      this.ajax('devolve_card', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){ this.showToast('Cartão devolvido'); location.reload(); }
        else alert(res.msg||'Erro');
      });
    },
    copyList(listId){
      this.closePicker();
      this.ajax('copy_list', {id: listId}).then(res=>{
        if(res.success) location.reload();
      });
    },
    archiveList(listId){
      this.closePicker();
      this.ajax('archive_list', {id: listId}).then(res=>{
        if(res.success){
          this.lists = this.lists.filter(l=> l.id!=listId);
          this.renderBoard();
        }
      });
    },
    deleteList(listId){
      this.closePicker();
      this.ajax('delete_list', {id: listId}).then(res=>{
        if(res.success){
          this.lists = this.lists.filter(l=> l.id!=listId);
          this.cards = this.cards.filter(c=> c.plugin_kanpro_lists_id!=listId);
          this.renderBoard();
        }
      });
    },

    // ---------- CARD OPERATIONS ----------
    showAddCard(listId){
      const listEl = document.querySelector(`.kp-list[data-list-id="${listId}"]`);
      listEl.querySelector('.kp-add-card').style.display='none';
      const comp = listEl.querySelector('.kp-card-composer');
      comp.style.display='block';
      listEl.classList.add('composer-open');
      comp.querySelector('textarea').focus();
      // enter rápido
      const ta = comp.querySelector('textarea');
      ta.onkeydown = (e)=>{
        if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); this.confirmAddCard(listId, comp.querySelector('button'))}
      };
    },
    hideAddCard(listId){
      const listEl = document.querySelector(`.kp-list[data-list-id="${listId}"]`);
      listEl.querySelector('.kp-add-card').style.display='flex';
      listEl.querySelector('.kp-card-composer').style.display='none';
      listEl.classList.remove('composer-open');
      listEl.querySelector('textarea').value='';
    },
    confirmAddCard(listId, btn){
      const listEl = document.querySelector(`.kp-list[data-list-id="${listId}"]`);
      const ta = listEl.querySelector('.kp-card-composer textarea');
      const name = ta.value.trim();
      if(!name) return;
      btn.disabled=true;
      this.ajax('add_card', {lists_id: listId, name}).then(res=>{
        btn.disabled=false;
        if(res.success){
          const newCard = res.card || {id: res.id, plugin_kanpro_lists_id: listId, plugin_kanpro_boards_id: this.board.id, name, rank: 999999, description:'', due_date:null, start_date:null, cover_color:null, is_completed:0, is_archived:0};
          this.cards.push(newCard);
          this.cardLabels[newCard.id]=[];
          this.cardMembers[newCard.id]=[];
          this.commentCounts[newCard.id]=0;
          this.attCounts[newCard.id]=0;
          this.checkProgress[newCard.id]={total:0,done:0};
          this.renderBoard();
          this.updateStats();
          // reabre o composer na mesma lista e rola até o fim para continuar criando embaixo
          const newListEl = document.querySelector(`.kp-list[data-list-id="${listId}"]`);
          if(newListEl){
            const box = newListEl.querySelector('.kp-list-cards');
            if(box) box.scrollTop = box.scrollHeight;
            this.showAddCard(listId);
          }
        } else alert(res.msg||'Erro');
      });
    },
    async quickEditCard(cardId, e){
      e.stopPropagation();
      const card = this.cards.find(c=> c.id==cardId);
      const newName = await this.kpPrompt('Editar título do cartão:', card.name);
      if(newName && newName!==card.name){
        this.ajax('update_card', {id: cardId, name: newName}).then(res=>{
          if(res.success){ card.name=newName; this.renderBoard();}
        });
      }
    },

    // Modal cartão
    openCard(cardId){
      const lc = (this.cards||[]).find(c=> c.id==cardId);
      if(lc && !this.isCardVisible(lc)){ this.showToast('Cartão invisível — aguardando aprovação do admin'); return; }
      this.currentCardId = cardId;
      const modal = $('#kanpro-card-modal');
      modal.style.display='block';
      document.body.style.overflow='hidden';
      // loading
      $('#card-modal-title').textContent='Carregando...';
      $('#card-modal-desc').textContent='';
      $('#card-modal-checklists').innerHTML='';
      $('#card-modal-comments').innerHTML='';
      $('#card-modal-attachments').innerHTML='';
      $('#card-modal-activity').innerHTML='';
      this.ajax('get_card', {cards_id: cardId}).then(res=>{
        if(!res.success){ alert(res.msg); this.closeCardModal(); return; }
        this.renderCardModal(res.data);
      });
    },
    closeCardModal(){
      // salva pendências de diário antes de fechar e limpa timers
      Object.keys(this._diaryTimers||{}).forEach(mid=>{
        clearTimeout(this._diaryTimers[mid]);
        const ta=document.getElementById("maint-diary-"+mid);
        if(ta && this._lastDiarySaved[mid]!==ta.value){
          // dispara save síncrono antes de fechar
          this.ajax("update_maintenance_machine", {id: mid, diary: ta.value});
        }
      });
      this._diaryTimers={};
      this._diarySaving={};
      $('#kanpro-card-modal').style.display='none';
      document.body.style.overflow='';
      this.currentCardId=null;
      this.closePicker();
    },
    renderCardModal(data){
      $('#card-modal-title').innerHTML = `<span style="color:#5e6c84;font-weight:700;margin-right:6px">#${data.id}</span>${this.escape(data.name)}`;
      $('#card-modal-listname').textContent = data.list_name||'Lista';
      const createdEl = $('#card-modal-created');
      if(createdEl) createdEl.textContent = data.date_creation ? ` • 🕐 Criado em ${this.formatDate(data.date_creation)}` : '';
      $('#card-modal-title').onclick = ()=> this.editCardTitle();
      // cover
      const cover = $('#card-modal-cover');
      if(data.cover_color){
        cover.style.height='72px';
        cover.style.background=data.cover_color;
      } else if(data.cover_attachment_id){
        // TODO fetch attachment url
        cover.style.height='160px';
        cover.style.background='#dfe1e6';
      } else { cover.style.height='0'; cover.style.background='transparent'; }

      // badges top (members, labels, dates)
      // members
      const membersWrap = $('#card-modal-members');
      const membersList = $('#card-modal-members-list');
      if(data.members && data.members.length){
        membersWrap.style.display='block';
        membersList.innerHTML = data.members.map(m=>{
          const initials = (m.firstname?.[0]||m.name?.[0]||'?').toUpperCase();
          return this.avatarHtml(m.picture_url, initials, m.realname||m.name);
        }).join('') + `<button onclick="Kanpro.openMembersPicker()" style="width:28px;height:28px;border-radius:50%;border:none;background:#dfe1e6;cursor:pointer"><i class="ti ti-plus"></i></button>`;
      } else { membersWrap.style.display='none'; membersList.innerHTML=''; }

      // labels
      const labelsWrap = $('#card-modal-labels');
      const labelsList = $('#card-modal-labels-list');
      if(data.labels && data.labels.length){
        labelsWrap.style.display='block';
        labelsList.innerHTML = data.labels.map(l=>`<span style="background:${this.escape(l.color)};color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700">${this.escape(l.name||' ')}</span>`).join('') + `<button onclick="Kanpro.openLabelsPicker()" style="background:#dfe1e6;border:none;padding:4px 8px;border-radius:4px;cursor:pointer"><i class="ti ti-plus"></i></button>`;
      } else { labelsWrap.style.display='none'; }

      // dates
      const datesWrap = $('#card-modal-dates');
      const datesVal = $('#card-modal-dates-val');
      if(data.due_date || data.start_date){
        datesWrap.style.display='block';
        const due = data.due_date ? this.formatDate(data.due_date) + (data.is_completed? ' ✅ Concluído':'') : '';
        const start = data.start_date ? this.formatDate(data.start_date) + ' → ' : '';
        datesVal.innerHTML = start + due + ` <label style="margin-left:8px"><input type="checkbox" ${data.is_completed?'checked':''} onchange="Kanpro.toggleComplete(${data.id}, this.checked)"> Concluído</label>`;
        datesVal.style.cursor='pointer';
        datesVal.onclick = ()=> this.openDatesPicker();
      } else { datesWrap.style.display='none'; }

      // chamado GLPI vinculado
      const tkWrap = $('#card-modal-ticket');
      const tkVal = $('#card-modal-ticket-val');
      if (tkWrap && tkVal) {
        const tk = data.ticket;
        if (tk) {
          tkWrap.style.display='block';
          const stColors = {1:'#ff991f',2:'#0079bf',3:'#6554c0',4:'#ffab00',5:'#61bd4f',6:'#6b778c'};
          const stBg = stColors[tk.status] || '#6b778c';
          const root = this.ajax_url.replace(/\/plugins\/kanpro\/front\/ajax\.php$/, '');
          const tkUrl = root + '/front/ticket.form.php?id=' + tk.id;
          tkVal.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:8px 10px">
              <span style="background:${stBg};color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">${this.escape(tk.status_label||('Status '+tk.status))}</span>
              <a href="${tkUrl}" target="_blank" style="font-weight:700;color:#0747a6;font-size:13px"><i class="ti ti-ticket"></i> #${tk.id}${tk.name ? ' — ' + this.escape(tk.name) : ''}${tk.restricted ? ' (sem acesso ao conteúdo)' : ''}</a>
              <button onclick="Kanpro.unlinkTicket()" title="Desvincular chamado" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#eb5a46;font-size:12px"><i class="ti ti-unlink"></i> Desvincular</button>
            </div>`;
        } else { tkWrap.style.display='none'; tkVal.innerHTML=''; }
      }

      // description
      const descEl = $('#card-modal-desc');
      const descEdit = $('#card-desc-edit');
      descEl.innerHTML = data.description ? this.parseMarkdown(data.description) : 'Adicionar uma descrição mais detalhada...';
      descEl.style.opacity = data.description ? '1' : '0.6';
      descEl.style.fontStyle = data.description ? 'normal' : 'italic';
      descEdit.value = data.description || '';

      // checklists
      const clContainer = $('#card-modal-checklists');
      clContainer.innerHTML='';
      (data.checklists||[]).forEach(cl=>{
        const done = cl.items.filter(i=> i.is_checked==1).length;
        const total = cl.items.length;
        const pct = total? Math.round(done/total*100):0;
        const div = document.createElement('div');
        div.className='kp-checklist';
        div.innerHTML = `
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong><i class="ti ti-checkbox"></i> ${this.escape(cl.name)}</strong>
            <button onclick="Kanpro.deleteChecklist(${cl.id})" style="background:none;border:none;cursor:pointer;color:#6b778c">Excluir</button>
          </div>
          ${total?`<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><span style="font-size:11px">${pct}%</span><div class="kp-progress"><div class="kp-progress-bar" style="width:${pct}%"></div></div></div>`:''}
          <div class="kp-checkitems" data-cl-id="${cl.id}">
            ${cl.items.map(it=>`
              <div class="kp-checkitem ${it.is_checked?'checked':''}" data-item-id="${it.id}">
                <input type="checkbox" ${it.is_checked?'checked':''} onchange="Kanpro.toggleCheckItem(${it.id}, this.checked)">
                <span style="flex:1;cursor:pointer" onclick="Kanpro.editCheckItem(${it.id})">${this.escape(it.name)}</span>
                <button onclick="Kanpro.deleteCheckItem(${it.id})" style="background:none;border:none;cursor:pointer;opacity:.6"><i class="ti ti-trash"></i></button>
              </div>`).join('')}
          </div>
          <div style="display:flex;gap:8px;margin-top:8px">
            <input type="text" placeholder="Adicionar um item" style="flex:1;padding:6px 8px;border:1px solid #dfe1e6;border-radius:4px" onkeydown="if(event.key==='Enter') Kanpro.addCheckItem(${cl.id}, this)">
            <button onclick="Kanpro.addCheckItem(${cl.id}, this.previousElementSibling)" style="background:#0079bf;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer">Adicionar</button>
          </div>
        `;
        clContainer.appendChild(div);
      });

      // manutenção - renderiza painel dedicado
      this.renderMaintenanceInModal(data);

      // attachments
      const attContainer = $('#card-modal-attachments');
      if(data.attachments && data.attachments.length){
        attContainer.innerHTML = data.attachments.map(a=>{
          const url = K.ajax_url.replace('ajax.php','attachment.php?id='+a.id);
          const isImage = a.mime && a.mime.indexOf('image/')===0;
          const isPdf = a.mime === 'application/pdf';
          const escapedName = this.escape(a.name).replace(/'/g,"\\'");
          let thumb;
          if(isImage){
            thumb = `<img src="${url}" alt="${this.escape(a.name)}" onclick="Kanpro.previewImage('${url}', '${escapedName}')" style="width:44px;height:44px;object-fit:cover;border-radius:4px;cursor:pointer;flex-shrink:0">`;
          } else if(isPdf){
            thumb = `<div onclick="Kanpro.previewPdf('${url}', '${escapedName}')" style="width:44px;height:44px;background:#eb5a46;border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;cursor:pointer;color:#fff"><i class="ti ti-file-type-pdf" style="font-size:20px"></i></div>`;
          } else {
            thumb = `<div style="width:44px;height:44px;background:#dfe1e6;border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ti ti-file"></i></div>`;
          }
          return `
          <div style="display:flex;gap:10px;padding:8px;background:#fff;border-radius:4px;align-items:center;box-shadow:0 1px 1px rgba(9,30,66,.13)">
            ${thumb}
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${this.escape(a.name)}</div>
              <div style="font-size:11px;color:#5e6c84">${this.formatFileSize(a.filesize)} • ${this.formatDate(a.date_creation)} • <a href="${url}" target="_blank">Abrir</a> • <a href="#" onclick="Kanpro.makeCover(${a.id});return false">Tornar capa</a></div>
            </div>
            <button onclick="Kanpro.deleteAttachment(${a.id})" style="background:none;border:none;cursor:pointer;color:#eb5a46"><i class="ti ti-trash"></i></button>
          </div>
        `;}).join('');
      } else attContainer.innerHTML='<div style="color:#5e6c84;font-size:13px">Nenhum anexo ainda.</div>';

      // comments
      const comContainer = $('#card-modal-comments');
      comContainer.innerHTML = (data.comments||[]).map(c=>{
        const pinned = c.is_pinned==1;
        return `
        <div style="display:flex;gap:8px">
          <div>${this.avatarHtml(c.picture_url, (c.firstname?.[0]||c.user_name?.[0]||'?').toUpperCase(), c.realname||c.firstname||c.user_name||'Usuário')}</div>
          <div style="flex:1;background:#fff;padding:8px 12px;border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13);${pinned?'border:1px solid #ffab00;background:#fffae6;':''}">
            <div style="font-weight:700;font-size:13px">${this.escape(c.realname||c.firstname||c.user_name||'Usuário')} <span style="font-weight:400;color:#5e6c84;font-size:11px">${this.formatDate(c.date_creation)}</span>${pinned?' <span style="background:#ffab00;color:#172b4d;padding:1px 8px;border-radius:10px;font-size:10px">📌 Fixado</span>':''}</div>
                        <div style="margin-top:4px;word-break:break-word">${this.highlightMentions(this.parseMarkdown(c.content))}</div>

            <div style="margin-top:6px;display:flex;gap:8px;font-size:12px"><a href="#" onclick="Kanpro.editComment(${c.id});return false">Editar</a> <a href="#" onclick="Kanpro.pinComment(${c.id});return false">${pinned?'Desafixar':'Fixar'}</a> <a href="#" onclick="Kanpro.deleteComment(${c.id});return false" style="color:#eb5a46">Excluir</a></div>
          </div>
        </div>
      `;}).join('') || '<div style="color:#5e6c84;font-size:13px">Seja o primeiro a comentar</div>';

      // activity
      const actContainer = $('#card-modal-activity');
      actContainer.innerHTML = (data.activities||[]).map(a=>`
        <div style="display:flex;gap:8px;font-size:12px;color:#5e6c84">
          <div class="kp-avatar sm">${this.escape((a.firstname?.[0]||a.user_name?.[0]||'?').toUpperCase())}</div>
          <div><strong>${this.escape(a.realname||a.firstname||a.user_name||'Sistema')}</strong> ${this.escape(a.details||a.action)} <span style="color:#97a0af">${this.formatDate(a.date_creation)}</span></div>
        </div>
      `).join('');
      actContainer.style.display='none'; // começa oculto, botão mostra

      // movimentação — linha do tempo (criação, mudanças de lista, arquivamento)
      const movesContainer = $('#card-modal-moves');
      if(movesContainer){
        const moves = (data.activities||[]).filter(a=> ['card_move','card_create','card_archive'].includes(a.action));
        movesContainer.innerHTML = moves.map((a,i)=>{
          const clean = String(a.details||a.action).replace(/^\[from:\d+\]\s*/, '');
          const who = this.escape(a.realname||a.firstname||a.user_name||'Sistema');
          const dot = a.action==='card_create' ? '#61bd4f' : (a.action==='card_archive' ? '#ff5630' : '#0079bf');
          const last = i===moves.length-1;
          return `<div style="display:flex;gap:10px">
            <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:14px">
              <span style="width:10px;height:10px;border-radius:50%;background:${dot};margin-top:4px;flex-shrink:0"></span>
              ${last?'':'<span style="width:2px;flex:1;background:#dfe1e6;min-height:12px"></span>'}
            </div>
            <div style="padding-bottom:12px;min-width:0">
              <div style="font-size:13px;color:#172b4d">${this.escape(clean)}</div>
              <div style="font-size:11px;color:#5e6c84">${who} • ${this.formatDate(a.date_creation)}</div>
            </div>
          </div>`;
        }).join('') || '<div style="color:#5e6c84;font-size:13px">Sem movimentações registradas ainda.</div>';
      }

      // atualiza cache local
      const idx = this.cards.findIndex(x=> x.id==data.id);
      if(idx>=0){
        this.cards[idx].name=data.name;
        this.cards[idx].description=data.description;
        this.cards[idx].due_date=data.due_date;
        this.cards[idx].start_date=data.start_date;
        this.cards[idx].cover_color=data.cover_color;
        this.cards[idx].is_completed=data.is_completed;
        this.cards[idx].is_maintenance=data.is_maintenance||0;
      }
      // atualiza maps
      this.cardLabels[data.id] = data.labels||[];
      this.cardMembers[data.id] = data.members?.map(m=>({users_id:m.id||m.users_id, name:m.realname||m.name, initials:(m.firstname?.[0]||"?").toUpperCase()})) || [];
      // maintenance progress
      if(data.maintenance_progress) this.maintenanceProgress[data.id] = data.maintenance_progress;
      else if(data.is_maintenance && data.maintenance_machines){
        const total = data.maintenance_machines.length;
        const done = data.maintenance_machines.filter(m=> m.is_done==1).length;
        this.maintenanceProgress[data.id] = {total, done, percent: total? Math.round(done/total*100):0};
      }
      // atualiza botão manutenção
      const maintBtn = document.getElementById("kp-maintenance-btn");
      if(maintBtn){
        if(data.is_maintenance){
          maintBtn.innerHTML = "<i class=\"ti ti-tool\"></i> Gerenciar Manutenção";
          maintBtn.style.background = "#ffab00";
          maintBtn.style.color = "#fff";
          maintBtn.style.border = "1px solid #ff991f";
        } else {
          maintBtn.innerHTML = "<i class=\"ti ti-tool\"></i> Manutenção";
          maintBtn.style.background = "#fffae6";
          maintBtn.style.color = "#172b4d";
          maintBtn.style.border = "1px solid #ffab00";
        }
      }
      // atualiza botão fixar
      const pinBtn = document.getElementById("kp-pin-btn");
      if(pinBtn){
        pinBtn.innerHTML = data.is_pinned==1
          ? '<i class="ti ti-pinned-off"></i> Desafixar'
          : '<i class="ti ti-pin"></i> Fixar no topo';
      }
      // aprovação pendente
      const apprBox = document.getElementById("card-modal-approval");
      if(apprBox){
        if(data.approval_from>0){
          const fromList = (this.lists||[]).find(l=> l.id==data.approval_from);
          const fromName = fromList ? this.escape(fromList.name) : ('lista #' + data.approval_from);
          apprBox.innerHTML = `
            <div style="background:#fffae6;border:1px solid #ffab00;border-radius:8px;padding:12px">
              <div style="font-weight:700;color:#975500;font-size:13px"><i class="ti ti-clock"></i> Aguardando aprovação</div>
              <div style="font-size:12px;color:#5e6c84;margin:4px 0 8px">Veio de <strong>${fromName}</strong>. Membros movem, só admin do quadro aprova.</div>
              ${this.isBoardAdmin() ? `<div style="display:flex;gap:8px">
                <button onclick="Kanpro.approveCard()" style="background:#006644;color:#fff;border:none;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:700;font-size:12px"><i class="ti ti-check"></i> Aprovar</button>
                <button onclick="Kanpro.devolveCard()" style="background:#fff;border:1px solid #ffab00;color:#975500;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:700;font-size:12px"><i class="ti ti-arrow-back"></i> Devolver</button>
              </div>` : `<div style="font-size:11px;color:#975500">Aguarde um admin do quadro.</div>`}
            </div>`;
        } else apprBox.innerHTML = '';
      }
      // re-render board silencioso (mantém modal)
      this.renderBoardQuick();
    },

    // ==================== MANUTENÇÃO ====================
    maintStatusMeta(rawStatus){
      let s = String(rawStatus||"").trim().toLowerCase();
      if(s==="pending") s="pendente";
      if(s==="defect"||s==="defeito"||s==="nok") s="inservivel";
      if(s==="garantia") return {status:s, label:"🛡️ Garantia", color:"#0052cc", text:"#fff"};
      if(s==="ok") return {status:s, label:"✅ OK", color:"#61bd4f", text:"#fff"};
      if(s==="inservivel") return {status:s, label:"❌ Inservível", color:"#eb5a46", text:"#fff"};
      if(s==="pendente") return {status:s, label:"⏳ Pendente", color:"#ffab00", text:"#172b4d"};
      return {status:"", label:"— Selecione *", color:"#ffebe6", text:"#bf2600"};
    },
    maintMachineById(mid){
      const ms = (this._lastModalData && this._lastModalData.maintenance_machines) || [];
      return ms.find(m=> String(m.id)===String(mid)) || null;
    },
    patchMaintUI(mid){
      // atualiza visuals a partir do cache local SEM rebuildar innerHTML:
      // não pisca, não fecha <select> aberto, não tira o foco do relatório
      const data = this._lastModalData;
      if(!data || !data.maintenance_machines) return;
      const machines = data.maintenance_machines;
      const total = machines.length;
      const done = machines.filter(m=>m.is_done==1).length;
      const pct = total ? Math.round(done/total*100) : 0;
      const allDone = total>0 && done===total;
      const lab = document.getElementById('maint-progress-label');
      if(lab) lab.textContent = `${done}/${total} • ${pct}%`;
      const pctEl = document.getElementById('maint-progress-pct');
      if(pctEl) pctEl.textContent = pct+'%';
      const bar = document.getElementById('maint-progress-bar');
      if(bar){ bar.style.width = pct+'%'; bar.style.background = allDone ? '#61bd4f' : '#ffab00'; }
      if(mid==null) return;
      const m = machines.find(x=> String(x.id)===String(mid));
      const row = document.querySelector(`.kp-maint-machine[data-mid="${mid}"]`);
      if(!m || !row) return;
      const isDone = m.is_done==1;
      const meta = this.maintStatusMeta(m.status);
      const isUrgent = String(m.is_urgent)==="1" || m.is_urgent===1;
      row.style.borderLeft = `4px solid ${isUrgent ? "#eb5a46" : (!meta.status ? "#eb5a46" : (isDone ? "#61bd4f" : "#ffab00"))}`;
      const head = row.firstElementChild;
      if(head) head.style.background = isUrgent ? "#ffecec" : (isDone ? "#e3fcef" : "#f4f5f7");
      const pill = document.getElementById('maint-row-stpill-'+mid);
      if(pill){ pill.textContent = meta.label; pill.style.background = meta.color; pill.style.color = meta.text; }
      const foot = document.getElementById('maint-row-foot-'+mid);
      if(foot) foot.innerHTML = isDone ? '<span style="color:#61bd4f;font-weight:600">✔ Concluída</span>' : '<span style="color:#ff991f">Em andamento</span>';
    },
    renderMaintenanceInModal(data){
      this._lastModalData = data;
      const wrap = document.getElementById("card-modal-maintenance");
      if(!wrap) return;
      const isMaint = !!(data.is_maintenance && data.is_maintenance==1);
      if(!isMaint){
        wrap.style.display="none";
        wrap.innerHTML="";
        return;
      }
      wrap.style.display="block";
      // preserva texto digitado ainda não salvo — re-render não pode apagar digitação
      const pendingDiaries = {};
      wrap.querySelectorAll('textarea[id^="maint-diary-"]').forEach(ta=>{
        pendingDiaries[ta.id.replace('maint-diary-','')] = ta.value;
      });
      // preserva foco + posição do cursor (re-render não pode tirar o usuário do relatório)
      const ae = document.activeElement;
      const focusId = (ae && wrap.contains(ae) && ae.id) ? ae.id : null;
      const focusSel = (focusId && typeof ae.selectionStart === 'number') ? {s: ae.selectionStart, e: ae.selectionEnd} : null;
      const machines = data.maintenance_machines || [];
      const progress = data.maintenance_progress || {total:machines.length, done: machines.filter(m=>m.is_done==1).length, percent: 0};
      if(progress.total && !progress.percent){
        progress.percent = progress.total? Math.round(progress.done/progress.total*100):0;
      }
      const pct = progress.percent || 0;
      const total = progress.total || 0;
      const done = progress.done || 0;
      const allDone = total>0 && done===total;
      // status obrigatório — conta faltantes
      const missingStatus = machines.filter(m=> !m.status || String(m.status).trim()==="").length;
      const hasMissing = missingStatus>0;
      const pendenteCount = machines.filter(m=> (m.status||"")==="pendente" || (m.status||"")==="pending").length;
      // inventário em massa
      const needsCount = machines.filter(m=> String(m.needs_inventory)==="1" || m.needs_inventory===1).length;
      const allNeed = total>0 && needsCount===total;
      // seleção em massa
      const selectMode = !!this._maintSelectMode;
      if(!this._maintSelected) this._maintSelected = new Set();
      const selCount = [...this._maintSelected].filter(id=> machines.some(m=> String(m.id)===String(id))).length;
      // botão finalizar: desabilita apenas se faltar status
      let finalizeBtnHtml = "";
      if (hasMissing) {
        finalizeBtnHtml = `<button disabled title="Selecione o Status Final de todas as máquinas (${missingStatus}/${total})" style="background:#dfe1e6;color:#5e6c84;border:none;padding:6px 12px;border-radius:4px;font-weight:600;font-size:12px;opacity:.6;cursor:not-allowed;white-space:nowrap;flex-shrink:0"><i class="ti ti-alert-circle"></i> FINALIZAR * ${missingStatus} sem status</button>`;
      } else if (allDone) {
        finalizeBtnHtml = `<button onclick="Kanpro.finalizeMaintenance()" style="background:#00b8d9;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:700;font-size:12px;white-space:nowrap;flex-shrink:0"><i class="ti ti-check"></i> FINALIZAR</button>`;
      } else {
        const pendenteInfo = pendenteCount>0 ? ` • ${pendenteCount} pendente(s) → novo card` : "";
        finalizeBtnHtml = `<button onclick="Kanpro.finalizeMaintenance()" title="Nem todos estão como 'Feito' — pendentes ficarão em novo card" style="background:#ffab00;color:#172b4d;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:700;font-size:12px;white-space:nowrap;flex-shrink:0"><i class="ti ti-check"></i> FINALIZAR (${pct}%${pendenteInfo})</button>`;
      }
      let html = `
        <div style="background:#fff;border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13);overflow:hidden;margin-bottom:16px;border-left:4px solid #ffab00">
          <div style="padding:12px 16px;background:#fffae6;border-bottom:1px solid #ffecb5;display:flex;align-items:center;gap:10px 12px;justify-content:space-between;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0"><i class="ti ti-tool" style="font-size:18px;color:#ff991f"></i><strong style="color:#172b4d;white-space:nowrap">Manutenção — Checklist por Máquina</strong> <span id="maint-progress-label" style="background:#ffab00;color:#172b4d;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap">${done}/${total} • ${pct}%</span>${hasMissing?` <span style="background:#eb5a46;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap">${missingStatus} sem Status</span>`:""}</div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <button onclick="Kanpro.openMaintenanceSetup()" style="background:#fff;border:1px solid #dfe1e6;padding:6px 10px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap;flex-shrink:0"><i class="ti ti-plus"></i> ${total? "Adicionar" : "Configurar"} máquinas</button>
              ${total? `<button onclick="Kanpro.setAllNeedsInventory(${allNeed?0:1})" title="${allNeed?"Tirar 'precisa inventariar' de todas as máquinas":"Marcar todas as máquinas como 'precisa inventariar'"}" style="background:${allNeed?"#fff":"#ede9fe"};border:1px solid #6554c0;color:#5e35b1;padding:6px 10px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap;flex-shrink:0"><i class="ti ti-clipboard-list"></i> ${allNeed?"Tirar 'precisa' de todas":"📋 Todas precisam inventariar"}</button>`:""}
              ${total? `<button onclick="Kanpro.toggleMaintSelectMode()" title="Selecionar máquinas para ação em massa" style="background:${selectMode?"#0079bf":"#fff"};border:1px solid #0079bf;color:${selectMode?"#fff":"#0079bf"};padding:6px 10px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap;flex-shrink:0"><i class="ti ti-checkbox"></i> ${selectMode?"Cancelar":"Selecionar"}</button>`:""}
              ${total? `<button onclick="Kanpro.printInfoSheet()" title="Imprimir folha informativa das máquinas (A4, envio automático)" style="background:#fff;border:1px solid #0052cc;color:#0052cc;padding:6px 10px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap;flex-shrink:0"><i class="ti ti-printer"></i> Folha</button>`:""}
              ${finalizeBtnHtml}
            </div>
          </div>
          ${selectMode? `
          <div style="padding:8px 16px;background:#e6fcff;border-bottom:1px solid #b3f0ff;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px">
            <strong><span id="maint-sel-count">${selCount}</span> selecionada(s)</strong>
            <button onclick="Kanpro.maintSelectAll(true)" style="background:#fff;border:1px solid #dfe1e6;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">Todas</button>
            <button onclick="Kanpro.maintSelectAll(false)" style="background:#fff;border:1px solid #dfe1e6;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">Limpar</button>
            <select id="maint-bulk-status" style="padding:5px 8px;border:1px solid #dfe1e6;border-radius:4px;font-size:12px;background:#fff">
              <option value="">— Status Final —</option>
              <option value="garantia">🛡️ Garantia</option>
              <option value="ok">✅ OK</option>
              <option value="inservivel">❌ Inservível</option>
              <option value="pendente">⏳ Pendente</option>
            </select>
            <button onclick="Kanpro.bulkApplyStatus()" style="background:#0079bf;color:#fff;border:none;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700">Aplicar status</button>
            <button onclick="Kanpro.bulkSetDone(1)" style="background:#61bd4f;color:#fff;border:none;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700">✓ Feito</button>
            <button onclick="Kanpro.bulkSetDone(0)" style="background:#fff;border:1px solid #dfe1e6;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:12px">○ Desmarcar</button>
          </div>`:""}
          ${hasMissing? `<div style="padding:8px 16px;background:#ffebe6;border-bottom:1px solid #ffbdad;color:#bf2600;font-size:12px"><i class="ti ti-alert-triangle"></i> <strong>Status Final obrigatório:</strong> selecione Garantia / Ok / Inservível / Pendente para todas as máquinas antes de finalizar. Faltam ${missingStatus}.</div>` : ""}
          ${pendenteCount>0? `<div style="padding:8px 16px;background:#e6fcff;border-bottom:1px solid #b3f0ff;color:#0052cc;font-size:11px"><i class="ti ti-info-circle"></i> ${pendenteCount} máquina(s) como <strong>Pendente</strong> ficarão em <strong>novo card</strong> após finalizar — as demais (Garantia/Ok/Inservível) irão para o termo e podem ser levadas.</div>` : ""}
          ${total? `<div style="padding:10px 16px"><div style="display:flex;align-items:center;gap:8px"><span id="maint-progress-pct" style="font-size:11px;color:#5e6c84;min-width:36px">${pct}%</span><div class="kp-progress" style="flex:1;height:8px"><div id="maint-progress-bar" class="kp-progress-bar" style="width:${pct}%;background:${allDone?"#61bd4f":"#ffab00"}"></div></div></div></div>` : ""}
        </div>
      `;
      if(total===0){
        html += `
          <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 1px rgba(9,30,66,.13);text-align:center">
            <div style="color:#5e6c84;font-size:14px;margin-bottom:8px"><i class="ti ti-info-circle"></i> Nenhuma máquina cadastrada ainda.</div>
            <div style="color:#6b778c;font-size:13px;margin-bottom:12px">Informe a quantidade e modelo das máquinas para gerar a checklist enumerada.</div>
            <div style="display:flex;gap:8px;justify-content:center">
              <button onclick="Kanpro.openMaintenanceSetup()" style="background:#ffab00;color:#172b4d;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-weight:700"><i class="ti ti-plus"></i> Configurar Máquinas</button>
            </div>
            <div style="margin-top:12px;background:#f4f5f7;padding:10px;border-radius:6px;text-align:left;font-size:12px;color:#5e6c84">
              <strong>Exemplos de entrada:</strong><br>
              <code style="background:#fff;padding:2px 6px;border-radius:4px">10x Notebook Positivo</code> <code style="background:#fff;padding:2px 6px;border-radius:4px">5x Notebook Ultra</code> <code style="background:#fff;padding:2px 6px;border-radius:4px">10x Notebook Multilaser</code><br>
              <span style="font-size:11px">Pode colar em uma linha: <em>10x Notebook Positivo, 10x Notebook Ultra, 10x Notebook Multilaser</em> — o sistema enumera de 1 em diante automaticamente.</span>
            </div>
          </div>
        `;
        html += `<div style="text-align:center;margin-top:10px"><a href="#" onclick="Kanpro.revertMaintenance();return false" style="color:#eb5a46;font-size:12px">Reverter para card normal</a></div>`;
        wrap.innerHTML = html;
        return;
      }
      html += `<div style="display:grid;gap:10px">`;
      machines.forEach(m=>{
        const isDone = m.is_done==1;
        const rawStatus = (m.status||"").toString().trim().toLowerCase();
        // normaliza legado
        let status = rawStatus;
        if(status==="pending") status="pendente";
        if(status==="defect" || status==="defeito" || status==="nok") status="inservivel";
        const diary = m.diary||"";
        let statusLabel="", statusColor="#dfe1e6", statusTextColor="#5e6c84";
        if(status==="garantia"){ statusLabel="🛡️ Garantia"; statusColor="#0052cc"; statusTextColor="#fff"; }
        else if(status==="ok"){ statusLabel="✅ OK"; statusColor="#61bd4f"; statusTextColor="#fff"; }
        else if(status==="inservivel"){ statusLabel="❌ Inservível"; statusColor="#eb5a46"; statusTextColor="#fff"; }
        else if(status==="pendente"){ statusLabel="⏳ Pendente"; statusColor="#ffab00"; statusTextColor="#172b4d"; }
        else { statusLabel="— Selecione *"; statusColor="#ffebe6"; statusTextColor="#bf2600"; }
        const isUrgent = String(m.is_urgent)==="1" || m.is_urgent===1;
        const urgBg = isUrgent ? "#eb5a46" : "#fff";
        const urgColor = isUrgent ? "#fff" : "#bf2600";
        const urgBorder = isUrgent ? "1px solid #eb5a46" : "1px solid #ffbdad";
        const urgLabel = isUrgent ? "🔥 Urgência" : "Urgência";
        const urgIcon = isUrgent ? "ti ti-alert-triangle" : "ti ti-flag";
        const borderColor = isUrgent ? "#eb5a46" : (!status ? "#eb5a46" : (isDone ? "#61bd4f" : "#ffab00"));
        const selectBorder = !status ? "2px solid #eb5a46" : `1px solid ${statusColor}`;
        const statusSelectBg = !status ? "#fff" : statusColor;
        const statusSelectColor = !status ? "#bf2600" : statusTextColor;
        const isInventoried = String(m.is_inventoried)==="1" || m.is_inventoried===1;
        const needsInv = String(m.needs_inventory)==="1" || m.needs_inventory===1;
        const needBg = needsInv ? "#ede9fe" : "#fff";
        const needColor = needsInv ? "#5e35b1" : "#5e6c84";
        const needBorder = needsInv ? "1px solid #6554c0" : "1px solid #dfe1e6";
        const needLabel = needsInv ? "📋 Precisa inventariar" : "○ Inventariar?";
        const invBg = isInventoried ? "#61bd4f" : "#fff";
        const invColor = isInventoried ? "#fff" : "#5e6c84";
        const invBorder = isInventoried ? "1px solid #61bd4f" : "1px solid #dfe1e6";
        const invLabel = isInventoried ? "✓ Inventariado" : "Inventariado?";
        const invIcon = isInventoried ? "ti ti-check" : "ti ti-clipboard";
        html += `
          <div class="kp-maint-machine${isUrgent?' urgent':''}" data-mid="${m.id}" style="background:${isUrgent?"#fff1f0":"#fff"};border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13);border-left:4px solid ${borderColor};overflow:hidden">
            <div style="padding:10px 12px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px;background:${isUrgent?"#ffecec":isDone?"#e3fcef":"#f4f5f7"};flex-wrap:wrap">
              <div style="display:flex;align-items:center;gap:8px;flex:1 1 220px;min-width:0">
                ${selectMode ? `<input type="checkbox" data-mid="${m.id}" ${this._maintSelected.has(String(m.id))?"checked":""} onchange="Kanpro.toggleMaintSelect(${m.id}, this.checked)" title="Selecionar máquina" style="width:18px;height:18px;accent-color:#0079bf;flex-shrink:0;cursor:pointer">` : ""}
                <span style="background:${isUrgent?"#eb5a46":"#091e42"};color:#fff;min-width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0">#${m.seq}</span>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;color:#172b4d;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${this.escape(m.model)} <small style="color:#5e6c84">#${m.seq}</small>${isUrgent?`<span style="background:#eb5a46;color:#fff;padding:1px 6px;border-radius:10px;font-size:10px;margin-left:6px">URGÊNCIA</span>`:""}</div>
                  <div style="font-size:11px;color:#5e6c84;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${this.escape(m.label)}</div>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:6px;flex:0 1 auto;flex-wrap:wrap;justify-content:flex-end;align-content:flex-start;max-width:100%">
                <label style="display:flex;align-items:center;gap:4px;background:#fff;padding:4px 8px;border-radius:20px;border:1px solid #dfe1e6;cursor:pointer;font-size:12px;white-space:nowrap;flex-shrink:0${status==="pendente"?";opacity:.55":""}">
                  <input type="checkbox" ${isDone?"checked":""} ${status==="pendente"?"disabled title='Máquina Pendente não pode ser marcada como Feita'":""} onchange="Kanpro.toggleMaintenanceDone(${m.id}, this.checked)" style="accent-color:#61bd4f"> Feito
                </label>
                <select onchange="Kanpro.updateMaintenanceStatus(${m.id}, this.value)" style="padding:6px 10px;border-radius:20px;border:${selectBorder};background:${statusSelectBg};color:${statusSelectColor};font-size:11px;font-weight:700;cursor:pointer;min-width:130px;flex-shrink:0">
                  <option value="" ${!status?"selected":""}>— Status Final *</option>
                  <option value="garantia" ${status==="garantia"?"selected":""}>🛡️ Garantia</option>
                  <option value="ok" ${status==="ok"?"selected":""}>✅ OK</option>
                  <option value="inservivel" ${status==="inservivel"?"selected":""}>❌ Inservível</option>
                  <option value="pendente" ${status==="pendente"?"selected":""}>⏳ Pendente</option>
                </select>
                <button onclick="Kanpro.toggleMaintenanceNeeds(${m.id})" title="Marcar se esta máquina precisa ser inventariada ou não" style="display:flex;align-items:center;gap:4px;background:${needBg};color:${needColor};border:${needBorder};padding:6px 10px;border-radius:20px;cursor:pointer;font-size:11px;font-weight:700;min-width:95px;justify-content:center;white-space:nowrap;flex-shrink:0">
                  <i class="ti ti-clipboard-list" style="font-size:12px"></i> ${needLabel}
                </button>
                ${needsInv ? `<button onclick="Kanpro.toggleMaintenanceInventoried(${m.id})" title="${isInventoried?"Clique para desmarcar inventário":"Clique para confirmar que foi inventariado"}" style="display:flex;align-items:center;gap:4px;background:${invBg};color:${invColor};border:${invBorder};padding:6px 10px;border-radius:20px;cursor:pointer;font-size:11px;font-weight:700;min-width:95px;justify-content:center;white-space:nowrap;flex-shrink:0">
                  <i class="${invIcon}" style="font-size:12px"></i> ${invLabel}
                </button>` : ""}
                <button onclick="Kanpro.toggleUrgent(${m.id})" title="${isUrgent?"Remover urgência":"Marcar como urgência"}" style="display:flex;align-items:center;gap:4px;background:${urgBg};color:${urgColor};border:${urgBorder};padding:6px 10px;border-radius:20px;cursor:pointer;font-size:11px;font-weight:700;min-width:80px;justify-content:center;white-space:nowrap;flex-shrink:0">
                  <i class="${urgIcon}" style="font-size:12px"></i> ${urgLabel}
                </button>
                ${isUrgent ? `<button onclick="Kanpro.retiradaMachine(${m.id})" title="Criar card de Retirada para esta máquina e ir para Assinatura" style="display:flex;align-items:center;gap:4px;background:#ff5630;color:#fff;border:1px solid #ff5630;padding:6px 10px;border-radius:20px;cursor:pointer;font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0"><i class="ti ti-truck" style="font-size:12px"></i> Retirada</button>` : ""}
                <button onclick="Kanpro.openMachineNotes(${m.id})" title="Anotações sobre esta máquina" style="position:relative;display:flex;align-items:center;gap:4px;background:#fff;color:#5e6c84;border:1px solid #dfe1e6;padding:6px 10px;border-radius:20px;cursor:pointer;font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0">
                  <i class="ti ti-notes" style="font-size:13px"></i> Notas${(parseInt(m.notes_count||0)>0)?`<span style="background:#eb5a46;color:#fff;min-width:18px;height:18px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;padding:0 5px">${parseInt(m.notes_count)}</span>`:""}
                </button>
                <button onclick="Kanpro.deleteMaintenanceMachine(${m.id})" title="Remover máquina" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;width:28px;height:28px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ti ti-trash" style="font-size:14px"></i></button>
              </div>
            </div>
            <div style="padding:10px 12px">
              <div style="font-size:11px;font-weight:600;color:#5e6c84;margin-bottom:4px;letter-spacing:.04em">RELATÓRIO — o que foi feito nesta máquina</div>
              <textarea id="maint-diary-${m.id}" placeholder="Descreva o que foi feito nesta máquina... (ex: limpeza interna, troca de pasta térmica, verificação de memória)" style="width:100%;min-height:56px;padding:8px;border:1px solid #dfe1e6;border-radius:6px;resize:vertical;font-size:13px;box-sizing:border-box" oninput="Kanpro.onDiaryInput(${m.id})" onblur="Kanpro.autoSaveDiary(${m.id})">${this.escape(diary)}</textarea>
              <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap">
                <span id="maint-save-status-${m.id}" style="font-size:11px;color:#5e6c84"></span>
                <span style="font-size:10px;color:#97a0af;font-style:italic">💾 salvamento automático a cada digitação</span>
                <span style="margin-left:auto;font-size:11px;color:#97a0af;display:flex;align-items:center;gap:6px;flex-wrap:wrap">Status Final: <span id="maint-row-stpill-${m.id}" style="background:${statusColor};color:${statusTextColor};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">${statusLabel}</span> • <span id="maint-row-foot-${m.id}">${isDone?'<span style="color:#61bd4f;font-weight:600">✔ Concluída</span>':'<span style="color:#ff991f">Em andamento</span>'}</span> • <span style="background:${!needsInv?"#dfe1e6":(isInventoried?"#61bd4f":"#ffab00")};color:${!needsInv?"#5e6c84":(isInventoried?"#fff":"#172b4d")};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">${!needsInv?"—":(isInventoried?"✓ Inventariado":"◷ Falta inventariar")}</span></span>
              </div>
            </div>
          </div>
        `;
      });
      html += `</div>`;
      html += `<div style="display:flex;gap:8px;justify-content:center;margin-top:12px">
        <button onclick="Kanpro.openMaintenanceSetup(true)" style="background:#fff;border:1px solid #dfe1e6;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px"><i class="ti ti-plus"></i> Adicionar mais máquinas</button>
        <button onclick="Kanpro.revertMaintenance()" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px"><i class="ti ti-arrow-back"></i> Reverter manutenção</button>
      </div>`;
      wrap.innerHTML = html;
      // inicializa cache de autosave para evitar save desnecessário logo ao abrir
      machines.forEach(m=>{ this._lastDiarySaved[m.id] = m.diary||""; });
      // restaura digitação pendente e reagenda o save
      Object.keys(pendingDiaries).forEach(mid=>{
        const ta = document.getElementById('maint-diary-'+mid);
        if(ta && ta.value !== pendingDiaries[mid]){
          ta.value = pendingDiaries[mid];
          clearTimeout(this._diaryTimers[mid]);
          this._diaryTimers[mid] = setTimeout(()=> this.autoSaveDiary(mid), 600);
        }
      });
      // restaura foco + cursor de onde o usuário estava digitando
      if(focusId){
        const el = document.getElementById(focusId);
        if(el && typeof el.focus === 'function'){
          try{
            el.focus({preventScroll:true});
            if(focusSel && typeof el.setSelectionRange === 'function'){
              const len = (el.value||'').length;
              el.setSelectionRange(Math.min(focusSel.s,len), Math.min(focusSel.e,len));
            }
          }catch(e){}
        }
      }
    },

    openMaintenanceFlow(){
      const cardId = this.currentCardId;
      if(!cardId) return;
      const localCard = this.cards.find(c=> String(c.id)===String(cardId));
      if(localCard && localCard.is_maintenance==1){
        const panel = document.getElementById("card-modal-maintenance");
        if(panel){ panel.scrollIntoView({behavior:"smooth", block:"start"}); panel.style.boxShadow="0 0 0 3px #ffab00"; setTimeout(()=> panel.style.boxShadow="", 1500); return; }
      }
      this.ajax("get_maintenance", {cards_id: cardId}).then(res=>{
        if(res && res.success && res.is_maintenance){
          const panel = document.getElementById("card-modal-maintenance");
          if(panel){ panel.scrollIntoView({behavior:"smooth", block:"start"}); panel.style.boxShadow="0 0 0 3px #ffab00"; setTimeout(()=> panel.style.boxShadow="", 1500); return; }
        }
        this.showMaintenanceStep1();
      }).catch(()=> this.showMaintenanceStep1());
      setTimeout(()=>{
        const picker = document.getElementById("kanpro-picker");
        if(!picker || picker.style.display==="none"){
          const panel = document.getElementById("card-modal-maintenance");
          const isVisible = panel && panel.style.display!=="none" && panel.innerHTML.trim()!=="";
          if(!isVisible) this.showMaintenanceStep1();
        }
      }, 900);
    },
    showMaintenanceStep1(){
      // Busca entidades GLPI antes de mostrar desafio — nome do Card virará nome da Entidade
      this.showPicker({title:"Confirmação — Manutenção", html: '<div style="padding:24px;text-align:center;color:#5e6c84"><i class="ti ti-loader" style="font-size:20px;animation:spin 1s linear infinite;display:inline-block"></i><br>Carregando entidades...</div>'});
      this.ajax("list_entities", {}).then(res=>{
        let entities = (res && res.success && Array.isArray(res.entities)) ? res.entities : [];
        // fallback se listagem vazia
        if(!entities.length){
          entities = [];
        }
        this._maintEntities = entities;
        let challenge;
        if (Math.random() < 0.10) {
          const specials = ["PAIVA","MASSON","FERRARI","MORANGO","SAWATA"];
          challenge = specials[Math.floor(Math.random()*specials.length)];
        } else {
          const others = MAINT_CHALLENGE_WORDS.filter(w=> !["PAIVA","MASSON","FERRARI","MORANGO","SAWATA"].includes(w));
          challenge = others[Math.floor(Math.random()*others.length)];
        }
        this._maintChallenge = challenge;
        // filtra raiz e prepara lista já sem prefixo
        this._maintEntities = entities.filter(e=>{
          const raw=(e.completename||e.name||'').trim();
          return raw !== 'Unidade Regional de Ensino de Jales' && raw.toLowerCase() !== 'unidade regional de ensino de jales' && raw !== 'Entidade Raiz' && raw.toLowerCase() !== 'entidade raiz';
        });
        const html = `
          <div style="display:grid;gap:10px">
            <div style="background:#e6f7ff;border:1px solid #91d5ff;padding:8px 10px;border-radius:6px;color:#003a8c;font-size:12px;line-height:1.3">
              <strong><i class="ti ti-building" style="color:#1890ff"></i> Entidade</strong> — digite para buscar. O <strong>nome do Card virará o nome da entidade</strong> selecionada.
            </div>
            <div style="position:relative">
              <input id="maint-entity-search" type="text" placeholder="Digite para buscar entidade... ex: Adelino, EE, Jales" autocomplete="off" style="width:100%;padding:10px 10px 10px 36px;border:2px solid #1890ff;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box" oninput="Kanpro.onEntitySearch(this.value)" onfocus="Kanpro.showEntityDropdown()" onkeydown="if(event.key==='Escape') Kanpro.hideEntityDropdown()">
              <i class="ti ti-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8c8c8c;font-size:14px"></i>
              <div id="maint-entity-dropdown" style="position:absolute;top:100%;left:0;right:0;max-height:180px;overflow-y:auto;background:#fff;border:1px solid #91d5ff;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;z-index:20"></div>
            </div>
            <input type="hidden" id="maint-entity-select" value="">
            <div id="maint-entity-selected" style="font-size:12px;color:#389e0d;display:none;background:#f6ffed;border:1px solid #b7eb8f;padding:6px 8px;border-radius:4px"><i class="ti ti-check"></i> Selecionado: <strong id="maint-entity-selected-name"></strong> <a href="#" onclick="Kanpro.clearEntitySelection();return false" style="margin-left:8px;color:#ff4d4f;font-size:11px">trocar</a></div>
            <div id="maint-entity-error" style="color:#eb5a46;font-size:12px;display:none;min-height:14px"></div>
            <div style="background:#fffae6;border:1px solid #ffecb5;padding:8px 10px;border-radius:6px;color:#172b4d;font-size:12px;line-height:1.3">
              <strong><i class="ti ti-alert-triangle" style="color:#ff991f"></i> Atenção</strong> — Este card vira <strong>Manutenção</strong> com checklist por máquina (1 em diante).
            </div>
            <div style="background:#091e42;color:#fff;padding:10px;border-radius:8px;text-align:center;letter-spacing:0.08em">
              <div style="font-size:10px;opacity:.7;letter-spacing:0.04em">DIGITE A PALAVRA ABAIXO</div>
              <div style="font-size:22px;font-weight:800;margin-top:2px">${challenge}</div>
            </div>
            <input id="maint-confirm-input" type="text" placeholder="${challenge}" autocomplete="off" autocapitalize="characters" style="width:100%;padding:8px;border:2px solid #ffab00;border-radius:6px;font-size:15px;box-sizing:border-box;text-transform:uppercase;letter-spacing:0.06em;text-align:center;font-weight:700">
            <div id="maint-step1-error" style="color:#eb5a46;font-size:12px;display:none;min-height:14px"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:#fff;padding-top:4px">
              <button onclick="Kanpro.closePicker()" style="background:#f4f5f7;border:none;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Cancelar</button>
              <button id="maint-step1-btn" onclick="Kanpro.confirmMaintenanceStep1()" style="background:#ffab00;color:#172b4d;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px">Confirmar e Converter</button>
            </div>
            <div style="text-align:center"><a href="#" onclick="Kanpro.showMaintenanceStep1();return false" style="font-size:11px;color:#5e6c84">Gerar outra palavra</a></div>
          </div>
        `;
        this.showPicker({title:"Confirmação — Manutenção", html});
        setTimeout(()=>{
          const picker = document.getElementById("kanpro-picker");
          const body = document.getElementById("picker-body");
          if(picker){
            picker.style.maxHeight = "85vh";
            picker.style.display = "flex";
            picker.style.flexDirection = "column";
          }
          if(body){
            body.style.maxHeight = "none";
            body.style.overflowY = "visible";
          }
          const search=document.getElementById("maint-entity-search");
          const inp=document.getElementById("maint-confirm-input");
          if(search) search.focus();
          // renderiza dropdown inicial com todas (filtradas já)
          this.renderEntityDropdown("");
          if(inp){ inp.addEventListener("keydown", e=>{ if(e.key==="Enter") Kanpro.confirmMaintenanceStep1(); }); }
          if(search){
            search.addEventListener("keydown", e=>{
              if(e.key==="Enter"){
                e.preventDefault();
                const dd=document.getElementById("maint-entity-dropdown");
                const first=dd?.querySelector(".maint-entity-item");
                if(first) first.click();
                else document.getElementById("maint-confirm-input")?.focus();
              } else if(e.key==="Escape"){
                this.hideEntityDropdown();
              }
            });
          }
          // fecha dropdown ao clicar fora
          const onDocClick=(ev)=>{
            const wrap=document.getElementById("maint-entity-search")?.parentElement;
            const dd=document.getElementById("maint-entity-dropdown");
            if(wrap && dd && !wrap.contains(ev.target)){
              this.hideEntityDropdown();
            }
          };
          // remove listener anterior se houver
          if(this._entityDocClick) document.removeEventListener("click", this._entityDocClick);
          this._entityDocClick=onDocClick;
          document.addEventListener("click", onDocClick);
        }, 30);
      }).catch(()=>{
        // fallback sem entidades — ainda mostra desafio mas sem seleção
        let challenge;
        if (Math.random() < 0.10) {
          const specials = ["PAIVA","MASSON","FERRARI","MORANGO","SAWATA"];
          challenge = specials[Math.floor(Math.random()*specials.length)];
        } else {
          const others = MAINT_CHALLENGE_WORDS.filter(w=> !["PAIVA","MASSON","FERRARI","MORANGO","SAWATA"].includes(w));
          challenge = others[Math.floor(Math.random()*others.length)];
        }
        this._maintChallenge = challenge;
        this._maintEntities = [];
        const html = `
          <div style="display:grid;gap:8px">
            <div style="background:#fff1f0;border:1px solid #ffa39e;padding:8px 10px;border-radius:6px;color:#a8071a;font-size:12px">Erro ao carregar entidades. Tente novamente.</div>
            <div style="background:#091e42;color:#fff;padding:10px;border-radius:8px;text-align:center;letter-spacing:0.08em">
              <div style="font-size:10px;opacity:.7;letter-spacing:0.04em">DIGITE A PALAVRA ABAIXO</div>
              <div style="font-size:22px;font-weight:800;margin-top:2px">${challenge}</div>
            </div>
            <input id="maint-confirm-input" type="text" placeholder="${challenge}" autocomplete="off" autocapitalize="characters" style="width:100%;padding:8px;border:2px solid #ffab00;border-radius:6px;font-size:15px;box-sizing:border-box;text-transform:uppercase;letter-spacing:0.06em;text-align:center;font-weight:700">
            <div id="maint-step1-error" style="color:#eb5a46;font-size:12px;display:none;min-height:14px"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:#fff;padding-top:4px">
              <button onclick="Kanpro.closePicker()" style="background:#f4f5f7;border:none;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Cancelar</button>
              <button id="maint-step1-btn" onclick="Kanpro.confirmMaintenanceStep1()" style="background:#ffab00;color:#172b4d;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px">Confirmar e Converter</button>
            </div>
          </div>
        `;
        this.showPicker({title:"Confirmação — Manutenção", html});
      });
    },
    confirmMaintenanceStep1(){
      const sel = document.getElementById("maint-entity-select");
      const search = document.getElementById("maint-entity-search");
      const entErr = document.getElementById("maint-entity-error");
      const entities_id = sel ? parseInt(sel.value||"0") : 0;
      if(sel && !entities_id){
        if(entErr){ entErr.textContent="Selecione a entidade. O nome do card virará o nome dela."; entErr.style.display="block"; }
        if(search){ search.style.borderColor="#eb5a46"; search.focus(); this.showEntityDropdown(); }
        else if(sel){ sel.style.borderColor="#eb5a46"; sel.focus(); }
        return;
      }
      if(entErr) entErr.style.display="none";
      if(search) search.style.borderColor="#52c41a";
      if(sel) sel.style.borderColor="#1890ff";
      const inp = document.getElementById("maint-confirm-input");
      const err = document.getElementById("maint-step1-error");
      const btn = document.getElementById("maint-step1-btn");
      const val = (inp?.value||"").trim().toUpperCase();
      const challenge = (this._maintChallenge||"").toUpperCase();
      const normVal = val.normalize ? val.normalize("NFD").replace(/[̀-ͯ]/g,"") : val;
      const normChallenge = challenge.normalize ? challenge.normalize("NFD").replace(/[̀-ͯ]/g,"") : challenge;
      if(!val || normVal !== normChallenge){
        if(err){ err.textContent=`Digite exatamente "${challenge}" para continuar.`; err.style.display="block"; }
        inp.style.borderColor="#eb5a46";
        inp.focus();
        inp.select();
        return;
      }
      if(btn){ btn.disabled=true; btn.textContent="Convertendo..."; }
      if(err){ err.style.display="none"; }
      this._maintConfirmText = challenge;
      this._maintEntitiesId = entities_id;
      this.ajax("convert_to_maintenance", {cards_id: this.currentCardId, confirm_text: challenge, entities_id: entities_id}).then(res=>{
        if(btn){ btn.disabled=false; btn.textContent="Confirmar e Converter"; }
        if(!res.success){
          if(err){ err.textContent=res.msg||"Falha ao converter"; err.style.display="block"; }
          return;
        }
        this.closePicker();
        this.showToast("Card convertido para Manutenção — " + (res.new_name||""));
        this.handleConvertTicket(res);
        const c = this.cards.find(x=> String(x.id)===String(this.currentCardId));
        if(c){ c.is_maintenance=1; if(res.new_name) c.name=res.new_name; this.renderBoard(); }
        this.refreshCardModal((r)=>{ if(r&&r.success) setTimeout(()=> this.openMaintenanceSetup(), 400); else location.reload(); });
      });
    },
    filterMaintEntities(q){ this.onEntitySearch(q); },
    onEntitySearch(q){ this.renderEntityDropdown(q||""); this.showEntityDropdown(); },
    showEntityDropdown(){
      const dd=document.getElementById("maint-entity-dropdown");
      if(dd) dd.style.display="block";
    },
    hideEntityDropdown(){
      const dd=document.getElementById("maint-entity-dropdown");
      if(dd) dd.style.display="none";
    },
    renderEntityDropdown(filter){
      const dd=document.getElementById("maint-entity-dropdown");
      if(!dd) return;
      const norm=s=> s.normalize ? s.normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase() : s.toLowerCase();
      const term=norm((filter||"").trim());
      const entities=this._maintEntities||[];
      const filtered=entities.filter(e=>{
        const raw=(e.completename||e.name||'');
        const short=raw.includes(' > ') ? raw.split(' > ').pop().trim() : raw;
        const hay=norm(short+" "+raw);
        return !term || hay.includes(term);
      }).slice(0,80);
      if(!filtered.length){
        dd.innerHTML='<div style="padding:10px;color:#8c8c8c;font-size:12px;text-align:center">Nenhuma entidade encontrada</div>';
        dd.style.display="block";
        return;
      }
      dd.innerHTML=filtered.map(e=>{
        const raw=(e.completename||e.name||'');
        const short=raw.includes(' > ') ? raw.split(' > ').pop().trim() : raw;
        const escShort=this.escape(short);
        const escRaw=this.escape(raw);
        const hint = raw!==short ? ` title="${escRaw}"` : "";
        return `<div class="maint-entity-item" data-id="${e.id}" data-name="${escShort}"${hint} style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:12px;display:flex;justify-content:space-between;align-items:center"><span>${escShort}</span><small style="color:#8c8c8c;margin-left:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:45%">${escRaw!==short?escRaw:''}</small></div>`;
      }).join("");
      dd.querySelectorAll(".maint-entity-item").forEach(el=>{
        el.addEventListener("click", ()=>{
          const id=parseInt(el.dataset.id);
          const name=el.dataset.name;
          this.selectMaintEntity(id, name);
        });
        el.addEventListener("mouseenter", ()=> el.style.background="#e6f7ff");
        el.addEventListener("mouseleave", ()=> el.style.background="#fff");
      });
      dd.style.display="block";
    },
    selectMaintEntity(id, name){
      const hid=document.getElementById("maint-entity-select");
      const search=document.getElementById("maint-entity-search");
      const selBox=document.getElementById("maint-entity-selected");
      const selName=document.getElementById("maint-entity-selected-name");
      if(hid) hid.value=String(id);
      if(search) search.value=name;
      if(selName) selName.textContent=name;
      if(selBox) selBox.style.display="block";
      this.hideEntityDropdown();
      const err=document.getElementById("maint-entity-error");
      if(err) err.style.display="none";
      const sI=document.getElementById("maint-entity-search");
      if(sI) sI.style.borderColor="#52c41a";
    },
    clearEntitySelection(){
      const hid=document.getElementById("maint-entity-select");
      const search=document.getElementById("maint-entity-search");
      const selBox=document.getElementById("maint-entity-selected");
      if(hid) hid.value="";
      if(search){ search.value=""; search.focus(); search.style.borderColor="#1890ff"; }
      if(selBox) selBox.style.display="none";
      this.renderEntityDropdown("");
      this.showEntityDropdown();
    },
    showMaintenanceStep2(){
      const html = `
        <div style="display:grid;gap:12px">
          <div style="background:#e6fcff;border:1px solid #b3f0ff;padding:10px;border-radius:6px;color:#0052cc;font-size:13px">
            <i class="ti ti-lock"></i> <strong>Etapa 2/2 — Autenticação</strong><br>Confirme sua identidade digitando sua <strong>senha do GLPI</strong>.
          </div>
          <div style="font-size:13px;color:#172b4d"><small style="color:#5e6c84">Esta é a 2ª etapa de verificação para garantir que a conversão é intencional.</small></div>
          <input id="maint-password-input" type="password" placeholder="Sua senha do GLPI" style="width:100%;padding:10px;border:2px solid #0079bf;border-radius:6px;font-size:14px;box-sizing:border-box">
          <div id="maint-step2-error" style="color:#eb5a46;font-size:12px;display:none"></div>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <button onclick="Kanpro.showMaintenanceStep1()" style="background:#f4f5f7;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600">← Voltar</button>
            <button id="maint-step2-btn" onclick="Kanpro.confirmMaintenanceStep2()" style="background:#0079bf;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:700"><i class="ti ti-check"></i> Autenticar e Converter</button>
          </div>
        </div>
      `;
      this.showPicker({title:"Manutenção — Etapa 2/2", html});
      setTimeout(()=>{ const inp=document.getElementById("maint-password-input"); if(inp){ inp.focus(); inp.addEventListener("keydown", e=>{ if(e.key==="Enter") Kanpro.confirmMaintenanceStep2(); }); } }, 100);
    },
    confirmMaintenanceStep2(){
      const pwdInp = document.getElementById("maint-password-input");
      const err = document.getElementById("maint-step2-error");
      const btn = document.getElementById("maint-step2-btn");
      const pwd = pwdInp?.value||"";
      if(!pwd){
        if(err){ err.textContent="Informe sua senha."; err.style.display="block"; }
        return;
      }
      if(btn){ btn.disabled=true; btn.textContent="Verificando..."; }
      this.ajax("convert_to_maintenance", {cards_id: this.currentCardId, password: pwd, confirm_text: this._maintConfirmText||"MANUTENCAO"}).then(res=>{
        if(btn){ btn.disabled=false; btn.textContent="Autenticar e Converter"; }
        if(!res.success){
          if(err){ err.textContent=res.msg||"Falha na autenticação"; err.style.display="block"; }
          return;
        }
        this.closePicker();
        this.showToast("Card convertido para Manutenção!");
        this.handleConvertTicket(res);
        const c = this.cards.find(x=> x.id==this.currentCardId);
        if(c) c.is_maintenance=1;
        this.refreshCardModal((r)=>{ if(r&&r.success) setTimeout(()=> this.openMaintenanceSetup(), 400); else location.reload(); });
      });
    },
    getMaintModels(){
      const defaults = ["Notebook Positivo","Notebook Multilaser","Notebook Ultra","Notebook Lenovo","Desktop Legado","Desktop","Tablet Positivo","Smartphone"];
      try{
        const custom = JSON.parse(localStorage.getItem("kanpro_custom_models")||"[]");
        if(Array.isArray(custom) && custom.length){
          const merged = [...defaults];
          custom.forEach(m=>{
            const mm = String(m).trim();
            if(mm && !merged.includes(mm)) merged.push(mm);
          });
          return merged;
        }
      }catch(e){}
      return defaults;
    },
    saveCustomModel(model){
      const m = String(model).trim();
      if(!m) return false;
      if(m.length>80) return false;
      try{
        const cur = JSON.parse(localStorage.getItem("kanpro_custom_models")||"[]");
        if(!Array.isArray(cur)) throw new Error();
        if(cur.includes(m)) return false;
        cur.push(m);
        localStorage.setItem("kanpro_custom_models", JSON.stringify(cur));
        return true;
      }catch(e){
        try{ localStorage.setItem("kanpro_custom_models", JSON.stringify([m])); return true; }catch(_){ return false; }
      }
    },
    addMaintenanceRow(qty=1, model=""){
      const wrap = document.getElementById("maint-rows");
      if(!wrap) return;
      const models = this.getMaintModels();
      const selModel = model || models[0] || "Notebook Positivo";
      const row = document.createElement("div");
      row.className = "maint-row";
      row.style.cssText = "display:flex;gap:8px;align-items:center;background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:8px";
      const qtyVal = Math.max(1, Math.min(500, parseInt(qty)||1));
      row.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:2px;min-width:90px">
          <label style="font-size:10px;font-weight:700;color:#5e6c84;letter-spacing:.04em">QTD</label>
          <input type="number" min="1" max="500" value="${qtyVal}" style="width:80px;padding:8px;border:1px solid #dfe1e6;border-radius:6px;font-size:14px;text-align:center;font-weight:700">
        </div>
        <div style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:0">
          <label style="font-size:10px;font-weight:700;color:#5e6c84;letter-spacing:.04em">MODELO</label>
          <select style="width:100%;padding:8px;border:1px solid #dfe1e6;border-radius:6px;font-size:13px;background:#fff">
            ${models.map(m=> `<option value="${this.escape(m)}" ${m===selModel?"selected":""}>${this.escape(m)}</option>`).join("")}
            <option value="__custom__">➕ Outro / Novo modelo...</option>
          </select>
        </div>
        <button title="Remover" onclick="Kanpro.removeMaintenanceRow(this)" style="margin-top:14px;background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;width:32px;height:32px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ti ti-trash"></i></button>
      `;
      // eventos
      const qtyInput = row.querySelector("input");
      const sel = row.querySelector("select");
      qtyInput.addEventListener("input", ()=> this.updateMaintPreview());
      qtyInput.addEventListener("change", ()=> this.updateMaintPreview());
      sel.addEventListener("change", ()=>{
        if(sel.value==="__custom__"){
          const novo = prompt("Nome do novo modelo:");
          if(novo && novo.trim()){
            const ok = this.saveCustomModel(novo.trim());
            if(ok){
              this.refreshMaintModelSelects(novo.trim());
              sel.value = novo.trim();
            } else {
              sel.value = models[0];
            }
          } else {
            sel.value = models[0];
          }
        }
        this.updateMaintPreview();
      });
      wrap.appendChild(row);
      this.updateMaintPreview();
    },
    removeMaintenanceRow(btn){
      const row = btn.closest(".maint-row");
      if(row) row.remove();
      const wrap = document.getElementById("maint-rows");
      if(wrap && wrap.children.length===0){
        this.addMaintenanceRow(1, this.getMaintModels()[0]);
      }
      this.updateMaintPreview();
    },
    promptAddCustomModel(){
      const novo = prompt("Cadastrar novo modelo (ex: Notebook Dell):");
      if(!novo || !novo.trim()) return;
      const ok = this.saveCustomModel(novo.trim());
      if(!ok){ alert("Modelo já existe ou inválido."); return; }
      this.refreshMaintModelSelects(novo.trim());
      // adiciona uma linha com esse modelo já selecionado
      this.addMaintenanceRow(1, novo.trim());
    },
    refreshMaintModelSelects(selectValue){
      const models = this.getMaintModels();
      document.querySelectorAll("#maint-rows select").forEach(sel=>{
        const cur = sel.value;
        const keepCustom = cur==="__custom__" ? selectValue : cur;
        sel.innerHTML = models.map(m=> `<option value="${this.escape(m)}">${this.escape(m)}</option>`).join("") + `<option value="__custom__">➕ Outro / Novo modelo...</option>`;
        if(models.includes(keepCustom)) sel.value = keepCustom;
        else if(selectValue && models.includes(selectValue)) sel.value = selectValue;
      });
      this.updateMaintPreview();
    },
    updateMaintPreview(){
      const rows = document.querySelectorAll("#maint-rows .maint-row");
      let total=0;
      const parts=[];
      rows.forEach(r=>{
        const qty = parseInt(r.querySelector("input")?.value||"0")||0;
        const model = r.querySelector("select")?.value||"";
        if(qty>0 && model && model!=="__custom__"){
          total+=qty;
          parts.push(qty+"x "+model);
        }
      });
      const prev = document.getElementById("maint-setup-preview");
      if(prev){
        if(total===0) prev.innerHTML = "<span style='opacity:.6'>Adicione pelo menos um tipo</span>";
        else prev.innerHTML = parts.join(", ") + ` → <strong>${total} máquinas</strong> (1…${total})`;
      }
      const btn = document.getElementById("maint-setup-btn");
      if(btn) btn.disabled = total===0;
    },
    openMaintenanceSetup(isAppend=false){
      const isAppendMode = !!isAppend;
      const title = isAppendMode ? "Adicionar Máquinas" : "Configurar Máquinas — Manutenção";
      const models = this.getMaintModels();
      const html = `
        <div style="display:grid;gap:10px">
          <div style="background:#f4f5f7;padding:8px 10px;border-radius:6px;font-size:11px;color:#5e6c84;line-height:1.4">
            Informe <strong>quantidade</strong> e <strong>modelo</strong> por linha. Use <code style="background:#fff;padding:1px 4px;border-radius:3px">+</code> para adicionar mais tipos.
          </div>
          <div id="maint-rows" style="display:grid;gap:8px;max-height:220px;overflow-y:auto;padding-right:2px"></div>
          <div style="display:flex;gap:8px">
            <button onclick="Kanpro.addMaintenanceRow()" style="flex:1;background:#fff;border:1px dashed #97a0af;color:#172b4d;padding:8px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px"><i class="ti ti-plus"></i> Adicionar tipo</button>
            <button onclick="Kanpro.promptAddCustomModel()" title="Cadastrar novo modelo" style="background:#fffae6;border:1px solid #ffab00;color:#172b4d;padding:8px 12px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px"><i class="ti ti-plus"></i> Modelo</button>
          </div>
          <div id="maint-setup-preview" style="background:#fff;border:1px dashed #dfe1e6;border-radius:6px;padding:8px;min-height:32px;font-size:12px;color:#5e6c84;text-align:center">Adicione pelo menos um tipo</div>
          <div id="maint-setup-error" style="color:#eb5a46;font-size:12px;display:none;min-height:14px"></div>
          <div style="display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:#fff;padding-top:6px">
            <button onclick="Kanpro.closePicker()" style="background:#f4f5f7;border:none;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Cancelar</button>
            <button id="maint-setup-btn" onclick="Kanpro.submitMaintenanceSetup(${isAppendMode?1:0})" style="background:#ffab00;color:#172b4d;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px"><i class="ti ti-tool"></i> ${isAppendMode?"Adicionar":"Gerar Checklist Enumerado"}</button>
          </div>
        </div>
      `;
      this.showPicker({title, html});
      setTimeout(()=>{
        const picker = document.getElementById("kanpro-picker");
        const body = document.getElementById("picker-body");
        if(picker){
          picker.style.maxHeight = "85vh";
          picker.style.display = "flex";
          picker.style.flexDirection = "column";
          picker.style.width = "520px";
          picker.style.maxWidth = "95vw";
        }
        if(body){
          body.style.maxHeight = "70vh";
          body.style.overflowY = "auto";
        }
        // cria primeira linha se vazio
        const wrap = document.getElementById("maint-rows");
        if(wrap && wrap.children.length===0){
          this.addMaintenanceRow(1, models[0]);
        }
        this.updateMaintPreview();
      }, 30);
    },
    submitMaintenanceSetup(isAppend){
      const err=document.getElementById("maint-setup-error");
      const btn=document.getElementById("maint-setup-btn");
      const rows=document.querySelectorAll("#maint-rows .maint-row");
      const defs=[];
      rows.forEach(r=>{
        const qty = parseInt(r.querySelector("input")?.value||"0")||0;
        const model = (r.querySelector("select")?.value||"").trim();
        if(qty>0 && model && model!=="__custom__" && model!==""){
          defs.push({qty, model});
        }
      });
      // fallback para compat: se nao houver rows mas houver textarea antigo
      if(defs.length===0){
        const ta=document.getElementById("maint-setup-raw");
        if(ta){
          const raw=(ta.value||"").trim();
          if(raw){
            // tenta parse simples via split
            raw.split(/[\n,;]+/).forEach(part=>{
              part=part.trim();
              if(!part) return;
              const m=part.match(/(\d+)\s*[xX]\s*(.+)/);
              if(m) defs.push({qty: parseInt(m[1]), model: m[2].trim()});
              else defs.push({qty:1, model: part});
            });
          }
        }
      }
      if(defs.length===0){
        if(err){ err.textContent="Informe pelo menos um tipo com quantidade e modelo."; err.style.display="block"; }
        return;
      }
      let total=0;
      defs.forEach(d=> total+=d.qty);
      if(total<=0 || total>500){
        if(err){ err.textContent="Total de máquinas inválido (1-500). Total: "+total; err.style.display="block"; }
        return;
      }
      if(btn){ btn.disabled=true; btn.textContent="Processando..."; }
      if(err) err.style.display="none";
      const action = isAppend ? "add_maintenance_machines" : "setup_maintenance_machines";
      const payload = {cards_id: this.currentCardId, definitions: JSON.stringify(defs)};
      if(!isAppend) payload.replace = 0;
      this.ajax(action, payload).then(res=>{
        if(btn){ btn.disabled=false; btn.textContent= isAppend ? "Adicionar" : "Gerar Checklist Enumerado"; }
        if(!res.success){
          if(err){ err.textContent=res.msg||"Erro ao configurar"; err.style.display="block"; }
          if(res.need_replace){
            if(confirm("Já existem máquinas. Deseja SUBSTITUIR? Esta ação apagará o cadastro atual.")){
              this.ajax("setup_maintenance_machines", {cards_id: this.currentCardId, definitions: JSON.stringify(defs), replace: 1}).then(r2=>{
                if(!r2.success) alert(r2.msg||"Erro");
                else { this.closePicker(); this.refreshCardModal(); }
              });
            }
          }
          return;
        }
        this.closePicker();
        this.showToast(isAppend ? "Máquinas adicionadas!" : "Checklist gerado: "+(res.total||total)+" máquinas enumeradas");
        this.refreshCardModal();
      });
    },
    toggleMaintenanceDone(mid, checked){
      // Pendente nunca pode ser Feito — barra na origem (o checkbox já vem disabled, isto é rede de segurança)
      const row = document.querySelector(`.kp-maint-machine[data-mid="${mid}"]`);
      const sel = row ? row.querySelector('select') : null;
      const st = sel ? sel.value : '';
      if(checked && (st==='pendente' || st==='pending')){
        alert('Máquina com status Pendente não pode ser marcada como Feita. Troque o status primeiro (Garantia/Ok/Inservível).');
        const cb = row ? row.querySelector('input[type=checkbox]') : null;
        if(cb) cb.checked = false;
        return;
      }
      // otimista: atualiza cache + visuals na hora, SEM rebuild (não pisca, não fecha select)
      const m = this.maintMachineById(mid);
      const prevDone = m ? m.is_done : null;
      if(m){ m.is_done = checked?1:0; this.patchMaintUI(mid); }
      this.ajax("update_maintenance_machine", {id: mid, is_done: checked?1:0}).then(res=>{
        if(res.success){
          this.showToast(checked?"Máquina marcada como feita":"Marca removida");
          // refresh coalescido: confirma com o servidor sem matar interação em andamento
          this.scheduleModalRefresh(700);
        } else {
          if(m && prevDone!==null){ m.is_done = prevDone; this.patchMaintUI(mid); }
          const cb2 = document.querySelector(`.kp-maint-machine[data-mid="${mid}"] input[type=checkbox]`);
          if(cb2) cb2.checked = !checked;
          alert(res.msg||"Erro");
        }
      }).catch(()=>{
        if(m && prevDone!==null){ m.is_done = prevDone; this.patchMaintUI(mid); }
      });
    },
    updateMaintenanceStatus(mid, status){
      const data = {id: mid, status};
      // Ao virar Pendente, desmarca Feito na hora (Pendente nunca é Feito)
      const row = document.querySelector(`.kp-maint-machine[data-mid="${mid}"]`);
      const cb = row ? row.querySelector('input[type=checkbox]') : null;
      if(status==='pendente' || status==='pending'){
        data.is_done = 0;
        if(cb){ cb.checked = false; cb.disabled = true; cb.title = 'Máquina Pendente não pode ser marcada como Feita'; }
      } else if(cb){
        cb.disabled = false; cb.title = '';
      }
      // otimista: espelha no cache + patch visual imediato (o <select> já mostra o novo valor)
      const m = this.maintMachineById(mid);
      let prev = null;
      if(m){
        prev = {status: m.status, is_done: m.is_done};
        const meta = this.maintStatusMeta(status);
        m.status = meta.status || status;
        if(status==='pendente' || status==='pending') m.is_done = 0;
        this.patchMaintUI(mid);
      }
      this.ajax("update_maintenance_machine", data).then(res=>{
        if(!res.success){
          if(m && prev){ m.status = prev.status; m.is_done = prev.is_done; this.patchMaintUI(mid); }
          alert(res.msg||"Erro ao salvar status");
        }
        // coalescido: não fecha nada que o usuário abriu logo em seguida
        this.scheduleModalRefresh(700);
      }).catch(()=>{
        if(m && prev){ m.status = prev.status; m.is_done = prev.is_done; this.patchMaintUI(mid); }
      });
    },
    /* ---------- folha informativa ---------- */
    ensureHtml2Pdf(){
      return new Promise(resolve=>{
        if(window.html2pdf){ resolve(true); return; }
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
        s.onload = ()=> resolve(true);
        s.onerror = ()=> resolve(false);
        document.head.appendChild(s);
        setTimeout(()=> resolve(!!window.html2pdf), 4000);
      });
    },
    printInfoSheet(){
      // prévia em nova guia + envio automático para a impressora (igual ao termo do assetmgrstatus)
      const cid = this.currentCardId;
      if(!cid){ this.showAlert('Cartão inválido.', 'Atenção'); return; }
      this.showToast('🖨️ Enviando folha para a impressora...');
      this.ajax('get_info_sheet', {cards_id: cid}).then(async res=>{
        if(!res.success || !res.html){
          this.showAlert(res.msg||'Erro ao gerar folha.', 'Falha ao imprimir');
          return;
        }
        // 1) prévia: como está a folha (com botões Imprimir / Imprimir na HP dentro da guia)
        try {
          const w = window.open('', '_blank');
          if(w){ w.document.write(res.html); w.document.close(); }
          else this.showAlert('Permita pop-ups para ver a prévia da folha.', 'Informação');
        } catch(e){}
        // 2) gera PDF no navegador (idêntico à prévia) e envia ao servidor p/ CUPS
        let pdfBase64 = null;
        try {
          await this.ensureHtml2Pdf();
          if(window.html2pdf){
            const tmp = document.createElement('div');
            tmp.style.cssText = 'position:fixed;left:-99999px;top:0;width:794px;background:#fff';
            tmp.innerHTML = res.html;
            // html2pdf ignora @media print: remove botoes .no-print senao saem impressos
            tmp.querySelectorAll('.no-print, script').forEach(el=> el.remove());
            document.body.appendChild(tmp);
            const target = tmp.querySelector('.folha') || tmp;
            const opt = { margin: [10,10,10,10], filename: 'Folha-' + String(cid).padStart(4,'0') + '.pdf',
              image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2, useCORS: true, scrollY: 0, logging: false },
              jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } };
            const uri = await window.html2pdf().set(opt).from(target).outputPdf('datauristring');
            pdfBase64 = (uri.split(',')[1] || null);
            tmp.remove();
          }
        } catch(e){ pdfBase64 = null; }
        const payload = {cards_id: cid};
        if(pdfBase64) payload.pdf_base64 = pdfBase64;
        this.ajax('print_info_sheet', payload).then(res2=>{
          if(res2.success) this.showToast('🖨️ Folha impressa (' + (res2.printer||'') + (res2.request_id ? ' • Job ' + res2.request_id : '') + ')');
          else this.showAlert('Falha ao imprimir\n' + (res2.msg||'Erro desconhecido'), '❌ Falha ao imprimir');
        });
      });
    },
    /* ---------- seleção em massa (manutenção) ---------- */
    toggleMaintSelectMode(){
      this._maintSelectMode = !this._maintSelectMode;
      if(!this._maintSelected) this._maintSelected = new Set();
      this._maintSelected.clear(); // seleção sempre começa zerada ao (re)abrir o modo
      if(this._lastModalData) this.renderMaintenanceInModal(this._lastModalData);
    },
    toggleMaintSelect(mid, checked){
      if(!this._maintSelected) this._maintSelected = new Set();
      if(checked) this._maintSelected.add(String(mid));
      else this._maintSelected.delete(String(mid));
      const el = document.getElementById('maint-sel-count');
      if(el) el.textContent = this._maintSelected.size;
    },
    maintSelectAll(on){
      if(!this._maintSelected) this._maintSelected = new Set();
      const machines = (this._lastModalData && this._lastModalData.maintenance_machines) || [];
      if(on) machines.forEach(m=> this._maintSelected.add(String(m.id)));
      else this._maintSelected.clear();
      if(this._lastModalData) this.renderMaintenanceInModal(this._lastModalData);
    },
    maintSelectedIds(){
      const machines = (this._lastModalData && this._lastModalData.maintenance_machines) || [];
      const valid = new Set(machines.map(m=> String(m.id)));
      const fromSet = [...(this._maintSelected||[])].filter(id=> valid.has(String(id)));
      // fonte da verdade extra: checkboxes marcados no DOM (cobre qualquer dessincronia)
      const fromDom = [...document.querySelectorAll('#card-modal-maintenance input[type="checkbox"][data-mid]:checked')]
        .map(el=> String(el.dataset.mid)).filter(id=> valid.has(id));
      return [...new Set([...fromSet, ...fromDom])];
    },
    bulkAfterSuccess(msg){
      if(this._maintSelected) this._maintSelected.clear();
      this.showToast(msg);
      this.refreshCardModal();
    },
    bulkApplyStatus(){
      const sel = document.getElementById('maint-bulk-status');
      const st = sel ? sel.value : '';
      if(!st){ this.showToast('Escolha um Status Final'); return; }
      const ids = this.maintSelectedIds();
      if(!ids.length){ this.showToast('Selecione ao menos uma máquina'); return; }
      this.ajax('bulk_update_machines', {cards_id: this.currentCardId, ids: JSON.stringify(ids), status: st}).then(res=>{
        if(res.success) this.bulkAfterSuccess(`${res.updated||0} máquinas atualizadas`);
        else this.showToast(res.msg||'Erro');
      });
    },
    bulkSetDone(val){
      const ids = this.maintSelectedIds();
      if(!ids.length){ this.showToast('Selecione ao menos uma máquina'); return; }
      this.ajax('bulk_update_machines', {cards_id: this.currentCardId, ids: JSON.stringify(ids), is_done: val}).then(res=>{
        if(res.success) this.bulkAfterSuccess(`${res.updated||0} máquinas atualizadas`);
        else this.showToast(res.msg||'Erro');
      });
    },
    setAllNeedsInventory(val){
      this.ajax('set_all_needs_inventory', {cards_id: this.currentCardId, needs_inventory: val}).then(res=>{
        if(res.success){
          this.showToast(val ? `📋 ${res.updated||0} máquinas: precisa inventariar` : 'Marcas de inventário removidas');
          this.refreshCardModal();
        } else alert(res.msg||'Erro');
      });
    },
    toggleMaintenanceNeeds(mid){
      const data = this._lastModalData && this._lastModalData.maintenance_machines ? this._lastModalData.maintenance_machines.find(m=> String(m.id)===String(mid)) : null;
      const current = data ? Number(data.needs_inventory)||0 : 0;
      const newVal = current ? 0 : 1;
      if(data){ data.needs_inventory = newVal; if(!newVal) data.is_inventoried = 0; this.patchMaintUI(mid); }
      this.ajax("update_maintenance_machine", {id: mid, needs_inventory: newVal}).then(res=>{
        if(res.success){
          this.showToast(newVal ? "📋 Precisa inventariar" : "Não precisa inventariar");
          this.scheduleModalRefresh(600);
        } else {
          if(data){ data.needs_inventory = current; this.patchMaintUI(mid); }
          alert(res.msg||"Erro");
        }
      }).catch(()=>{ if(data){ data.needs_inventory = current; this.patchMaintUI(mid); } });
    },
    toggleMaintenanceInventoried(mid){
      // busca estado atual para inverter
      const wrap = document.querySelector(`.kp-maint-machine[data-mid="${mid}"]`);
      const btn = wrap ? wrap.querySelector('button[onclick*="toggleMaintenanceInventoried"]') : null;
      const currentlyInventoried = btn && btn.textContent.includes("Inventariado") && btn.style.background.includes("61bd4f");
      // se não deu para detectar, busca no cache de máquinas
      let currentVal = currentlyInventoried ? 1 : 0;
      // tenta confirmar via dados da modal se possível
      try {
        const data = this._lastModalData && this._lastModalData.maintenance_machines ? this._lastModalData.maintenance_machines.find(m=> String(m.id)===String(mid)) : null;
        if(data) currentVal = Number(data.is_inventoried)||0;
      } catch(e){}
      const newVal = currentVal ? 0 : 1;
      if(btn){ btn.disabled=true; btn.style.opacity=".6"; }
      const mInv = this.maintMachineById(mid);
      if(mInv){ mInv.is_inventoried = newVal; this.patchMaintUI(mid); }
      this.ajax("update_maintenance_machine", {id: mid, is_inventoried: newVal}).then(res=>{
        if(btn){ btn.disabled=false; btn.style.opacity="1"; }
        if(res.success){
          this.showToast(newVal ? "✓ Inventariado" : "Inventário desmarcado");
          this.scheduleModalRefresh(600);
        } else {
          if(mInv){ mInv.is_inventoried = currentVal; this.patchMaintUI(mid); }
          alert(res.msg||"Erro ao atualizar inventário");
          if(btn){ btn.disabled=false; btn.style.opacity="1"; }
        }
      }).catch(()=>{
        if(mInv){ mInv.is_inventoried = currentVal; this.patchMaintUI(mid); }
        if(btn){ btn.disabled=false; btn.style.opacity="1"; }
      });
    },
    toggleUrgent(mid){
      const data = this._lastModalData && this._lastModalData.maintenance_machines ? this._lastModalData.maintenance_machines.find(m=> String(m.id)===String(mid)) : null;
      const current = data ? Number(data.is_urgent)||0 : 0;
      const newVal = current ? 0 : 1;
      if(data){ data.is_urgent = newVal; this.patchMaintUI(mid); }
      this.ajax("update_maintenance_machine", {id: mid, is_urgent: newVal}).then(res=>{
        if(res.success){
          this.showToast(newVal ? "🔥 Urgência marcada" : "Urgência removida");
          this.scheduleModalRefresh(600);
        } else {
          if(data){ data.is_urgent = current; this.patchMaintUI(mid); }
          alert(res.msg||"Erro");
        }
      }).catch(()=>{ if(data){ data.is_urgent = current; this.patchMaintUI(mid); } });
    },
    retiradaMachine(mid){
      if(!confirm("Criar card de Retirada para esta máquina (urgência)? O card atual perderá esta máquina e um novo card será criado com as mesmas informações, indo para Assinatura.")) return;
      this.ajax("retirada_machine", {id: mid}).then(res=>{
        if(!res.success){ alert(res.msg||"Erro"); return; }
        this.showToast("Retirada criada — card #" + res.new_card_id);
        this.refreshCardModal();
        if(res.new_card_id) setTimeout(()=> this.openCard(res.new_card_id), 600);
        if(res.assinatura_url) window.open(res.assinatura_url, "_blank");
        else if(res.transfer_id){
          const base = this.ajax_url.replace("/ajax.php","");
          window.open(base + "/../assetmgrstatus/front/assinatura.php?f=pendente&highlight=" + res.transfer_id, "_blank");
        } else {
          const base = this.ajax_url.replace("/plugins/kanpro/front/ajax.php","");
          window.open(base + "/plugins/assetmgrstatus/front/assinatura.php?f=pendente&highlight=" + (res.transfer_id||""), "_blank");
        }
      });
    },
    onDiaryInput(mid){
      const ta=document.getElementById("maint-diary-"+mid);
      const status=document.getElementById("maint-save-status-"+mid);
      if(!ta) return;
      if(status){ status.textContent=" ✎ digitando…"; status.style.color="#97a0af"; }
      clearTimeout(this._diaryTimers[mid]);
      const val = ta.value;
      const lastChar = val.slice(-1);
      // a cada palavra (espaço/quebra) salva mais rápido — 400ms vs 800ms
      const delay = (lastChar===" "||lastChar==="\n") ? 400 : 800;
      this._diaryTimers[mid]=setTimeout(()=> this.autoSaveDiary(mid), delay);
    },
    autoSaveDiary(mid){
      const ta=document.getElementById("maint-diary-"+mid);
      const status=document.getElementById("maint-save-status-"+mid);
      if(!ta) return;
      const diary=ta.value;
      if(this._lastDiarySaved[mid]===diary) return;
      if(this._diarySaving[mid]){
        // salvamento em andamento: reagenda em vez de descartar a digitação
        clearTimeout(this._diaryTimers[mid]);
        this._diaryTimers[mid]=setTimeout(()=> this.autoSaveDiary(mid), 800);
        return;
      }
      if(status){ status.textContent=" Salvando…"; status.style.color="#5e6c84"; }
      this._diarySaving[mid]=true;
      this.ajax("update_maintenance_machine", {id: mid, diary}).then(res=>{
        this._diarySaving[mid]=false;
        if(res.success){
          this._lastDiarySaved[mid]=diary;
          if(status){ status.textContent=" ✓ Salvo automaticamente"; status.style.color="#61bd4f"; setTimeout(()=>{ if(status.textContent.includes("Salvo")) status.textContent=""; }, 2200); }
        } else {
          if(status){ status.textContent=" Erro ao salvar"; status.style.color="#eb5a46"; }
        }
      }).catch(()=>{
        this._diarySaving[mid]=false;
        if(status){ status.textContent=" Erro ao salvar"; status.style.color="#eb5a46"; }
      });
    },
    deleteMaintenanceMachine(mid){
      this.kpConfirm("Remover esta máquina? A numeração será re-sequenciada (1…N).").then(ok=>{
        if(!ok) return;
        this.ajax("delete_maintenance_machine", {id: mid}).then(res=>{
          if(res.success){
            this.showToast("Máquina removida");
            this.refreshCardModal();
          } else alert(res.msg||"Erro");
        });
      });
    },
    // ---------- ANOTAÇÕES DA MÁQUINA ----------
    openMachineNotes(mid){
      const data = this._lastModalData && this._lastModalData.maintenance_machines ? this._lastModalData.maintenance_machines.find(m=> String(m.id)===String(mid)) : null;
      const title = data ? `Anotações — Máquina #${data.seq} ${data.model||''}` : `Anotações da máquina`;
      this.showPicker({title, html: '<div style="padding:24px;text-align:center;color:#5e6c84"><i class="ti ti-loader" style="font-size:20px"></i><br>Carregando anotações...</div>'});
      this.ajax("get_machine_notes", {machine_id: mid}).then(res=>{
        if(!res.success){ this.showPicker({title, html: `<div style="padding:16px;color:#eb5a46">${this.escape(res.msg||'Erro ao carregar')}</div>`}); return; }
        const notes = res.notes || [];
        let html = `<div style="display:grid;gap:8px;max-height:300px;overflow-y:auto;margin-bottom:12px">`;
        if(!notes.length) html += `<div style="text-align:center;color:#97a0af;font-size:13px;padding:16px 8px"><i class="ti ti-notes-off" style="font-size:22px"></i><br>Nenhuma anotação ainda.<br>Registre observações sobre esta máquina abaixo.</div>`;
        notes.forEach(n=>{
          const when = n.date_creation ? new Date(n.date_creation.replace(' ','T')).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';
          html += `<div style="background:#f4f5f7;border-radius:6px;padding:8px 10px">
            <div style="font-size:13px;color:#172b4d;white-space:pre-wrap;word-break:break-word">${this.escape(n.note||'')}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:11px;color:#97a0af">
              <span style="font-weight:700">${this.escape(n.user_name||'')}</span><span>${this.escape(when)}</span>
              <button onclick="Kanpro.deleteMachineNote(${n.id}, ${mid})" title="Excluir anotação" style="margin-left:auto;background:none;border:none;color:#eb5a46;cursor:pointer;font-size:14px"><i class="ti ti-trash"></i></button>
            </div>
          </div>`;
        });
        html += `</div>`;
        html += `<div style="display:grid;gap:8px">
          <textarea id="machine-note-input" placeholder="Escrever anotação sobre esta máquina..." style="width:100%;min-height:64px;padding:8px;border:1px solid #dfe1e6;border-radius:6px;resize:vertical;font-size:13px;box-sizing:border-box"></textarea>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <button onclick="Kanpro.closePicker()" style="background:#f4f5f7;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Fechar</button>
            <button onclick="Kanpro.addMachineNote(${mid})" style="background:#0079bf;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px"><i class="ti ti-plus"></i> Adicionar</button>
          </div>
        </div>`;
        this.showPicker({title, html});
        setTimeout(()=>{ const ta=document.getElementById('machine-note-input'); if(ta) ta.focus(); }, 100);
      });
    },
    addMachineNote(mid){
      const ta = document.getElementById('machine-note-input');
      const text = (ta ? ta.value : '').trim();
      if(!text){ if(ta) ta.focus(); return; }
      this.ajax("add_machine_note", {machine_id: mid, note: text}).then(res=>{
        if(res.success){
          this.showToast("Anotação adicionada");
          this.openMachineNotes(mid); // recarrega lista
          this.refreshCardModal();
        } else alert(res.msg||"Erro");
      });
    },
    deleteMachineNote(noteId, mid){
      this.kpConfirm("Excluir esta anotação?").then(ok=>{
        if(!ok) return;
        this.ajax("delete_machine_note", {id: noteId}).then(res=>{
          if(res.success){
            this.openMachineNotes(mid); // recarrega lista
            this.refreshCardModal();
          } else alert(res.msg||"Erro");
        });
      });
    },
    revertMaintenance(){
      this.kpConfirm("Reverter este card para modo normal? O histórico de máquinas será mantido, mas o modo manutenção será desativado.").then(async ok=>{
        if(!ok) return;
        const pwd = await this.kpPrompt("Confirme sua senha para reverter (digite sua senha do GLPI):", "");
        if(pwd===null) return;
        let password = pwd;
        if(password===""){
          const html = `
            <div style="display:grid;gap:10px">
              <div style="font-size:13px">Digite sua senha do GLPI para confirmar reversão:</div>
              <input id="revert-pwd" type="password" style="width:100%;padding:10px;border:2px solid #eb5a46;border-radius:6px">
              <div style="display:flex;gap:8px;justify-content:flex-end">
                <button onclick="Kanpro.closePicker()" style="background:#f4f5f7;border:none;padding:8px 14px;border-radius:6px;cursor:pointer">Cancelar</button>
                <button onclick="Kanpro.doRevertWithPwd()" style="background:#eb5a46;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer">Reverter</button>
              </div>
            </div>`;
          this.showPicker({title:"Reverter Manutenção", html});
          setTimeout(()=>{ const inp=document.getElementById("revert-pwd"); if(inp) inp.focus(); }, 100);
          this._revertCardId = this.currentCardId;
          return;
        }
        this.doRevertAjax(password);
      });
    },
    doRevertWithPwd(){
      const inp=document.getElementById("revert-pwd");
      const pwd=inp?.value||"";
      if(!pwd) return;
      this.closePicker();
      this.doRevertAjax(pwd);
    },
    doRevertAjax(password){
      this.ajax("revert_maintenance", {cards_id: this.currentCardId, password}).then(res=>{
        if(!res.success){ alert(res.msg||"Falha ao reverter"); return; }
        this.showToast("Modo manutenção revertido");
        const c=this.cards.find(x=> x.id==this.currentCardId);
        if(c) c.is_maintenance=0;
        this.refreshCardModal();
      });
    },
    generateMaintenanceTerm(){
      const cardId=this.currentCardId;
      if(!cardId) return;
      this.ajax("get_maintenance_term_data", {cards_id: cardId}).then(res=>{
        if(!res.success){ alert(res.msg||"Erro ao buscar dados"); return; }
        if(!res.is_maintenance){ alert("Card não é de manutenção"); return; }
        const total=res.progress.total, done=res.progress.done;
        if(done!==total){
          const ok = confirm("Atenção: nem todas as máquinas estão marcadas como feitas ("+done+"/"+total+"). Deseja gerar o termo assim mesmo?");
          if(!ok) return;
        }
        const html = this.buildTermHtml(res.card, res.machines, res.card.board_name, res.card.list_name);
        const w = window.open("", "_blank");
        if(!w){ alert("Pop-up bloqueado. Permita pop-ups para gerar termo."); return; }
        w.document.write(html);
        w.document.close();
        this.showToast("Termo gerado");
      });
    },
    finalizeMaintenance(){
      const cardId=this.currentCardId;
      if(!cardId) return;
      // valida status obrigatório local antes de chamar backend
      const checkAndPrompt = ()=>{
        // busca dados atuais do modal para validar pendentes sem recarregar
        const wrap = document.getElementById("card-modal-maintenance");
        if(wrap){
          const selects = wrap.querySelectorAll("select");
          let missing = 0;
          selects.forEach(s=>{ if(!s.value || s.value.trim()==="") missing++; });
          if(missing>0){
            alert(`Selecione o Status Final de todas as máquinas antes de finalizar. Faltam ${missing} com status em branco (campo obrigatório ao lado de 'Feito').`);
            return false;
          }
        }
        return true;
      };
      if(!checkAndPrompt()) return;
      const prog = this.maintenanceProgress[cardId];
      let force = 0;
      // pendentes não precisam estar 100% — apenas não-pendentes
      const pendingCountLocal = document.querySelectorAll(".kp-maint-machine select option[value='pendente']:checked").length;
      if(prog && prog.total>0 && prog.done!==prog.total){
        // verifica se há pendentes — pendentes justificam não estar 100% Feito (ficam em novo card)
        const wrap = document.getElementById("card-modal-maintenance");
        const pendingCount = wrap ? [...wrap.querySelectorAll("select")].filter(s=> s.value==="pendente").length : 0;
        if(pendingCount>0){
          const ok = confirm(`Atenção: ${prog.done}/${prog.total} concluídas como 'Feito', mas ${pendingCount} máquina(s) como Pendente ficarão em NOVO CARD. As demais (Garantia/Ok/Inservível) irão para o termo.\nDeseja continuar?`);
          if(!ok) return;
          force = 1;
        } else {
          const ok = confirm(`Atenção: ${prog.done}/${prog.total} concluídas. Deseja FINALIZAR mesmo assim e enviar para Assinatura?`);
          if(!ok) return;
          force = 1;
        }
      } else {
        // mesmo se 100% Feito, confirma pendentes
        const wrap = document.getElementById("card-modal-maintenance");
        const pendingCount = wrap ? [...wrap.querySelectorAll("select")].filter(s=> s.value==="pendente").length : 0;
        if(pendingCount>0){
          const ok = confirm(`${pendingCount} máquina(s) como Pendente ficarão em NOVO CARD e não irão para o termo. As demais (Garantia/Ok/Inservível) serão enviadas para Assinatura. Continuar?`);
          if(!ok) return;
        }
      }
      const btn = document.querySelector("#card-modal-maintenance button[onclick*='finalizeMaintenance']");
      if(btn){ btn.disabled=true; btn.textContent="Finalizando..."; }
      this.ajax("finalize_maintenance", {cards_id: cardId, force}).then(res=>{
        if(btn){ btn.disabled=false; btn.textContent="FINALIZAR"; }
        if(!res.success){
          if(res.need_status){
            alert(res.msg||"Selecione o Status Final de todas as máquinas.");
            // destaca selects vazios
            const wrap = document.getElementById("card-modal-maintenance");
            if(wrap) wrap.querySelectorAll("select").forEach(s=>{ if(!s.value) s.style.boxShadow="0 0 0 2px #eb5a46"; });
            return;
          }
          if(res.need_100){
            const goLocal = confirm((res.msg||"Conclua 100%") + "\nDeseja gerar termo local (fallback) em vez de enviar para Assinatura?");
            if(goLocal) this.generateMaintenanceTerm();
            return;
          }
          if(res.all_pending){
            this.showToast(`Novo card #${res.pending_card_id} criado com ${res.pending_count} pendente(s).`);
            this.ajax("get_card", {cards_id: cardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
            if(res.pending_card_id){
              setTimeout(()=>{ const el=document.querySelector(`.kp-card[data-card-id="${res.pending_card_id}"]`); if(el){ el.classList.add('kp-card-updated'); el.scrollIntoView({behavior:"smooth",block:"center"}); } }, 600);
            }
            return;
          }
          alert(res.msg||"Erro ao finalizar");
          return;
        }
        if(res.pending_only){
          this.showToast(`Pendentes movidos para novo card #${res.pending_card_id} — ${res.pending_count} máquinas`);
          this.ajax("get_card", {cards_id: cardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
          // abre novo card em modal?
          setTimeout(()=>{ if(res.pending_card_id) this.openCard(res.pending_card_id); }, 600);
          return;
        }
        let msg = "Enviado para Assinatura!";
        if(res.pending_card_id) msg += ` Pendentes → card #${res.pending_card_id} (${res.pending_count})`;
        this.showToast(msg);
        // marca local como Retirada imediatamente (sem esperar polling) — Concluído vem após assinatura via polling
        this.transferStatus[cardId] = {label:'Retirada', status:'retirada'};
        this.renderBoard();
        this.ajax("get_card", {cards_id: cardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
        // abre apenas a aba de Assinaturas — não abre mais o termo sem assinar
        let assinaturaUrl = res.assinatura_url;
        if(!assinaturaUrl){
          try{
            const base = this.ajax_url.replace("/plugins/kanpro/front/ajax.php","");
            assinaturaUrl = base + "/plugins/assetmgrstatus/front/assinatura.php?f=pendente&highlight=" + (res.transfer_id||"");
          }catch(e){
            assinaturaUrl = "/plugins/assetmgrstatus/front/assinatura.php?f=pendente";
          }
        }
        window.open(assinaturaUrl, "_blank");
        if(res.pending_card_id){
          // informa pendentes
          setTimeout(()=> alert(`✅ Pendentes (${res.pending_count}) movidos para novo card #${res.pending_card_id}. O novo card ficou na mesma lista para atenção posterior.`), 900);
        }
        this.closeCardModal();
      }).catch(e=>{
        if(btn){ btn.disabled=false; btn.textContent="FINALIZAR"; }
        alert("Erro: "+(e.message||e));
      });
    },
    buildTermHtml(card, machines, boardName, listName){
      const now = new Date().toLocaleDateString("pt-BR") + " " + new Date().toLocaleTimeString("pt-BR");
      const total = machines.length;
      const norm = s=> (s||"").toString().trim().toLowerCase();
      const garantiaCount = machines.filter(m=> norm(m.status)==="garantia").length;
      const okCount = machines.filter(m=> norm(m.status)==="ok" || m.is_ok==1).length;
      const inservivelCount = machines.filter(m=> ["inservivel","defect","defeito","nok"].includes(norm(m.status))).length;
      const pendenteCount = machines.filter(m=> ["pendente","pending"].includes(norm(m.status))).length;
      const inventoriedCount = machines.filter(m=> String(m.is_inventoried)==="1" || m.is_inventoried===1).length;
      const semStatus = total - garantiaCount - okCount - inservivelCount - pendenteCount;
      const esc = s=> this.escape(s||"");
      const rows = machines.map(m=>{
        const st = norm(m.status);
        let statusLabel="—", statusColor="#97a0af";
        if(st==="garantia"){ statusLabel="GARANTIA"; statusColor="#0052cc"; }
        else if(st==="ok"){ statusLabel="OK"; statusColor="#61bd4f"; }
        else if(st==="inservivel"||st==="defect"||st==="defeito"||st==="nok"){ statusLabel="INSERVÍVEL"; statusColor="#eb5a46"; }
        else if(st==="pendente"||st==="pending"){ statusLabel="PENDENTE"; statusColor="#ffab00"; }
        else if(!st){ statusLabel="SEM STATUS"; statusColor="#bf2600"; }
        else { statusLabel=st.toUpperCase(); statusColor="#5e6c84"; }
        const doneIcon = m.is_done==1 ? "✔" : "—";
        return `
          <tr>
            <td style="text-align:center;font-weight:700">#${m.seq}</td>
            <td>${esc(m.model)}</td>
            <td style="font-size:11px">${esc(m.label)}</td>
            <td style="text-align:center"><span style="background:${statusColor};color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">Status Final: ${statusLabel}</span></td>
            <td style="text-align:center">${doneIcon}</td>
            <td style="font-size:12px;white-space:pre-wrap;word-break:break-word;min-width:320px">${esc(m.diary||"—")}</td>
          </tr>`;
      }).join("");
      return `<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Termo de Manutenção — ${esc(card.name)}</title>
<style>
  @media print { .no-print{display:none} @page{margin:15mm} }
  body{font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#172b4d;margin:0;padding:20px;background:#fff}
  h1{font-size:20px;margin:0 0 4px}
  h2{font-size:14px;margin:16px 0 8px;color:#0052cc;border-bottom:2px solid #dfe1e6;padding-bottom:6px}
  table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12px}
  th{background:#091e42;color:#fff;padding:8px;text-align:left;font-size:11px}
  td{padding:6px 8px;border-bottom:1px solid #dfe1e6;vertical-align:top}
  tr:nth-child(even) td{background:#f4f5f7}
  .header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:3px solid #0052cc;padding-bottom:12px;margin-bottom:16px}
  .meta{font-size:12px;color:#5e6c84}
  .summary{display:flex;gap:12px;margin:12px 0}
  .summary div{background:#f4f5f7;padding:10px 14px;border-radius:8px;flex:1;text-align:center}
  .summary strong{font-size:18px;display:block}
</style>
</head>
<body>
  <div class="header">
    <div>
      <h1>🔧 Termo de Manutenção</h1>
      <div class="meta"><strong>Quadro:</strong> ${esc(boardName||"—")} &nbsp;|&nbsp; <strong>Lista:</strong> ${esc(listName||"—")} &nbsp;|&nbsp; <strong>Cartão:</strong> #${card.id} — ${esc(card.name)}</div>
      <div class="meta">Gerado em: ${now} &nbsp;|&nbsp; Total de máquinas: ${total} &nbsp;|&nbsp; Garantia: ${garantiaCount} &nbsp;|&nbsp; OK: ${okCount} &nbsp;|&nbsp; Inservível: ${inservivelCount} &nbsp;|&nbsp; Pendente: ${pendenteCount} &nbsp;|&nbsp; Inventariado: ${inventoriedCount}${semStatus?` &nbsp;|&nbsp; <span style="color:#bf2600">Sem Status: ${semStatus}</span>`:""}</div>
    </div>
    <div class="no-print" style="text-align:right">
      <button onclick="window.print()" style="background:#0052cc;color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:700">🖨️ Imprimir / Salvar PDF</button><br>
      <small style="color:#5e6c84">Use o navegador para salvar em PDF</small>
    </div>
  </div>
  <h2>Resumo da Manutenção — Status Final</h2>
  <div class="summary">
    <div><strong>${total}</strong><span>Total de Máquinas</span></div>
    <div style="background:#e6f7ff"><strong style="color:#0052cc">${garantiaCount}</strong><span>Garantia</span></div>
    <div style="background:#e3fcef"><strong style="color:#006644">${okCount}</strong><span>OK</span></div>
    <div style="background:#ffebe6"><strong style="color:#bf2600">${inservivelCount}</strong><span>Inservível</span></div>
    <div style="background:#fff8e6"><strong style="color:#974f00">${pendenteCount}</strong><span>Pendente</span></div>
    <div style="background:#e3fcef;border:1px solid #61bd4f"><strong style="color:#006644">${inventoriedCount}</strong><span>Inventariado</span></div>
  </div>
  <h2>Descrição do Card</h2>
  <div style="background:#f4f5f7;padding:10px;border-radius:6px;white-space:pre-wrap">${esc(card.description||"—")}</div>
  <h2>Checklist por Máquina — Relatório (Status Final)</h2>
  <table>
    <thead>
      <tr><th style="width:50px">#</th><th style="min-width:140px">Modelo</th><th>Etiqueta</th><th style="width:130px">Status Final</th><th style="width:50px">Feito</th><th>O QUE FOI FEITO</th></tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>
  <h2>Assinaturas</h2>
  <div style="display:flex;gap:40px;margin-top:30px">
    <div style="flex:1;text-align:center;border-top:1px solid #172b4d;padding-top:8px;margin-top:40px">Responsável pela Manutenção<br><small style="color:#5e6c84">Nome / Assinatura / Data</small></div>
    <div style="flex:1;text-align:center;border-top:1px solid #172b4d;padding-top:8px;margin-top:40px">Responsável pelo Recebimento<br><small style="color:#5e6c84">Nome / Assinatura / Data</small></div>
  </div>
  <div style="margin-top:20px;text-align:center;color:#97a0af;font-size:10px">Documento gerado automaticamente pelo KanPro — Quadros Kanban GLPI • ${esc(card.name)} • #${card.id}</div>
</body>
</html>`;
    },

    renderBoardQuick(){
      // re-render apenas sem resetar modal, mantendo filtros
      this.renderBoard();
      this.renderMemberAvatars();
    },

    async editCardTitle(){
      const cur = this.cards.find(c=> c.id==this.currentCardId);
      const data = this._lastModalData;
      const isMaint = data && data.is_maintenance==1;
      if(isMaint){
        this.editMaintenanceCardTitle();
        return;
      }
      const novo = await this.kpPrompt('Título do cartão:', cur.name);
      if(novo && novo!==cur.name){
        this.ajax('update_card', {id: this.currentCardId, name: novo}).then(res=>{
          if(res.success){
            cur.name=novo;
            $('#card-modal-title').innerHTML = `<span style="color:#5e6c84;font-weight:700;margin-right:6px">#${this.currentCardId}</span>${this.escape(novo)}`;
            this.renderBoard();
            this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
          }
        });
      }
    },
    editMaintenanceCardTitle(customTitle){
      const pickerTitle = customTitle || "Alterar entidade — Manutenção";
      const cur = this.cards.find(c=> c.id==this.currentCardId);
      if(!cur) return;
      this.showPicker({title:pickerTitle, html: '<div style="padding:24px;text-align:center;color:#5e6c84"><i class="ti ti-loader" style="font-size:20px;animation:spin 1s linear infinite;display:inline-block"></i><br>Carregando entidades...</div>'});
      const doShow = (entities)=>{
        // botão Escola: mostra todas, inclusive a mãe (só ignora a raiz técnica)
        const filteredEntities = (entities||[]).filter(e=>{
          const raw=(e.completename||e.name||'').trim();
          return raw !== 'Entidade Raiz' && raw.toLowerCase() !== 'entidade raiz';
        });
        this._renameEntities = filteredEntities;
        const curName = this.escape(cur.name);
        const html = `
          <div style="display:grid;gap:10px">
            <div style="background:#e6f7ff;border:1px solid #91d5ff;padding:8px 10px;border-radius:6px;color:#003a8c;font-size:12px;line-height:1.3">
              <strong><i class="ti ti-building" style="color:#1890ff"></i> Entidade atual:</strong> ${curName}<br><small style="color:#595959">Selecione outra entidade abaixo. Com "Somar" marcado o nome fica "Escola - Título atual"; desmarcado, substitui pelo nome da escola.</small>
            </div>
            <div style="position:relative">
              <input id="rename-entity-search" type="text" placeholder="Digite para buscar entidade... ex: Adelino, EE, Jales" autocomplete="off" style="width:100%;padding:10px 10px 10px 36px;border:2px solid #1890ff;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box" oninput="Kanpro.onRenameEntitySearch(this.value)" onfocus="Kanpro.showRenameEntityDropdown()" onkeydown="if(event.key==='Escape') Kanpro.hideRenameEntityDropdown()">
              <i class="ti ti-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8c8c8c;font-size:14px"></i>
              <div id="rename-entity-dropdown" style="position:absolute;top:100%;left:0;right:0;max-height:320px;overflow-y:auto;background:#fff;border:1px solid #91d5ff;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;z-index:20"></div>
            </div>
            <input type="hidden" id="rename-entity-select" value="">
            <div id="rename-entity-selected" style="font-size:12px;color:#389e0d;display:none;background:#f6ffed;border:1px solid #b7eb8f;padding:6px 8px;border-radius:4px"><i class="ti ti-check"></i> Selecionado: <strong id="rename-entity-selected-name"></strong> <a href="#" onclick="Kanpro.clearRenameSelection();return false" style="margin-left:8px;color:#ff4d4f;font-size:11px">trocar</a></div>
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:#172b4d;cursor:pointer;background:#f4f5f7;padding:8px 10px;border-radius:6px">
              <input type="checkbox" id="rename-entity-prepend" checked style="accent-color:#1890ff;width:16px;height:16px">
              <span>Somar com o título atual <small style="color:#8c8c8c">("Escola - Título")</small></span>
            </label>
            <div id="rename-entity-error" style="color:#eb5a46;font-size:12px;display:none;min-height:14px"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:#fff;padding-top:4px">
              <button onclick="Kanpro.closePicker()" style="background:#f4f5f7;border:none;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Cancelar</button>
              <button id="rename-entity-save" onclick="Kanpro.confirmRenameEntity()" style="background:#1890ff;color:#fff;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px">Salvar</button>
            </div>
          </div>
        `;
        // posiciona picker perto do título do card (topo do modal) ao invés do centro da tela
        const titleEl = document.getElementById('card-modal-title');
        let px=null, py=null;
        if(titleEl){
          const r = titleEl.getBoundingClientRect();
          px = Math.max(12, r.left);
          py = r.bottom + 8;
        }
        this.showPicker({title:pickerTitle, html, x: px, y: py});
        setTimeout(()=>{
          const picker = document.getElementById("kanpro-picker");
          const body = document.getElementById("picker-body");
          if(picker){
            picker.style.maxHeight = "none";
            picker.style.height = "auto";
            picker.style.display = "flex";
            picker.style.flexDirection = "column";
            // sem barra de rolagem — modal cresce conforme conteúdo
            picker.style.minWidth = "500px";
            picker.style.width = "560px";
            picker.style.maxWidth = "94vw";
            picker.style.overflow = "visible";
          }
          if(body){
            body.style.maxHeight = "none";
            body.style.height = "auto";
            body.style.overflowY = "visible";
            body.style.overflow = "visible";
          }
          const search=document.getElementById("rename-entity-search");
          if(search) search.focus();
          this.renderRenameEntityDropdown("");
          if(search){
            search.addEventListener("keydown", e=>{
              if(e.key==="Enter"){
                e.preventDefault();
                const dd=document.getElementById("rename-entity-dropdown");
                const first=dd?.querySelector(".rename-entity-item");
                if(first) first.click();
                else document.getElementById("rename-entity-save")?.click();
              } else if(e.key==="Escape"){
                this.hideRenameEntityDropdown();
              }
            });
          }
          const onDocClick=(ev)=>{
            const wrap=document.getElementById("rename-entity-search")?.parentElement;
            const dd=document.getElementById("rename-entity-dropdown");
            if(wrap && dd && !wrap.contains(ev.target)){
              this.hideRenameEntityDropdown();
            }
          };
          if(this._renameDocClick) document.removeEventListener("click", this._renameDocClick);
          this._renameDocClick=onDocClick;
          document.addEventListener("click", onDocClick);
        }, 30);
      };
      if(this._renameEntities && this._renameEntities.length){
        doShow(this._renameEntities);
      } else {
        this.ajax("list_entities", {include_root: 1}).then(res=>{
          let entities = (res && res.success && Array.isArray(res.entities)) ? res.entities : [];
          this._renameEntities = entities;
          doShow(entities);
        }).catch(()=>{
          doShow([]);
        });
      }
    },
    onRenameEntitySearch(q){ this.renderRenameEntityDropdown(q||""); this.showRenameEntityDropdown(); },
    showRenameEntityDropdown(){
      const dd=document.getElementById("rename-entity-dropdown");
      if(dd) dd.style.display="block";
    },
    hideRenameEntityDropdown(){
      const dd=document.getElementById("rename-entity-dropdown");
      if(dd) dd.style.display="none";
    },
    renderRenameEntityDropdown(filter){
      const dd=document.getElementById("rename-entity-dropdown");
      if(!dd) return;
      const norm=s=> s.normalize ? s.normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase() : s.toLowerCase();
      const term=norm((filter||"").trim());
      const entities=this._renameEntities||this._maintEntities||[];
      const filtered=entities.filter(e=>{
        const raw=(e.completename||e.name||'');
        const short=raw.includes(' > ') ? raw.split(' > ').pop().trim() : raw;
        const hay=norm(short+" "+raw);
        return !term || hay.includes(term);
      }).slice(0,80);
      if(!filtered.length){
        dd.innerHTML='<div style="padding:10px;color:#8c8c8c;font-size:12px;text-align:center">Nenhuma entidade encontrada</div>';
        dd.style.display="block";
        return;
      }
      dd.innerHTML=filtered.map(e=>{
        const raw=(e.completename||e.name||'');
        const short=raw.includes(' > ') ? raw.split(' > ').pop().trim() : raw;
        const escShort=this.escape(short);
        const escRaw=this.escape(raw);
        const hint = raw!==short ? ` title="${escRaw}"` : "";
        return `<div class="rename-entity-item" data-id="${e.id}" data-name="${escShort}"${hint} style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:12px;display:flex;justify-content:space-between;align-items:center"><span>${escShort}</span><small style="color:#8c8c8c;margin-left:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:45%">${escRaw!==short?escRaw:''}</small></div>`;
      }).join("");
      dd.querySelectorAll(".rename-entity-item").forEach(el=>{
        el.addEventListener("click", ()=>{
          const id=parseInt(el.dataset.id);
          const name=el.dataset.name;
          this.selectRenameEntity(id, name);
        });
        el.addEventListener("mouseenter", ()=> el.style.background="#e6f7ff");
        el.addEventListener("mouseleave", ()=> el.style.background="#fff");
      });
      dd.style.display="block";
    },
    selectRenameEntity(id, name){
      const hid=document.getElementById("rename-entity-select");
      const search=document.getElementById("rename-entity-search");
      const selBox=document.getElementById("rename-entity-selected");
      const selName=document.getElementById("rename-entity-selected-name");
      if(hid){ hid.value=String(id); hid.dataset.name=name; }
      if(search) search.value=name;
      if(selName) selName.textContent=name;
      if(selBox) selBox.style.display="block";
      this.hideRenameEntityDropdown();
      const err=document.getElementById("rename-entity-error");
      if(err) err.style.display="none";
      const sI=document.getElementById("rename-entity-search");
      if(sI) sI.style.borderColor="#52c41a";
    },
    clearRenameSelection(){
      const hid=document.getElementById("rename-entity-select");
      const search=document.getElementById("rename-entity-search");
      const selBox=document.getElementById("rename-entity-selected");
      if(hid){ hid.value=""; hid.dataset.name=""; }
      if(search){ search.value=""; search.focus(); search.style.borderColor="#1890ff"; }
      if(selBox) selBox.style.display="none";
      this.renderRenameEntityDropdown("");
      this.showRenameEntityDropdown();
    },
    confirmRenameEntity(){
      const hid=document.getElementById("rename-entity-select");
      const search=document.getElementById("rename-entity-search");
      const err=document.getElementById("rename-entity-error");
      const btn=document.getElementById("rename-entity-save");
      let entities_id = hid ? parseInt(hid.value||'', 10) : NaN;
      let selectedName = hid ? (hid.dataset.name||"") : "";
      // id 0 é válido (entidade raiz renomeada) — só NaN/negativo é "nada selecionado"
      const noneSelected = !Number.isInteger(entities_id) || entities_id < 0;
      if(noneSelected){
        // fallback: resolve pelo texto (caso o clique não tenha fixado o id — ex: nome digitado por extenso)
        const typed = (search ? search.value : '').trim();
        if(typed){
          const norm=s=> s.normalize ? s.normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase() : s.toLowerCase();
          const ntyped = norm(typed);
          const found = (this._renameEntities||this._maintEntities||[]).find(e=>{
            const raw=(e.completename||e.name||'');
            const short=raw.includes(' > ') ? raw.split(' > ').pop().trim() : raw;
            return norm(short)===ntyped || norm(raw)===ntyped || norm(e.name||'')===ntyped;
          });
          if(found && found.id !== undefined && found.id !== null && String(found.id).trim() !== ''){
            entities_id = parseInt(found.id, 10);
            const fraw=(found.completename||found.name||'');
            selectedName = fraw.includes(' > ') ? fraw.split(' > ').pop().trim() : fraw;
            if(hid){ hid.value=String(entities_id); hid.dataset.name=selectedName; }
            try{ console.warn('[kanpro] entidade resolvida pelo texto:', typed, entities_id); }catch(e){}
          }
        }
      }
      if(!Number.isInteger(entities_id) || entities_id < 0){
        if(err){ err.textContent="Selecione a entidade."; err.style.display="block"; }
        if(search){ search.style.borderColor="#eb5a46"; search.focus(); this.showRenameEntityDropdown(); }
        return;
      }
      let newName = selectedName;
      const ent = (this._renameEntities||this._maintEntities||[]).find(e=> String(e.id)===String(entities_id));
      if(ent){
        const raw=(ent.completename||ent.name||'');
        const short=raw.includes(' > ') ? raw.split(' > ').pop().trim() : raw;
        const cleanShort = short.includes('Unidade Regional') ? (ent.name||short) : short;
        newName = cleanShort;
        if(!newName) newName=selectedName;
      }
      newName = newName.trim().substring(0,255);
      // "Somar com o título atual" (marcado por padrão): "Escola - Título atual"
      const prepend = document.getElementById('rename-entity-prepend');
      const curCard = this.cards.find(c=> String(c.id)===String(this.currentCardId));
      const curTitle = (curCard ? curCard.name : '').trim();
      if(prepend && prepend.checked && curTitle && curTitle.toLowerCase() !== newName.toLowerCase() && !curTitle.toLowerCase().startsWith(newName.toLowerCase() + ' - ')){
        newName = (newName + ' - ' + curTitle).substring(0,255);
      }
      if(!newName){
        if(err){ err.textContent="Nome da entidade vazio."; err.style.display="block"; }
        return;
      }
      if(btn){ btn.disabled=true; btn.textContent="Salvando..."; }
      if(err) err.style.display="none";
      this.ajax("update_card", {id: this.currentCardId, name: newName}).then(res=>{
        if(btn){ btn.disabled=false; btn.textContent="Salvar"; }
        if(!res.success){
          if(err){ err.textContent=res.msg||"Falha ao renomear"; err.style.display="block"; }
          return;
        }
        this.closePicker();
        this.showToast("Nome alterado para: " + newName);
        const c = this.cards.find(x=> String(x.id)===String(this.currentCardId));
        if(c){ c.name=newName; this.renderBoard(); }
        this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    editDescription(){
      $('#card-modal-desc').style.display='none';
      $('#card-desc-edit').style.display='block';
      $('#card-desc-actions').style.display='flex';
      $('#card-desc-edit').focus();
    },
    cancelDescription(){
      $('#card-modal-desc').style.display='block';
      $('#card-desc-edit').style.display='none';
      $('#card-desc-actions').style.display='none';
    },
    saveDescription(){
      const val = $('#card-desc-edit').value;
      this.ajax('update_card', {id: this.currentCardId, description: val}).then(res=>{
        if(res.success){
          $('#card-modal-desc').innerHTML = val ? this.parseMarkdown(val) : 'Adicionar uma descrição mais detalhada...';
          $('#card-modal-desc').style.opacity = val ? '1':'0.6';
          const card = this.cards.find(c=>c.id==this.currentCardId);
          if(card) card.description=val;
          this.cancelDescription();
          this.renderBoard();
        }
      });
    },

    // Members picker
    openMembersPicker(){
      const cardId = this.currentCardId;
      const members = this.cardMembers[cardId]||[];
      const memberIds = new Set(members.map(m=> m.users_id));
      // busca usuários do quadro + todos GLPI? Usa members do quadro + users_dropdown se houver
      // para simplificar usa lista de membros do quadro + busca via ajax? Aqui usa members do quadro
      // fallback: se poucos, usa todos do board members + tenta buscar via API? Usa this.members
      const allUsers = this.members.length ? this.members : [];
      // se vazio, tenta usar lista fixa do backend (users_dropdown injetado? não temos, então busca via DOM)
      // Vamos buscar via ajax? Adiciona opção de buscar
      let html = `<input type="text" placeholder="Buscar membros..." oninput="Kanpro.filterPicker(this.value)" style="width:100%;padding:6px 8px;border:1px solid #dfe1e6;border-radius:4px;margin-bottom:8px"><div style="max-height:240px;overflow:auto">`;
      if(allUsers.length===0) html += `<div style="color:#5e6c84;font-size:13px">Nenhum membro no quadro. O acesso é gerenciado na engrenagem da tela "Seus Quadros".</div>`;
      else {
        html += allUsers.map(u=>`
          <label class="kp-picker-item" data-search="${this.escape(u.name)}" style="cursor:pointer">
            <input type="checkbox" ${memberIds.has(u.users_id)?'checked':''} onchange="Kanpro.toggleCardMember(${cardId}, ${u.users_id}, this.checked)"> 
            ${this.avatarHtml(u.picture_url, u.initials, u.name, 'sm')} 
            <span style="flex:1">${this.escape(u.name)} <small style="color:#5e6c84">${this.escape(u.role||'')}</small></span>
            ${memberIds.has(u.users_id)?'<i class="ti ti-check" style="color:#61bd4f"></i>':''}
          </label>
        `).join('');
      }
      html += `</div><div style="margin-top:8px;font-size:11px;color:#5e6c84;text-align:center">O acesso ao quadro é gerenciado na engrenagem da tela "Seus Quadros".</div>`;
      this.showPicker({title:'Membros', html, x: null, y: null}); // centraliza se null
    },

    toggleCardMember(cardId, users_id, checked){
      // o checkbox já reflete, mas faz toggle via ajax (que já verifica exists)
      // se checked true mas já existia, o backend vai remover — então precisamos garantir lógica
      // nossa toggle já alterna; então se checked true, mas backend diz added false? Vamos só chamar toggle e atualizar UI
      this.ajax('toggle_card_member', {cards_id: cardId, users_id}).then(res=>{
        if(res.success){
          // atualiza local
          let arr = this.cardMembers[cardId]||[];
          if(res.added){
            const u = this.members.find(x=> x.users_id==users_id);
            if(u) arr.push({users_id, name: u.name, initials: u.initials});
          } else {
            arr = arr.filter(x=> x.users_id!=users_id);
          }
          this.cardMembers[cardId]=arr;
          // atualiza modal members
          this.ajax('get_card', {cards_id: cardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
        }
      });
    },

    // Labels picker
    openLabelsPicker(){
      const cardId = this.currentCardId;
      const cardLabelIds = new Set((this.cardLabels[cardId]||[]).map(l=> l.id));
      let html = `<div style="display:grid;gap:6px">`;
      // labels do quadro
      if(this.labels.length===0) html+=`<div style="color:#5e6c84">Nenhuma etiqueta.</div>`;
      this.labels.forEach(l=>{
        const checked = cardLabelIds.has(l.id);
        html+=`
          <label class="kp-picker-item" style="background:${this.escape(l.color)};color:#fff;padding:8px;border-radius:4px;cursor:pointer;justify-content:space-between">
            <span style="display:flex;align-items:center;gap:8px"><input type="checkbox" ${checked?'checked':''} onchange="Kanpro.toggleLabel(${cardId}, ${l.id}, this.checked)" style="accent-color:#fff"> ${this.escape(l.name||'Etiqueta')}</span>
            <span style="display:flex;gap:4px">
              <button onclick="event.preventDefault(); Kanpro.editLabel(${l.id})" style="background:rgba(255,255,255,.3);border:none;color:#fff;padding:2px 6px;border-radius:4px;cursor:pointer"><i class="ti ti-pencil"></i></button>
              <button onclick="event.preventDefault(); Kanpro.askDeleteLabel(${l.id})" style="background:rgba(255,255,255,.3);border:none;color:#fff;padding:2px 6px;border-radius:4px;cursor:pointer"><i class="ti ti-trash"></i></button>
            </span>
          </label>`;
      });
      html+=`</div>
        <hr style="margin:12px 0;border:none;border-top:1px solid #dfe1e6">
        <div style="display:grid;gap:8px">
          <strong>Criar nova etiqueta</strong>
          <div style="display:flex;gap:6px">
            <input id="new-label-name" type="text" placeholder="Nome (opcional)" style="flex:1;padding:6px 8px;border:1px solid #dfe1e6;border-radius:4px">
            <input id="new-label-color" type="color" value="#61bd4f" style="width:40px;height:32px;border:none;padding:0">
            <button onclick="Kanpro.createLabel()" style="background:#0079bf;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer">Criar</button>
          </div>
        </div>`;
      this.showPicker({title:'Etiquetas', html});
    },
    toggleLabel(cardId, labelId, checked){
      this.ajax('toggle_card_label', {cards_id: cardId, labels_id: labelId}).then(res=>{
        if(res.success){
          // atualiza local
          const label = this.labels.find(l=> l.id==labelId);
          let arr = this.cardLabels[cardId]||[];
          if(res.added) arr.push(label);
          else arr = arr.filter(x=> x.id!=labelId);
          this.cardLabels[cardId]=arr;
          this.renderBoard();
          this.ajax('get_card', {cards_id: cardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
        }
      });
    },
    createLabel(){
      const name = $('#new-label-name').value.trim();
      const color = $('#new-label-color').value;
      this.ajax('add_label', {boards_id: this.board.id, name, color}).then(res=>{
        if(res.success){
          this.labels.push({id: res.id, plugin_kanpro_boards_id: this.board.id, name, color});
          this.openLabelsPicker();
          this.renderBoardMenuDetails();
        }
      });
    },
    async editLabel(labelId){
      const l = this.labels.find(x=> x.id==labelId);
      const newName = await this.kpPrompt('Nome da etiqueta:', l.name);
      if(newName===null) return;
      const newColor = await this.kpPrompt('Cor (hex #rrggbb):', l.color) || l.color;
      const curDue = (l.due_date||'').substring(0,10);
      const newDue = await this.kpPrompt('Prazo da etiqueta (AAAA-MM-DD — cartão fica vermelho ao vencer; vazio = sem prazo):', curDue);
      if(newDue===null) return;
      const dueVal = (newDue.trim() !== '' && /^\d{4}-\d{2}-\d{2}/.test(newDue.trim())) ? newDue.trim() : '';
      this.ajax('update_label', {id: labelId, name: newName, color: newColor}).then(res=>{
        if(!res.success) return;
        l.name=newName; l.color=newColor;
        this.ajax('set_label_due', {id: labelId, due_date: dueVal}).then(r2=>{
          if(r2.success){
            l.due_date = dueVal || null;
            // reflete nos cartões
            for(let cid in this.cardLabels) (this.cardLabels[cid]||[]).forEach(x=>{ if(x.id==labelId) x.due_date = l.due_date; });
            this.openLabelsPicker(); this.renderBoard();
          }
        });
      });
    },
    deleteLabel(labelId){
      this.ajax('delete_label', {id: labelId}).then(res=>{
        if(res.success){ this.labels = this.labels.filter(x=> x.id!=labelId); for(let cid in this.cardLabels) this.cardLabels[cid]=this.cardLabels[cid].filter(x=> x.id!=labelId); this.openLabelsPicker(); this.renderBoard(); }
      });
    },

    // Checklist
    async addChecklist(){
      const name = await this.kpPrompt('Nome do checklist:', 'Checklist');
      if(!name) return;
      this.ajax('add_checklist', {cards_id: this.currentCardId, name}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success){ this.renderCardModal(r.data); this.updateCheckProgressLocal(r.data); } });
      });
    },
    openChecklistPicker(){
      this.addChecklist();
    },
    async deleteChecklist(id){
      if(!await this.kpConfirm('Excluir checklist?')) return;
      this.ajax('delete_checklist', {id}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success){ this.renderCardModal(r.data); this.updateCheckProgressLocal(r.data); } });
      });
    },
    addCheckItem(clId, input){
      const name = input.value.trim();
      if(!name) return;
      this.ajax('add_checkitem', {checklists_id: clId, name}).then(res=>{
        if(res.success){ input.value=''; this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success){ this.renderCardModal(r.data); this.updateCheckProgressLocal(r.data); } }); }
      });
    },
    toggleCheckItem(itemId, checked){
      this.ajax('toggle_checkitem', {id: itemId}).then(res=>{
        if(res.success){
          this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success){ this.renderCardModal(r.data); this.updateCheckProgressLocal(r.data); } });
        }
      });
    },
    async editCheckItem(itemId){
      const novo = await this.kpPrompt('Editar item:');
      if(novo===null) return;
      this.ajax('rename_checkitem', {id: itemId, name: novo}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    deleteCheckItem(itemId){
      this.ajax('delete_checkitem', {id: itemId}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success){ this.renderCardModal(r.data); this.updateCheckProgressLocal(r.data); } });
      });
    },
    updateCheckProgressLocal(data){
      // recalcula badge de progresso (X/Y) do cartão a partir dos checklists retornados por get_card
      if(!data || !data.id) return;
      let total = 0, done = 0;
      (data.checklists || []).forEach(cl=>{
        (cl.items || []).forEach(it=>{
          total++;
          if (it.is_checked == 1 || it.is_checked === true) done++;
        });
      });
      this.checkProgress[data.id] = {total, done};
      this.renderBoard();
    },

    // ---------- CARD <-> CHAMADO ----------
    async ticketButton(){
      if(!this.currentCardId) return;
      const atual = this.ticketMap && this.ticketMap[this.currentCardId];
      if (atual) {
        const change = await this.kpConfirm('Este cartão já tem o chamado #' + atual.id + ' vinculado.\n\n(OK = vincular OUTRO pelo nº • Cancelar = voltar)');
        if (!change) return;
        this.linkTicketPicker();
        return;
      }
      const create = await this.kpConfirm('Criar um NOVO chamado a partir deste cartão?\n\n(OK = criar novo • Cancelar = vincular um existente pelo nº)');
      if (create) {
        this.ajax('create_ticket_from_card', {cards_id: this.currentCardId}).then(res=>{
          if(res.success){
            this.ticketMap[this.currentCardId] = res.ticket;
            const c = this.cards.find(x=> x.id==this.currentCardId);
            if(c) c.tickets_id = res.ticket.id;
            this.showToast('Chamado #' + res.ticket.id + (res.existed ? ' (já existia, vinculado)' : ' criado e vinculado!'));
            this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
            this.renderBoard();
          } else alert(res.msg||'Erro ao criar chamado');
        });
      } else {
        this.linkTicketPicker();
      }
    },
    handleConvertTicket(res){
      if(!res || !this.currentCardId) return;
      if(res.ticket_id){
        this.ticketMap[this.currentCardId] = {id: res.ticket_id, name:'', restricted:true, status:1, status_label:'Novo'};
        const c = this.cards.find(x=> String(x.id)===String(this.currentCardId));
        if(c) c.tickets_id = res.ticket_id;
        this.showToast('Chamado #' + res.ticket_id + ' criado automaticamente!');
        this.renderBoard();
      } else if(res.ticket_warning){
        this.showToast('Chamado não criado: ' + res.ticket_warning);
      }
    },
    async linkTicketPicker(){
      if(!this.currentCardId) return;
      const atual = this.ticketMap && this.ticketMap[this.currentCardId];
      const val = await this.kpPrompt('Nº do chamado GLPI para vincular:' + (atual ? ' (atual: #' + atual.id + ')' : ''), atual ? String(atual.id) : '');
      if(val===null) return;
      const tid = parseInt(val, 10);
      if(!tid){ alert('Informe um número de chamado válido'); return; }
      this.ajax('link_ticket', {cards_id: this.currentCardId, tickets_id: tid}).then(res=>{
        if(res.success){
          this.ticketMap[this.currentCardId] = res.ticket;
          const c = this.cards.find(x=> x.id==this.currentCardId);
          if(c) c.tickets_id = tid;
          this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
          this.renderBoard();
        } else alert(res.msg||'Erro ao vincular');
      });
    },
    async unlinkTicket(){
      if(!this.currentCardId) return;
      if(!await this.kpConfirm('Desvincular o chamado deste cartão?')) return;
      this.ajax('unlink_ticket', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){
          delete this.ticketMap[this.currentCardId];
          const c = this.cards.find(x=> x.id==this.currentCardId);
          if(c) c.tickets_id = 0;
          this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
          this.renderBoard();
        } else alert(res.msg||'Erro');
      });
    },

    addComment(){
      const val = $('#card-comment-input').value.trim();
      if(!val) return;
      this.ajax('add_comment', {cards_id: this.currentCardId, content: val}).then(res=>{
        if(res.success){
          $('#card-comment-input').value='';
          this.commentCounts[this.currentCardId] = (this.commentCounts[this.currentCardId]||0)+1;
          this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
        }
      });
    },
    async editComment(id){
      const existing = (this._lastModalData?.comments || []).find(c=> String(c.id)===String(id));
      const cur = await this.kpPrompt('Editar comentário:', existing ? existing.content : '');
      if(cur===null) return;
      this.ajax('update_comment', {id, content: cur}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    pinComment(id){
      this.ajax('toggle_comment_pin', {id}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
        else alert(res.msg||'Erro');
      });
    },
    async deleteComment(id){
      if(!await this.kpConfirm('Excluir comentário?')) return;
      this.ajax('delete_comment', {id}).then(res=>{
        if(res.success){ this.commentCounts[this.currentCardId] = Math.max(0,(this.commentCounts[this.currentCardId]||1)-1); this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); }); }
      });
    },
    toggleActivity(){
      const el = $('#card-modal-activity');
      el.style.display = el.style.display==='none' ? 'grid' : 'none';
    },

    previewImage(url, name){
      const overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:30000;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;cursor:zoom-out';
      overlay.innerHTML = `
        <div style="position:absolute;top:16px;right:20px;display:flex;gap:12px;align-items:center">
          <a href="${url}" target="_blank" style="color:#fff;text-decoration:none;font-size:13px;background:rgba(255,255,255,.15);padding:6px 12px;border-radius:6px" onclick="event.stopPropagation()"><i class="ti ti-download"></i> Abrir original</a>
          <button style="background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:16px" onclick="event.stopPropagation();this.closest('div[style*=fixed]').remove()">✕</button>
        </div>
        <img src="${url}" alt="${this.escape(name||'')}" style="max-width:90vw;max-height:82vh;object-fit:contain;border-radius:4px;box-shadow:0 8px 32px rgba(0,0,0,.5);cursor:default" onclick="event.stopPropagation()">
        ${name ? `<div style="color:#fff;margin-top:12px;font-size:13px;opacity:.8">${this.escape(name)}</div>` : ''}
      `;
      overlay.addEventListener('click', ()=> overlay.remove());
      const onKey = e=>{ if(e.key==='Escape'){ overlay.remove(); document.removeEventListener('keydown', onKey); } };
      document.addEventListener('keydown', onKey);
      document.body.appendChild(overlay);
    },
    previewPdf(url, name){
      const overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:30000;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px';
      overlay.innerHTML = `
        <div style="position:absolute;top:16px;right:20px;display:flex;gap:12px;align-items:center">
          <a href="${url}" target="_blank" style="color:#fff;text-decoration:none;font-size:13px;background:rgba(255,255,255,.15);padding:6px 12px;border-radius:6px"><i class="ti ti-download"></i> Abrir original</a>
          <button data-a="close" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:16px">✕</button>
        </div>
        <iframe src="${url}" style="width:min(900px, 90vw);height:82vh;border:none;border-radius:4px;background:#fff;box-shadow:0 8px 32px rgba(0,0,0,.5)"></iframe>
        ${name ? `<div style="color:#fff;margin-top:12px;font-size:13px;opacity:.8">${this.escape(name)}</div>` : ''}
      `;
      overlay.querySelector('[data-a="close"]').onclick = ()=> overlay.remove();
      const onKey = e=>{ if(e.key==='Escape'){ overlay.remove(); document.removeEventListener('keydown', onKey); } };
      document.addEventListener('keydown', onKey);
      document.body.appendChild(overlay);
    },
    // Attachments
    uploadAttachment(input){
      const file = input.files[0];
      if(!file) return;
      const fd = new FormData();
      fd.append('file', file);
      fd.append('cards_id', this.currentCardId);
      this.ajax('upload_attachment', fd, true).then(res=>{
        if(res.success){
          this.attCounts[this.currentCardId]=(this.attCounts[this.currentCardId]||0)+1;
          this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
        } else alert('Erro no upload');
        input.value='';
      });
    },
    async deleteAttachment(id){
      if(!await this.kpConfirm('Excluir anexo?')) return;
      this.ajax('delete_attachment', {id}).then(res=>{
        if(res.success){ this.attCounts[this.currentCardId]=Math.max(0,(this.attCounts[this.currentCardId]||1)-1); this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); }); }
      });
    },
    makeCover(attId){
      // usa attachment como capa? Para simplificar usa cor
      alert('Coberturas com imagem em breve. Use "Capa" para cor.');
    },

    // Dates
    openDatesPicker(){
      const card = this.cards.find(c=> c.id==this.currentCardId);
      const html = `
        <div style="display:grid;gap:12px">
          <label>Data de início<br><input type="datetime-local" id="picker-start" value="${card.start_date ? this.toLocalDatetime(card.start_date) : ''}" style="width:100%;padding:6px;border:1px solid #dfe1e6;border-radius:4px"></label>
          <label>Data de entrega<br><input type="datetime-local" id="picker-due" value="${card.due_date ? this.toLocalDatetime(card.due_date) : ''}" style="width:100%;padding:6px;border:1px solid #dfe1e6;border-radius:4px"></label>
          <label><input type="checkbox" id="picker-complete" ${card.is_completed?'checked':''}> Marcar como concluído</label>
          <div style="display:flex;gap:8px">
            <button onclick="Kanpro.saveDates()" style="background:#0079bf;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;flex:1">Salvar</button>
            <button onclick="Kanpro.closePicker()" style="background:#dfe1e6;border:none;padding:8px 16px;border-radius:4px;cursor:pointer">Cancelar</button>
          </div>
          <button onclick="Kanpro.clearDates()" style="background:#eb5a46;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer">Remover datas</button>
        </div>`;
      this.showPicker({title:'Datas', html});
    },
    saveDates(){
      const start = $('#picker-start').value ? $('#picker-start').value.replace('T',' ') + ':00' : '';
      const due = $('#picker-due').value ? $('#picker-due').value.replace('T',' ') + ':00' : '';
      const complete = $('#picker-complete').checked ? 1 : 0;
      Promise.all([
        this.ajax('set_dates', {cards_id: this.currentCardId, start_date: start, due_date: due}),
        this.ajax('update_card', {id: this.currentCardId, is_completed: complete})
      ]).then(()=>{
        this.closePicker();
        this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.updateCardLocalDates(start,due,complete); });
      });
    },
    clearDates(){
      this.ajax('set_dates', {cards_id: this.currentCardId, start_date: '', due_date: ''}).then(()=>{
        this.closePicker();
        this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); const c=this.cards.find(x=>x.id==this.currentCardId); if(c) c.due_date=null; this.renderBoard(); });
      });
    },
    updateCardLocalDates(start,due,complete){
      const c=this.cards.find(x=>x.id==this.currentCardId);
      if(c){ c.start_date=start||null; c.due_date=due||null; c.is_completed=complete; this.renderBoard(); }
    },
    toggleComplete(cardId, checked){
      this.ajax('toggle_complete', {cards_id: cardId}).then(res=>{
        if(res.success){ const c=this.cards.find(x=>x.id==cardId); if(c) c.is_completed=res.is_completed; this.ajax('get_card', {cards_id: cardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); }); }
      });
    },
    openCoverPicker(){
      const card = this.cards.find(c=> c.id==this.currentCardId);
      const colors = ['#61bd4f','#f2d600','#ff9f1a','#eb5a46','#c377e0','#0079bf','#00b8d9','#ff78cb','#344563','#6b778c'];
      let html = `<div style="display:grid;gap:8px"><div style="font-weight:600">Cor da capa</div><div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px">`;
      colors.forEach(col=>{
        const sel = card.cover_color===col ? '3px solid #172b4d' : '2px solid transparent';
        html+=`<button onclick="Kanpro.setCover('${col}')" style="height:32px;background:${col};border:${sel};border-radius:4px;cursor:pointer"></button>`;
      });
      html+=`</div><button onclick="Kanpro.setCover('')" style="background:#dfe1e6;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;width:100%">Remover capa</button></div>`;
      this.showPicker({title:'Capa', html});
    },
    setCover(color){
      this.ajax('set_cover', {cards_id: this.currentCardId, cover_color: color}).then(res=>{
        if(res.success){ const c=this.cards.find(x=>x.id==this.currentCardId); if(c) c.cover_color=color||null; this.closePicker(); this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); }); }
      });
    },

    // Move / Copy / Archive
    moveCardPicker(){
      const cardId = this.currentCardId;
      const card = this.cards.find(c=> c.id==cardId);
      let html = `<div style="display:grid;gap:8px">`;
      html+=`<label>Quadro: <strong>${this.escape(this.board.name)}</strong></label>`;
      html+=`<label>Lista<br><select id="move-list-select" style="width:100%;padding:6px;border:1px solid #dfe1e6;border-radius:4px">`;
      this.lists.forEach(l=> html+=`<option value="${l.id}" ${l.id==card.plugin_kanpro_lists_id?'selected':''}>${this.escape(l.name)}</option>`);
      html+=`</select></label>`;
      html+=`<label>Posição<br><select id="move-pos-select" style="width:100%;padding:6px;border:1px solid #dfe1e6;border-radius:4px"><option value="0">Topo</option><option value="9999" selected>Fim</option></select></label>`;
      html+=`<button onclick="Kanpro.confirmMoveCard()" style="background:#0079bf;color:#fff;border:none;padding:8px 12px;border-radius:4px;cursor:pointer">Mover</button>`;
      html+=`</div>`;
      this.showPicker({title:'Mover cartão', html});
    },
    confirmMoveCard(){
      const target = parseInt($('#move-list-select').value);
      const pos = parseInt($('#move-pos-select').value);
      const actualPos = pos===9999 ? null : pos;
      this.ajax('move_card', {cards_id: this.currentCardId, target_lists_id: target, position: actualPos===null?'':actualPos}).then(res=>{
        if(res.success){ this.closePicker(); this.closeCardModal(); location.reload(); }
      });
    },
    copyCard(){
      const targetList = this.cards.find(c=> c.id==this.currentCardId)?.plugin_kanpro_lists_id;
      this.ajax('copy_card', {cards_id: this.currentCardId, target_lists_id: targetList}).then(res=>{
        if(res.success){ alert('Cartão copiado!'); this.closeCardModal(); location.reload(); }
      });
    },
    async archiveCard(){
      if(!await this.kpConfirm('Arquivar este cartão?')) return;
      this.ajax('archive_card', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){ this.cards = this.cards.filter(c=> c.id!=this.currentCardId); this.closeCardModal(); this.renderBoard(); }
      });
    },
    async deleteCard(){
      if(!await this.kpConfirm('Excluir permanentemente? Esta ação não pode ser desfeita.')) return;
      this.ajax('delete_card', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){ this.cards = this.cards.filter(c=> c.id!=this.currentCardId); this.closeCardModal(); this.renderBoard(); }
      });
    },

    // Board actions
    async renameBoard(){
      if(!this.canEdit) return;
      const novo = await this.kpPrompt('Novo nome do quadro:', this.board.name);
      if(novo && novo!==this.board.name){
        this.ajax('rename_board', {boards_id: this.board.id, name: novo}).then(res=>{
          if(res.success){ this.board.name=novo; $('#board-title').textContent=novo; document.title = novo + ' — KanPro'; }
        });
      }
    },
    toggleStar(){
      this.ajax('star_board', {boards_id: this.board.id}).then(res=>{
        if(res.success) alert(res.is_starred?'⭐ Quadro favoritado!':'Removido dos favoritos');
      });
    },
    openBoardMenu(){
      $('#kanpro-board-menu').style.display='block';
      this.loadBoardActivity();
      this.renderBoardMenuColors();
      this.renderBoardMenuBackground();
      this.renderBoardMenuWallpapers();
      this.updateTrashCount();
    },
    closeBoardMenu(){ $('#kanpro-board-menu').style.display='none'; },
    updateTrashCount(){
      const el = document.getElementById('board-menu-trash-count');
      if(!el) return;
      const now = Date.now();
      if(this._trashCount && now - this._trashCount.t < 60000){ el.textContent = this._trashCount.txt; return; }
      this.ajax('get_trash', {boards_id: this.board.id}).then(res=>{
        if(res && res.success){
          const n = (res.archived||[]).length + (res.deleted||[]).length;
          const txt = n ? `(${n})` : '';
          this._trashCount = {t: Date.now(), txt};
          const el2 = document.getElementById('board-menu-trash-count');
          if(el2) el2.textContent = txt;
        }
      });
    },
    async duplicateBoard(){
      const name = await this.kpPrompt('Nome do novo quadro:', (this.board.name||'') + ' (cópia)');
      if(!name) return;
      this.ajax('duplicate_board', {boards_id: this.board.id, name}).then(res=>{
        if(res.success) window.location.href = 'kanban.php?boards_id=' + res.id;
        else alert(res.msg||'Erro ao duplicar');
      });
    },
    openTrash(){
      this.closeBoardMenu();
      this.showPicker({title:'🗑️ Lixeira do quadro', html:'<div style="padding:20px;text-align:center;color:#5e6c84">Carregando...</div>'});
      const p = document.getElementById('kanpro-picker');
      if(p){ p.style.minWidth='540px'; p.style.maxWidth='94vw'; p.style.width='560px'; p.style.maxHeight='90vh'; p.style.display='flex'; p.style.flexDirection='column'; }
      const b = document.getElementById('picker-body');
      if(b){ b.style.maxHeight='70vh'; b.style.overflowY='auto'; }
      this.loadTrash();
    },
    loadTrash(){
      this.ajax('get_trash', {boards_id: this.board.id}).then(res=>{
        const body = document.getElementById('picker-body');
        if(!body) return;
        if(!res.success){ body.innerHTML = '<div style="color:#bf2600">' + this.escape(res.msg||'Erro') + '</div>'; return; }
        this._trashCount = null;
        const arch = (res.archived||[]).map(a=>`
          <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #dfe1e6;padding:8px 10px;border-radius:8px;gap:8px">
            <span style="min-width:0"><span style="display:block;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${this.escape(a.name)}</span>
            <span style="display:block;font-size:11px;color:#5e6c84">${this.escape(a.list_name||'')} • ${this.formatDate(a.date_mod)}</span></span>
            <span style="display:flex;gap:6px;flex-shrink:0">
              <button onclick="Kanpro.restoreArchived(${a.id})" style="background:#e3fcef;border:1px solid #61bd4f;color:#006644;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700">Restaurar</button>
              <button onclick="Kanpro.deleteForever(${a.id}, 0)" title="Excluir definitivamente" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px">Excluir</button>
            </span>
          </div>`).join('') || '<div style="text-align:center;color:#5e6c84;font-size:12px;padding:8px">Nenhum cartão arquivado</div>';
        const del = (res.deleted||[]).map(t=>`
          <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #dfe1e6;padding:8px 10px;border-radius:8px;gap:8px">
            <span style="min-width:0"><span style="display:block;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${this.escape(t.card_name)}</span>
            <span style="display:block;font-size:11px;color:#5e6c84">${this.escape(t.list_name||'')} • excluído em ${this.formatDate(t.date)}</span></span>
            <span style="display:flex;gap:6px;flex-shrink:0">
              <button onclick="Kanpro.restoreTrashItem(${t.id})" style="background:#e3fcef;border:1px solid #61bd4f;color:#006644;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700">Restaurar</button>
              <button onclick="Kanpro.deleteForever(0, ${t.id})" title="Apagar para sempre" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px">Apagar</button>
            </span>
          </div>`).join('') || '<div style="text-align:center;color:#5e6c84;font-size:12px;padding:8px">Nada excluído</div>';
        body.innerHTML = `
          <div style="display:grid;gap:12px">
            <div><div style="font-size:12px;font-weight:700;color:#172b4d;margin-bottom:6px">📦 Arquivados (${(res.archived||[]).length})</div><div style="display:grid;gap:6px;max-height:200px;overflow-y:auto">${arch}</div></div>
            <div><div style="font-size:12px;font-weight:700;color:#172b4d;margin-bottom:6px">🗑️ Excluídos (${(res.deleted||[]).length})</div><div style="display:grid;gap:6px;max-height:200px;overflow-y:auto">${del}</div></div>
            <div style="font-size:11px;color:#97a0af">Restaurar excluídos recria o cartão (etiquetas, membros, checklists e comentários; anexos não voltam).</div>
          </div>`;
      });
    },
    restoreArchived(id){
      this.ajax('restore_archived', {cards_id: id}).then(res=>{
        if(res.success){ this.showToast('Cartão restaurado'); this.closePicker(); location.reload(); }
        else alert(res.msg||'Erro');
      });
    },
    async deleteForever(cardId, trashId){
      if(!confirm('Excluir DEFINITIVAMENTE? Não será possível restaurar.')) return;
      if(trashId){
        this.ajax('purge_trash', {trash_id: trashId}).then(res=>{
          if(res.success){ this.showToast('Apagado para sempre'); this.loadTrash(); }
          else alert(res.msg||'Erro');
        });
      } else {
        this.ajax('delete_card', {cards_id: cardId}).then(res=>{
          if(res.success){ this.showToast('Excluído'); this.loadTrash(); }
          else alert(res.msg||'Erro');
        });
      }
    },
    restoreTrashItem(id){
      this.ajax('restore_trash', {trash_id: id}).then(res=>{
        if(res.success){ this.showToast('Cartão restaurado'); this.closePicker(); location.reload(); }
        else alert(res.msg||'Erro');
      });
    },
    // --- NOVO: paleta de cores/temas dentro do quadro ---
    getBoardThemes(){
      // vem do PHP via K.themes, fallback para paleta padrão
      const src = (window.KANPRO && window.KANPRO.themes) || (K && K.themes);
      if (src && src.solids) return src;
      const solids = {
        '#0079bf':'Azul Clássico','#00aecc':'Ciano','#0091a8':'Teal','#00875a':'Verde Esmeralda','#4bbf6b':'Verde Claro','#61bd4f':'Verde Trello','#519839':'Verde Escuro','#7bc86c':'Menta','#d29034':'Laranja Queimado','#ff9f1a':'Laranja Vivo','#ff7a3d':'Laranja Avermelhado','#f2d600':'Amarelo Sol','#ffcc02':'Amarelo Ouro','#ff7452':'Coral','#eb5a46':'Vermelho Claro','#b04632':'Vermelho Tijolo','#c377e0':'Roxo Lavanda','#9c6ade':'Roxo Médio','#6554c0':'Roxo Profundo','#ff78cb':'Rosa Chiclete','#e1316f':'Rosa Forte','#344563':'Grafite','#172b4d':'Azul Marinho','#091e42':'Azul Noite','#6b778c':'Cinza Neutro','#2c3e50':'Cinza Azulado','#0078d4':'Azul Windows','#e95420':'Laranja Ubuntu','#8e8e93':'Cinza macOS'
      };
      const gradients = {
        'linear-gradient(135deg, #0079bf 0%, #00d2ff 100%)':'Oceano','linear-gradient(135deg, #61bd4f 0%, #00aecc 100%)':'Floresta Tropical','linear-gradient(135deg, #ff9f1a 0%, #eb5a46 100%)':'Pôr do Sol','linear-gradient(135deg, #6554c0 0%, #ff78cb 100%)':'Aurora Roxa','linear-gradient(135deg, #344563 0%, #091e42 100%)':'Noite Profunda','linear-gradient(135deg, #b04632 0%, #ffab00 100%)':'Vulcão','linear-gradient(135deg, #006064 0%, #00b8d9 100%)':'Ártico','linear-gradient(135deg, #d29034 0%, #f2d600 100%)':'Deserto Dourado','linear-gradient(135deg, #00875a 0%, #57d9a3 100%)':'Selva','linear-gradient(135deg, #e1316f 0%, #ff7452 100%)':'Magenta Flame','linear-gradient(135deg, #091e42 0%, #6554c0 100%)':'Galáxia','linear-gradient(135deg, #172b4d 0%, #00aecc 100%)':'Boreal','linear-gradient(135deg, #0b6cb4 0%, #59b947 100%)':'Windows XP','linear-gradient(135deg, #0078d4 0%, #5b5bd6 100%)':'Windows 11','linear-gradient(135deg, #0f9d8f 0%, #7fd4c1 100%)':'Windows Vista','linear-gradient(135deg, #e95420 0%, #77216f 100%)':'Ubuntu','linear-gradient(135deg, #3fa34d 0%, #1b4d2e 100%)':'Mint','linear-gradient(135deg, #2c001e 0%, #77216f 100%)':'Terminal Linux','linear-gradient(135deg, #2d5da1 0%, #9b59d0 100%)':'Big Sur','linear-gradient(135deg, #ff6a00 0%, #ee0979 100%)':'Ventura','linear-gradient(135deg, #1c1c1e 0%, #4a4a4f 100%)':'macOS Escuro'
      };
      return {solids, gradients};
    },
    renderBoardMenuColors(){
      const wrap = document.getElementById('board-menu-colors');
      const gradWrap = document.getElementById('board-menu-gradients');
      const preview = document.getElementById('board-menu-color-preview');
      const labelEl = document.getElementById('board-menu-color-label');
      const customInput = document.getElementById('board-menu-custom');
      if(!wrap || !gradWrap) return;
      const themes = this.getBoardThemes();
      const current = this.board.color || '#0079bf';
      // tenta achar label
      const allLabels = {...themes.solids, ...themes.gradients};
      const currentLabel = allLabels[current] || (current.includes('gradient') ? 'Degradê' : current);
      if(labelEl) labelEl.textContent = currentLabel;
      if(preview) preview.style.background = current;
      if(customInput && /^#[0-9a-fA-F]{6}$/.test(current)) customInput.value = current;
      // render sólidos
      wrap.innerHTML = Object.entries(themes.solids).map(([hex,label])=>{
        const isSel = hex === current;
        const border = isSel ? '3px solid #172b4d' : '2px solid transparent';
        const shadow = isSel ? '0 3px 10px rgba(9,30,66,.25)' : '0 1px 3px rgba(0,0,0,.15)';
        const check = isSel ? '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:'+ (hex.toLowerCase()==='#f2d600' || hex.toLowerCase()==='#ffcc02' ? '#172b4d':'#fff') +';font-weight:900;font-size:13px;text-shadow:0 1px 2px rgba(0,0,0,.4)">✓</span>' : '';
        return `<span title="${this.escape(label)}" onclick="Kanpro.setBoardColor('${this.escape(hex)}')" style="width:34px;height:34px;border-radius:6px;background:${this.escape(hex)};border:${border};box-shadow:${shadow};cursor:pointer;display:inline-block;position:relative;flex-shrink:0;transform:${isSel?'scale(1.06)':'scale(1)'}">${check}</span>`;
      }).join('');
      // render gradients
      gradWrap.innerHTML = Object.entries(themes.gradients).map(([grad,label])=>{
        const isSel = grad === current;
        const border = isSel ? '3px solid #172b4d' : '2px solid transparent';
        const shadow = isSel ? '0 3px 10px rgba(9,30,66,.25)' : '0 1px 3px rgba(0,0,0,.15)';
        const escGrad = grad.replace(/'/g, "\\'");
        const check = isSel ? '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:13px;text-shadow:0 1px 3px rgba(0,0,0,.5)">✓</span>' : '';
        return `<span title="${this.escape(label)}" onclick="Kanpro.setBoardColor('${escGrad}')" style="width:74px;height:34px;border-radius:6px;background:${grad};border:${border};box-shadow:${shadow};cursor:pointer;display:inline-block;position:relative;flex-shrink:0;transform:${isSel?'scale(1.04)':'scale(1)'}">${check}</span>`;
      }).join('');
    },
    getBoardBackgroundUrl(){
      const bg = this.board.background;
      if(!bg) return '';
      const base = this.ajax_url.replace('/ajax.php','/background.php');
      const v = String(bg).split('').reduce((a,c)=>a+c.charCodeAt(0),0) % 9973;
      return base + '?boards_id=' + this.board.id + '&v=' + v;
    },
    renderBoardMenuBackground(){
      const preview = document.getElementById('board-menu-bg-preview');
      const empty = document.getElementById('board-menu-bg-empty');
      const label = document.getElementById('board-menu-bg-label');
      const removeBtn = document.getElementById('board-menu-bg-remove');
      if(!preview) return;
      const bg = this.board.background;
      if(bg){
        const url = this.getBoardBackgroundUrl();
        preview.style.backgroundImage = "url('" + url + "')";
        preview.style.backgroundSize = 'cover';
        preview.style.backgroundPosition = 'center';
        preview.style.display = 'block';
        if(empty) empty.style.display = 'none';
        if(label) label.textContent = 'Imagem ativa';
        if(removeBtn) removeBtn.style.display = 'inline-flex';
      } else {
        preview.style.backgroundImage = 'none';
        preview.style.display = 'none';
        if(empty) empty.style.display = 'flex';
        if(label) label.textContent = 'Sem imagem';
        if(removeBtn) removeBtn.style.display = 'none';
      }
    },
    applyBoardBackground(){
      const app = document.getElementById('kanpro-app');
      if(!app) return;
      const color = this.board.color || '#0079bf';
      const bg = this.board.background;
      if(bg){
        const url = this.getBoardBackgroundUrl();
        app.style.background = "url('" + url + "') center / cover no-repeat, " + color;
        app.style.backgroundSize = 'cover';
        app.style.backgroundPosition = 'center';
      } else {
        app.style.background = color;
      }
    },
    uploadBoardBackground(input){
      const file = input.files[0];
      if(!file) return;
      if(file.size > 5*1024*1024){ alert('Imagem muito grande — máximo 5MB'); input.value=''; return; }
      if(!file.type.startsWith('image/')){ alert('Formato inválido — use JPG, PNG, WebP ou GIF'); input.value=''; return; }
      const preview = document.getElementById('board-menu-bg-preview');
      const empty = document.getElementById('board-menu-bg-empty');
      if(preview){
        preview.style.backgroundImage = "url('" + URL.createObjectURL(file) + "')";
        preview.style.display = 'block';
        preview.style.backgroundSize = 'cover';
        preview.style.backgroundPosition = 'center';
        if(empty) empty.style.display='none';
      }
      this.showToast('Enviando imagem...');
      const fd = new FormData();
      fd.append('file', file);
      fd.append('boards_id', this.board.id);
      this.ajax('upload_board_background', fd, true).then(res=>{
        input.value='';
        if(res.success){
          this.board.background = res.background;
          this.applyBoardBackground();
          this.renderBoardMenuBackground();
          this.showToast('Imagem de fundo atualizada! Recomendado 1920×1080');
        } else {
          alert(res.msg||'Erro ao enviar imagem');
          this.renderBoardMenuBackground();
        }
      });
    },
    removeBoardBackground(){
      if(!this.board.background) return;
      if(!confirm('Remover imagem de fundo? O quadro voltará para a cor/degradê.')) return;
      this.ajax('remove_board_background', {boards_id: this.board.id}).then(res=>{
        if(res.success){
          this.board.background = null;
          this.applyBoardBackground();
          this.renderBoardMenuBackground();
          this.showToast('Imagem removida');
        } else alert(res.msg||'Erro ao remover');
      });
    },
    // --- Papéis de parede prontos (SVG embutido) ---
    getBoardWallpapers(){
      return [
        {key:'xp-bliss', file:'xp-bliss.svg', label:'Windows XP'},
        {key:'win11-bloom', file:'win11-bloom.svg', label:'Windows 11'},
        {key:'vista-aurora', file:'vista-aurora.svg', label:'Windows Vista'},
        {key:'win7-aurora', file:'win7-aurora.svg', label:'Windows 7'},
        {key:'win10-hero', file:'win10-hero.svg', label:'Windows 10'},
        {key:'win98-clouds', file:'win98-clouds.svg', label:'Windows 98'},
        {key:'ubuntu', file:'ubuntu.svg', label:'Ubuntu'},
        {key:'mint', file:'mint.svg', label:'Mint'},
        {key:'fedora', file:'fedora.svg', label:'Fedora'},
        {key:'debian', file:'debian.svg', label:'Debian'},
        {key:'mac-waves', file:'mac-waves.svg', label:'macOS Ondas'},
        {key:'mac-night', file:'mac-night.svg', label:'macOS Noite'},
        {key:'mac-sonoma', file:'mac-sonoma.svg', label:'macOS Sonoma'},
        {key:'mac-sequoia', file:'mac-sequoia.svg', label:'macOS Sequoia'},
        {key:'mario', file:'mario.svg', label:'Mario'},
        {key:'csgo', file:'csgo.svg', label:'Counter-Strike'},
        {key:'mirage', file:'mirage.svg', label:'Mirage (CS2)'},
        {key:'lol', file:'lol.svg', label:'League of Legends'},
        {key:'valorant', file:'valorant.svg', label:'Valorant'},
        {key:'kanban', file:'kanban.svg', label:'Kanban'},
        {key:'inventario', file:'inventario.svg', label:'Inventário'},
        {key:'protocolo', file:'protocolo.svg', label:'Protocolo'},
        {key:'acesso-escola', file:'acesso-escola.svg', label:'Acesso Escola'},
        {key:'agenda-carros', file:'agenda-carros.svg', label:'Agenda Carros'},
        {key:'whatsapp', file:'whatsapp.svg', label:'WhatsApp'},
        {key:'manutencao-pc', file:'manutencao-pc.svg', label:'Manutenção PC'},
        {key:'manutencao-notebook', file:'manutencao-notebook.svg', label:'Manutenção Notebook'},
        {key:'manutencao-rede', file:'manutencao-rede.svg', label:'Manutenção Rede'},
      ];
    },
    wallpaperThumbUrl(file){
      return this.ajax_url.replace('/front/ajax.php','/public/img/wallpapers/'+file);
    },
    setBoardWallpaper(key, closeAfter){
      if(!key) return;
      this.ajax('set_board_wallpaper', {boards_id: this.board.id, wallpaper: key}).then(res=>{
        if(res.success){
          this.board.background = res.background;
          this.applyBoardBackground();
          this.renderBoardMenuBackground();
          this.showToast('Papel de parede aplicado!');
          if(closeAfter) this.closePicker();
        } else alert(res.msg||'Erro ao aplicar papel de parede');
      });
    },
    renderBoardMenuWallpapers(){
      const wrap = document.getElementById('board-menu-wallpapers');
      if(!wrap) return;
      wrap.innerHTML = this.getBoardWallpapers().map(w=>`
        <div onclick="Kanpro.setBoardWallpaper('${w.key}')" title="${this.escape(w.label)}" style="position:relative;border-radius:8px;overflow:hidden;cursor:pointer;border:2px solid #dfe1e6;box-shadow:0 1px 3px rgba(0,0,0,.15);height:56px">
          <img src="${this.wallpaperThumbUrl(w.file)}" alt="${this.escape(w.label)}" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">
          <span style="position:absolute;left:0;right:0;bottom:0;background:rgba(9,30,66,.65);color:#fff;font-size:10px;font-weight:700;padding:2px 6px">${this.escape(w.label)}</span>
        </div>`).join('');
    },
    setBoardColor(color){
      if(!color) return;
      const isHex = /^#[0-9a-fA-F]{6}$/.test(color);
      const isGrad = color.startsWith('linear-gradient');
      if(!isHex && !isGrad){ alert('Cor inválida'); return; }
      const old = this.board.color;
      this.board.color = color;
      this.applyBoardBackground();
      this.renderBoardMenuColors();
      this.ajax('update_board_color', {boards_id: this.board.id, color}).then(res=>{
        if(res.success){
          this.showToast('Tema atualizado!');
        } else {
          this.board.color = old;
          this.applyBoardBackground();
          this.renderBoardMenuColors();
          alert(res.msg||'Erro ao salvar cor');
        }
      });
    },
    async openBoardSettings(){
      // abre picker centralizado com paleta completa (alternativa ao menu lateral)
      const themes = this.getBoardThemes();
      const current = this.board.color || '#0079bf';
      let solidsHtml = Object.entries(themes.solids).map(([hex,label])=>{
        const isSel = hex === current;
        const border = isSel ? '3px solid #172b4d' : '2px solid #dfe1e6';
        const check = isSel ? '✓' : '';
        return `<button onclick="Kanpro.setBoardColor('${this.escape(hex)}');Kanpro.closePicker()" title="${this.escape(label)}" style="width:38px;height:38px;border-radius:8px;background:${this.escape(hex)};border:${border};cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;text-shadow:0 1px 2px rgba(0,0,0,.4)">${check}</button>`;
      }).join('');
      let gradsHtml = Object.entries(themes.gradients).map(([grad,label])=>{
        const isSel = grad === current;
        const escGrad = grad.replace(/'/g, "\\'");
        const border = isSel ? '3px solid #172b4d' : '2px solid #dfe1e6';
        return `<button onclick="Kanpro.setBoardColor('${escGrad}');Kanpro.closePicker()" title="${this.escape(label)}" style="width:86px;height:38px;border-radius:8px;background:${grad};border:${border};cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;text-shadow:0 1px 2px rgba(0,0,0,.4)">${isSel?'✓':''}</button>`;
      }).join('');
      let wallsHtml = this.getBoardWallpapers().map(w=>`
        <div onclick="Kanpro.setBoardWallpaper('${w.key}', true)" title="${this.escape(w.label)}" style="position:relative;border-radius:8px;overflow:hidden;cursor:pointer;border:2px solid #dfe1e6;box-shadow:0 1px 3px rgba(0,0,0,.15);height:64px">
          <img src="${this.wallpaperThumbUrl(w.file)}" alt="${this.escape(w.label)}" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">
          <span style="position:absolute;left:0;right:0;bottom:0;background:rgba(9,30,66,.65);color:#fff;font-size:10px;font-weight:700;padding:2px 6px">${this.escape(w.label)}</span>
        </div>`).join('');
      const html = `
        <div style="display:grid;gap:14px">
          <div style="height:42px;border-radius:8px;border:1px solid #dfe1e6;background:${current};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;text-shadow:0 1px 2px rgba(0,0,0,.4)" id="picker-preview">Prévia: ${this.escape(current)}</div>
          <div>
            <div style="font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">CORES SÓLIDAS</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">${solidsHtml}</div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">DEGRADÊS — TEMAS</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">${gradsHtml}</div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">PAPÉIS DE PAREDE</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">${wallsHtml}</div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px">DEGRADÊS — TEMAS</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">${gradsHtml}</div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;padding:10px;background:#f4f5f7;border-radius:8px;border:1px solid #dfe1e6">
            <input type="color" id="picker-custom-color" value="${/^#[0-9a-fA-F]{6}$/.test(current)?current:'#0079bf'}" style="width:44px;height:36px;border:none;padding:0;border-radius:6px;cursor:pointer">
            <div style="flex:1">
              <div style="font-size:12px;font-weight:700">Cor personalizada</div>
              <small style="color:#6b778c">Escolha e clique em Aplicar</small>
            </div>
            <button onclick="Kanpro.setBoardColor(document.getElementById('picker-custom-color').value);Kanpro.closePicker()" style="background:#0079bf;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:700">Aplicar</button>
          </div>
          <div style="display:flex;gap:8px">
            <button onclick="Kanpro.closePicker()" style="flex:1;background:#f4f5f7;border:none;padding:10px;border-radius:6px;cursor:pointer;font-weight:600">Fechar</button>
          </div>
        </div>`;
      this.showPicker({title:'🎨 Alterar Cor / Tema do Quadro', html});
      // picker maior para caber paleta
      setTimeout(()=>{
        const p=document.getElementById('kanpro-picker');
        if(p){ p.style.minWidth='520px'; p.style.maxWidth='560px'; p.style.width='540px'; }
        const custom=document.getElementById('picker-custom-color');
        const prev=document.getElementById('picker-preview');
        if(custom && prev){
          custom.addEventListener('input', ()=>{ prev.style.background=custom.value; prev.textContent='Prévia: '+custom.value; });
        }
      }, 30);
    },
    async archiveBoard(){
      if(!await this.kpConfirm('Arquivar quadro?')) return;
      this.ajax('archive_board', {boards_id: this.board.id}).then(res=>{
        if(res.success) location.href = K.ajax_url.replace('/front/ajax.php','/front/board.php');
      });
    },
    deleteBoard(){
      this.ajax('delete_board', {boards_id: this.board.id}).then(res=>{
        if(res.success) location.href = K.ajax_url.replace('/front/ajax.php','/front/board.php');
        else alert(res.msg||'Erro');
      });
    },
    openGlobalSearch(){
      const html = `
        <input id="global-search-input" type="text" placeholder="Buscar cartões em todos os quadros..." style="width:100%;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;box-sizing:border-box;font-size:14px;margin-bottom:12px" autocomplete="off">
        <div id="global-search-results" style="display:grid;gap:6px;max-height:50vh;overflow-y:auto"><div style="text-align:center;color:#5e6c84;font-size:13px;padding:12px">Digite pelo menos 2 letras...</div></div>
      `;
      this.showPicker({title:'Busca global', html});
      const input = document.getElementById('global-search-input');
      const resultsBox = document.getElementById('global-search-results');
      let debounceTimer = null;
      const doSearch = (q)=>{
        if(q.trim().length < 2){
          resultsBox.innerHTML = '<div style="text-align:center;color:#5e6c84;font-size:13px;padding:12px">Digite pelo menos 2 letras...</div>';
          return;
        }
        resultsBox.innerHTML = '<div style="text-align:center;color:#5e6c84;font-size:13px;padding:12px">Buscando...</div>';
        this.ajax('global_search_cards', {q}).then(res=>{
          if(!res.success){ resultsBox.innerHTML = '<div style="text-align:center;color:#eb5a46;font-size:13px;padding:12px">Erro ao buscar.</div>'; return; }
          if(!res.results.length){ resultsBox.innerHTML = '<div style="text-align:center;color:#5e6c84;font-size:13px;padding:12px">Nenhum cartão encontrado.</div>'; return; }
          resultsBox.innerHTML = res.results.map(r=> `
            <div onclick="Kanpro.goToCard(${r.board_id}, ${r.card_id})" style="background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:10px 12px;cursor:pointer">
              <div style="font-size:13px;font-weight:600;color:#172b4d">${this.escape(r.card_name)}</div>
              <div style="font-size:11px;color:#5e6c84;margin-top:2px"><i class="ti ti-layout-kanban"></i> ${this.escape(r.board_name)} ${r.list_name ? '· '+this.escape(r.list_name) : ''}</div>
            </div>`).join('');
        });
      };
      input.addEventListener('input', e=>{
        clearTimeout(debounceTimer);
        const q = e.target.value;
        debounceTimer = setTimeout(()=> doSearch(q), 300);
      });
      setTimeout(()=> input.focus(), 30);
    },
    goToCard(boardId, cardId){
      const url = this.ajax_url.replace(/\/front\/ajax\.php.*$/, `/front/kanban.php?boards_id=${boardId}&open_card=${cardId}`);
      if(boardId == this.board.id){
        this.closePicker();
        this.openCard(cardId);
      } else {
        window.open(url, '_blank');
      }
    },
    loadBoardActivity(){
      this.ajax('get_board_activity', {boards_id: this.board.id}).then(res=>{
        if(res.success){
          const c = $('#board-menu-activity');
          if(c) c.innerHTML = res.data.map(a=> `<div style="font-size:12px;padding:6px;background:#fff;border-radius:4px"><strong>${this.escape(a.realname||a.firstname||a.user_name||'Sistema')}</strong> ${this.escape(a.details||a.action)}<br><small style="color:#5e6c84">${this.formatDate(a.date_creation)}</small></div>`).join('') || '<small style="color:#5e6c84">Sem atividade ainda.</small>';
        }
      });
    },
    renderMemberAvatars(){
      const wrap = $('#board-members-avatars');
      if(!wrap) return;
      wrap.innerHTML = this.members.slice(0,5).map(m=> this.avatarHtml(m.picture_url, m.initials, m.name, '', 'margin-left:-6px;border:2px solid #fff')).join('') + (this.members.length>5? `<span class="kp-avatar" style="background:#091e42;color:#fff;margin-left:-6px">+${this.members.length-5}</span>`:'');
    },
    renderBoardMenuDetails(){
      const labWrap = $('#board-menu-labels');
      if(labWrap) labWrap.innerHTML = this.labels.map(l=> `<div style="display:flex;justify-content:space-between;align-items:center;background:${this.escape(l.color)};color:#fff;padding:6px 10px;border-radius:4px"><span>${this.escape(l.name||'Sem nome')}</span><span style="font-size:11px;opacity:.8">${this.cardLabelsCount(l.id)} cartões</span></div>`).join('') || '<small style="color:#5e6c84">Nenhuma etiqueta</small>';
      const memWrap = $('#board-menu-members');
      if(memWrap) memWrap.innerHTML = this.members.map(m=> `<div style="display:flex;align-items:center;gap:8px;background:#fff;padding:6px 8px;border-radius:4px">${this.avatarHtml(m.picture_url, m.initials, m.name, 'sm')}<span style="flex:1">${this.escape(m.name)}</span><small style="background:#dfe1e6;padding:2px 6px;border-radius:10px">${m.role}</small></div>`).join('') || '<small style="color:#5e6c84">Só você</small>';
    },
    cardLabelsCount(labelId){
      let c=0;
      for(let cid in this.cardLabels) if(this.cardLabels[cid].some(l=> l.id==labelId)) c++;
      return c;
    },

    // Filter
    normText(s){
      s = String(s||'');
      if(s.normalize) s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      return s.toLowerCase();
    },
    filterCards(text){
      this.filterText = this.normText(text||'');
      this.applyFilters();
    },
    applyFilters(){
      $$('.kp-card').forEach(el=>{
        const cardId = parseInt(el.dataset.cardId);
        const card = this.cards.find(c=> c.id==cardId);
        if(!card){ el.style.display=''; return; }
        const matchText = !this.filterText || this.normText(card.name).includes(this.filterText) || this.normText(card.description||'').includes(this.filterText);
        // label filter
        let matchLabel = true;
        if(this.labelFilter.size>0){
          const labs = this.cardLabels[cardId]||[];
          matchLabel = labs.some(l=> this.labelFilter.has(String(l.id)));
        }
        let matchMember = true;
        if(this.memberFilter.size>0){
          const mems = this.cardMembers[cardId]||[];
          matchMember = mems.some(m=> this.memberFilter.has(String(m.users_id)));
        }
        const show = matchText && matchLabel && matchMember;
        el.style.display = show ? '' : 'none';
        el.classList.toggle('filtered-out', !show && this.filterText);
      });
      this.updateStats();
    },
    isCardFilteredOut(card){
      if(this.filterText && !this.normText(card.name).includes(this.filterText) && !this.normText(card.description||'').includes(this.filterText)) return true;
      return false;
    },
    openFilterMenu(){
      // labels e members checkbox
      let html = `<div style="display:grid;gap:12px">`;
      html+=`<div><strong>Etiquetas</strong><div style="display:grid;gap:4px;margin-top:6px">`;
      this.labels.forEach(l=>{
        const checked = this.labelFilter.has(String(l.id)) ? 'checked' : '';
        html+=`<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" ${checked} onchange="Kanpro.toggleLabelFilter('${l.id}', this.checked)"> <span style="background:${this.escape(l.color)};color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;min-width:80px">${this.escape(l.name||'Etiqueta')}</span></label>`;
      });
      if(!this.labels.length) html+=`<small style="color:#5e6c84">Nenhuma etiqueta</small>`;
      html+=`</div></div>`;
      html+=`<div><strong>Membros</strong><div style="display:grid;gap:4px;margin-top:6px">`;
      this.members.forEach(m=>{
        const checked = this.memberFilter.has(String(m.users_id)) ? 'checked' : '';
        html+=`<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" ${checked} onchange="Kanpro.toggleMemberFilter('${m.users_id}', this.checked)"> <span class="kp-avatar sm">${this.escape(m.initials)}</span> ${this.escape(m.name)}</label>`;
      });
      if(!this.members.length) html+=`<small style="color:#5e6c84">Nenhum membro</small>`;
      html+=`</div></div>`;
      html+=`<button onclick="Kanpro.clearFilters()" style="background:#dfe1e6;border:none;padding:6px 12px;border-radius:4px;cursor:pointer">Limpar filtros</button>`;
      html+=`</div>`;
      this.showPicker({title:'Filtrar cartões', html});
    },
    toggleLabelFilter(id, checked){ if(checked) this.labelFilter.add(id); else this.labelFilter.delete(id); this.applyFilters(); },
    toggleMemberFilter(id, checked){ if(checked) this.memberFilter.add(id); else this.memberFilter.delete(id); this.applyFilters(); },
    clearFilters(){ this.labelFilter.clear(); this.memberFilter.clear(); this.filterText=''; const inp=$('#kanpro-filter'); if(inp) inp.value=''; this.applyFilters(); this.closePicker(); },

    // Calendar
    showCalendarView(){
      let cal = $('#kanpro-calendar');
      if(!cal){
        cal = document.createElement('div');
        cal.id='kanpro-calendar';
        document.body.appendChild(cal);
      }
      cal.style.display='block';
      if(!cal.dataset.bound){
        cal.dataset.bound = '1';
        cal.addEventListener('click', e=>{ if(e.target===cal) Kanpro.closeCalendarView(); });
      }
      // gera calendário do mês atual
      const now = new Date();
      const year = now.getFullYear(), month = now.getMonth();
      const firstDay = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month+1, 0).getDate();
      const daysInPrev = new Date(year, month, 0).getDate();
      let html = `<div style="max-width:1000px;margin:0 auto;background:#f4f5f7;border-radius:10px;padding:20px;box-shadow:0 12px 32px rgba(0,0,0,.35)"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px"><h2 style="margin:0">📅 Calendário — ${now.toLocaleDateString('pt-BR',{month:'long',year:'numeric'})}</h2><button onclick="Kanpro.closeCalendarView()" style="background:#172b4d;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer">✕ Fechar</button></div>`;
      html+=`<div class="kp-cal-grid">`;
      ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'].forEach(d=> html+=`<div class="kp-cal-header">${d}</div>`);
      // prev month filler
      for(let i=firstDay-1;i>=0;i--) html+=`<div class="kp-cal-cell other"><div class="kp-cal-daynum">${daysInPrev - i}</div></div>`;
      for(let d=1; d<=daysInMonth; d++){
        const isToday = d===now.getDate();
        html+=`<div class="kp-cal-cell ${isToday?'today':''}"><div class="kp-cal-daynum">${d}</div>`;
        // cartões com due_date neste dia
        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        this.cards.filter(c=> c.due_date && c.due_date.startsWith(dateStr) && c.is_archived==0 && this.isCardVisible(c)).forEach(c=>{
          const list = this.lists.find(l=> l.id==c.plugin_kanpro_lists_id);
          html+=`<div class="kp-cal-card" onclick="Kanpro.openCard(${c.id}); document.getElementById('kanpro-calendar').style.display='none'"><strong>${this.escape(c.name)}</strong><br><small>${this.escape(list?.name||'')}</small></div>`;
        });
        html+=`</div>`;
      }
      const totalCells = firstDay + daysInMonth;
      const remaining = (7 - totalCells%7)%7;
      for(let i=1;i<=remaining;i++) html+=`<div class="kp-cal-cell other"><div class="kp-cal-daynum">${i}</div></div>`;
      html+=`</div></div>`;
      cal.innerHTML=html;
    },
    closeCalendarView(){
      const cal = document.getElementById('kanpro-calendar');
      if(cal) cal.style.display='none';
    },

    // Helpers
    updateStats(){
      const total = this.cards.filter(c=> c.is_archived==0 && this.isCardVisible(c)).length;
      const listsCount = this.lists.filter(l=> l.is_archived==0).length;
      const el = $('#kanpro-stats');
      if(el) el.textContent = `${listsCount} listas • ${total} cartões`;
    },
    updateAssinaturaButton(){
      const btn = document.getElementById('kanpro-assinatura-btn');
      if(!btn) return;
      const hasMaint = this.cards.some(c=> c.is_maintenance==1 && c.is_archived==0);
      btn.style.display = hasMaint ? 'inline-flex' : 'none';
    },
    showPicker({title, html, x, y}){
      const p = $('#kanpro-picker');
      $('#picker-title').textContent = title||'';
      $('#picker-body').innerHTML = html||'';
      p.style.display='block';
      const hasPos = (x !== null && x !== undefined && y !== null && y !== undefined);
      if(hasPos){
        p.style.left = Math.max(12, Math.min(x, window.innerWidth-360)) + 'px';
        p.style.top = (y+8) + 'px';
        p.style.right='auto';
        p.style.transform='none';
      } else {
        // centraliza próximo ao modal ou centro da tela
        p.style.left = '50%';
        p.style.top = '50%';
        p.style.transform = 'translate(-50%,-50%)';
      }
      // posicionamento inteligente se sair da tela (só se aberto perto de um clique, não quando centralizado)
      if(hasPos){
        setTimeout(()=>{
          const rect = p.getBoundingClientRect();
          if(rect.right > window.innerWidth) p.style.left = (window.innerWidth - rect.width -12) + 'px';
          if(rect.bottom > window.innerHeight) p.style.top = (window.innerHeight - rect.height -12) + 'px';
        }, 0);
      }
    },
    closePicker(){
      const p = $('#kanpro-picker');
      const b = document.getElementById('picker-body');
      p.style.display='none';
      p.style.transform='none';
      p.style.minWidth='300px';
      p.style.maxWidth='360px';
      p.style.width='';
      p.style.maxHeight='';
      p.style.flexDirection='';
      if(b){ b.style.maxHeight='400px'; b.style.overflowY='auto'; }
    },
    kpConfirm(message){
      return new Promise(resolve=>{
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:20000;display:flex;align-items:center;justify-content:center;padding:16px';
        overlay.innerHTML = `
          <div style="background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.3);max-width:380px;width:100%;padding:20px">
            <div style="font-size:14px;color:#172b4d;margin-bottom:18px;white-space:pre-wrap;line-height:1.4">${this.escape(message)}</div>
            <div style="display:flex;justify-content:flex-end;gap:8px">
              <button data-a="cancel" style="background:#f4f5f7;color:#172b4d;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Cancelar</button>
              <button data-a="ok" style="background:#eb5a46;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Confirmar</button>
            </div>
          </div>`;
        document.body.appendChild(overlay);
        const cleanup = (val)=>{ overlay.remove(); resolve(val); };
        overlay.addEventListener('click', e=>{ if(e.target===overlay) cleanup(false); });
        overlay.querySelector('[data-a="cancel"]').onclick = ()=> cleanup(false);
        overlay.querySelector('[data-a="ok"]').onclick = ()=> cleanup(true);
        const onKey = e=>{ if(e.key==='Escape'){ cleanup(false); document.removeEventListener('keydown', onKey); } };
        document.addEventListener('keydown', onKey);
      });
    },
    kpPrompt(message, defaultValue){
      return new Promise(resolve=>{
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:20000;display:flex;align-items:center;justify-content:center;padding:16px';
        overlay.innerHTML = `
          <div style="background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.3);max-width:380px;width:100%;padding:20px">
            <div style="font-size:14px;color:#172b4d;margin-bottom:10px;font-weight:600">${this.escape(message)}</div>
            <input type="text" data-a="input" value="${this.escape(defaultValue||'')}" style="width:100%;padding:9px 10px;border:1px solid #dfe1e6;border-radius:6px;margin-bottom:16px;box-sizing:border-box;font-size:14px">
            <div style="display:flex;justify-content:flex-end;gap:8px">
              <button data-a="cancel" style="background:#f4f5f7;color:#172b4d;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Cancelar</button>
              <button data-a="ok" style="background:#0079bf;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">OK</button>
            </div>
          </div>`;
        document.body.appendChild(overlay);
        const input = overlay.querySelector('[data-a="input"]');
        const cleanup = (val)=>{ overlay.remove(); resolve(val); };
        overlay.addEventListener('click', e=>{ if(e.target===overlay) cleanup(null); });
        overlay.querySelector('[data-a="cancel"]').onclick = ()=> cleanup(null);
        overlay.querySelector('[data-a="ok"]').onclick = ()=> cleanup(input.value);
        input.addEventListener('keydown', e=>{
          if(e.key==='Enter'){ e.preventDefault(); cleanup(input.value); }
          if(e.key==='Escape'){ cleanup(null); }
        });
        setTimeout(()=>{ input.focus(); input.select(); }, 30);
      });
    },
    async askDeleteList(listId){
      if(await this.kpConfirm('Excluir lista e todos os cartões?')) this.deleteList(listId);
    },
    async askDeleteLabel(labelId){
      if(await this.kpConfirm('Excluir etiqueta?')) this.deleteLabel(labelId);
    },
    filterPicker(text){
      const q = (text||'').toLowerCase();
      $$('#picker-body .kp-picker-item').forEach(el=>{
        const search = (el.dataset.search||el.textContent).toLowerCase();
        el.style.display = search.includes(q) ? 'flex' : 'none';
      });
    },
    escape(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); },
    formatDate(s){
      if(!s) return '';
      const d = new Date(s);
      return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
    },
    formatDateShort(s){
      if(!s) return '';
      const d = new Date(s);
      return d.toLocaleDateString('pt-BR',{day:'2-digit',month:'short'});
    },
    formatDateTiny(s){
      if(!s) return '';
      const d = new Date(s);
      if(isNaN(d)) return '';
      const pad=n=>String(n).padStart(2,'0');
      return `${pad(d.getDate())}/${pad(d.getMonth()+1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    formatFileSize(b){
      if(b<1024) return b+' B';
      if(b<1024*1024) return (b/1024).toFixed(1)+' KB';
      return (b/1024/1024).toFixed(1)+' MB';
    },
    toLocalDatetime(s){
      const d = new Date(s);
      const pad=n=>String(n).padStart(2,'0');
      return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    async addBoardLabel(){
      const name = await this.kpPrompt('Nome da etiqueta:','');
      const color = await this.kpPrompt('Cor hex (#rrggbb):','#61bd4f');
      if(!color) return;
      this.ajax('add_label', {boards_id: this.board.id, name: name||'', color}).then(res=>{
        if(res.success){ this.labels.push({id:res.id, plugin_kanpro_boards_id:this.board.id, name:name||'', color}); this.renderBoardMenuDetails(); this.renderBoard(); alert('Etiqueta criada!'); }
      });
    },
    /* ---------- RELATÓRIO DO QUADRO ---------- */
    openBoardReport(){
      this.showPicker({title:'📊 Relatório do quadro', html:'<div style="padding:20px;text-align:center;color:#5e6c84">Carregando...</div>'});
      const p = document.getElementById('kanpro-picker');
      if(p){ p.style.minWidth='620px'; p.style.maxWidth='94vw'; p.style.width='640px'; p.style.maxHeight='90vh'; p.style.display='flex'; p.style.flexDirection='column'; }
      const b = document.getElementById('picker-body');
      if(b){ b.style.maxHeight='72vh'; b.style.overflowY='auto'; }
      this.ajax('get_board_report', {boards_id: this.board.id}).then(res=>{
        if(!res.success){ alert(res.msg||'Erro'); this.closePicker(); return; }
        this._lastReport = res;
        this.renderBoardReport(30);
      });
    },
    reportParseDate(s){
      if(!s) return null;
      const t = Date.parse(String(s).replace(' ', 'T'));
      return isNaN(t) ? null : t;
    },
    reportFmtDur(ms){
      if(ms < 0) ms = 0;
      const min = ms/60000;
      if(min < 60) return Math.round(min) + 'min';
      const h = min/60;
      if(h < 48) return (Math.round(h*10)/10).toString().replace('.', ',') + 'h';
      return (Math.round(h/24*10)/10).toString().replace('.', ',') + ' dias';
    },
    reportFmtDate(s){
      if(!s) return '—';
      const d = new Date(String(s).replace(' ', 'T'));
      return isNaN(d) ? s : d.toLocaleDateString('pt-BR');
    },
    renderBoardReport(days){
      const res = this._lastReport;
      if(!res) return;
      const now = Date.now();
      const since = days > 0 ? now - days*86400000 : 0;
      const listById = {};
      (res.lists||[]).forEach(l=> listById[l.id] = l.name);
      const movesByCard = {};
      (res.moves||[]).forEach(m=>{ (movesByCard[m.card_id] = movesByCard[m.card_id] || []).push(m); });
      // tempo por lista (a partir do histórico de movimentações)
      const stats = {}; // listId -> {ms, visits}
      const bump = (lid, ms)=>{ if(lid===undefined||lid===null) return; const k = String(lid); stats[k] = stats[k] || {ms:0, visits:0}; stats[k].ms += Math.max(0,ms); stats[k].visits++; };
      (res.cards||[]).forEach(c=>{
        const evs = (movesByCard[c.id]||[]).filter(e=> e.action==='card_move');
        let prevT = this.reportParseDate(c.date_creation) || now;
        let firstFrom = null;
        if(evs.length){
          const m0 = String(evs[0].details||'').match(/^\[from:(\d+)\]/);
          firstFrom = m0 ? parseInt(m0[1]) : null;
        }
        let prevList = (firstFrom !== null && firstFrom !== undefined) ? firstFrom : c.list_id;
        evs.forEach(e=>{
          const t = this.reportParseDate(e.date);
          if(t !== null){ bump(prevList, t - prevT); prevT = t; }
          prevList = e.list_id;
        });
        const endT = (c.is_archived && c.date_mod) ? (this.reportParseDate(c.date_mod) || now) : now;
        bump(c.list_id, endT - prevT);
      });
      // contagens no período
      let created = 0, completed = 0;
      (res.cards||[]).forEach(c=>{ const t = this.reportParseDate(c.date_creation); if(t !== null && t >= since) created++; });
      const completedIds = new Set();
      (res.moves||[]).forEach(m=>{
        if(m.action!=='card_complete') return;
        const t = this.reportParseDate(m.date);
        if(t !== null && t >= since) completedIds.add(m.card_id);
      });
      completed = completedIds.size;
      const archived = (res.cards||[]).filter(c=> c.is_archived).length;
      const active = (res.cards||[]).filter(c=> !c.is_archived).length;
      const periodLabel = days>0 ? `últimos ${days} dias` : 'todo o período';
      const listRows = (res.lists||[]).map(l=>{
        const s = stats[String(l.id)];
        const avg = (s && s.visits) ? s.ms/s.visits : 0;
        return `<tr><td style="padding:6px 8px;border-bottom:1px solid #dfe1e6">${this.escape(l.name)}${l.is_archived?' (arquivada)':''}</td>`
          + `<td style="padding:6px 8px;border-bottom:1px solid #dfe1e6;text-align:center">${s ? s.visits : 0}</td>`
          + `<td style="padding:6px 8px;border-bottom:1px solid #dfe1e6;text-align:right">${s ? this.reportFmtDur(avg) : '—'}</td></tr>`;
      }).join('');
      const cardRows = (res.cards||[]).map(c=>{
        const mv = (movesByCard[c.id]||[]).filter(e=> e.action==='card_move').length;
        return `<tr><td style="padding:6px 8px;border-bottom:1px solid #dfe1e6">#${c.id} ${this.escape(c.name)}</td>`
          + `<td style="padding:6px 8px;border-bottom:1px solid #dfe1e6">${this.escape(listById[c.list_id]||'—')}</td>`
          + `<td style="padding:6px 8px;border-bottom:1px solid #dfe1e6">${this.reportFmtDate(c.date_creation)}</td>`
          + `<td style="padding:6px 8px;border-bottom:1px solid #dfe1e6;text-align:center">${mv}</td>`
          + `<td style="padding:6px 8px;border-bottom:1px solid #dfe1e6;text-align:center">${c.is_completed?'✅':''}</td>`
          + `<td style="padding:6px 8px;border-bottom:1px solid #dfe1e6;text-align:center">${c.is_archived?'📦':''}</td></tr>`;
      }).join('');
      const html = `
        <div style="display:grid;gap:12px">
          <label style="font-size:12px;font-weight:600;color:#5e6c84">Período
            <select id="kpr-days" onchange="Kanpro.renderBoardReport(parseInt(this.value))" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px;background:#fff">
              <option value="7"${days===7?' selected':''}>Últimos 7 dias</option>
              <option value="30"${days===30?' selected':''}>Últimos 30 dias</option>
              <option value="90"${days===90?' selected':''}>Últimos 90 dias</option>
              <option value="0"${days===0?' selected':''}>Todo o período</option>
            </select>
          </label>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">
            <div style="background:#e6fcff;border-radius:8px;padding:10px;text-align:center"><div style="font-size:20px;font-weight:800;color:#0079bf">${created}</div><div style="font-size:11px;color:#5e6c84">Criados<br>(${periodLabel})</div></div>
            <div style="background:#e3fcef;border-radius:8px;padding:10px;text-align:center"><div style="font-size:20px;font-weight:800;color:#006644">${completed}</div><div style="font-size:11px;color:#5e6c84">Concluídos<br>(${periodLabel})</div></div>
            <div style="background:#f4f5f7;border-radius:8px;padding:10px;text-align:center"><div style="font-size:20px;font-weight:800;color:#172b4d">${active}</div><div style="font-size:11px;color:#5e6c84">Ativos<br>agora</div></div>
            <div style="background:#fffae6;border-radius:8px;padding:10px;text-align:center"><div style="font-size:20px;font-weight:800;color:#975500">${archived}</div><div style="font-size:11px;color:#5e6c84">Arquivados<br>(total)</div></div>
          </div>
          <div>
            <div style="font-size:12px;font-weight:700;color:#172b4d;margin-bottom:6px">⏱️ Tempo médio por lista</div>
            <table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="background:#f4f5f7"><th style="padding:6px 8px;text-align:left">Lista</th><th style="padding:6px 8px">Passagens</th><th style="padding:6px 8px;text-align:right">Tempo médio</th></tr></thead><tbody>${listRows}</tbody></table>
          </div>
          <div>
            <div style="font-size:12px;font-weight:700;color:#172b4d;margin-bottom:6px">🗂️ Cartões (${(res.cards||[]).length})</div>
            <div style="max-height:240px;overflow-y:auto;border:1px solid #dfe1e6;border-radius:8px">
            <table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="background:#f4f5f7;position:sticky;top:0"><th style="padding:6px 8px;text-align:left">Cartão</th><th style="padding:6px 8px;text-align:left">Lista</th><th style="padding:6px 8px;text-align:left">Criado em</th><th style="padding:6px 8px">Mov.</th><th style="padding:6px 8px">OK</th><th style="padding:6px 8px">Arq.</th></tr></thead><tbody>${cardRows}</tbody></table>
            </div>
          </div>
          <div style="font-size:11px;color:#97a0af">Tempo por lista calculado pelo histórico de movimentações. Conclusões contam a partir desta versão.</div>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <button onclick="Kanpro.exportBoardReportPDF()" style="background:#0079bf;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:700"><i class="ti ti-printer"></i> Exportar PDF</button>
            <button onclick="Kanpro.exportBoardReportCSV()" style="background:#006644;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:700"><i class="ti ti-download"></i> Exportar CSV</button>
          </div>
        </div>`;
      document.getElementById('picker-body').innerHTML = html;
    },
    exportBoardReportPDF(){
      const res = this._lastReport;
      if(!res) return;
      const listById = {};
      (res.lists||[]).forEach(l=> listById[l.id] = l.name);
      const movesByCard = {};
      (res.moves||[]).forEach(m=>{ (movesByCard[m.card_id] = movesByCard[m.card_id] || []).push(m); });
      const esc = s=> String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      const rows = (res.cards||[]).map(c=>{
        const mv = (movesByCard[c.id]||[]).filter(e=> e.action==='card_move').length;
        return `<tr><td>#${c.id} ${esc(c.name)}</td><td>${esc(listById[c.list_id]||'—')}</td><td>${esc((c.date_creation||'').substring(0,10))}</td><td style="text-align:center">${mv}</td><td style="text-align:center">${c.is_completed?'Sim':''}</td><td style="text-align:center">${c.is_archived?'Sim':''}</td></tr>`;
      }).join('');
      const lists = (res.lists||[]).map(l=>`<li>${esc(l.name)}${l.is_archived?' (arquivada)':''}</li>`).join('');
      const total = (res.cards||[]).length;
      const done = (res.cards||[]).filter(c=> c.is_completed).length;
      const arch = (res.cards||[]).filter(c=> c.is_archived).length;
      const w = window.open('', '_blank');
      if(!w){ alert('Permita pop-ups para exportar o PDF.'); return; }
      w.document.write(`<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório — ${esc(this.board.name||'Quadro')}</title>
      <style>body{font-family:Arial,sans-serif;color:#172b4d;margin:24px}h1{font-size:20px}h2{font-size:15px;margin-top:20px}.meta{font-size:12px;color:#5e6c84}.summary{display:flex;gap:12px;margin:12px 0}.summary div{background:#f4f5f7;padding:10px 14px;border-radius:8px;flex:1;text-align:center}.summary strong{font-size:18px;display:block}table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}th,td{border:1px solid #dfe1e6;padding:6px 8px;text-align:left}th{background:#f4f5f7}.no-print{margin:16px 0}@media print{.no-print{display:none}}</style>
      </head><body>
      <h1>📊 Relatório do quadro — ${esc(this.board.name||'')}</h1>
      <div class="meta">Gerado em ${new Date().toLocaleString('pt-BR')} • Total de cartões: ${total} • Concluídos: ${done} • Arquivados: ${arch}</div>
      <div class="no-print"><button onclick="window.print()" style="background:#0079bf;color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:700">🖨️ Imprimir / Salvar PDF</button></div>
      <h2>Listas</h2><ul>${lists}</ul>
      <h2>Cartões (${total})</h2>
      <table><thead><tr><th>Cartão</th><th>Lista</th><th>Criado em</th><th>Mov.</th><th>OK</th><th>Arq.</th></tr></thead><tbody>${rows}</tbody></table>
      <div class="meta" style="margin-top:16px">Gerado pelo KanPro</div>
      </body></html>`);
      w.document.close();
      w.focus();
      setTimeout(()=>{ try{ w.print(); }catch(e){} }, 400);
    },
    exportBoardReportCSV(){
      const res = this._lastReport;
      if(!res) return;
      const listById = {};
      (res.lists||[]).forEach(l=> listById[l.id] = l.name);
      const movesByCard = {};
      (res.moves||[]).forEach(m=>{ (movesByCard[m.card_id] = movesByCard[m.card_id] || []).push(m); });
      const q = v=> `"${String(v ?? '').replace(/"/g, '""')}"`;
      const lines = ['Cartão;Lista atual;Criado em;Concluído;Arquivado;Movimentações'];
      (res.cards||[]).forEach(c=>{
        const mv = (movesByCard[c.id]||[]).filter(e=> e.action==='card_move').length;
        lines.push([q('#'+c.id+' '+c.name), q(listById[c.list_id]||''), q(c.date_creation||''), c.is_completed?'SIM':'NÃO', c.is_archived?'SIM':'NÃO', mv].join(';'));
      });
      const blob = new Blob(["\ufeff" + lines.join("\r\n")], {type:'text/csv;charset=utf-8'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'relatorio-quadro-' + (this.board.id || 'kanpro') + '.csv';
      document.body.appendChild(a);
      a.click();
      setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 500);
    },
    /* ---------- MENÇÕES @membro ---------- */
    mentionNames(){
      const set = new Map();
      const push = n=>{
        n = String(n||'').trim();
        if(!n) return;
        set.set(n.toLowerCase(), n);
        const first = n.split(/\s+/)[0];
        if(first && first !== n) set.set(first.toLowerCase(), first);
      };
      (this.members||[]).forEach(m=> push(m.name));
      ((this._lastModalData && this._lastModalData.members) || []).forEach(m=>{
        push(m.realname && m.firstname ? (m.firstname + ' ' + m.realname) : (m.realname || m.firstname || m.name));
        push(m.firstname);
      });
      return [...set.values()].sort((a,b)=> b.length - a.length);
    },
    highlightMentions(html){
      const names = this.mentionNames();
      if(!names.length) return html;
      const escRe = s=> s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const re = new RegExp('(^|[^\\w@])@(' + names.map(escRe).join('|') + ')(?![\\w])', 'gi');
      return String(html).split(/(<[^>]*>)/g).map(part=>{
        if(part.startsWith('<')) return part;
        return part.replace(re, '$1<span style="background:#e6fcff;color:#0747a6;font-weight:700;padding:0 4px;border-radius:4px">@$2</span>');
      }).join('');
    },
    /* ---------- avatar com foto do GLPI (fallback: inicial) ---------- */
    avatarHtml(pictureUrl, initials, title, extraClass, extraStyle){
      // foto em camada absoluta sobre a inicial: carregou cobre tudo, falhou some e a letra fica
      const pic = pictureUrl
        ? `<img src="${pictureUrl}" alt="" loading="lazy" onerror="this.remove()" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:50%">` : '';
      return `<span class="kp-avatar ${extraClass||''}" title="${this.escape(title||'')}" style="position:relative;${extraStyle||''}">${this.escape(initials||'?')}${pic}</span>`;
    },
    /* ---------- MARKDOWN SIMPLES ---------- */
    parseMarkdown(text){
      if(!text) return '';
      let html = this.escape(text)
        .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(mm, txt, url){
          if (/^\s*(javascript|data|vbscript)\s*:/i.test(url)) return txt;
          return '<a href="' + url + '" target="_blank" rel="noopener">' + txt + '</a>';
        })
        .replace(/\n/g, '<br>');
      return html;
    },
    /* ---------- DARK MODE ---------- */
    toggleDarkMode(){
      const dark = !document.body.classList.contains('kanpro-dark');
      this.applyDarkMode(dark);
      try { localStorage.setItem('kanpro_dark', dark ? '1' : '0'); } catch(e){}
    },
    applyDarkMode(force){
      const dark = (force !== undefined) ? force : (function(){ try { return localStorage.getItem('kanpro_dark')==='1'; } catch(e){ return false; } })();
      document.body.classList.toggle('kanpro-dark', !!dark);
      const btn = document.getElementById('kanpro-dark-btn');
      if(btn) btn.innerHTML = dark ? '<i class="ti ti-sun"></i>' : '<i class="ti ti-moon"></i>';
    }
  };

  window.Kanpro = Kanpro;
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', ()=> Kanpro.init());
  else Kanpro.init();

  // expose global close handlers for inline onclick
  window.KanproClosePicker = ()=> Kanpro.closePicker();

})();
