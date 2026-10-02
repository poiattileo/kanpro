// KanPro dnd — extraído de kanpro.js (split sem build) + touch para mobile.
// Carrega DEPOIS de kanpro.js e estende window.Kanpro via Object.assign.
// Desktop mantém HTML5 drag; touch usa long-press (350ms) + ghost + highlight (sem lib externa).
(function(){
  if (!window.Kanpro) return;
  const K = window.Kanpro;

  function cardEl(id){ return document.querySelector(`.kp-card[data-card-id="${id}"]`); }

  function bindTouchOnce(){
    if (K._touchDndBound) return;
    K._touchDndBound = true;
    let pressTimer = null, draggingId = null, ghost = null, startX = 0, startY = 0;

    const clearGhost = ()=>{
      try { ghost && ghost.remove(); } catch(_){}
      ghost = null;
      document.querySelectorAll('.kp-list-cards.touch-target').forEach(el=> el.classList.remove('touch-target'));
    };

    document.addEventListener('touchstart', (e)=>{
      const card = e.target && e.target.closest ? e.target.closest('.kp-card') : null;
      if (!card || K.dragCard || K.dragList) return;
      const id = card.dataset.cardId;
      const t = e.touches[0];
      startX = t.clientX; startY = t.clientY;
      pressTimer = setTimeout(()=>{
        // long-press: inicia arraste touch (vibra se disponível)
        try { navigator.vibrate && navigator.vibrate(30); } catch(_){}
        draggingId = id;
        card.classList.add('dragging');
        ghost = card.cloneNode(true);
        ghost.style.cssText = 'position:fixed;z-index:40000;pointer-events:none;opacity:.9;transform:scale(1.03) rotate(1deg);box-shadow:0 12px 28px rgba(0,0,0,.3);width:' + card.offsetWidth + 'px';
        ghost.style.left = (startX - card.offsetWidth/2) + 'px';
        ghost.style.top = (startY - 30) + 'px';
        document.body.appendChild(ghost);
      }, 350);
    }, {passive:true});

    document.addEventListener('touchmove', (e)=>{
      if (!draggingId) {
        // cancelou se moveu antes do long-press
        if (pressTimer) {
          const t = e.touches[0];
          if (Math.hypot(t.clientX-startX, t.clientY-startY) > 10) { clearTimeout(pressTimer); pressTimer = null; }
        }
        return;
      }
      e.preventDefault();
      const t = e.touches[0];
      if (ghost) { ghost.style.left = (t.clientX - 60) + 'px'; ghost.style.top = (t.clientY - 30) + 'px'; }
      // highlight da coluna alvo
      document.querySelectorAll('.kp-list-cards.touch-target').forEach(el=> el.classList.remove('touch-target'));
      const el = document.elementFromPoint(t.clientX, t.clientY);
      const col = el && el.closest ? el.closest('.kp-list-cards') : null;
      if (col) col.classList.add('touch-target');
    }, {passive:false});

    document.addEventListener('touchend', (e)=>{
      if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
      if (!draggingId) return;
      const t = (e.changedTouches && e.changedTouches[0]) || {clientX: startX, clientY: startY};
      const el = document.elementFromPoint(t.clientX, t.clientY);
      const col = el && el.closest ? el.closest('.kp-list-cards') : null;
      const id = draggingId;
      draggingId = null;
      document.querySelectorAll('.kp-card.dragging').forEach(c=> c.classList.remove('dragging'));
      clearGhost();
      if (!col) { K.renderBoard && K.renderBoard(); return; }
      const targetListId = parseInt(col.dataset.listId, 10);
      const cardsEls = [...col.querySelectorAll('.kp-card')];
      // posição aproximada pelo Y do toque
      let pos = cardsEls.length;
      for (let i=0;i<cardsEls.length;i++){
        const r = cardsEls[i].getBoundingClientRect();
        if (t.clientY < r.top + r.height/2) { pos = i; break; }
      }
      // ignora se é a mesma posição
      try { K.moveCardTo && K.moveCardTo(id, targetListId, pos); }
      catch(_){ K.renderBoard && K.renderBoard(); }
    }, {passive:true});

    document.addEventListener('touchcancel', ()=>{
      if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
      draggingId = null;
      document.querySelectorAll('.kp-card.dragging').forEach(c=> c.classList.remove('dragging'));
      clearGhost();
    }, {passive:true});
  }

  // Envolve o enable original: depois de ligar o DnD desktop, liga o touch (1x).
  const __origEnable = K.enableDragAndDrop;
  Object.assign(K, {
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
    enableDragAndDrop(){
      try { __origEnable && __origEnable.call(this); } catch(_){}
      try { bindTouchOnce.call(this); } catch(_){}
      // CSS touch: permite scroll vertical mas segura o long-press nos cards
      try {
        if (!document.getElementById('kp-touch-dnd-css')) {
          const st = document.createElement('style');
          st.id = 'kp-touch-dnd-css';
          st.textContent = '.kp-card{touch-action:pan-y}.kp-list-cards.touch-target{outline:2px dashed #0079bf;outline-offset:-2px;border-radius:8px}.kp-card.dragging{opacity:.4}';
          document.head.appendChild(st);
        }
      } catch(_){}
    },
  });
})();
