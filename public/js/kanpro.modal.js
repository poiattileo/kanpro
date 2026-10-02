// KanPro modal — extraído de kanpro.js (split sem build).
// Carrega DEPOIS de kanpro.js (ver setup.php) e estende window.Kanpro via Object.assign.
// Inclui focus-trap + Esc central: um único keydown fecha picker/modal/confirm (mata os bugs de picker).
(function(){
  if (!window.Kanpro) return;
  const K = window.Kanpro;

  // Fecha tudo com Esc numa ordem só (antes cada modal tinha seu listener vazando).
  let __escBound = false;
  function bindEscOnce(){
    if (__escBound) return;
    __escBound = true;
    document.addEventListener('keydown', (e)=>{
      if (e.key !== 'Escape') return;
      try {
        // edição inline de comentário tem prioridade (não perde o resto)
        const ae = document.activeElement;
        if (ae && ae.id && ae.id.indexOf('kp-comment-edit-') === 0 && K.cancelCommentEdit) {
          K.cancelCommentEdit(ae.id.replace('kp-comment-edit-', ''));
          return;
        }
      } catch(_){}
      try { K.closePicker && K.closePicker(); } catch(_){}
      try { K.closeBoardMenu && K.closeBoardMenu(); } catch(_){}
      try { K.closeCalendarView && K.closeCalendarView(); } catch(_){}
      try { K.clearCardSelection && K.clearCardSelection(); } catch(_){}
      // card-modal só fecha se não houver confirm/alert por cima
      try {
        if (!document.getElementById('kp-confirm-overlay') && !document.getElementById('kp-alert-overlay')) {
          K.closeCardModal && K.closeCardModal();
        }
      } catch(_){}
    });
  }

  // Focus-trap simples p/ overlays (acessibilidade + evita Tab fugir do modal).
  function trapFocus(overlay){
    try {
      const sel = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
      const els = [...overlay.querySelectorAll(sel)].filter(el=> !el.disabled && el.offsetParent !== null);
      if (!els.length) return;
      const first = els[0], last = els[els.length-1];
      overlay.addEventListener('keydown', (e)=>{
        if (e.key !== 'Tab') return;
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      });
      setTimeout(()=> first.focus(), 30);
    } catch(_){}
  }

  Object.assign(K, {
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
      bindEscOnce();
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
      trapFocus(ov);
      const close = ()=> ov.remove();
      ov.addEventListener('click', e=>{ if(e.target===ov) close(); });
      ov.querySelector('#kp-alert-ok').addEventListener('click', close);
    },
    // Confirmação customizada no mesmo visual do showAlert (substitui confirm() nativo) — retorna Promise<boolean>
    showConfirm(message, title, okLabel){
      bindEscOnce();
      return new Promise(resolve=>{
        document.getElementById('kp-confirm-overlay')?.remove();
        const ov = document.createElement('div');
        ov.id = 'kp-confirm-overlay';
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:30000;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box';
        ov.innerHTML = `
          <div style="background:#fff;border-radius:10px;box-shadow:0 16px 48px rgba(0,0,0,.35);max-width:480px;width:100%;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid #dfe1e6;font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px">
              <span style="font-size:18px">⚠️</span>
              <span>${this.escape(title||'Atenção')}</span>
            </div>
            <div style="padding:16px;font-size:13px;color:#172b4d;white-space:pre-line;line-height:1.6">${this.escape(message||'')}</div>
            <div style="padding:12px 16px;background:#f4f5f7;display:flex;gap:8px;justify-content:flex-end">
              <button id="kp-confirm-no" style="background:#fff;color:#172b4d;border:1px solid #dfe1e6;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:700">Cancelar</button>
              <button id="kp-confirm-yes" style="background:#0052cc;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:700">${this.escape(okLabel||'Confirmar')}</button>
            </div>
          </div>`;
        document.body.appendChild(ov);
        trapFocus(ov);
        const done = v=>{ ov.remove(); resolve(v); };
        ov.addEventListener('click', e=>{ if(e.target===ov) done(false); });
        ov.querySelector('#kp-confirm-no').addEventListener('click', ()=> done(false));
        ov.querySelector('#kp-confirm-yes').addEventListener('click', ()=> done(true));
      });
    },
    kpConfirm(message){
      bindEscOnce();
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
        trapFocus(overlay);
        const cleanup = (val)=>{ overlay.remove(); resolve(val); };
        overlay.addEventListener('click', e=>{ if(e.target===overlay) cleanup(false); });
        overlay.querySelector('[data-a="cancel"]').onclick = ()=> cleanup(false);
        overlay.querySelector('[data-a="ok"]').onclick = ()=> cleanup(true);
      });
    },
    kpPrompt(message, defaultValue){
      bindEscOnce();
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
        trapFocus(overlay);
        const input = overlay.querySelector('[data-a="input"]');
        const cleanup = (val)=>{ overlay.remove(); resolve(val); };
        overlay.addEventListener('click', e=>{ if(e.target===overlay) cleanup(null); });
        overlay.querySelector('[data-a="cancel"]').onclick = ()=> cleanup(null);
        overlay.querySelector('[data-a="ok"]').onclick = ()=> cleanup(input.value);
        input.addEventListener('keydown', e=>{
          if(e.key==='Enter'){ e.preventDefault(); cleanup(input.value); }
        });
        setTimeout(()=>{ input.focus(); input.select(); }, 30);
      });
    },
  });

  bindEscOnce();
})();
