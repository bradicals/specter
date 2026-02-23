<?php
declare(strict_types=1);

// ─── Helpers ────────────────────────────────────────────────────────────────

function uuid4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function defaultData(): array {
    return [
        'columns' => [
            ['id' => 'todo',       'label' => 'To Do'],
            ['id' => 'inprogress', 'label' => 'In Progress'],
            ['id' => 'blocked',    'label' => 'Blocked'],
            ['id' => 'done',       'label' => 'Done'],
        ],
        'cards' => []
    ];
}

function readData(): array {
    $path = __DIR__ . '/data/kanban.json';
    if (!file_exists($path)) return defaultData();
    $d = json_decode(file_get_contents($path), true);
    return (is_array($d) && isset($d['columns'], $d['cards'])) ? $d : defaultData();
}

function writeData(array $data): void {
    $path = __DIR__ . '/data/kanban.json';
    $fp = fopen($path, 'c');
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function slugify(string $label): string {
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '', $slug);
    return $slug ?: 'col' . substr(md5($label), 0, 6);
}

function validDate(mixed $val): string {
    if (!is_string($val) || $val === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return '';
    $d = \DateTime::createFromFormat('Y-m-d', $val);
    return ($d && $d->format('Y-m-d') === $val) ? $val : '';
}

function jsonOut(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function bodyJson(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// ─── Router ─────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';

// CORS preflight
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// ── GET /css/*.css ── serve stylesheets ──────────────────────────────────────
if ($method === 'GET' && preg_match('#^/css/([a-zA-Z0-9_-]+\.css)$#', $uri, $m)) {
    $file = __DIR__ . '/css/' . $m[1];
    if (file_exists($file)) {
        header('Content-Type: text/css; charset=utf-8');
        readfile($file);
    } else {
        http_response_code(404);
        echo '/* not found */';
    }
    exit;
}

// ── GET / ── serve HTML ──────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/') {
    header('Content-Type: text/html; charset=utf-8');
    echo htmlPage();
    exit;
}

// ── GET /api/cards ───────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/cards') {
    jsonOut(readData()['cards']);
    exit;
}

// ── POST /api/cards ──────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/cards') {
    $body = bodyJson();
    $data = readData();
    $card = [
        'id'              => uuid4(),
        'ticketId'        => $body['ticketId']        ?? '',
        'title'           => $body['title']           ?? '',
        'description'     => $body['description']     ?? '',
        'descriptionHtml' => $body['descriptionHtml'] ?? '',
        'notes'           => $body['notes']           ?? '',
        'url'             => $body['url']             ?? '',
        'testingUrl'      => $body['testingUrl']      ?? '',
        'priority'        => $body['priority']        ?? 'medium',
        'dueDate'         => validDate($body['dueDate'] ?? ''),
        'column'          => $body['column']          ?? ($data['columns'][0]['id'] ?? 'todo'),
        'attachments'     => $body['attachments']     ?? [],
        'links'           => $body['links']           ?? [],
        'createdAt'       => date('c'),
    ];
    $data['cards'][] = $card;
    writeData($data);
    jsonOut($card, 201);
    exit;
}

// ── PATCH /api/cards/{id} ────────────────────────────────────────────────────
if ($method === 'PATCH' && preg_match('#^/api/cards/([^/]+)$#', $uri, $m)) {
    $id   = $m[1];
    $body = bodyJson();
    if (array_key_exists('dueDate', $body)) $body['dueDate'] = validDate($body['dueDate'] ?? '');
    $data = readData();
    $found = null;
    foreach ($data['cards'] as &$card) {
        if ($card['id'] === $id) {
            foreach (['ticketId','title','description','descriptionHtml','notes','url','testingUrl','priority','dueDate','column','attachments','links'] as $f) {
                if (array_key_exists($f, $body)) $card[$f] = $body[$f];
            }
            $found = $card;
            break;
        }
    }
    unset($card);
    if ($found === null) { jsonOut(['error' => 'Not found'], 404); exit; }
    writeData($data);
    jsonOut($found);
    exit;
}

// ── DELETE /api/cards/{id} ───────────────────────────────────────────────────
if ($method === 'DELETE' && preg_match('#^/api/cards/([^/]+)$#', $uri, $m)) {
    $id   = $m[1];
    $data = readData();
    $data['cards'] = array_values(array_filter($data['cards'], fn($c) => $c['id'] !== $id));
    writeData($data);
    jsonOut(['ok' => true]);
    exit;
}

// ── GET /api/columns ─────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/columns') {
    jsonOut(readData()['columns']);
    exit;
}

// ── POST /api/columns ────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/columns') {
    $body  = bodyJson();
    $label = trim($body['label'] ?? '');
    if ($label === '') { jsonOut(['error' => 'label required'], 400); exit; }
    $data  = readData();
    $id    = $body['id'] ?? slugify($label);
    // ensure uniqueness
    $existing = array_column($data['columns'], 'id');
    $base = $id; $i = 2;
    while (in_array($id, $existing, true)) $id = $base . $i++;
    $col = ['id' => $id, 'label' => $label];
    $data['columns'][] = $col;
    writeData($data);
    jsonOut($col, 201);
    exit;
}

// ── PATCH /api/columns/{id} ──────────────────────────────────────────────────
if ($method === 'PATCH' && preg_match('#^/api/columns/([^/]+)$#', $uri, $m)) {
    $id   = $m[1];
    $body = bodyJson();
    $data = readData();
    $found = null;
    foreach ($data['columns'] as &$col) {
        if ($col['id'] === $id) {
            if (isset($body['label'])) $col['label'] = $body['label'];
            $found = $col;
            break;
        }
    }
    unset($col);
    if ($found === null) { jsonOut(['error' => 'Not found'], 404); exit; }
    writeData($data);
    jsonOut($found);
    exit;
}

// ── DELETE /api/columns/{id} ─────────────────────────────────────────────────
if ($method === 'DELETE' && preg_match('#^/api/columns/([^/]+)$#', $uri, $m)) {
    $id   = $m[1];
    $data = readData();
    $data['columns'] = array_values(array_filter($data['columns'], fn($c) => $c['id'] !== $id));
    $fallback = $data['columns'][0]['id'] ?? null;
    if ($fallback !== null) {
        foreach ($data['cards'] as &$card) {
            if ($card['column'] === $id) $card['column'] = $fallback;
        }
        unset($card);
    } else {
        $data['cards'] = array_values(array_filter($data['cards'], fn($c) => $c['column'] !== $id));
    }
    writeData($data);
    jsonOut(['ok' => true]);
    exit;
}

// ── POST /api/import ─────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/import') {
    $body = bodyJson();
    $data = readData();
    $card = [
        'id'              => uuid4(),
        'ticketId'        => $body['ticketId']        ?? '',
        'title'           => $body['title']           ?? '',
        'description'     => $body['description']     ?? '',
        'descriptionHtml' => $body['descriptionHtml'] ?? '',
        'notes'           => $body['notes']           ?? '',
        'url'             => $body['url']             ?? '',
        'testingUrl'      => $body['testingUrl']      ?? '',
        'priority'        => $body['priority']        ?? 'medium',
        'dueDate'         => validDate($body['dueDate'] ?? ''),
        'column'          => $data['columns'][0]['id'] ?? 'todo',
        'attachments'     => $body['attachments']     ?? [],
        'links'           => $body['links']           ?? [],
        'createdAt'       => date('c'),
    ];
    $data['cards'][] = $card;
    writeData($data);
    jsonOut($card, 201);
    exit;
}

