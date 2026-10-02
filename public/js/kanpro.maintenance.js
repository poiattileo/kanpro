// KanPro maintenance — split sem build (fase 1: polling backoff + namespace).
// Carrega DEPOIS de kanpro.js e estende window.Kanpro via Object.assign.
// Fase 2 move os ~1500 linhas de fluxo manutenção (showMaintenanceStep*/submit/finalize) para cá.
(function(){
  if (!window.Kanpro) return;
  const K = window.Kanpro;

  // Backoff: 3s base, sobe até 15s após 10 ciclos sem mudança, volta a 3s no 1º diff.
  // Economiza ~80% de requests em quadro parado sem piorar a latência quando há atividade.
  // + botão sync manual (K.forceSync já existe no core).
  const BASE_MS = 3000, MAX_MS = 15000, QUIET_ROUNDS = 10;

  function nextInterval(){
    const q = K._unchangedRounds || 0;
    if (q < QUIET_ROUNDS) return BASE_MS;
    const step = Math.min(q - QUIET_ROUNDS + 1, 6);
    return Math.min(BASE_MS + step * 2000, MAX_MS);
  }

  const __origPoll = K.pollBoardUpdates;
  Object.assign(K, {
    maintenance: K.maintenance || {},
    startPolling(){
      try {
        this._lastSnapshotJson = JSON.stringify({
          lists: this.lists, hiddenLists: this.hiddenLists, cards: this.cards, labels: this.labels,
          cardLabels: this.cardLabels, cardMembers: this.cardMembers,
          checkProgress: this.checkProgress, maintenanceProgress: this.maintenanceProgress, commentCounts: this.commentCounts,
          attCounts: this.attCounts, members: this.members, transferStatus: this.transferStatus
        });
      } catch(_){ this._lastSnapshotJson = null; }
      this._lastStamp = null;
      this._unchangedRounds = 0;
      this._pollIntervalMs = BASE_MS;
      try { this.ajax('presence_heartbeat', {boards_id: this.board.id}); } catch(_){}
      if(this._pollTimer) clearTimeout(this._pollTimer);
      this._pollingStartedAt = Date.now();
      const loop = ()=>{
        this.pollBoardUpdates().finally(()=>{
          this._pollIntervalMs = nextInterval();
          this._pollTimer = setTimeout(loop, this._pollIntervalMs);
        });
      };
      this._pollTimer = setTimeout(loop, this._pollIntervalMs);
      if(!this._pollFocusBound){
        this._pollFocusBound = true;
        document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) this.pollBoardUpdates(); });
        window.addEventListener('focus', ()=> this.pollBoardUpdates());
      }
      // botão sync manual aparece no header se ainda não existe
      try {
        if (!document.getElementById('kp-sync-btn')) {
          const h = document.querySelector('.kp-board-header-actions, #kanpro-board-actions');
          if (h) {
            const b = document.createElement('button');
            b.id = 'kp-sync-btn';
            b.title = 'Sincronizar agora';
            b.style.cssText = 'background:#fff;border:1px solid #dfe1e6;border-radius:6px;padding:6px 10px;cursor:pointer;font-size:12px';
            b.innerHTML = '↻ Sync';
            b.onclick = ()=> K.forceSync && K.forceSync();
            h.appendChild(b);
          }
        }
      } catch(_){}
    },
  });

  // Se o core ganhar backoff próprio no futuro, o wrapper acima continua válido (idempotente).
  void __origPoll;
})();
