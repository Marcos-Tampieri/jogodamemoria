// script.js - Cliente que comunica com game.php via fetch POST JSON (polling)
// Atualizado: exibe game_id quando o jogo é criado e adiciona botão "Copiar ID".

const API = 'game.php';
const POLL_INTERVAL = 1200; // ms

// UI elements
const boardEl = document.getElementById('board');
const createBtn = document.getElementById('createBtn');
const joinBtn = document.getElementById('joinBtn');
const playerNameInput = document.getElementById('playerName');
const joinGameIdInput = document.getElementById('joinGameId');

const meNameEl = document.getElementById('meName');
const p1El = document.getElementById('p1');
const p2El = document.getElementById('p2');
const turnEl = document.getElementById('turn');
const movesEl = document.getElementById('moves');

let gameId = localStorage.getItem('mem_game_id') ? Number(localStorage.getItem('mem_game_id')) : null;
let playerId = localStorage.getItem('mem_player_id') ? Number(localStorage.getItem('mem_player_id')) : null;
let myName = localStorage.getItem('mem_player_name') || null;

let deck = [];            // array de strings (ícones) provido pelo servidor
let matched = [];         // array booleana
let flippedLocal = [];    // indices expostos temporariamente no cliente para animação
let pollingTimer = null;
let lockUI = false;       // previne cliques rápidos enquanto aguardamos resposta

// cria ou obtém o container que mostra o game_id + botão copiar
function ensureGameInfoUI() {
  let container = document.getElementById('gameInfo');
  if (container) return container;

  const lobby = document.querySelector('.lobby') || document.body;
  container = document.createElement('div');
  container.id = 'gameInfo';
  container.style.display = 'inline-flex';
  container.style.gap = '8px';
  container.style.alignItems = 'center';
  container.style.marginLeft = '8px';
  container.innerHTML = `
    <span id="gameIdLabel" style="color:rgba(255,255,255,0.9)">Game ID: <strong id="gameIdValue">—</strong></span>
    <button id="copyGameIdBtn" class="btn btn-ghost" style="padding:6px 8px;border-radius:8px;font-size:0.9rem">Copiar ID</button>
  `;
  lobby.appendChild(container);

  const copyBtn = container.querySelector('#copyGameIdBtn');
  copyBtn.addEventListener('click', () => {
    const v = document.getElementById('gameIdValue').textContent;
    if (!v || v === '—') return;
    navigator.clipboard?.writeText(v.toString()).then(() => {
      copyBtn.textContent = 'Copiado!';
      setTimeout(() => { copyBtn.textContent = 'Copiar ID'; }, 1200);
    }).catch(() => alert('Não foi possível copiar para a área de transferência.'));
  });

  return container;
}

function showGameId(gid) {
  const container = ensureGameInfoUI();
  const valEl = document.getElementById('gameIdValue');
  valEl.textContent = gid ?? '—';
  // também coloca no campo de entrada para fácil compartilhamento
  if (joinGameIdInput) joinGameIdInput.value = gid ?? '';
}

// ------------ utilitários --------------
function postJson(payload) {
  return fetch(API, {
    method: 'POST',
    headers: {'Content-Type':'application/json;charset=utf-8'},
    body: JSON.stringify(payload),
    credentials: 'same-origin'
  }).then(r => r.json());
}

function setSession(gid, pid, name) {
  gameId = gid != null ? Number(gid) : null;
  playerId = pid != null ? Number(pid) : null;
  myName = name || null;
  if (gameId != null) localStorage.setItem('mem_game_id', String(gameId)); else localStorage.removeItem('mem_game_id');
  if (playerId != null) localStorage.setItem('mem_player_id', String(playerId)); else localStorage.removeItem('mem_player_id');
  if (myName) localStorage.setItem('mem_player_name', myName); else localStorage.removeItem('mem_player_name');
  meNameEl.textContent = myName || '—';
  showGameId(gameId);
}

// ------------ UI render --------------
function renderBoard() {
  boardEl.innerHTML = '';
  if (!deck || deck.length === 0) {
    boardEl.innerHTML = '<div style="color:rgba(255,255,255,0.4);padding:18px">Aguardando jogo...</div>';
    return;
  }
  deck.forEach((icon, idx) => {
    const card = document.createElement('button');
    card.className = 'card';
    card.type = 'button';
    card.dataset.index = idx;
    card.setAttribute('aria-label', 'Carta ' + idx);
    if (matched[idx]) {
      card.classList.add('matched', 'is-flipped');
      card.setAttribute('aria-disabled', 'true');
    } else if (flippedLocal.includes(idx)) {
      card.classList.add('is-flipped');
    } else {
      card.classList.remove('is-flipped', 'matched');
      card.setAttribute('aria-disabled', 'false');
    }

    card.innerHTML = `
      <div class="card-inner">
        <div class="card-face card-front">?</div>
        <div class="card-face card-back">${icon}</div>
      </div>
    `;

    card.addEventListener('click', onCardClick);
    card.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
    });

    boardEl.appendChild(card);
  });
}