// ── POST /api/reorder ────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/reorder') {
    $body    = bodyJson();
    $column  = $body['column']  ?? '';
    $cardIds = $body['cardIds'] ?? [];
    if (!$column || !is_array($cardIds)) { jsonOut(['error' => 'column and cardIds required'], 400); exit; }
    $data = readData();
    $validCols = array_column($data['columns'], 'id');
    if (!in_array($column, $validCols, true)) { jsonOut(['error' => 'unknown column'], 400); exit; }
    // Separate cards in target column from others
    $inCol  = [];
    $others = [];
    foreach ($data['cards'] as $card) {
        if ($card['column'] === $column) $inCol[$card['id']] = $card;
        else $others[] = $card;
    }
    // Build reordered list from cardIds, then append any stragglers
    $ordered = [];
    foreach ($cardIds as $id) {
        if (isset($inCol[$id])) { $ordered[] = $inCol[$id]; unset($inCol[$id]); }
    }
    foreach ($inCol as $card) $ordered[] = $card;
    // Rebuild: others first (preserving order), then ordered column cards interleaved at original column positions
    // Simpler: just rebuild entire array keeping column groups in order
    $result = [];
    $colInserted = false;
    $seenCols = [];
    foreach ($data['columns'] as $col) {
        if ($col['id'] === $column) {
            foreach ($ordered as $c) $result[] = $c;
        } else {
            foreach ($others as $c) {
                if ($c['column'] === $col['id']) $result[] = $c;
            }
        }
    }
    $data['cards'] = $result;
    writeData($data);
    jsonOut(['ok' => true]);
    exit;
}

// ── 404 ──────────────────────────────────────────────────────────────────────
jsonOut(['error' => 'Not found'], 404);
exit;

// ─── HTML Page ───────────────────────────────────────────────────────────────

function htmlPage(): string {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Specter</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script>
  tailwind.config = {
    darkMode: 'class',
    theme: {
      extend: {
        colors: {
          primary: 'rgb(var(--color-primary-rgb))',
        },
        fontFamily: {
          display: ['Inter', 'sans-serif'],
        },
      },
    },
    corePlugins: {
      preflight: false,
    },
  }
</script>
<link rel="stylesheet" href="/css/base.css">
<link rel="stylesheet" href="/css/layout.css">
<link rel="stylesheet" href="/css/cards.css">
<link rel="stylesheet" href="/css/modals.css">
<link id="theme-css" rel="stylesheet" href="">
</head>
<body>
<div id="liquid-bg" style="display:none"></div>
<div class="rsz rsz-n"  onmousedown="nativeMsg('resize-t')"></div>
<div class="rsz rsz-s"  onmousedown="nativeMsg('resize-b')"></div>
<div class="rsz rsz-w"  onmousedown="nativeMsg('resize-l')"></div>
<div class="rsz rsz-e"  onmousedown="nativeMsg('resize-r')"></div>
<div class="rsz rsz-nw" onmousedown="nativeMsg('resize-tl')"></div>
<div class="rsz rsz-ne" onmousedown="nativeMsg('resize-tr')"></div>
<div class="rsz rsz-sw" onmousedown="nativeMsg('resize-bl')"></div>
<div class="rsz rsz-se" onmousedown="nativeMsg('resize-br')"></div>
<div id="stash-tab" onclick="unstashApp()">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M12 2C8.13 2 5 5.13 5 9v9l2-2 2 2 2-2 2 2 2-2 2 2V9c0-3.87-3.13-7-7-7z" fill="#7b8fff"/>
    <circle cx="9.5" cy="9.5" r="1.5" fill="#0e1018"/>
    <circle cx="14.5" cy="9.5" r="1.5" fill="#0e1018"/>
  </svg>
  <span class="tab-label">Specter</span>
</div>
<div id="app">
  <div id="titlebar">
    <span class="ghost-title">&#128123; Specter</span>
    <div class="win-btns">
      <button id="settings-btn" onclick="showSettings()" title="Settings" style="-webkit-app-region:no-drag;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-dim);font-size:14px;padding:1px 6px;cursor:pointer;height:22px;margin-right:2px;line-height:1;">&#9881;</button>
      <button onclick="stashApp()" title="Stash to side">&#x25B6;</button>
      <button class="close-btn" onclick="nativeMsg('close')" title="Close">&#10005;</button>
    </div>
  </div>
  <div id="board-wrap"></div>
</div>

<script>
'use strict';

// ── State ────────────────────────────────────────────────────────────────────
let state = { columns: [], cards: [] };
let lastColumnsJson = '';
let lastCardsJson   = '';
let dragCardId      = null;

// ── Native bridge ────────────────────────────────────────────────────────────
function nativeMsg(msg) {
  if (window.chrome?.webview) window.chrome.webview.postMessage(msg);
  else if (msg === 'close') window.close();
}

// Titlebar drag — fires on mousedown anywhere in #titlebar except buttons
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('titlebar').addEventListener('mousedown', e => {
    if (e.button === 0 && !e.target.closest('button')) nativeMsg('drag');
  });
  initColorScheme();
  initTheme();
});

// ── Stash ────────────────────────────────────────────────────────────────────
function stashApp() {
  document.body.classList.add('is-stashed');
  document.getElementById('stash-tab').classList.add('visible');
  nativeMsg('stash');
}
function unstashApp() {
  document.body.classList.remove('is-stashed');
  document.getElementById('stash-tab').classList.remove('visible');
  nativeMsg('unstash');
}

// ── API ──────────────────────────────────────────────────────────────────────
async function api(method, path, body) {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (body !== undefined) opts.body = JSON.stringify(body);
  const r = await fetch(path, opts);
  return r.json();
}

// ── Poll ─────────────────────────────────────────────────────────────────────
async function poll() {
  try {
    const [columns, cards] = await Promise.all([
      fetch('/api/columns').then(r => r.json()),
      fetch('/api/cards').then(r => r.json()),
    ]);
    const cj = JSON.stringify(columns);
    const kj = JSON.stringify(cards);
    if (cj !== lastColumnsJson || kj !== lastCardsJson) {
      lastColumnsJson = cj;
      lastCardsJson   = kj;
      state.columns   = columns;
      state.cards     = cards;
      if (document.querySelector('.modal-backdrop')) return; // don't blow away open modal
      render();
    }
  } catch (e) { /* server may be starting up */ }
}
setInterval(poll, 2000);
poll();

