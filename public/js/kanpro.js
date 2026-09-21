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
      if(this.openCardId){ this.openCard(this.openCardId); }
      this.startPolling();
      // clicar fora fecha picker, board-menu e card-modal
      document.addEventListener('click', e=>{
        const picker = document.getElementById('kanpro-picker');
        if(picker && picker.style.display!=='none' && !picker.contains(e.target) && !e.target.closest('[onclick*="open"]') && !e.target.closest('[onclick*="Picker"]') && !e.target.closest('.kp-sidebar-btn')){
          // evita fechar se clique é no botão que abriu (já tratado por showPicker)
          const isPickerBtn = e.target.closest('button');
          if(!isPickerBtn || !isPickerBtn.textContent.match(/Membros|Etiquetas|Datas|Capa|Mover|Convidar|Filtrar/)){
            // só fecha se não for dentro do picker
            if(!picker.contains(e.target)) this.closePicker();
          }
        }
        const bmenu = document.getElementById('kanpro-board-menu');
        if(bmenu && bmenu.style.display!=='none' && !bmenu.contains(e.target) && !e.target.closest('[onclick*="openBoardMenu"]') && !e.target.closest('[onclick*="BoardMenu"]')){
          if(!e.target.closest('#kanpro-board-menu')) this.closeBoardMenu();
        }
      });
      // ESC fecha tudo
      document.addEventListener('keydown', e=>{
        if (e.key==='Escape') { this.closeCardModal(); this.closePicker(); this.closeBoardMenu(); }
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

    // ---------- ATUALIZAÇÃO EM TEMPO REAL (polling) ----------
    startPolling(){
      this._lastSnapshotJson = JSON.stringify({
        lists: this.lists, cards: this.cards, labels: this.labels,
        cardLabels: this.cardLabels, cardMembers: this.cardMembers,
        checkProgress: this.checkProgress, maintenanceProgress: this.maintenanceProgress, commentCounts: this.commentCounts,
        attCounts: this.attCounts, members: this.members, transferStatus: this.transferStatus
      });
      this.ajax('presence_heartbeat', {boards_id: this.board.id});
      if(this._pollTimer) clearInterval(this._pollTimer);
      this._pollingStartedAt = Date.now();
      this._pollTimer = setInterval(()=> this.pollBoardUpdates(), 2000);
    },
    pollBoardUpdates(){
      if(document.hidden) return; // economiza requisição em aba não visível
      this.ajax('presence_heartbeat', {boards_id: this.board.id});
      if(this.dragCard || this.dragList) return; // não atrapalha um arraste em andamento
      this.ajax('get_board_snapshot', {boards_id: this.board.id}).then(res=>{
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
          if(!isTyping){
            this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{
              if(r.success) this.renderCardModal(r.data);
            });
          }
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
        box.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:25000;display:flex;flex-direction:column;gap:8px;align-items:center';
        document.body.appendChild(box);
      }
      const toast = document.createElement('div');
      toast.style.cssText = 'background:#172b4d;color:#fff;padding:10px 18px;border-radius:20px;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.25);opacity:0;transform:translateY(8px);transition:opacity .2s,transform .2s;display:flex;align-items:center;gap:8px';
      toast.innerHTML = `<i class="ti ti-refresh"></i> ${this.escape(message)}`;
      box.appendChild(toast);
      requestAnimationFrame(()=>{ toast.style.opacity='1'; toast.style.transform='translateY(0)'; });
      setTimeout(()=>{
        toast.style.opacity='0';
        toast.style.transform='translateY(8px)';
        setTimeout(()=> toast.remove(), 250);
      }, 3000);
    },

    // ---------- BOARD ----------
    renderBoard(){
      const board = $('#kanpro-board');
      if(!board) return;
      board.innerHTML = '';
      // ordena listas por rank
      this.lists.sort((a,b)=> parseFloat(a.rank)-parseFloat(b.rank));
      this.cards.sort((a,b)=> parseFloat(a.rank)-parseFloat(b.rank));

      this.lists.forEach(list=>{
        if(list.is_archived==1) return;
        const cardsInList = this.cards.filter(c=> c.plugin_kanpro_lists_id==list.id && c.is_archived==0);
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

      this.enableDragAndDrop();
      this.updateAssinaturaButton();
      this.updateStats();
    },

    createListEl(list, cardsInList){
      const div = document.createElement('div');
      div.className = 'kp-list';
      div.dataset.listId = list.id;
      div.draggable = true;
      div.innerHTML = `
        <div class="kp-list-header">
          <div class="kp-list-title" onclick="Kanpro.editListTitle(${list.id})" title="Clique para editar">${this.escape(list.name)}</div>
          <input class="kp-list-title-input" style="display:none" onkeydown="if(event.key==='Enter') Kanpro.saveListTitle(${list.id}, this)" onblur="Kanpro.saveListTitle(${list.id}, this)">
          <span class="kp-list-count">${cardsInList.length}</span>
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
      }
      // TODO: cover_attachment image

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
      if (prog && prog.total>0) {
        const doneClass = prog.done===prog.total ? 'check-done' : '';
        badges.push(`<span class="kp-badge ${doneClass}"><i class="ti ti-checkbox"></i> ${prog.done}/${prog.total}</span>`);
      }
      if (card.description && card.description.trim()) badges.push(`<span class="kp-badge"><i class="ti ti-align-left"></i></span>`);
      if (comments>0) badges.push(`<span class="kp-badge"><i class="ti ti-message"></i> ${comments}</span>`);
      if (atts>0) badges.push(`<span class="kp-badge"><i class="ti ti-paperclip"></i> ${atts}</span>`);
      if (members.length) {
        // members avatars handled separately
      }

      let membersHtml = '';
      if(members.length){
        membersHtml = `<div class="kp-card-members">${members.slice(0,4).map(m=>`<span class="kp-avatar sm" title="${this.escape(m.name)}">${this.escape(m.initials)}</span>`).join('')}${members.length>4?`<span class="kp-avatar sm" style="background:#091e42;color:#fff">+${members.length-4}</span>`:''}</div>`;
      }

      div.innerHTML = `
        ${coverHtml}
        ${labelsHtml}
        <div class="kp-card-title"><span style="color:#5e6c84;font-weight:700;margin-right:4px">#${card.id}</span>${this.escape(card.name)}</div>
        ${badges.length?`<div class="kp-card-badges">${badges.join('')}</div>`:''}
        ${membersHtml}
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
          this.updateStats();
        }
      });
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
      const rect = e.target.getBoundingClientRect();
      this.showPicker({
        title: `Ações da lista: ${list.name}`,
        x: rect.left - 280,
        y: rect.top + 28,
        html: `
          <div style="display:grid;gap:4px">
            <button class="kp-picker-item" onclick="Kanpro.editListTitle(${listId}); Kanpro.closePicker()"><i class="ti ti-pencil"></i> Renomear lista</button>
            <button class="kp-picker-item" onclick="Kanpro.copyList(${listId})"><i class="ti ti-copy"></i> Copiar lista</button>
            <button class="kp-picker-item" onclick="Kanpro.archiveList(${listId})"><i class="ti ti-archive"></i> Arquivar lista</button>
            <hr style="margin:4px 0;border:none;border-top:1px solid #dfe1e6">
            <button class="kp-picker-item" style="color:#eb5a46" onclick="Kanpro.askDeleteList(${listId})"><i class="ti ti-trash"></i> Excluir lista</button>
          </div>`
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
          ta.value='';
          ta.focus();
          this.renderBoard();
          this.updateStats();
          // mantém composer aberto para adicionar vários
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
          return `<span class="kp-avatar" title="${this.escape(m.realname||m.name)}">${this.escape(initials)}</span>`;
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

      // description
      const descEl = $('#card-modal-desc');
      const descEdit = $('#card-desc-edit');
      descEl.textContent = data.description || 'Adicionar uma descrição mais detalhada...';
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
      comContainer.innerHTML = (data.comments||[]).map(c=>`
        <div style="display:flex;gap:8px">
          <div class="kp-avatar">${this.escape((c.firstname?.[0]||c.user_name?.[0]||'?').toUpperCase())}</div>
          <div style="flex:1;background:#fff;padding:8px 12px;border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13)">
            <div style="font-weight:700;font-size:13px">${this.escape(c.realname||c.firstname||c.user_name||'Usuário')} <span style="font-weight:400;color:#5e6c84;font-size:11px">${this.formatDate(c.date_creation)}</span></div>
            <div style="margin-top:4px;white-space:pre-wrap;word-break:break-word">${this.escape(c.content)}</div>
            <div style="margin-top:6px;display:flex;gap:8px;font-size:12px"><a href="#" onclick="Kanpro.editComment(${c.id});return false">Editar</a> <a href="#" onclick="Kanpro.deleteComment(${c.id});return false" style="color:#eb5a46">Excluir</a></div>
          </div>
        </div>
      `).join('') || '<div style="color:#5e6c84;font-size:13px">Seja o primeiro a comentar</div>';

      // activity
      const actContainer = $('#card-modal-activity');
      actContainer.innerHTML = (data.activities||[]).map(a=>`
        <div style="display:flex;gap:8px;font-size:12px;color:#5e6c84">
          <div class="kp-avatar sm">${this.escape((a.firstname?.[0]||a.user_name?.[0]||'?').toUpperCase())}</div>
          <div><strong>${this.escape(a.realname||a.firstname||a.user_name||'Sistema')}</strong> ${this.escape(a.details||a.action)} <span style="color:#97a0af">${this.formatDate(a.date_creation)}</span></div>
        </div>
      `).join('');
      actContainer.style.display='none'; // começa oculto, botão mostra

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
      // re-render board silencioso (mantém modal)
      this.renderBoardQuick();
    },

    // ==================== MANUTENÇÃO ====================
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
      // botão finalizar: desabilita apenas se faltar status
      let finalizeBtnHtml = "";
      if (hasMissing) {
        finalizeBtnHtml = `<button disabled title="Selecione o Status Final de todas as máquinas (${missingStatus}/${total})" style="background:#dfe1e6;color:#5e6c84;border:none;padding:6px 12px;border-radius:4px;font-weight:600;font-size:12px;opacity:.6;cursor:not-allowed"><i class="ti ti-alert-circle"></i> FINALIZAR * ${missingStatus} sem status</button>`;
      } else if (allDone) {
        finalizeBtnHtml = `<button onclick="Kanpro.finalizeMaintenance()" style="background:#00b8d9;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:700;font-size:12px"><i class="ti ti-check"></i> FINALIZAR</button>`;
      } else {
        const pendenteInfo = pendenteCount>0 ? ` • ${pendenteCount} pendente(s) → novo card` : "";
        finalizeBtnHtml = `<button onclick="Kanpro.finalizeMaintenance()" title="Nem todos estão como 'Feito' — pendentes ficarão em novo card" style="background:#ffab00;color:#172b4d;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:700;font-size:12px"><i class="ti ti-check"></i> FINALIZAR (${pct}%${pendenteInfo})</button>`;
      }
      let html = `
        <div style="background:#fff;border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13);overflow:hidden;margin-bottom:16px;border-left:4px solid #ffab00">
          <div style="padding:12px 16px;background:#fffae6;border-bottom:1px solid #ffecb5;display:flex;align-items:center;gap:8px;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px"><i class="ti ti-tool" style="font-size:18px;color:#ff991f"></i><strong style="color:#172b4d">Manutenção — Checklist por Máquina</strong> <span style="background:#ffab00;color:#172b4d;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">${done}/${total} • ${pct}%</span>${hasMissing?` <span style="background:#eb5a46;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">${missingStatus} sem Status</span>`:""}</div>
            <div style="display:flex;gap:6px;align-items:center">
              <button onclick="Kanpro.openMaintenanceSetup()" style="background:#fff;border:1px solid #dfe1e6;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:12px"><i class="ti ti-plus"></i> ${total? "Adicionar" : "Configurar"} máquinas</button>
              ${finalizeBtnHtml}
            </div>
          </div>
          ${hasMissing? `<div style="padding:8px 16px;background:#ffebe6;border-bottom:1px solid #ffbdad;color:#bf2600;font-size:12px"><i class="ti ti-alert-triangle"></i> <strong>Status Final obrigatório:</strong> selecione Garantia / Ok / Inservível / Pendente para todas as máquinas antes de finalizar. Faltam ${missingStatus}.</div>` : ""}
          ${pendenteCount>0? `<div style="padding:8px 16px;background:#e6fcff;border-bottom:1px solid #b3f0ff;color:#0052cc;font-size:11px"><i class="ti ti-info-circle"></i> ${pendenteCount} máquina(s) como <strong>Pendente</strong> ficarão em <strong>novo card</strong> após finalizar — as demais (Garantia/Ok/Inservível) irão para o termo e podem ser levadas.</div>` : ""}
          ${total? `<div style="padding:10px 16px"><div style="display:flex;align-items:center;gap:8px"><span style="font-size:11px;color:#5e6c84;min-width:36px">${pct}%</span><div class="kp-progress" style="flex:1;height:8px"><div class="kp-progress-bar" style="width:${pct}%;background:${allDone?"#61bd4f":"#ffab00"}"></div></div></div></div>` : ""}
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
        const invBg = isInventoried ? "#61bd4f" : "#fff";
        const invColor = isInventoried ? "#fff" : "#5e6c84";
        const invBorder = isInventoried ? "1px solid #61bd4f" : "1px solid #dfe1e6";
        const invLabel = isInventoried ? "✓ Inventariado" : "Inventário";
        const invIcon = isInventoried ? "ti ti-check" : "ti ti-clipboard";
        html += `
          <div class="kp-maint-machine${isUrgent?' urgent':''}" data-mid="${m.id}" style="background:${isUrgent?"#fff1f0":"#fff"};border-radius:8px;box-shadow:0 1px 1px rgba(9,30,66,.13);border-left:4px solid ${borderColor};overflow:hidden">
            <div style="padding:10px 12px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px;background:${isUrgent?"#ffecec":isDone?"#e3fcef":"#f4f5f7"};flex-wrap:wrap">
              <div style="display:flex;align-items:center;gap:8px;flex:1 1 220px;min-width:0">
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
                <button onclick="Kanpro.toggleMaintenanceInventoried(${m.id})" title="${isInventoried?"Clique para desmarcar inventário":"Clique para marcar como inventariado"}" style="display:flex;align-items:center;gap:4px;background:${invBg};color:${invColor};border:${invBorder};padding:6px 10px;border-radius:20px;cursor:pointer;font-size:11px;font-weight:700;min-width:95px;justify-content:center;white-space:nowrap;flex-shrink:0">
                  <i class="${invIcon}" style="font-size:12px"></i> ${invLabel}
                </button>
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
              <div style="font-size:11px;font-weight:600;color:#5e6c84;margin-bottom:4px;letter-spacing:.04em">DIÁRIO — o que foi feito nesta máquina</div>
              <textarea id="maint-diary-${m.id}" placeholder="Descreva o que foi feito nesta máquina... (ex: limpeza interna, troca de pasta térmica, verificação de memória)" style="width:100%;min-height:56px;padding:8px;border:1px solid #dfe1e6;border-radius:6px;resize:vertical;font-size:13px;box-sizing:border-box" oninput="Kanpro.onDiaryInput(${m.id})" onblur="Kanpro.autoSaveDiary(${m.id})">${this.escape(diary)}</textarea>
              <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap">
                <button onclick="Kanpro.saveMaintenanceDiary(${m.id})" style="background:#0079bf;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:600"><i class="ti ti-device-floppy"></i> Salvar diário</button>
                <span id="maint-save-status-${m.id}" style="font-size:11px;color:#5e6c84"></span>
                <span style="font-size:10px;color:#97a0af;font-style:italic">autosave a cada palavra</span>
                <span style="margin-left:auto;font-size:11px;color:#97a0af;display:flex;align-items:center;gap:6px;flex-wrap:wrap">Status Final: <span style="background:${statusColor};color:${statusTextColor};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">${statusLabel}</span> • ${isDone?'<span style="color:#61bd4f;font-weight:600">✔ Concluída</span>':'<span style="color:#ff991f">Em andamento</span>'} • <span style="background:${isInventoried?"#61bd4f":"#dfe1e6"};color:${isInventoried?"#fff":"#5e6c84"};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">${isInventoried?"✓ Inventariado":"○ Inventário"}</span></span>
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
        const c = this.cards.find(x=> String(x.id)===String(this.currentCardId));
        if(c){ c.is_maintenance=1; if(res.new_name) c.name=res.new_name; this.renderBoard(); }
        this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{
          if(r.success){
            this.renderCardModal(r.data);
            setTimeout(()=> this.openMaintenanceSetup(), 400);
          } else location.reload();
        });
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
        const c = this.cards.find(x=> x.id==this.currentCardId);
        if(c) c.is_maintenance=1;
        this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{
          if(r.success){
            this.renderCardModal(r.data);
            setTimeout(()=> this.openMaintenanceSetup(), 400);
          } else location.reload();
        });
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
                else { this.closePicker(); this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); }); this.renderBoard(); }
              });
            }
          }
          return;
        }
        this.closePicker();
        this.showToast(isAppend ? "Máquinas adicionadas!" : "Checklist gerado: "+(res.total||total)+" máquinas enumeradas");
        this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
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
      this.ajax("update_maintenance_machine", {id: mid, is_done: checked?1:0}).then(res=>{
        if(res.success){
          const cardId=this.currentCardId;
          this.ajax("get_card", {cards_id: cardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
          this.showToast(checked?"Máquina marcada como feita":"Marca removida");
        } else alert(res.msg||"Erro");
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
      this.ajax("update_maintenance_machine", data).then(res=>{
        if(res.success){
          this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
        }
      });
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
      this.ajax("update_maintenance_machine", {id: mid, is_inventoried: newVal}).then(res=>{
        if(btn){ btn.disabled=false; btn.style.opacity="1"; }
        if(res.success){
          this.showToast(newVal ? "✓ Inventariado" : "Inventário desmarcado");
          this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
        } else {
          alert(res.msg||"Erro ao atualizar inventário");
          if(btn){ btn.disabled=false; btn.style.opacity="1"; }
        }
      }).catch(()=>{
        if(btn){ btn.disabled=false; btn.style.opacity="1"; }
      });
    },
    toggleUrgent(mid){
      const data = this._lastModalData && this._lastModalData.maintenance_machines ? this._lastModalData.maintenance_machines.find(m=> String(m.id)===String(mid)) : null;
      const current = data ? Number(data.is_urgent)||0 : 0;
      const newVal = current ? 0 : 1;
      this.ajax("update_maintenance_machine", {id: mid, is_urgent: newVal}).then(res=>{
        if(res.success){
          this.showToast(newVal ? "🔥 Urgência marcada" : "Urgência removida");
          this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
        } else alert(res.msg||"Erro");
      });
    },
    retiradaMachine(mid){
      if(!confirm("Criar card de Retirada para esta máquina (urgência)? O card atual perderá esta máquina e um novo card será criado com as mesmas informações, indo para Assinatura.")) return;
      this.ajax("retirada_machine", {id: mid}).then(res=>{
        if(!res.success){ alert(res.msg||"Erro"); return; }
        this.showToast("Retirada criada — card #" + res.new_card_id);
        this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
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
      if(this._diarySaving[mid]) return;
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
    saveMaintenanceDiary(mid){
      const ta=document.getElementById("maint-diary-"+mid);
      const status=document.getElementById("maint-save-status-"+mid);
      if(!ta) return;
      const diary=ta.value;
      // cancela autosave pendente e salva imediatamente
      clearTimeout(this._diaryTimers[mid]);
      if(status) status.textContent=" Salvando...";
      this.ajax("update_maintenance_machine", {id: mid, diary}).then(res=>{
        if(res.success){
          this._lastDiarySaved[mid]=diary;
          if(status){ status.textContent=" ✓ Salvo"; status.style.color="#61bd4f"; setTimeout(()=> status.textContent="", 2000); }
        } else {
          if(status){ status.textContent=" Erro ao salvar"; status.style.color="#eb5a46"; }
        }
      });
    },
    deleteMaintenanceMachine(mid){
      this.kpConfirm("Remover esta máquina? A numeração será re-sequenciada (1…N).").then(ok=>{
        if(!ok) return;
        this.ajax("delete_maintenance_machine", {id: mid}).then(res=>{
          if(res.success){
            this.showToast("Máquina removida");
            this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
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
          this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
        } else alert(res.msg||"Erro");
      });
    },
    deleteMachineNote(noteId, mid){
      this.kpConfirm("Excluir esta anotação?").then(ok=>{
        if(!ok) return;
        this.ajax("delete_machine_note", {id: noteId}).then(res=>{
          if(res.success){
            this.openMachineNotes(mid); // recarrega lista
            this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
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
        this.ajax("get_card", {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); });
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
  <h2>Checklist por Máquina — Diário (Status Final)</h2>
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
    editMaintenanceCardTitle(){
      const cur = this.cards.find(c=> c.id==this.currentCardId);
      if(!cur) return;
      this.showPicker({title:"Alterar entidade — Manutenção", html: '<div style="padding:24px;text-align:center;color:#5e6c84"><i class="ti ti-loader" style="font-size:20px;animation:spin 1s linear infinite;display:inline-block"></i><br>Carregando entidades...</div>'});
      const doShow = (entities)=>{
        // filtra raiz e já sem prefixo
        const filteredEntities = (entities||[]).filter(e=>{
          const raw=(e.completename||e.name||'').trim();
          return raw !== 'Unidade Regional de Ensino de Jales' && raw.toLowerCase() !== 'unidade regional de ensino de jales' && raw !== 'Entidade Raiz' && raw.toLowerCase() !== 'entidade raiz';
        });
        this._renameEntities = filteredEntities;
        const curName = this.escape(cur.name);
        const html = `
          <div style="display:grid;gap:10px">
            <div style="background:#e6f7ff;border:1px solid #91d5ff;padding:8px 10px;border-radius:6px;color:#003a8c;font-size:12px;line-height:1.3">
              <strong><i class="ti ti-building" style="color:#1890ff"></i> Entidade atual:</strong> ${curName}<br><small style="color:#595959">Selecione outra entidade abaixo para alterar o nome do card. O nome do card virará o nome curto da entidade (último nível).</small>
            </div>
            <div style="position:relative">
              <input id="rename-entity-search" type="text" placeholder="Digite para buscar entidade... ex: Adelino, EE, Jales" autocomplete="off" style="width:100%;padding:10px 10px 10px 36px;border:2px solid #1890ff;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box" oninput="Kanpro.onRenameEntitySearch(this.value)" onfocus="Kanpro.showRenameEntityDropdown()" onkeydown="if(event.key==='Escape') Kanpro.hideRenameEntityDropdown()">
              <i class="ti ti-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8c8c8c;font-size:14px"></i>
              <div id="rename-entity-dropdown" style="position:absolute;top:100%;left:0;right:0;max-height:320px;overflow-y:auto;background:#fff;border:1px solid #91d5ff;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;z-index:20"></div>
            </div>
            <input type="hidden" id="rename-entity-select" value="">
            <div id="rename-entity-selected" style="font-size:12px;color:#389e0d;display:none;background:#f6ffed;border:1px solid #b7eb8f;padding:6px 8px;border-radius:4px"><i class="ti ti-check"></i> Selecionado: <strong id="rename-entity-selected-name"></strong> <a href="#" onclick="Kanpro.clearRenameSelection();return false" style="margin-left:8px;color:#ff4d4f;font-size:11px">trocar</a></div>
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
        this.showPicker({title:"Alterar entidade — Manutenção", html, x: px, y: py});
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
      if(this._maintEntities && this._maintEntities.length){
        doShow(this._maintEntities);
      } else {
        this.ajax("list_entities", {}).then(res=>{
          let entities = (res && res.success && Array.isArray(res.entities)) ? res.entities : [];
          this._maintEntities = entities;
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
      const entities_id = hid ? parseInt(hid.value||"0") : 0;
      const selectedName = hid ? (hid.dataset.name||"") : "";
      if(!entities_id){
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
          $('#card-modal-desc').textContent = val || 'Adicionar uma descrição mais detalhada...';
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
      if(allUsers.length===0) html += `<div style="color:#5e6c84;font-size:13px">Nenhum membro no quadro. Convide membros primeiro no menu do quadro.</div>`;
      else {
        html += allUsers.map(u=>`
          <label class="kp-picker-item" data-search="${this.escape(u.name)}" style="cursor:pointer">
            <input type="checkbox" ${memberIds.has(u.users_id)?'checked':''} onchange="Kanpro.toggleCardMember(${cardId}, ${u.users_id}, this.checked)"> 
            <span class="kp-avatar sm">${this.escape(u.initials)}</span> 
            <span style="flex:1">${this.escape(u.name)} <small style="color:#5e6c84">${this.escape(u.role||'')}</small></span>
            ${memberIds.has(u.users_id)?'<i class="ti ti-check" style="color:#61bd4f"></i>':''}
          </label>
        `).join('');
      }
      html += `</div><div style="margin-top:8px"><button onclick="Kanpro.openInvite()" style="background:#0079bf;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;width:100%"><i class="ti ti-user-plus"></i> Convidar para o quadro</button></div>`;
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
      this.ajax('update_label', {id: labelId, name: newName, color: newColor}).then(res=>{
        if(res.success){ l.name=newName; l.color=newColor; this.openLabelsPicker(); this.renderBoard(); }
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

    // Comments
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
      const cur = await this.kpPrompt('Editar comentário:');
      if(cur===null) return;
      this.ajax('update_comment', {id, content: cur}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
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
    },
    closeBoardMenu(){ $('#kanpro-board-menu').style.display='none'; },
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
    openInvite(){
      const memberIds = new Set(this.members.map(m=> String(m.users_id)));
      const all = (K.allUsers || []);
      const available = all.filter(u=> !memberIds.has(String(u.id)));
      const html = `
        <div style="display:grid;gap:10px">
          <label style="font-size:12px;font-weight:600;color:#5e6c84">Papel no quadro
            <select id="invite-role" style="width:100%;margin-top:4px;padding:8px;border:1px solid #dfe1e6;border-radius:6px;background:#fff"><option value="member">👤 Membro</option><option value="admin">⭐ Administrador</option><option value="observer">👁️ Observador</option></select>
          </label>
          <input id="invite-search" type="text" placeholder="🔍 Buscar por nome ou login..." oninput="Kanpro.filterInvite(this.value)" style="padding:10px;border:1px solid #dfe1e6;border-radius:6px;outline:none">
          <div id="invite-list" style="max-height:260px;overflow-y:auto;display:grid;gap:6px;border:1px solid #dfe1e6;border-radius:8px;padding:6px;background:#f9fafb"></div>
          <div style="font-size:11px;color:#5e6c84;text-align:center">${available.length} disponível(is) · ${all.length} no total · digite para filtrar</div>
          <hr style="border:none;border-top:1px solid #dfe1e6;margin:2px 0">
          <div style="font-size:12px;font-weight:700;color:#172b4d">Membros atuais (${this.members.length})</div>
          <div id="invite-current" style="display:grid;gap:6px;max-height:140px;overflow-y:auto"></div>
        </div>`;
      this.showPicker({title:'Convidar para o quadro', html});
      // picker responsivo: ocupa viewport mas sem cortar botão à direita
      const picker = document.getElementById('kanpro-picker');
      const body = document.getElementById('picker-body');
      if(picker){
        const w = Math.min(520, window.innerWidth - 32);
        picker.style.minWidth = w + 'px';
        picker.style.maxWidth = w + 'px';
        picker.style.width = w + 'px';
        picker.style.left = '50%';
        picker.style.right = 'auto';
        picker.style.transform = 'translate(-50%,-50%)';
        picker.style.maxHeight = '90vh';
        picker.style.overflow = 'hidden';
        picker.style.display = 'flex';
        picker.style.flexDirection = 'column';
      }
      if(body){ body.style.maxHeight = '70vh'; body.style.overflowY = 'auto'; body.style.minHeight = '0'; }
      setTimeout(()=>{
        const cur = document.getElementById('invite-current');
        if(cur) cur.innerHTML = this.members.map(m=> `<div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #dfe1e6;padding:8px 10px;border-radius:8px"><span style="display:flex;align-items:center;gap:8px"><span class="kp-avatar sm">${this.escape(m.initials)}</span><span style="font-size:13px">${this.escape(m.name)}</span> <small style="background:#dfe1e6;padding:2px 6px;border-radius:10px;font-size:11px">${m.role}</small></span><button onclick="event.stopPropagation();Kanpro.removeMember(${m.users_id})" title="Remover" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;width:28px;height:28px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="ti ti-x" style="font-size:14px"></i></button></div>`).join('') || '<div style="text-align:center;color:#5e6c84;font-size:13px;padding:8px;border:1px dashed #dfe1e6;border-radius:8px">Nenhum membro além de você</div>';
        this.renderInviteList('');
        const inp = document.getElementById('invite-search');
        if(inp) inp.focus();
      }, 50);
    },
    renderInviteList(filter){
      const q = (filter||'').toLowerCase().trim();
      const memberIds = new Set(this.members.map(m=> String(m.users_id)));
      const all = (K.allUsers || []);
      const list = document.getElementById('invite-list');
      if(!list) return;
      let filtered = all.filter(u=> !memberIds.has(String(u.id)));
      if(q) filtered = filtered.filter(u=> u.name.toLowerCase().includes(q) || u.login.toLowerCase().includes(q));
      if(filtered.length===0){
        list.innerHTML = '<div style="padding:20px;text-align:center;color:#5e6c84"><i class="ti ti-search-off" style="font-size:24px"></i><div style="margin-top:6px;font-size:13px">Nenhum usuário encontrado</div><div style="font-size:11px">Tente outro termo</div></div>';
        return;
      }
      list.innerHTML = filtered.slice(0,60).map(u=> `
        <div onclick="Kanpro.confirmInviteId(${u.id})" style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:10px 12px;gap:10px;cursor:pointer">
          <span style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;overflow:hidden"><span class="kp-avatar sm" style="flex-shrink:0">${this.escape(u.initials)}</span><span style="min-width:0;flex:1;overflow:hidden"><div style="font-size:13px;font-weight:600;color:#172b4d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${this.escape(u.name)}</div><div style="font-size:11px;color:#5e6c84;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">@${this.escape(u.login)}</div></span></span>
          <button onclick="event.stopPropagation();Kanpro.confirmInviteId(${u.id}, this)" style="background:#0079bf;color:#fff;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;flex-shrink:0;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.15)">Adicionar</button>
        </div>`).join('') + (filtered.length>60 ? `<div style="text-align:center;font-size:11px;color:#5e6c84;padding:6px;background:#fff;border:1px dashed #dfe1e6;border-radius:8px">+${filtered.length-60} mais — refine a busca</div>` : '');
    },
    filterInvite(q){ this.renderInviteList(q); },
    confirmInviteId(uid, btn){
      const role = document.getElementById('invite-role')?.value || 'member';
      if(btn){ btn.disabled=true; btn.textContent='...'; }
      console.log('KanPro invite', uid, role, this.ajax_url);
      this.ajax('invite_member', {boards_id: this.board.id, users_id: uid, role}).then(res=>{
        if(btn){ btn.disabled=false; btn.textContent='Adicionar'; }
        if(res.success){
          const u = (K.allUsers||[]).find(x=> x.id==uid);
          if(u) this.members.push({users_id: uid, name: u.name, initials: u.initials, role});
          this.renderMemberAvatars();
          this.renderBoardMenuDetails();
          // re-render lista sem fechar picker (evita flicker)
          this.renderInviteList(document.getElementById('invite-search')?.value || '');
          const cur = document.getElementById('invite-current');
          if(cur) cur.innerHTML = this.members.map(m=> `<div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #dfe1e6;padding:8px 10px;border-radius:8px"><span style="display:flex;align-items:center;gap:8px"><span class="kp-avatar sm">${this.escape(m.initials)}</span><span style="font-size:13px">${this.escape(m.name)}</span> <small style="background:#dfe1e6;padding:2px 6px;border-radius:10px;font-size:11px">${m.role}</small></span><button onclick="event.stopPropagation();Kanpro.removeMember(${m.users_id})" style="background:#fef2f2;border:1px solid #fecaca;color:#eb5a46;width:28px;height:28px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="ti ti-x"></i></button></div>`).join('');
        } else {
          console.warn('invite failed', res);
          if(res.msg && res.msg.includes('Sem permissão')){
            alert('Sem permissão (precisa UPDATE em Perfil → KanPro). Saia e entre novamente.');
          } else if(res.msg && res.msg.includes('já é membro')){
            alert('Usuário já é membro do quadro.');
            this.renderInviteList(document.getElementById('invite-search')?.value || '');
          } else alert(res.msg||'Erro ao convidar (ver console)');
        }
      });
    },
    confirmInvite(){
      // legado: mantém para compat, mas agora usa lista
      const input = document.getElementById('invite-search');
      alert('Selecione um usuário na lista acima e clique em Adicionar.');
      if(input) input.focus();
    },
    async removeMember(uid){
      if(!await this.kpConfirm('Remover membro?')) return;
      this.ajax('remove_member', {boards_id: this.board.id, users_id: uid}).then(res=>{
        if(res.success) location.reload();
      });
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
      wrap.innerHTML = this.members.slice(0,5).map(m=> `<span class="kp-avatar" style="margin-left:-6px;border:2px solid #fff" title="${this.escape(m.name)}">${this.escape(m.initials)}</span>`).join('') + (this.members.length>5? `<span class="kp-avatar" style="background:#091e42;color:#fff;margin-left:-6px">+${this.members.length-5}</span>`:'');
    },
    renderBoardMenuDetails(){
      const labWrap = $('#board-menu-labels');
      if(labWrap) labWrap.innerHTML = this.labels.map(l=> `<div style="display:flex;justify-content:space-between;align-items:center;background:${this.escape(l.color)};color:#fff;padding:6px 10px;border-radius:4px"><span>${this.escape(l.name||'Sem nome')}</span><span style="font-size:11px;opacity:.8">${this.cardLabelsCount(l.id)} cartões</span></div>`).join('') || '<small style="color:#5e6c84">Nenhuma etiqueta</small>';
      const memWrap = $('#board-menu-members');
      if(memWrap) memWrap.innerHTML = this.members.map(m=> `<div style="display:flex;align-items:center;gap:8px;background:#fff;padding:6px 8px;border-radius:4px"><span class="kp-avatar sm">${this.escape(m.initials)}</span><span style="flex:1">${this.escape(m.name)}</span><small style="background:#dfe1e6;padding:2px 6px;border-radius:10px">${m.role}</small></div>`).join('') || '<small style="color:#5e6c84">Só você</small>';
    },
    cardLabelsCount(labelId){
      let c=0;
      for(let cid in this.cardLabels) if(this.cardLabels[cid].some(l=> l.id==labelId)) c++;
      return c;
    },

    // Filter
    filterCards(text){
      this.filterText = (text||'').toLowerCase();
      this.applyFilters();
    },
    applyFilters(){
      $$('.kp-card').forEach(el=>{
        const cardId = parseInt(el.dataset.cardId);
        const card = this.cards.find(c=> c.id==cardId);
        if(!card){ el.style.display=''; return; }
        const matchText = !this.filterText || card.name.toLowerCase().includes(this.filterText) || (card.description||'').toLowerCase().includes(this.filterText);
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
      if(this.filterText && !card.name.toLowerCase().includes(this.filterText) && !(card.description||'').toLowerCase().includes(this.filterText)) return true;
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
      // gera calendário do mês atual
      const now = new Date();
      const year = now.getFullYear(), month = now.getMonth();
      const firstDay = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month+1, 0).getDate();
      const daysInPrev = new Date(year, month, 0).getDate();
      let html = `<div style="max-width:1000px;margin:0 auto"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px"><h2 style="margin:0">📅 Calendário — ${now.toLocaleDateString('pt-BR',{month:'long',year:'numeric'})}</h2><button onclick="document.getElementById('kanpro-calendar').style.display='none'" style="background:#172b4d;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer">✕ Fechar</button></div>`;
      html+=`<div class="kp-cal-grid">`;
      ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'].forEach(d=> html+=`<div class="kp-cal-header">${d}</div>`);
      // prev month filler
      for(let i=firstDay-1;i>=0;i--) html+=`<div class="kp-cal-cell other"><div class="kp-cal-daynum">${daysInPrev - i}</div></div>`;
      for(let d=1; d<=daysInMonth; d++){
        const isToday = d===now.getDate();
        html+=`<div class="kp-cal-cell ${isToday?'today':''}"><div class="kp-cal-daynum">${d}</div>`;
        // cartões com due_date neste dia
        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        this.cards.filter(c=> c.due_date && c.due_date.startsWith(dateStr) && c.is_archived==0).forEach(c=>{
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

    // Helpers
    updateStats(){
      const total = this.cards.filter(c=> c.is_archived==0).length;
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
    }
  };

  window.Kanpro = Kanpro;
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', ()=> Kanpro.init());
  else Kanpro.init();

  // expose global close handlers for inline onclick
  window.KanproClosePicker = ()=> Kanpro.closePicker();

})();