// ------------ eventos UI -------------
createBtn.addEventListener('click', async () => {
  const name = (playerNameInput.value || 'Jogador').trim();
  createBtn.disabled = true;
  try {
    const res = await postJson({ action: 'create', player_name: name });
    if (res.ok) {
      // corrigido: setSession com game_id e player_id
      setSession(res.game_id, res.player_id, name);
      showGameId(res.game_id);
      startPolling();
    } else {
      alert('Erro: ' + (res.error || 'unknown'));
    }
  } catch (err) {
    console.error(err);
    alert('Erro de rede');
  } finally {
    createBtn.disabled = false;
  }
});

joinBtn.addEventListener('click', async () => {
  const gid = Number(joinGameIdInput.value);
  const name = (playerNameInput.value || 'Jogador').trim();
  if (!gid) { alert('Informe ID do jogo para entrar'); return; }
  joinBtn.disabled = true;
  try {
    const res = await postJson({ action: 'join', game_id: gid, player_name: name });
    if (res.ok) {
      setSession(res.game_id, res.player_id, name);
      startPolling();
    } else {
      alert('Erro: ' + (res.error || 'unknown'));
    }
  } catch (err) {
    console.error(err);
    alert('Erro de rede');
  } finally {
    joinBtn.disabled = false;
  }
});

async function onCardClick(e) {
  if (!gameId || !playerId) { alert('Crie ou entre em um jogo primeiro'); return; }
  if (lockUI) return;
  const idx = Number(e.currentTarget.dataset.index);
  if (matched[idx]) return;
  if (flippedLocal.includes(idx)) return;

  lockUI = true;
  try {
    const res = await postJson({ action: 'flip', game_id: gameId, player_id: playerId, index: idx });
    if (!res.ok) {
      console.warn('flip error:', res.error);
      await fetchStateOnce();
      lockUI = false;
      return;
    }
    if (Array.isArray(res.flipped)) {
      flippedLocal = res.flipped.slice();
      renderBoard();
    }
    if (res.match === false) {
      setTimeout(async () => {
        flippedLocal = [];
        renderBoard();
        await fetchStateOnce();
        lockUI = false;
      }, 800);
    } else if (res.match === true) {
      flippedLocal = [];
      await fetchStateOnce();
      lockUI = false;
    } else {
      // only first card flipped
      lockUI = false;
    }
  } catch (err) {
    console.error(err);
    lockUI = false;
  }
}

// ------------ polling / state --------------
async function fetchStateOnce() {
  if (!gameId) return;
  try {
    const res = await postJson({ action: 'state', game_id: gameId });
    if (!res.ok) {
      console.warn('state error', res.error);
      return;
    }
    const g = res.game;
    const players = res.players || [];

    deck = g.deck || [];
    matched = g.matched || Array(deck.length).fill(false);

    p1El.textContent = players[0] ? `${players[0].name} (${players[0].score})` : '—';
    p2El.textContent = players[1] ? `${players[1].name} (${players[1].score})` : '—';
    movesEl.textContent = g.moves ?? 0;

    turnEl.textContent = g.current_player_id ? (Number(g.current_player_id) === Number(playerId) ? 'Você' : (players.find(p => p.id === g.current_player_id)?.name || 'Outro')) : '—';

    if (g.flipped && g.flipped.length > 0) {
      // servidor-temporary flips (ex: quando um jogador errou)
      flippedLocal = g.flipped.slice();
    } else {
      // não sobrescrever se usuário está vendo flips por animação local
      if (flippedLocal.length === 0) flippedLocal = [];
    }

    renderBoard();
  } catch (err) {
    console.error('fetchStateOnce error', err);
  }
}

function startPolling() {
  if (pollingTimer) clearInterval(pollingTimer);
  fetchStateOnce();
  pollingTimer = setInterval(fetchStateOnce, POLL_INTERVAL);
}

// iniciar se já havia sessão salva
if (gameId && playerId) {
  meNameEl.textContent = myName || '—';
  showGameId(gameId);
  startPolling();
} else {
  // garante que o UI de gameId exista mesmo antes de criar jogo
  ensureGameInfoUI();
}