// ── Render ───────────────────────────────────────────────────────────────────
function render() {
  const wrap = document.getElementById('board-wrap');
  wrap.innerHTML = '';

  state.columns.forEach(col => {
    const cards = state.cards.filter(c => c.column === col.id);
    wrap.appendChild(buildColumn(col, cards));
  });

  // Add column button
  const addColBtn = document.createElement('button');
  addColBtn.id = 'add-col-btn';
  addColBtn.title = 'Add column';
  addColBtn.textContent = '+';
  addColBtn.onclick = addColumn;
  wrap.appendChild(addColBtn);
}

function buildColumn(col, cards) {
  const div = document.createElement('div');
  div.className = 'column';
  div.dataset.colId = col.id;

  // Drag-over handlers with positional reordering
  div.addEventListener('dragover', e => {
    e.preventDefault();
    div.classList.add('drag-over');
    const list = div.querySelector('.cards-list');
    let ph = list.querySelector('.drop-placeholder');
    if (!ph) {
      ph = document.createElement('div');
      ph.className = 'drop-placeholder';
    }
    // Find the card we're hovering over to position placeholder
    const cardEls = [...list.querySelectorAll('.card:not(.dragging)')];
    let inserted = false;
    for (const cardEl of cardEls) {
      const rect = cardEl.getBoundingClientRect();
      if (e.clientY < rect.top + rect.height / 2) {
        list.insertBefore(ph, cardEl);
        inserted = true;
        break;
      }
    }
    if (!inserted) list.appendChild(ph);
  });
  div.addEventListener('dragleave', e => {
    if (!div.contains(e.relatedTarget)) {
      div.classList.remove('drag-over');
      div.querySelector('.drop-placeholder')?.remove();
    }
  });
  div.addEventListener('drop', async e => {
    e.preventDefault();
    div.classList.remove('drag-over');
    const list = div.querySelector('.cards-list');
    const ph = list.querySelector('.drop-placeholder');
    if (dragCardId) {
      // Determine insert position from placeholder
      const cardEls = [...list.querySelectorAll('.card')];
      const phIndex = [...list.children].indexOf(ph);
      // Get ordered card IDs for this column (excluding the dragged card, then inserting it)
      const colCards = state.cards.filter(c => c.column === col.id && c.id !== dragCardId);
      // Build new order: cards before placeholder index, then dragged card, then rest
      const newOrder = [];
      let pos = 0;
      for (let i = 0; i < list.children.length; i++) {
        const child = list.children[i];
        if (child === ph) {
          newOrder.push(dragCardId);
        } else if (child.classList.contains('card') && child.dataset.cardId !== dragCardId) {
          newOrder.push(child.dataset.cardId);
        }
      }
      // If the card came from a different column, move it first
      const draggedCard = state.cards.find(c => c.id === dragCardId);
      if (draggedCard && draggedCard.column !== col.id) {
        await api('PATCH', `/api/cards/${dragCardId}`, { column: col.id });
      }
      // Reorder
      await api('POST', '/api/reorder', { column: col.id, cardIds: newOrder });
      dragCardId = null;
      // Force refresh
      lastCardsJson = '';
      await poll();
    }
    ph?.remove();
  });

  // Restore saved width
  const savedW = localStorage.getItem('specter-col-w-' + col.id);
  if (savedW) div.style.width = savedW + 'px';

  // Resize handle
  const resizeHandle = document.createElement('div');
  resizeHandle.className = 'col-resize-handle';
  resizeHandle.addEventListener('mousedown', e => {
    e.preventDefault();
    resizeHandle.classList.add('dragging');
    const startX = e.clientX;
    const startW = div.offsetWidth;
    const onMove = ev => {
      const newW = Math.max(160, startW + ev.clientX - startX);
      div.style.width = newW + 'px';
    };
    const onUp = () => {
      resizeHandle.classList.remove('dragging');
      localStorage.setItem('specter-col-w-' + col.id, div.offsetWidth);
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
  div.appendChild(resizeHandle);

  // Header
  const header = document.createElement('div');
  header.className = 'col-header';

  const titleInput = document.createElement('input');
  titleInput.className = 'col-title';
  titleInput.type = 'text';
  titleInput.value = col.label;
  titleInput.title = 'Click to rename';
  const saveTitle = async () => {
    const v = titleInput.value.trim();
    if (v && v !== col.label) {
      await api('PATCH', `/api/columns/${col.id}`, { label: v });
      await poll();
    } else { titleInput.value = col.label; }
  };
  titleInput.addEventListener('blur', saveTitle);
  titleInput.addEventListener('keydown', e => { if (e.key === 'Enter') titleInput.blur(); if (e.key === 'Escape') { titleInput.value = col.label; titleInput.blur(); } });

  const count = document.createElement('span');
  count.className = 'col-count';
  count.textContent = cards.length;

  const delBtn = document.createElement('button');
  delBtn.className = 'col-del';
  delBtn.title = 'Delete column';
  delBtn.textContent = '×';
  delBtn.onclick = async () => {
    if (confirm(`Delete column "${col.label}"? Cards will move to the next column.`)) {
      await api('DELETE', `/api/columns/${col.id}`);
      await poll();
    }
  };

  header.appendChild(titleInput);
  header.appendChild(count);
  header.appendChild(delBtn);
  div.appendChild(header);

  // Cards list
  const list = document.createElement('div');
  list.className = 'cards-list';
  cards.forEach(card => list.appendChild(buildCard(card)));
  div.appendChild(list);

  // Add card button
  const addBtn = document.createElement('button');
  addBtn.className = 'add-card-btn';
  addBtn.textContent = '+ Add card';
  addBtn.onclick = () => showNewCardModal(col.id);
  div.appendChild(addBtn);

  return div;
}

function buildCard(card) {
  const div = document.createElement('div');
  div.className = 'card';
  div.dataset.cardId = card.id;
  div.dataset.priority = card.priority;
  div.draggable = true;
  div.style.setProperty('--card-drift-offset', `${(Math.random() * 10).toFixed(1)}s`);

  div.addEventListener('dblclick', e => {
    e.stopPropagation();
    showCardModal(card);
  });

  div.addEventListener('dragstart', () => {
    dragCardId = card.id;
    setTimeout(() => div.classList.add('dragging'), 0);
  });
  div.addEventListener('dragend', () => {
    dragCardId = null;
    div.classList.remove('dragging');
  });

  const top = document.createElement('div');
  top.className = 'card-top';

  if (card.ticketId) {
    const tid = document.createElement('span');
    tid.className = 'ticket-id';
    tid.textContent = card.ticketId;
    top.appendChild(tid);
  }

  const pri = document.createElement('span');
  pri.className = `pri-badge ${card.priority}`;
  pri.textContent = card.priority;
  top.appendChild(pri);

  if (card.dueDate) {
    const due = document.createElement('span');
    due.className = 'due-badge';
    const d = new Date(card.dueDate + 'T00:00:00');
    const now = new Date(); now.setHours(0,0,0,0);
    const diff = Math.floor((d - now) / 86400000);
    if (diff < 0) due.classList.add('overdue');
    else if (diff <= 2) due.classList.add('soon');
    due.textContent = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    top.appendChild(due);
  }

  const del = document.createElement('button');
  del.className = 'card-del';
  del.title = 'Delete card';
  del.textContent = '×';
  del.onclick = e => {
    e.stopPropagation();
    showConfirm('Delete this card? This cannot be undone.', async () => {
      await api('DELETE', `/api/cards/${card.id}`);
      await poll();
    });
  };
  top.appendChild(del);

  div.appendChild(top);

  const title = document.createElement('div');
  title.className = 'card-title';
  title.textContent = card.title;
  div.appendChild(title);

  const preview = card.description || card.notes;
  if (preview) {
    const notesEl = document.createElement('div');
    notesEl.className = 'card-notes';
    notesEl.textContent = preview.length > 120 ? preview.slice(0, 120) + '…' : preview;
    div.appendChild(notesEl);
  }

  return div;
}

function showNewCardModal(colId) {
  document.querySelector('.modal-backdrop')?.remove();

  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.remove(); });

  const modal = document.createElement('div');
  modal.className = 'modal';
  modal.addEventListener('click', e => e.stopPropagation());

  // Header
  const header = document.createElement('div');
  header.className = 'modal-header';
  const mTitle = document.createElement('div');
  mTitle.className = 'modal-title';
  mTitle.textContent = 'New Card';
  const closeBtn = document.createElement('button');
  closeBtn.className = 'modal-close';
  closeBtn.textContent = '×';
  closeBtn.onclick = () => backdrop.remove();
  header.appendChild(mTitle);
  header.appendChild(closeBtn);

  // Fields helper
  const field = (labelText, el) => {
    const wrap = document.createElement('div');
    const lbl = document.createElement('label');
    lbl.textContent = labelText;
    wrap.appendChild(lbl);
    wrap.appendChild(el);
    return wrap;
  };

  // Form fields
  const tidIn = document.createElement('input');
  tidIn.placeholder = 'e.g. QA-101';

  const titleIn = document.createElement('input');
  titleIn.placeholder = 'Title *';

  const descIn = document.createElement('textarea');
  descIn.placeholder = 'Description…';

  const notesIn = document.createElement('textarea');
  notesIn.placeholder = 'Notes…';

  // URL field
  const urlIn = document.createElement('input');
  urlIn.placeholder = 'https://…';
  const linkBtn = document.createElement('a');
  linkBtn.className = 'btn-link';
  linkBtn.target = '_blank'; linkBtn.rel = 'noopener noreferrer';
  linkBtn.title = 'Open link'; linkBtn.textContent = '↗';
  const syncLink = () => {
    const v = urlIn.value.trim();
    if (v) { linkBtn.href = v; linkBtn.removeAttribute('disabled'); }
    else   { linkBtn.removeAttribute('href'); linkBtn.setAttribute('disabled', ''); }
  };
  syncLink();
  urlIn.addEventListener('input', syncLink);
  const urlRow = document.createElement('div');
  urlRow.className = 'modal-url-row';
  const urlFieldWrap = document.createElement('div');
  const urlLbl = document.createElement('label'); urlLbl.textContent = 'URL';
  urlFieldWrap.appendChild(urlLbl); urlFieldWrap.appendChild(urlIn);
  urlRow.appendChild(urlFieldWrap); urlRow.appendChild(linkBtn);

  // Testing doc URL field
  const testUrlIn = document.createElement('input');
  testUrlIn.placeholder = 'https://docs.google.com/…';
  const testLinkBtn = document.createElement('a');
  testLinkBtn.className = 'btn-link';
  testLinkBtn.target = '_blank'; testLinkBtn.rel = 'noopener noreferrer';
  testLinkBtn.title = 'Open testing doc'; testLinkBtn.textContent = '↗';
  const syncTestLink = () => {
    const v = testUrlIn.value.trim();
    if (v) { testLinkBtn.href = v; testLinkBtn.removeAttribute('disabled'); }
    else   { testLinkBtn.removeAttribute('href'); testLinkBtn.setAttribute('disabled', ''); }
  };
  syncTestLink();
  testUrlIn.addEventListener('input', syncTestLink);
  const testUrlRow = document.createElement('div');
  testUrlRow.className = 'modal-url-row';
  const testUrlFieldWrap = document.createElement('div');
  const testUrlLbl = document.createElement('label'); testUrlLbl.textContent = 'Testing Doc';
  testUrlFieldWrap.appendChild(testUrlLbl); testUrlFieldWrap.appendChild(testUrlIn);
  testUrlRow.appendChild(testUrlFieldWrap); testUrlRow.appendChild(testLinkBtn);

  // Axosoft URL extraction
  const axosoftIn = document.createElement('input');
  axosoftIn.placeholder = '🔗 Paste Axosoft URL to auto-fill ID…';
  axosoftIn.style.fontSize = '11px';
  const axosoftHint = document.createElement('div');
  axosoftHint.style.cssText = 'font-size:10px;color:var(--accent);height:14px;margin-top:-6px;transition:opacity 0.4s;opacity:0;';
  const extractFromUrl = (val) => {
    try {
      const u = new URL(val.trim());
      const id = u.searchParams.get('id');
      if (id) {
        tidIn.value = id;
        urlIn.value = val.trim(); syncLink();
        axosoftIn.value = '';
        axosoftHint.textContent = '✓ Ticket ID extracted';
        axosoftHint.style.opacity = '1';
        setTimeout(() => { axosoftHint.style.opacity = '0'; }, 2000);
        titleIn.focus();
      }
    } catch { /* not a valid URL */ }
  };
  axosoftIn.addEventListener('paste', e => { setTimeout(() => extractFromUrl(axosoftIn.value), 0); });
  axosoftIn.addEventListener('input', () => extractFromUrl(axosoftIn.value));

  // Due date
  const dueDateIn = document.createElement('input');
  dueDateIn.type = 'date';

  // Priority + Column row
  const priSel = document.createElement('select');
  ['high','medium','low'].forEach(p => {
    const o = document.createElement('option');
    o.value = p; o.textContent = p[0].toUpperCase() + p.slice(1);
    if (p === 'medium') o.selected = true;
    priSel.appendChild(o);
  });
  const colSel = document.createElement('select');
  state.columns.forEach(col => {
    const o = document.createElement('option');
    o.value = col.id; o.textContent = col.label;
    if (col.id === colId) o.selected = true;
    colSel.appendChild(o);
  });
  const pcRow = document.createElement('div');
  pcRow.className = 'modal-row';
  pcRow.appendChild(field('Priority', priSel));
  pcRow.appendChild(field('Column', colSel));

  // Footer
  const footer = document.createElement('div');
  footer.className = 'modal-footer';
  const saveBtn = document.createElement('button');
  saveBtn.className = 'btn-save';
  saveBtn.textContent = 'Add card';
  saveBtn.onclick = async () => {
    const t = titleIn.value.trim();
    if (!t) { titleIn.focus(); return; }
    await api('POST', '/api/cards', {
      ticketId:    tidIn.value.trim(),
      title:       t,
      description: descIn.value.trim(),
      notes:       notesIn.value.trim(),
      url:         urlIn.value.trim(),
      testingUrl:  testUrlIn.value.trim(),
      priority:    priSel.value,
      dueDate:     dueDateIn.value,
      column:      colSel.value,
    });
    backdrop.remove();
    await poll();
  };
  const cancelBtn = document.createElement('button');
  cancelBtn.className = 'btn-cancel';
  cancelBtn.textContent = 'Cancel';
  cancelBtn.onclick = () => backdrop.remove();
  footer.appendChild(saveBtn);
  footer.appendChild(cancelBtn);

  modal.appendChild(header);
  modal.appendChild(axosoftIn);
  modal.appendChild(axosoftHint);
  modal.appendChild(field('Ticket ID', tidIn));
  modal.appendChild(field('Title', titleIn));
  modal.appendChild(field('Description', descIn));
  modal.appendChild(field('Notes', notesIn));
  modal.appendChild(urlRow);
  modal.appendChild(testUrlRow);
  modal.appendChild(field('Due Date', dueDateIn));
  modal.appendChild(pcRow);
  modal.appendChild(footer);
  backdrop.appendChild(modal);
  document.body.appendChild(backdrop);
  titleIn.focus();

  const onKey = e => { if (e.key === 'Escape') { backdrop.remove(); document.removeEventListener('keydown', onKey); } };
  document.addEventListener('keydown', onKey);
}

function showConfirm(message, onConfirm) {
  const bd = document.createElement('div');
  bd.className = 'confirm-backdrop';

  const box = document.createElement('div');
  box.className = 'confirm-modal';
  box.addEventListener('click', e => e.stopPropagation());

  const msg = document.createElement('div');
  msg.className = 'confirm-msg';
  msg.textContent = message;

  const btns = document.createElement('div');
  btns.className = 'confirm-btns';

  const cancelB = document.createElement('button');
  cancelB.className = 'btn-cancel';
  cancelB.textContent = 'Cancel';
  cancelB.onclick = () => bd.remove();

  const confirmB = document.createElement('button');
  confirmB.className = 'btn-danger';
  confirmB.textContent = 'Delete';
  confirmB.onclick = () => { bd.remove(); onConfirm(); };

  btns.appendChild(cancelB);
  btns.appendChild(confirmB);
  box.appendChild(msg);
  box.appendChild(btns);
  bd.appendChild(box);
  document.body.appendChild(bd);
  confirmB.focus();

  const onKey = e => {
    if (e.key === 'Escape') { bd.remove(); document.removeEventListener('keydown', onKey); }
  };
  document.addEventListener('keydown', onKey);
}

function showCardModal(card) {
  document.querySelector('.modal-backdrop')?.remove();

  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  backdrop.addEventListener('click', e => {
    if (e.target === backdrop) backdrop.remove();
  });

  const modal = document.createElement('div');
  modal.className = 'modal view-mode';

  // Header
  const header = document.createElement('div');
  header.className = 'modal-header';
  const mTitle = document.createElement('div');
  mTitle.className = 'modal-title';
  mTitle.textContent = card.ticketId ? `${card.ticketId} — Details` : 'Card Details';
  const editBtn = document.createElement('button');
  editBtn.className = 'btn-edit';
  editBtn.textContent = 'Edit';
  editBtn.onclick = () => {
    modal.classList.remove('view-mode');
    descView.style.display = 'none';
    descIn.style.display = '';
    descIn.readOnly = false;
    notesIn.readOnly = false;
    titleIn.focus();
  };
  const closeBtn = document.createElement('button');
  closeBtn.className = 'modal-close';
  closeBtn.textContent = '×';
  closeBtn.onclick = () => backdrop.remove();
  header.appendChild(mTitle);
  header.appendChild(editBtn);
  header.appendChild(closeBtn);

  // Per-card layout (heights + collapse state)
  const _layoutKey = 'specter-layout-' + (card.ticketId || card.id);
  const _layout    = JSON.parse(localStorage.getItem(_layoutKey) || '{}');
  const _saveLayout = (patch) => {
    const current = JSON.parse(localStorage.getItem(_layoutKey) || '{}');
    localStorage.setItem(_layoutKey, JSON.stringify(Object.assign(current, patch)));
  };

  // Fields helper — collapsible sections persist per-card in localStorage
  const field = (labelText, el, collapsible) => {
    const wrap = document.createElement('div');
    if (!collapsible) {
      const lbl = document.createElement('label');
      lbl.textContent = labelText;
      wrap.appendChild(lbl);
      wrap.appendChild(el);
      return wrap;
    }
    wrap.className = 'collapsible-section';
    const lbl = document.createElement('label');
    lbl.className = 'collapsible-toggle';
    const arrow = document.createElement('span');
    arrow.className = 'collapse-arrow';
    const labelSpan = document.createElement('span');
    labelSpan.textContent = labelText;
    lbl.appendChild(arrow);
    lbl.appendChild(labelSpan);
    wrap.appendChild(lbl);
    const content = document.createElement('div');
    content.className = 'collapsible-content';
    content.appendChild(el);
    wrap.appendChild(content);
    const collapseKey = 'collapse-' + labelText.toLowerCase().replace(/\s+/g, '-');
    if (_layout[collapseKey]) {
      content.style.display = 'none';
      arrow.textContent = '▸';
    } else {
      arrow.textContent = '▾';
    }
    lbl.onclick = () => {
      const hiding = content.style.display !== 'none';
      content.style.display = hiding ? 'none' : '';
      arrow.textContent = hiding ? '▸' : '▾';
      _saveLayout({ [collapseKey]: hiding });
    };
    return wrap;
  };

  // Ticket ID
  const tidIn = document.createElement('input');
  tidIn.value = card.ticketId || '';
  tidIn.placeholder = 'e.g. QA-101';

  // Title
  const titleIn = document.createElement('input');
  titleIn.value = card.title || '';
  titleIn.placeholder = 'Title';

  // Description — HTML view (view mode) + textarea (edit mode)
  const savedDescH = _layout.descHeight || '140px';

  const descView = document.createElement('div');
  descView.className = 'desc-html-view';
  descView.style.height = savedDescH;
  if (card.descriptionHtml) {
    const tmp = document.createElement('div');
    tmp.innerHTML = card.descriptionHtml;
    // Sanitize: remove dangerous elements and attributes
    tmp.querySelectorAll('script,style,iframe,object,embed,form,meta,link,base').forEach(el => el.remove());
    tmp.querySelectorAll('*').forEach(el => {
      // Strip event handler attributes (on*)
      for (const attr of [...el.attributes]) {
        if (attr.name.startsWith('on')) el.removeAttribute(attr.name);
      }
      // Strip dangerous href/src schemes
      ['href', 'src', 'action', 'formaction', 'xlink:href'].forEach(a => {
        const v = (el.getAttribute(a) || '').trim().toLowerCase().replace(/\s+/g, '');
        if (v.startsWith('javascript:') || v.startsWith('vbscript:') || (v.startsWith('data:') && !v.startsWith('data:image/'))) {
          el.removeAttribute(a);
        }
      });
    });
    // Clean inline styles: strip visual overrides but keep layout/whitespace props
    tmp.querySelectorAll('*').forEach(el => {
      const s = el.getAttribute('style');
      if (s) {
        const cleaned = s
          .replace(/font-size\s*:[^;]+;?/gi, '')
          .replace(/background(-color)?\s*:[^;]+;?/gi, '')
          .replace(/color\s*:[^;]+;?/gi, '')
          .replace(/font-family\s*:[^;]*(arial|helvetica|times|verdana|georgia|calibri|tahoma)[^;]*;?/gi, '')
          .trim().replace(/;+$/, '');
        if (cleaned) el.setAttribute('style', cleaned);
        else el.removeAttribute('style');
      }
      el.removeAttribute('class');
      el.removeAttribute('bgcolor');
      el.removeAttribute('color');
      if (el.tagName === 'FONT') {
        const span = document.createElement('span');
        // Preserve monospace hint from <font face="Courier...">
        const face = (el.getAttribute('face') || '').toLowerCase();
        if (face.includes('courier') || face.includes('mono') || face.includes('consolas')) {
          span.style.fontFamily = 'Cascadia Code, Consolas, monospace';
          span.style.whiteSpace = 'pre-wrap';
        }
        while (el.firstChild) span.appendChild(el.firstChild);
        el.replaceWith(span);
      }
    });
    // Make images small clickable thumbnails that open the lightbox
    tmp.querySelectorAll('img').forEach(img => {
      const src = img.src;
      img.title = 'Click to enlarge';
      img.onclick = ev => { ev.stopPropagation(); showLightbox(src); };
      img.onerror = () => { img.style.display = 'none'; };
    });
    tmp.querySelectorAll('a').forEach(a => { a.target = '_blank'; });
    descView.appendChild(tmp);
  } else {
    descView.style.whiteSpace = 'pre-wrap';
    descView.textContent = card.description || '';
  }
  // Save height on resize
  const descViewObs = new ResizeObserver(() => {
    if (descView.style.height) _saveLayout({ descHeight: descView.style.height });
  });
  descViewObs.observe(descView);

  const descIn = document.createElement('textarea');
  descIn.value = card.description || '';
  descIn.placeholder = 'Description…';
  descIn.style.height = savedDescH;
  descIn.style.display = 'none';
  descIn.addEventListener('mouseup', () => {
    if (descIn.style.height) _saveLayout({ descHeight: descIn.style.height });
  });

  // Notes
  const notesIn = document.createElement('textarea');
  notesIn.value = card.notes || '';
  notesIn.placeholder = 'Notes…';
  notesIn.readOnly = true;
  const savedNotesH = _layout.notesHeight;
  if (savedNotesH) notesIn.style.height = savedNotesH;
  notesIn.addEventListener('mouseup', () => {
    if (notesIn.style.height) _saveLayout({ notesHeight: notesIn.style.height });
  });

  // URL field + open link button
  const urlIn = document.createElement('input');
  urlIn.value = card.url || '';
  urlIn.placeholder = 'https://…';

  const linkBtn = document.createElement('a');
  linkBtn.className = 'btn-link';
  linkBtn.target = '_blank';
  linkBtn.rel = 'noopener noreferrer';
  linkBtn.title = 'Open link';
  linkBtn.textContent = '↗';
  const syncLink = () => {
    const v = urlIn.value.trim();
    if (v) { linkBtn.href = v; linkBtn.removeAttribute('disabled'); }
    else   { linkBtn.removeAttribute('href'); linkBtn.setAttribute('disabled', ''); }
  };
  syncLink();
  urlIn.addEventListener('input', syncLink);

  const urlRow = document.createElement('div');
  urlRow.className = 'modal-url-row';
  const urlFieldWrap = document.createElement('div');
  urlFieldWrap.appendChild(urlIn);
  urlRow.appendChild(urlFieldWrap);
  urlRow.appendChild(linkBtn);

  // Testing doc URL field + open link button
  const testUrlIn = document.createElement('input');
  testUrlIn.value = card.testingUrl || '';
  testUrlIn.placeholder = 'https://docs.google.com/…';

  const testLinkBtn = document.createElement('a');
  testLinkBtn.className = 'btn-link';
  testLinkBtn.target = '_blank';
  testLinkBtn.rel = 'noopener noreferrer';
  testLinkBtn.title = 'Open testing doc';
  testLinkBtn.textContent = '↗';
  const syncTestLink = () => {
    const v = testUrlIn.value.trim();
    if (v) { testLinkBtn.href = v; testLinkBtn.removeAttribute('disabled'); }
    else   { testLinkBtn.removeAttribute('href'); testLinkBtn.setAttribute('disabled', ''); }
  };
  syncTestLink();
  testUrlIn.addEventListener('input', syncTestLink);

  const testUrlRow = document.createElement('div');
  testUrlRow.className = 'modal-url-row';
  const testUrlFieldWrap = document.createElement('div');
  testUrlFieldWrap.appendChild(testUrlIn);
  testUrlRow.appendChild(testUrlFieldWrap);
  testUrlRow.appendChild(testLinkBtn);

  // Due date
  const dueDateIn = document.createElement('input');
  dueDateIn.type = 'date';
  dueDateIn.value = card.dueDate || '';

  // Priority + Column row
  const priSel = document.createElement('select');
  ['high','medium','low'].forEach(p => {
    const o = document.createElement('option');
    o.value = p; o.textContent = p[0].toUpperCase() + p.slice(1);
    if (p === card.priority) o.selected = true;
    priSel.appendChild(o);
  });

  const colSel = document.createElement('select');
  state.columns.forEach(col => {
    const o = document.createElement('option');
    o.value = col.id; o.textContent = col.label;
    if (col.id === card.column) o.selected = true;
    colSel.appendChild(o);
  });

  const row = document.createElement('div');
  row.className = 'modal-row';
  row.appendChild(field('Priority', priSel));
  row.appendChild(field('Column', colSel));

  // Footer buttons
  const footer = document.createElement('div');
  footer.className = 'modal-footer';

  const saveBtn = document.createElement('button');
  saveBtn.className = 'btn-save';
  saveBtn.textContent = 'Save changes';
  saveBtn.onclick = async () => {
    const updated = await api('PATCH', `/api/cards/${card.id}`, {
      ticketId:    tidIn.value.trim(),
      title:       titleIn.value.trim(),
      description: descIn.value.trim(),
      notes:       notesIn.value.trim(),
      url:         urlIn.value.trim(),
      testingUrl:  testUrlIn.value.trim(),
      priority:    priSel.value,
      dueDate:     dueDateIn.value,
      column:      colSel.value,
    });
    if (updated && !updated.error) {
      Object.assign(card, updated);
      const idx = state.cards.findIndex(c => c.id === card.id);
      if (idx !== -1) { state.cards[idx] = { ...updated }; lastCardsJson = JSON.stringify(state.cards); }
    }
    mTitle.textContent = card.ticketId ? `${card.ticketId} — Details` : 'Card Details';
    modal.classList.add('view-mode');
    descIn.style.display = 'none';
    descView.style.display = '';
    descIn.readOnly = true;
    notesIn.readOnly = true;
    render(); // bypass poll guard — modal is on <body>, not #board-wrap
  };

  const delBtn = document.createElement('button');
  delBtn.className = 'btn-danger';
  delBtn.textContent = 'Delete';
  delBtn.onclick = () => {
    showConfirm('Delete this card? This cannot be undone.', async () => {
      await api('DELETE', `/api/cards/${card.id}`);
      backdrop.remove();
      await poll();
    });
  };

  const cancelBtn = document.createElement('button');
  cancelBtn.className = 'btn-cancel';
  cancelBtn.textContent = 'Cancel';
  cancelBtn.onclick = () => {
    modal.classList.add('view-mode');
    descIn.style.display = 'none';
    descView.style.display = '';
    descIn.readOnly = true;
    notesIn.readOnly = true;
  };

  footer.appendChild(saveBtn);
  footer.appendChild(delBtn);
  footer.appendChild(cancelBtn);

  const descWrap = document.createElement('div');
  descWrap.appendChild(descView);
  descWrap.appendChild(descIn);

  modal.appendChild(header);
  modal.appendChild(field('Ticket ID', tidIn));
  modal.appendChild(field('Title', titleIn));
  modal.appendChild(field('Description', descWrap, true));
  modal.appendChild(field('Notes', notesIn, true));
  modal.appendChild(field('URL', urlRow, true));
  modal.appendChild(field('Testing Doc', testUrlRow, true));
  modal.appendChild(field('Due Date', dueDateIn, true));
  modal.appendChild(row);

  // Links list (read-only, stays inside modal)
  if (card.links && card.links.length > 0) {
    const linksList = document.createElement('div');
    linksList.className = 'links-list';
    card.links.forEach(({text, href}) => {
      const a = document.createElement('a');
      a.href = href; a.target = '_blank';
      a.textContent = text || href;
      a.className = 'card-link';
      linksList.appendChild(a);
    });
    modal.appendChild(field('Links', linksList, true));
  }

  modal.appendChild(footer);

  // Wrap modal + attachment side panel in a flex row
  const wrapper = document.createElement('div');
  wrapper.className = 'modal-wrapper';
  wrapper.addEventListener('click', e => e.stopPropagation());
  wrapper.appendChild(modal);

  if (card.attachments && card.attachments.length > 0) {
    const attPanel = document.createElement('div');
    attPanel.className = 'att-panel';
    card.attachments.forEach(src => {
      const img = document.createElement('img');
      img.src = src; img.className = 'att-panel-thumb';
      img.onerror = () => { img.style.display = 'none'; };
      img.onclick = () => showLightbox(src);
      attPanel.appendChild(img);
    });
    wrapper.appendChild(attPanel);
  }

  backdrop.appendChild(wrapper);
  document.body.appendChild(backdrop);

  titleIn.focus();

  const onKey = e => { if (e.key === 'Escape') { backdrop.remove(); document.removeEventListener('keydown', onKey); } };
  document.addEventListener('keydown', onKey);
}

function showLightbox(src) {
  const bd = document.createElement('div');
  bd.className = 'lightbox-backdrop';
  bd.onclick = () => bd.remove();

  const closeBtn = document.createElement('button');
  closeBtn.className = 'lightbox-close';
  closeBtn.textContent = '×';
  closeBtn.onclick = () => bd.remove();

  const img = document.createElement('img');
  img.src = src;
  img.onclick = e => e.stopPropagation();

  bd.appendChild(closeBtn);
  bd.appendChild(img);
  document.body.appendChild(bd);

  const onKey = e => { if (e.key === 'Escape') { bd.remove(); document.removeEventListener('keydown', onKey); } };
  document.addEventListener('keydown', onKey);
}

function showPrompt(message, placeholder, onConfirm) {
  const bd = document.createElement('div');
  bd.className = 'confirm-backdrop';

  const box = document.createElement('div');
  box.className = 'confirm-modal';
  box.addEventListener('click', e => e.stopPropagation());

  const msg = document.createElement('div');
  msg.className = 'confirm-msg';
  msg.textContent = message;

  const input = document.createElement('input');
  input.placeholder = placeholder;
  input.style.cssText = 'width:100%;background:rgba(10,12,22,0.7);border:1px solid var(--border);border-radius:5px;color:var(--text);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;';
  input.addEventListener('focus', () => input.style.borderColor = 'var(--accent)');
  input.addEventListener('blur',  () => input.style.borderColor = 'var(--border)');

  const btns = document.createElement('div');
  btns.className = 'confirm-btns';

  const cancelB = document.createElement('button');
  cancelB.className = 'btn-cancel';
  cancelB.textContent = 'Cancel';
  cancelB.onclick = () => bd.remove();

  const confirmB = document.createElement('button');
  confirmB.className = 'btn-save';
  confirmB.style.cssText = 'padding:5px 14px;';
  confirmB.textContent = 'Add';
  const submit = () => {
    const v = input.value.trim();
    if (!v) { input.focus(); return; }
    bd.remove();
    onConfirm(v);
  };
  confirmB.onclick = submit;
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') submit();
    if (e.key === 'Escape') bd.remove();
  });

  btns.appendChild(cancelB);
  btns.appendChild(confirmB);
  box.appendChild(msg);
  box.appendChild(input);
  box.appendChild(btns);
  bd.appendChild(box);
  document.body.appendChild(bd);
  input.focus();
}

