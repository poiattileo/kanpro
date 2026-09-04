// KanPro - Trello Clone JS
(function(){
  if (!window.KANPRO) return;
  const K = window.KANPRO;
  const $ = (s, el=document) => el.querySelector(s);
  const $$ = (s, el=document) => [...el.querySelectorAll(s)];

  const Kanpro = {
    board: K.board,
    lists: K.lists || [],
    cards: K.cards || [],
    labels: K.labels || [],
    cardLabels: K.cardLabels || {},
    cardMembers: K.cardMembers || {},
    checkProgress: K.checkProgress || {},
    commentCounts: K.commentCounts || {},
    attCounts: K.attCounts || {},
    members: K.members || [],
    ajax_url: (K.ajax_url && K.ajax_url.indexOf('/glpi/')===0) ? K.ajax_url.replace('ajax.php','ajax2.php') : (K.ajax_url ? K.ajax_url.replace(/^\/plugins\//, '/glpi/plugins/').replace('ajax.php','ajax2.php') : '/glpi/plugins/kanpro/front/ajax2.php'),
    canEdit: K.canEdit,
    currentCardId: null,
    dragCard: null,
    dragList: null,
    filterText: '',
    labelFilter: new Set(),
    memberFilter: new Set(),

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
      return fetch(this.ajax_url, { method:'POST', body: fd, credentials:'same-origin' })
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
      this.renderBoard();
      this.renderMemberAvatars();
      this.renderBoardMenuDetails();
      this.updateStats();
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
      // drag handle só no header
      div.addEventListener('dragstart', e=>{
        if(!e.target.closest('.kp-list-header')) { e.preventDefault(); return; }
        this.dragList = div;
        div.style.opacity='0.5';
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/plain', list.id);
      });
      div.addEventListener('dragend', ()=>{ div.style.opacity='1'; this.dragList=null; });
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
        <div class="kp-card-title">${this.escape(card.name)}</div>
        ${badges.length?`<div class="kp-card-badges">${badges.join('')}</div>`:''}
        ${membersHtml}
        <button class="kp-card-edit" onclick="event.stopPropagation(); Kanpro.quickEditCard(${card.id}, event)"><i class="ti ti-pencil" style="font-size:14px"></i></button>
      `;
      div.addEventListener('click', ()=> this.openCard(card.id));
      div.addEventListener('dragstart', e=>{
        this.dragCard = div;
        div.classList.add('dragging');
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/plain', card.id);
        // necessário para firefox
        setTimeout(()=> div.style.display='none', 0);
      });
      div.addEventListener('dragend', ()=>{
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
          if (!after) container.appendChild(dragging);
          else container.insertBefore(dragging, after);
        });
        container.addEventListener('dragleave', e=>{
          if (!container.contains(e.relatedTarget)) container.classList.remove('drag-over');
        });
        container.addEventListener('drop', e=>{
          e.preventDefault();
          container.classList.remove('drag-over');
          const cardId = e.dataTransfer.getData('text/plain');
          // verifica se é card ou list
          const isCard = this.cards.some(c=> c.id==cardId);
          if (!isCard) return;
          const targetListId = parseInt(container.dataset.listId);
          // calcula posição
          const cardsEls = [...container.querySelectorAll('.kp-card')];
          const pos = cardsEls.findIndex(el=> el.dataset.cardId==cardId);
          this.moveCardTo(cardId, targetListId, pos);
        });
      });

      // Listas (drag no header)
      const board = $('#kanpro-board');
      board.addEventListener('dragover', e=>{
        if(!this.dragList) return;
        e.preventDefault();
        const after = this.getDragAfterElementBoard(board, e.clientX);
        if (!after) board.insertBefore(this.dragList, board.querySelector('.kp-add-list'));
        else board.insertBefore(this.dragList, after);
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
            <button class="kp-picker-item" style="color:#eb5a46" onclick="if(confirm('Excluir lista e todos os cartões?')) Kanpro.deleteList(${listId})"><i class="ti ti-trash"></i> Excluir lista</button>
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
    quickEditCard(cardId, e){
      e.stopPropagation();
      const card = this.cards.find(c=> c.id==cardId);
      const newName = prompt('Editar título do cartão:', card.name);
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
      $('#kanpro-card-modal').style.display='none';
      document.body.style.overflow='';
      this.currentCardId=null;
      this.closePicker();
    },
    renderCardModal(data){
      $('#card-modal-title').textContent = data.name;
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

      // attachments
      const attContainer = $('#card-modal-attachments');
      if(data.attachments && data.attachments.length){
        attContainer.innerHTML = data.attachments.map(a=>`
          <div style="display:flex;gap:10px;padding:8px;background:#fff;border-radius:4px;align-items:center;box-shadow:0 1px 1px rgba(9,30,66,.13)">
            <div style="width:36px;height:36px;background:#dfe1e6;border-radius:4px;display:flex;align-items:center;justify-content:center"><i class="ti ti-file"></i></div>
            <div style="flex:1">
              <div style="font-weight:600;font-size:13px">${this.escape(a.name)}</div>
              <div style="font-size:11px;color:#5e6c84">${this.formatFileSize(a.filesize)} • ${this.formatDate(a.date_creation)} • <a href="${K.ajax_url.replace('ajax.php','attachment.php?id='+a.id)}" target="_blank">Abrir</a> • <a href="#" onclick="Kanpro.makeCover(${a.id});return false">Tornar capa</a></div>
            </div>
            <button onclick="Kanpro.deleteAttachment(${a.id})" style="background:none;border:none;cursor:pointer;color:#eb5a46"><i class="ti ti-trash"></i></button>
          </div>
        `).join('');
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
      if(idx>=0){ this.cards[idx].name=data.name; this.cards[idx].description=data.description; this.cards[idx].due_date=data.due_date; this.cards[idx].start_date=data.start_date; this.cards[idx].cover_color=data.cover_color; this.cards[idx].is_completed=data.is_completed; }
      // atualiza maps
      this.cardLabels[data.id] = data.labels||[];
      this.cardMembers[data.id] = data.members?.map(m=>({users_id:m.id||m.users_id, name:m.realname||m.name, initials:(m.firstname?.[0]||'?').toUpperCase()})) || [];
      // re-render board silencioso (mantém modal)
      this.renderBoardQuick();
    },

    renderBoardQuick(){
      // re-render apenas sem resetar modal, mantendo filtros
      this.renderBoard();
      this.renderMemberAvatars();
    },

    editCardTitle(){
      const cur = this.cards.find(c=> c.id==this.currentCardId);
      const novo = prompt('Título do cartão:', cur.name);
      if(novo && novo!==cur.name){
        this.ajax('update_card', {id: this.currentCardId, name: novo}).then(res=>{
          if(res.success){ cur.name=novo; $('#card-modal-title').textContent=novo; this.renderBoard(); }
        });
      }
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
              <button onclick="event.preventDefault(); if(confirm('Excluir etiqueta?')) Kanpro.deleteLabel(${l.id})" style="background:rgba(255,255,255,.3);border:none;color:#fff;padding:2px 6px;border-radius:4px;cursor:pointer"><i class="ti ti-trash"></i></button>
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
    editLabel(labelId){
      const l = this.labels.find(x=> x.id==labelId);
      const newName = prompt('Nome da etiqueta:', l.name);
      if(newName===null) return;
      const newColor = prompt('Cor (hex #rrggbb):', l.color) || l.color;
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
    addChecklist(){
      const name = prompt('Nome do checklist:', 'Checklist');
      if(!name) return;
      this.ajax('add_checklist', {cards_id: this.currentCardId, name}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    openChecklistPicker(){
      this.addChecklist();
    },
    deleteChecklist(id){
      if(!confirm('Excluir checklist?')) return;
      this.ajax('delete_checklist', {id}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    addCheckItem(clId, input){
      const name = input.value.trim();
      if(!name) return;
      this.ajax('add_checkitem', {checklists_id: clId, name}).then(res=>{
        if(res.success){ input.value=''; this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); }); }
      });
    },
    toggleCheckItem(itemId, checked){
      this.ajax('toggle_checkitem', {id: itemId}).then(res=>{
        if(res.success){
          // atualiza progress local
          this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.updateCheckProgressLocal(); });
        }
      });
    },
    editCheckItem(itemId){
      const novo = prompt('Editar item:');
      if(novo===null) return;
      this.ajax('rename_checkitem', {id: itemId, name: novo}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    deleteCheckItem(itemId){
      this.ajax('delete_checkitem', {id: itemId}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    updateCheckProgressLocal(){
      // recalcula badge
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
    editComment(id){
      const cur = prompt('Editar comentário:');
      if(cur===null) return;
      this.ajax('update_comment', {id, content: cur}).then(res=>{
        if(res.success) this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); });
      });
    },
    deleteComment(id){
      if(!confirm('Excluir comentário?')) return;
      this.ajax('delete_comment', {id}).then(res=>{
        if(res.success){ this.commentCounts[this.currentCardId] = Math.max(0,(this.commentCounts[this.currentCardId]||1)-1); this.ajax('get_card', {cards_id: this.currentCardId}).then(r=>{ if(r.success) this.renderCardModal(r.data); this.renderBoard(); }); }
      });
    },
    toggleActivity(){
      const el = $('#card-modal-activity');
      el.style.display = el.style.display==='none' ? 'grid' : 'none';
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
    deleteAttachment(id){
      if(!confirm('Excluir anexo?')) return;
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
    archiveCard(){
      if(!confirm('Arquivar este cartão?')) return;
      this.ajax('archive_card', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){ this.cards = this.cards.filter(c=> c.id!=this.currentCardId); this.closeCardModal(); this.renderBoard(); }
      });
    },
    deleteCard(){
      if(!confirm('Excluir permanentemente? Esta ação não pode ser desfeita.')) return;
      this.ajax('delete_card', {cards_id: this.currentCardId}).then(res=>{
        if(res.success){ this.cards = this.cards.filter(c=> c.id!=this.currentCardId); this.closeCardModal(); this.renderBoard(); }
      });
    },

    // Board actions
    renameBoard(){
      if(!this.canEdit) return;
      const novo = prompt('Novo nome do quadro:', this.board.name);
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
    },
    closeBoardMenu(){ $('#kanpro-board-menu').style.display='none'; },
    openBoardSettings(){
      const novo = prompt('Cor do quadro (hex):', this.board.color);
      if(novo && /^#[0-9a-fA-F]{6}$/.test(novo)){
        this.ajax('update_board_color', {boards_id: this.board.id, color: novo}).then(res=>{
          if(res.success){ this.board.color=novo; $('#kanpro-app').style.background=novo; this.closeBoardMenu(); }
        });
      }
    },
    archiveBoard(){
      if(!confirm('Arquivar quadro?')) return;
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
        picker.style.display = 'flex';
        picker.style.flexDirection = 'column';
      }
      if(body){ body.style.maxHeight = '70vh'; body.style.overflowY = 'auto'; }
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
        <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #dfe1e6;border-radius:8px;padding:10px 12px;gap:10px;flex-wrap:nowrap">
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
    removeMember(uid){
      if(!confirm('Remover membro?')) return;
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
    showPicker({title, html, x, y}){
      const p = $('#kanpro-picker');
      $('#picker-title').textContent = title||'';
      $('#picker-body').innerHTML = html||'';
      p.style.display='block';
      if(x!==null && y!==null){
        p.style.left = Math.max(12, Math.min(x, window.innerWidth-360)) + 'px';
        p.style.top = (y+8) + 'px';
        p.style.right='auto';
      } else {
        // centraliza próximo ao modal ou centro da tela
        p.style.left = '50%';
        p.style.top = '50%';
        p.style.transform = 'translate(-50%,-50%)';
      }
      // posicionamento inteligente se sair da tela
      setTimeout(()=>{
        const rect = p.getBoundingClientRect();
        if(rect.right > window.innerWidth) p.style.left = (window.innerWidth - rect.width -12) + 'px';
        if(rect.bottom > window.innerHeight) p.style.top = (window.innerHeight - rect.height -12) + 'px';
      }, 0);
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
    addBoardLabel(){
      const name = prompt('Nome da etiqueta:','');
      const color = prompt('Cor hex (#rrggbb):','#61bd4f');
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