async function addColumn() {
  showPrompt('New column name:', 'e.g. In Review', async label => {
    await api('POST', '/api/columns', { label });
    await poll();
  });
}

// ── Theme ─────────────────────────────────────────────────────────────────────
function applyTheme(theme) {
  const themeLink = document.getElementById('theme-css');
  const liquidBg = document.getElementById('liquid-bg');
  if (theme === 'glass') {
    document.body.classList.add('theme-glass');
    document.documentElement.classList.add('dark');
    if (themeLink) themeLink.href = '/css/theme-glass.css';
    if (liquidBg) liquidBg.style.display = 'block';
    initGlassSettings();
  } else {
    document.body.classList.remove('theme-glass');
    document.documentElement.classList.remove('dark');
    if (themeLink) themeLink.href = '';
    if (liquidBg) liquidBg.style.display = 'none';
  }
  localStorage.setItem('specter-theme', theme);
  // Toggle glass section visibility if settings modal is open
  const gs = document.getElementById('glass-settings-section');
  if (gs) gs.style.display = theme === 'glass' ? '' : 'none';
}

function initTheme() {
  const saved = localStorage.getItem('specter-theme') || 'normal';
  applyTheme(saved);
}

// ── Color Scheme ──────────────────────────────────────────────────────────────
function applyColorScheme(scheme) {
  if (scheme === 'orange') {
    document.documentElement.dataset.colorScheme = 'orange';
  } else {
    delete document.documentElement.dataset.colorScheme;
  }
  localStorage.setItem('specter-color-scheme', scheme);
}

function initColorScheme() {
  const saved = localStorage.getItem('specter-color-scheme') || 'purple';
  applyColorScheme(saved);
}

// ── Glass Settings ────────────────────────────────────────────────────────────
const GLASS_DEFAULTS = {
  'glass-col-opacity': 0.72,
  'glass-card-opacity': 0.65,
  'glass-blur': 24,
  'glass-bg-intensity': 1,
};

function applyGlassSetting(key, val) {
  document.documentElement.style.setProperty('--' + key, val);
  localStorage.setItem('specter-' + key, val);
}

function initGlassSettings() {
  Object.entries(GLASS_DEFAULTS).forEach(([key, def]) => {
    const saved = localStorage.getItem('specter-' + key);
    const val = saved !== null ? saved : def;
    document.documentElement.style.setProperty('--' + key, val);
  });
}

function resetGlassDefaults() {
  Object.entries(GLASS_DEFAULTS).forEach(([key, def]) => {
    localStorage.removeItem('specter-' + key);
    document.documentElement.style.setProperty('--' + key, def);
  });
  // Update slider inputs if modal is open
  Object.entries(GLASS_DEFAULTS).forEach(([key, def]) => {
    const slider = document.getElementById('slider-' + key);
    const display = document.getElementById('val-' + key);
    if (slider && display) {
      if (key === 'glass-blur') {
        slider.value = def;
        display.textContent = def + 'px';
      } else {
        slider.value = Math.round(def * 100);
        display.textContent = Math.round(def * 100) + '%';
      }
    }
  });
}

// ── Settings Modal ────────────────────────────────────────────────────────────
function showSettings() {
  document.querySelector('.modal-backdrop')?.remove();

  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.remove(); });

  const modal = document.createElement('div');
  modal.className = 'confirm-modal';
  modal.style.cssText = 'width:380px;max-width:90vw;';
  modal.addEventListener('click', e => e.stopPropagation());

  // Title
  const title = document.createElement('div');
  title.style.cssText = 'font-size:13px;font-weight:700;color:var(--accent);letter-spacing:0.04em;margin-bottom:4px;';
  title.textContent = 'Settings';
  modal.appendChild(title);

  // Theme select
  const themeRow = document.createElement('div');
  themeRow.className = 'settings-row';
  const themeLbl = document.createElement('label');
  themeLbl.textContent = 'Theme';
  const themeSel = document.createElement('select');
  themeSel.id = 'theme-select';
  themeSel.style.cssText = 'flex:1;background:rgba(10,12,22,0.7);border:1px solid var(--border);border-radius:5px;color:var(--text);padding:4px 8px;font-size:12px;font-family:inherit;outline:none;cursor:pointer;';
  [['normal','Normal'],['glass','Liquid Glass']].forEach(([v,t]) => {
    const o = document.createElement('option'); o.value = v; o.textContent = t;
    themeSel.appendChild(o);
  });
  themeSel.value = localStorage.getItem('specter-theme') || 'normal';
  themeSel.onchange = () => applyTheme(themeSel.value);
  themeRow.appendChild(themeLbl);
  themeRow.appendChild(themeSel);
  modal.appendChild(themeRow);

  // Color scheme select
  const colorRow = document.createElement('div');
  colorRow.className = 'settings-row';
  const colorLbl = document.createElement('label');
  colorLbl.textContent = 'Color Scheme';
  const colorSel = document.createElement('select');
  colorSel.id = 'color-scheme-select';
  colorSel.style.cssText = 'flex:1;background:rgba(10,12,22,0.7);border:1px solid var(--border);border-radius:5px;color:var(--text);padding:4px 8px;font-size:12px;font-family:inherit;outline:none;cursor:pointer;';
  [['purple','Purple'],['orange','Orange']].forEach(([v,t]) => {
    const o = document.createElement('option'); o.value = v; o.textContent = t;
    colorSel.appendChild(o);
  });
  colorSel.value = localStorage.getItem('specter-color-scheme') || 'purple';
  colorSel.onchange = () => applyColorScheme(colorSel.value);
  colorRow.appendChild(colorLbl);
  colorRow.appendChild(colorSel);
  modal.appendChild(colorRow);

  // Glass settings section
  const glassSection = document.createElement('div');
  glassSection.id = 'glass-settings-section';
  glassSection.style.display = (localStorage.getItem('specter-theme') || 'normal') === 'glass' ? '' : 'none';

  const sectionLabel = document.createElement('div');
  sectionLabel.className = 'settings-section-label';
  sectionLabel.textContent = 'Liquid Glass Settings';
  glassSection.appendChild(sectionLabel);

  // Slider builder
  const sliders = [
    { key: 'glass-col-opacity', label: 'Column Opacity', min: 0, max: 100, suffix: '%', toVar: v => v / 100 },
    { key: 'glass-card-opacity', label: 'Card Opacity', min: 0, max: 100, suffix: '%', toVar: v => v / 100 },
    { key: 'glass-blur', label: 'Blur Amount', min: 0, max: 48, suffix: 'px', toVar: v => v },
    { key: 'glass-bg-intensity', label: 'Background Intensity', min: 0, max: 100, suffix: '%', toVar: v => v / 100 },
  ];

  sliders.forEach(({ key, label, min, max, suffix, toVar }) => {
    const row = document.createElement('div');
    row.className = 'settings-row';

    const lbl = document.createElement('label');
    lbl.textContent = label;

    const slider = document.createElement('input');
    slider.type = 'range';
    slider.id = 'slider-' + key;
    slider.min = min;
    slider.max = max;
    slider.style.cssText = 'flex:1;cursor:pointer;';

    const valDisplay = document.createElement('span');
    valDisplay.className = 'val-display';
    valDisplay.id = 'val-' + key;

    // Read current value
    const saved = localStorage.getItem('specter-' + key);
    const def = GLASS_DEFAULTS[key];
    const current = saved !== null ? parseFloat(saved) : def;
    if (key === 'glass-blur') {
      slider.value = current;
      valDisplay.textContent = current + suffix;
    } else {
      slider.value = Math.round(current * 100);
      valDisplay.textContent = Math.round(current * 100) + suffix;
    }

    slider.oninput = () => {
      const raw = parseFloat(slider.value);
      const cssVal = toVar(raw);
      valDisplay.textContent = raw + suffix;
      applyGlassSetting(key, cssVal);
    };

    row.appendChild(lbl);
    row.appendChild(slider);
    row.appendChild(valDisplay);
    glassSection.appendChild(row);
  });

  modal.appendChild(glassSection);

  // Footer buttons
  const footer = document.createElement('div');
  footer.style.cssText = 'display:flex;gap:8px;margin-top:6px;';

  const resetBtn = document.createElement('button');
  resetBtn.className = 'btn-secondary';
  resetBtn.textContent = 'Reset Defaults';
  resetBtn.onclick = resetGlassDefaults;

  const doneBtn = document.createElement('button');
  doneBtn.className = 'btn-primary';
  doneBtn.textContent = 'Done';
  doneBtn.onclick = () => backdrop.remove();

  footer.appendChild(resetBtn);
  footer.appendChild(doneBtn);
  modal.appendChild(footer);

  backdrop.appendChild(modal);
  document.body.appendChild(backdrop);

  const onKey = e => { if (e.key === 'Escape') { backdrop.remove(); document.removeEventListener('keydown', onKey); } };
  document.addEventListener('keydown', onKey);
}
</script>
</body>
</html>
HTML;
}
