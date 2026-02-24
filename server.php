<?php
declare(strict_types=1);

// ─── Helpers ────────────────────────────────────────────────────────────────

function uuid4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function defaultColumns(): array {
    return [
        ['id' => 'todo',       'label' => 'To Do'],
        ['id' => 'inprogress', 'label' => 'In Progress'],
        ['id' => 'blocked',    'label' => 'Blocked'],
        ['id' => 'done',       'label' => 'Done'],
    ];
}

function defaultData(): array {
    return [
        'boards' => [[
            'id'      => 'main',
            'label'   => 'Main Board',
            'columns' => defaultColumns(),
            'cards'   => [],
        ]],
    ];
}

function readData(): array {
    $path = __DIR__ . '/data/kanban.json';
    if (!file_exists($path)) return defaultData();
    $d = json_decode(file_get_contents($path), true);
    if (!is_array($d)) return defaultData();
    // Legacy migration: wrap flat {columns, cards} into boards model
    if (isset($d['columns'], $d['cards']) && !isset($d['boards'])) {
        $d = [
            'boards' => [[
                'id'      => 'main',
                'label'   => 'Main Board',
                'columns' => $d['columns'],
                'cards'   => $d['cards'],
            ]],
        ];
    }
    if (!isset($d['boards']) || !is_array($d['boards']) || count($d['boards']) === 0) {
        return defaultData();
    }
    return $d;
}

function &getBoardById(array &$data, string $boardId): ?array {
    if ($boardId === '') return $data['boards'][0];
    foreach ($data['boards'] as &$board) {
        if ($board['id'] === $boardId) return $board;
    }
    $null = null;
    return $null;
}

/** Resolve board from ?boardId= or 404. */
function &requireBoard(array &$data): array {
    $board = &getBoardById($data, $_GET['boardId'] ?? '');
    if ($board === null) { jsonOut(['error' => 'Board not found'], 404); exit; }
    return $board;
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

// ── GET /api/boards ──────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/boards') {
    $data = readData();
    $list = array_map(fn($b) => ['id' => $b['id'], 'label' => $b['label']], $data['boards']);
    jsonOut($list);
    exit;
}

// ── POST /api/boards ─────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/boards') {
    $body  = bodyJson();
    $label = trim($body['label'] ?? '');
    if ($label === '') { jsonOut(['error' => 'label required'], 400); exit; }
    $data  = readData();
    $id    = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    $board = ['id' => $id, 'label' => $label, 'columns' => defaultColumns(), 'cards' => []];
    $data['boards'][] = $board;
    writeData($data);
    jsonOut(['id' => $id, 'label' => $label], 201);
    exit;
}

// ── PATCH /api/boards/{id} ───────────────────────────────────────────────────
if ($method === 'PATCH' && preg_match('#^/api/boards/([^/]+)$#', $uri, $m)) {
    $id   = $m[1];
    $body = bodyJson();
    if (isset($body['label']) && trim($body['label']) === '') {
        jsonOut(['error' => 'label cannot be empty'], 400); exit;
    }
    $data = readData();
    $found = null;
    foreach ($data['boards'] as &$board) {
        if ($board['id'] === $id) {
            if (isset($body['label'])) $board['label'] = trim($body['label']);
            $found = ['id' => $board['id'], 'label' => $board['label']];
            break;
        }
    }
    unset($board);
    if ($found === null) { jsonOut(['error' => 'Not found'], 404); exit; }
    writeData($data);
    jsonOut($found);
    exit;
}

// ── DELETE /api/boards/{id} ──────────────────────────────────────────────────
if ($method === 'DELETE' && preg_match('#^/api/boards/([^/]+)$#', $uri, $m)) {
    $id   = $m[1];
    $data = readData();
    if (count($data['boards']) <= 1) { jsonOut(['error' => 'Cannot delete last board'], 400); exit; }
    $data['boards'] = array_values(array_filter($data['boards'], fn($b) => $b['id'] !== $id));
    writeData($data);
    jsonOut(['ok' => true]);
    exit;
}

// ── GET /api/cards ───────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/cards') {
    $data = readData();
    $board = &requireBoard($data);
    jsonOut($board['cards']);
    exit;
}

// ── POST /api/cards ──────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/cards') {
    $body = bodyJson();
    $data = readData();
    $board = &requireBoard($data);
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
        'column'          => $body['column']          ?? ($board['columns'][0]['id'] ?? 'todo'),
        'attachments'     => $body['attachments']     ?? [],
        'links'           => $body['links']           ?? [],
        'createdAt'       => date('c'),
    ];
    $board['cards'][] = $card;
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
    $board = &requireBoard($data);
    $found = null;
    foreach ($board['cards'] as &$card) {
        if ($card['id'] === $id) {
            $prevCol = $card['column'];
            foreach (['ticketId','title','description','descriptionHtml','notes','url','testingUrl','priority','dueDate','column','attachments','links'] as $f) {
                if (array_key_exists($f, $body)) $card[$f] = $body[$f];
            }
            // Track completion history
            if (!isset($card['history'])) $card['history'] = [];
            if ($card['column'] === 'done' && $prevCol !== 'done') {
                $card['completedAt'] = date('c');
                $card['history'][] = ['action' => 'completed', 'date' => date('c')];
            } elseif ($card['column'] !== 'done' && $prevCol === 'done') {
                $card['completedAt'] = null;
                $card['history'][] = ['action' => 'reopened', 'date' => date('c'), 'movedTo' => $card['column']];
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
    $board = &requireBoard($data);
    $board['cards'] = array_values(array_filter($board['cards'], fn($c) => $c['id'] !== $id));
    writeData($data);
    jsonOut(['ok' => true]);
    exit;
}

// ── GET /api/columns ─────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/columns') {
    $data = readData();
    $board = &requireBoard($data);
    jsonOut($board['columns']);
    exit;
}

// ── POST /api/columns ────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/columns') {
    $body  = bodyJson();
    $label = trim($body['label'] ?? '');
    if ($label === '') { jsonOut(['error' => 'label required'], 400); exit; }
    $data  = readData();
    $board = &requireBoard($data);
    $id    = $body['id'] ?? slugify($label);
    $existing = array_column($board['columns'], 'id');
    $base = $id; $i = 2;
    while (in_array($id, $existing, true)) $id = $base . $i++;
    $col = ['id' => $id, 'label' => $label];
    $board['columns'][] = $col;
    writeData($data);
    jsonOut($col, 201);
    exit;
}

// ── PATCH /api/columns/{id} ──────────────────────────────────────────────────
if ($method === 'PATCH' && preg_match('#^/api/columns/([^/]+)$#', $uri, $m)) {
    $id   = $m[1];
    $body = bodyJson();
    $data = readData();
    $board = &requireBoard($data);
    $found = null;
    foreach ($board['columns'] as &$col) {
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
    $board = &requireBoard($data);
    $board['columns'] = array_values(array_filter($board['columns'], fn($c) => $c['id'] !== $id));
    $fallback = $board['columns'][0]['id'] ?? null;
    if ($fallback !== null) {
        foreach ($board['cards'] as &$card) {
            if ($card['column'] === $id) $card['column'] = $fallback;
        }
        unset($card);
    } else {
        $board['cards'] = array_values(array_filter($board['cards'], fn($c) => $c['column'] !== $id));
    }
    writeData($data);
    jsonOut(['ok' => true]);
    exit;
}

// ── POST /api/import ─────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/import') {
    $body = bodyJson();
    $data = readData();
    $board = &$data['boards'][0]; // Always import to first board for Chrome extension compat
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
        'column'          => $board['columns'][0]['id'] ?? 'todo',
        'attachments'     => $body['attachments']     ?? [],
        'links'           => $body['links']           ?? [],
        'createdAt'       => date('c'),
    ];
    $board['cards'][] = $card;
    writeData($data);
    jsonOut($card, 201);
    exit;
}

// ── POST /api/cards/{id}/move-to-board ───────────────────────────────────────
if ($method === 'POST' && preg_match('#^/api/cards/([^/]+)/move-to-board$#', $uri, $m)) {
    $cardId      = $m[1];
    $body        = bodyJson();
    $fromBoardId = $body['fromBoardId'] ?? '';
    $toBoardId   = $body['toBoardId']   ?? '';
    if (!$fromBoardId || !$toBoardId) { jsonOut(['error' => 'fromBoardId and toBoardId required'], 400); exit; }
    $data = readData();
    $fromBoard = &getBoardById($data, $fromBoardId);
    if ($fromBoard === null) { jsonOut(['error' => 'Source board not found'], 404); exit; }
    // Find and remove card from source board
    $card = null;
    $newCards = [];
    foreach ($fromBoard['cards'] as $c) {
        if ($c['id'] === $cardId) { $card = $c; }
        else { $newCards[] = $c; }
    }
    if ($card === null) { jsonOut(['error' => 'Card not found on source board'], 404); exit; }
    $fromBoard['cards'] = $newCards;
    // Find target board
    $toBoard = &getBoardById($data, $toBoardId);
    if ($toBoard === null) { jsonOut(['error' => 'Target board not found'], 404); exit; }
    // Match column by id, then by label, then fall back to first column
    $targetCol = null;
    $toCols = $toBoard['columns'];
    foreach ($toCols as $col) {
        if ($col['id'] === $card['column']) { $targetCol = $col['id']; break; }
    }
    if (!$targetCol) {
        // Try matching by label
        $fromColLabel = null;
        foreach ($fromBoard['columns'] as $col) {
            if ($col['id'] === $card['column']) { $fromColLabel = $col['label']; break; }
        }
        if ($fromColLabel) {
            foreach ($toCols as $col) {
                if (strcasecmp($col['label'], $fromColLabel) === 0) { $targetCol = $col['id']; break; }
            }
        }
    }
    if (!$targetCol) { $targetCol = $toCols[0]['id'] ?? 'todo'; }
    $card['column'] = $targetCol;
    $toBoard['cards'][] = $card;
    writeData($data);
    jsonOut($card);
    exit;
}

// ── POST /api/reorder ────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/reorder') {
    $body    = bodyJson();
    $column  = $body['column']  ?? '';
    $cardIds = $body['cardIds'] ?? [];
    if (!$column || !is_array($cardIds)) { jsonOut(['error' => 'column and cardIds required'], 400); exit; }
    $data = readData();
    $board = &requireBoard($data);
    $validCols = array_column($board['columns'], 'id');
    if (!in_array($column, $validCols, true)) { jsonOut(['error' => 'unknown column'], 400); exit; }
    $inCol  = [];
    $others = [];
    foreach ($board['cards'] as $card) {
        if ($card['column'] === $column) $inCol[$card['id']] = $card;
        else $others[] = $card;
    }
    $ordered = [];
    foreach ($cardIds as $id) {
        if (isset($inCol[$id])) { $ordered[] = $inCol[$id]; unset($inCol[$id]); }
    }
    foreach ($inCol as $card) $ordered[] = $card;
    $result = [];
    foreach ($board['columns'] as $col) {
        if ($col['id'] === $column) {
            foreach ($ordered as $c) $result[] = $c;
        } else {
            foreach ($others as $c) {
                if ($c['column'] === $col['id']) $result[] = $c;
            }
        }
    }
    $board['cards'] = $result;
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
<script src="https://cdn.jsdelivr.net/npm/marked@15.0.7/marked.min.js" integrity="sha256-k04+Nuni2gr7Gm51B1uw8JrwUpOoROhKdHfvQJEcNJo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.3.1/dist/purify.min.js" integrity="sha256-m0lAV/rWZW/ZziCJ0LaJjfljLBDkXkd1pDBzpGz/yMs=" crossorigin="anonymous"></script>
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
    <div id="board-tabs"></div>
    <div class="win-btns">
      <button id="search-btn" onclick="toggleSearch()" title="Search (Ctrl+F)" style="-webkit-app-region:no-drag;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-dim);font-size:12px;padding:1px 6px;cursor:pointer;height:22px;margin-right:2px;line-height:1;">&#128269;</button>
      <button id="settings-btn" onclick="showSettings()" title="Settings" style="-webkit-app-region:no-drag;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-dim);font-size:14px;padding:1px 6px;cursor:pointer;height:22px;margin-right:2px;line-height:1;">&#9881;</button>
      <button onclick="stashApp()" title="Stash to side">&#x25B6;</button>
      <button class="close-btn" onclick="nativeMsg('close')" title="Close">&#10005;</button>
    </div>
  </div>
  <div id="search-overlay" class="hidden">
    <span class="search-icon">&#128269;</span>
    <input id="search-input" type="text" placeholder="Search cards…" autocomplete="off" spellcheck="false">
    <button id="search-clear" title="Clear search">&times;</button>
  </div>
  <div id="board-wrap"></div>
</div>
<div id="toast-container"></div>

<script>
'use strict';

// ── State ────────────────────────────────────────────────────────────────────
let state = { boards: [], columns: [], cards: [] };
let state_activeBoardId = localStorage.getItem('specter-active-board') || null;
let lastBoardsJson  = '';
let lastColumnsJson = '';
let lastCardsJson   = '';
let dragCardId      = null;
let searchQuery     = '';
let shownReminders  = new Set((() => { try { const v = JSON.parse(sessionStorage.getItem('shownReminders') || '[]'); return Array.isArray(v) ? v : []; } catch { return []; } })());

// ── Native bridge ────────────────────────────────────────────────────────────
function nativeMsg(msg) {
  if (window.chrome?.webview) window.chrome.webview.postMessage(msg);
  else if (msg === 'close') window.close();
}

// ── Toast notifications ───────────────────────────────────────────────────
function showToast(message, type) {
  const container = document.getElementById('toast-container');
  const toast = document.createElement('div');
  toast.className = 'toast ' + type;
  const icon = document.createElement('span');
  icon.className = 'toast-icon';
  icon.textContent = type === 'danger' ? '\uD83D\uDD34' : '\u26A0\uFE0F';
  const msg = document.createElement('span');
  msg.className = 'toast-msg';
  msg.textContent = message;
  const close = document.createElement('button');
  close.className = 'toast-close';
  close.textContent = '\u00D7';
  close.onclick = () => dismissToast(toast);
  toast.appendChild(icon);
  toast.appendChild(msg);
  toast.appendChild(close);
  container.appendChild(toast);
  setTimeout(() => dismissToast(toast), 5000);
}
function dismissToast(toast) {
  if (toast.classList.contains('toast-exit')) return;
  toast.classList.add('toast-exit');
  const cleanup = () => {
    clearTimeout(fallbackTimer);
    toast.removeEventListener('animationend', cleanup);
    if (toast.parentNode) toast.remove();
  };
  toast.addEventListener('animationend', cleanup);
  const fallbackTimer = setTimeout(cleanup, 350);
}

// ── HTML sanitizer (DOMPurify with fallback) ─────────────────────────────
const SAFE_STYLE_PROPS = /^(white-space|padding|margin|text-align|text-indent|vertical-align|display|list-style|border-collapse|border-spacing|width|min-width|max-width|height|min-height|max-height)\s*:/i;
function sanitizeHtml(html) {
  if (typeof DOMPurify !== 'undefined') {
    return DOMPurify.sanitize(html, {
      ALLOWED_TAGS: ['h1','h2','h3','h4','h5','h6','p','br','hr','ul','ol','li',
        'strong','b','em','i','a','code','pre','blockquote','img','span','div','table',
        'thead','tbody','tr','th','td','del','s','sub','sup','font'],
      ALLOWED_ATTR: ['href','src','alt','title','target','rel','face','style'],
      ALLOW_DATA_ATTR: false,
      FORCE_BODY: true,
    });
  }
  // Fallback: escape HTML
  const tmp = document.createElement('div');
  tmp.textContent = html;
  return tmp.innerHTML;
}
// DOMPurify hook: filter style attribute to only allow safe layout properties
if (typeof DOMPurify !== 'undefined') {
  DOMPurify.addHook('uponSanitizeAttribute', (node, data) => {
    if (data.attrName === 'style' && data.attrValue) {
      const safe = data.attrValue.split(';')
        .map(p => p.trim())
        .filter(p => p && SAFE_STYLE_PROPS.test(p))
        .join('; ');
      data.attrValue = safe || '';
      if (!safe) data.keepAttr = false;
    }
  });
}

// ── Due-date reminder check (called after poll updates) ──────────────────
function checkDueReminders() {
  const now = new Date(); now.setHours(0,0,0,0);
  let changed = false;
  state.cards.forEach(card => {
    if (!card.dueDate || shownReminders.has(card.id)) return;
    const d = new Date(card.dueDate + 'T00:00:00');
    const diff = Math.floor((d - now) / 86400000);
    if (diff < 0) {
      shownReminders.add(card.id);
      changed = true;
      showToast(card.title + ' is overdue', 'danger');
    } else if (diff === 0) {
      shownReminders.add(card.id);
      changed = true;
      showToast(card.title + ' is due today', 'warning');
    }
  });
  if (changed) {
    try { sessionStorage.setItem('shownReminders', JSON.stringify(Array.from(shownReminders))); }
    catch (e) { /* quota exceeded or storage unavailable — ignore */ }
  }
}

// Titlebar drag — fires on mousedown anywhere in #titlebar except buttons
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('titlebar').addEventListener('mousedown', e => {
    if (e.button === 0 && !e.target.closest('button')) nativeMsg('drag');
  });
  initColorScheme();
  initTheme();
  // Search
  const searchInput = document.getElementById('search-input');
  const searchClear = document.getElementById('search-clear');
  const searchOverlay = document.getElementById('search-overlay');
  searchInput.addEventListener('input', () => {
    searchQuery = searchInput.value.trim().toLowerCase();
    searchClear.classList.toggle('visible', searchQuery.length > 0);
    render();
  });
  searchClear.addEventListener('click', () => {
    searchInput.value = '';
    searchQuery = '';
    searchClear.classList.remove('visible');
    render();
    searchInput.focus();
  });
  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSearch();
  });
  // Ctrl+F opens search
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
      e.preventDefault();
      toggleSearch(true);
    }
  });
});

// ── Search overlay ────────────────────────────────────────────────────────────
function toggleSearch(forceOpen) {
  const overlay = document.getElementById('search-overlay');
  const input = document.getElementById('search-input');
  const isHidden = overlay.classList.contains('hidden');
  if (forceOpen || isHidden) {
    overlay.classList.remove('hidden');
    input.focus();
    input.select();
  } else {
    closeSearch();
  }
}
function closeSearch() {
  const overlay = document.getElementById('search-overlay');
  const input = document.getElementById('search-input');
  overlay.classList.add('hidden');
  if (searchQuery) {
    input.value = '';
    searchQuery = '';
    document.getElementById('search-clear').classList.remove('visible');
    render();
  }
}

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

// ── Board helpers ─────────────────────────────────────────────────────────────
function boardParam() {
  return state_activeBoardId ? `boardId=${encodeURIComponent(state_activeBoardId)}` : '';
}
function apiUrl(path) {
  const sep = path.includes('?') ? '&' : '?';
  return state_activeBoardId ? `${path}${sep}boardId=${encodeURIComponent(state_activeBoardId)}` : path;
}

// ── Poll ─────────────────────────────────────────────────────────────────────
async function poll() {
  try {
    // 1. Fetch board list
    const boards = await fetch('/api/boards').then(r => r.json());
    const bj = JSON.stringify(boards);
    if (bj !== lastBoardsJson) {
      lastBoardsJson = bj;
      state.boards = boards;
    }
    // 2. Resolve activeBoardId
    if (!state_activeBoardId || !boards.find(b => b.id === state_activeBoardId)) {
      state_activeBoardId = boards[0]?.id || null;
      if (state_activeBoardId) localStorage.setItem('specter-active-board', state_activeBoardId);
    }
    // 3. Fetch columns + cards for active board
    const bp = boardParam();
    const [columns, cards] = await Promise.all([
      fetch('/api/columns' + (bp ? '?' + bp : '')).then(r => r.json()),
      fetch('/api/cards' + (bp ? '?' + bp : '')).then(r => r.json()),
    ]);
    const cj = JSON.stringify(columns);
    const kj = JSON.stringify(cards);
    const changed = cj !== lastColumnsJson || kj !== lastCardsJson;
    if (changed) {
      lastColumnsJson = cj;
      lastCardsJson   = kj;
      state.columns   = columns;
      state.cards     = cards;
    }
    renderTabs();
    if (changed) {
      checkDueReminders();
      if (document.querySelector('.modal-backdrop')) return;
      render();
    }
  } catch (e) { /* server may be starting up */ }
}
setInterval(poll, 2000);
poll();

// ── Board Tabs ───────────────────────────────────────────────────────────────
function renderTabs() {
  const container = document.getElementById('board-tabs');
  if (!container) return;
  const existing = container.dataset.json;
  const key = JSON.stringify({ boards: state.boards, active: state_activeBoardId });
  if (existing === key) return;
  container.dataset.json = key;
  container.innerHTML = '';
  state.boards.forEach(b => {
    const tab = document.createElement('button');
    tab.className = 'board-tab' + (b.id === state_activeBoardId ? ' active' : '');
    tab.onclick = () => switchBoard(b.id);
    tab.ondblclick = (e) => {
      e.stopPropagation();
      showPrompt('Rename board:', b.label, async (newName) => {
        await api('PATCH', `/api/boards/${b.id}`, { label: newName });
        lastBoardsJson = '';
        await poll();
      });
    };
    const label = document.createElement('span');
    label.className = 'tab-label';
    label.textContent = b.label;
    tab.appendChild(label);
    if (state.boards.length > 1) {
      const close = document.createElement('span');
      close.className = 'tab-close';
      close.textContent = '×';
      close.onclick = (e) => { e.stopPropagation(); deleteBoard(b.id, b.label); };
      tab.appendChild(close);
    }
    container.appendChild(tab);
  });
  const addBtn = document.createElement('button');
  addBtn.id = 'add-board-btn';
  addBtn.textContent = '+';
  addBtn.title = 'New board';
  addBtn.onclick = addBoard;
  container.appendChild(addBtn);
}

function switchBoard(id) {
  if (id === state_activeBoardId) return;
  state_activeBoardId = id;
  localStorage.setItem('specter-active-board', id);
  lastColumnsJson = '';
  lastCardsJson   = '';
  document.querySelector('.modal-backdrop')?.remove();
  poll();
}

function addBoard() {
  showPrompt('New board name:', 'e.g. Work', async (name) => {
    const res = await api('POST', '/api/boards', { label: name });
    if (res && res.id) {
      lastBoardsJson = '';
      switchBoard(res.id);
    }
  });
}

function deleteBoard(id, label) {
  showConfirm(`Delete board "${label}"? All its cards will be lost.`, async () => {
    await api('DELETE', `/api/boards/${id}`);
    lastBoardsJson = '';
    if (state_activeBoardId === id) {
      state_activeBoardId = null;
      localStorage.removeItem('specter-active-board');
    }
    lastColumnsJson = '';
    lastCardsJson   = '';
    await poll();
  });
}

// ── Context Menu (move card to board) ─────────────────────────────────────────
function dismissContextMenu() {
  document.querySelector('.ctx-menu')?.remove();
}
document.addEventListener('click', dismissContextMenu);
document.addEventListener('contextmenu', e => {
  if (!e.target.closest('.ctx-menu')) dismissContextMenu();
});

function showCardContextMenu(e, card) {
  e.preventDefault();
  e.stopPropagation();
  dismissContextMenu();
  const otherBoards = state.boards.filter(b => b.id !== state_activeBoardId);
  if (otherBoards.length === 0) return; // only one board, nothing to move to

  const menu = document.createElement('div');
  menu.className = 'ctx-menu';
  menu.style.left = e.clientX + 'px';
  menu.style.top  = e.clientY + 'px';

  const header = document.createElement('div');
  header.className = 'ctx-menu-header';
  header.textContent = 'Move to board';
  menu.appendChild(header);

  otherBoards.forEach(b => {
    const item = document.createElement('button');
    item.className = 'ctx-menu-item';
    item.textContent = b.label;
    item.onclick = async () => {
      dismissContextMenu();
      await api('POST', `/api/cards/${card.id}/move-to-board`, {
        fromBoardId: state_activeBoardId,
        toBoardId: b.id,
      });
      lastCardsJson = '';
      await poll();
    };
    menu.appendChild(item);
  });

  document.body.appendChild(menu);
  // Keep menu in viewport
  requestAnimationFrame(() => {
    const rect = menu.getBoundingClientRect();
    if (rect.right > window.innerWidth) menu.style.left = (window.innerWidth - rect.width - 4) + 'px';
    if (rect.bottom > window.innerHeight) menu.style.top = (window.innerHeight - rect.height - 4) + 'px';
  });
}

// ── Render ───────────────────────────────────────────────────────────────────
function render() {
  const wrap = document.getElementById('board-wrap');
  wrap.innerHTML = '';

  const filteredCards = searchQuery
    ? state.cards.filter(c => {
        const hay = `${c.ticketId} ${c.title} ${c.description} ${c.notes}`.toLowerCase();
        return hay.includes(searchQuery);
      })
    : state.cards;

  state.columns.forEach(col => {
    const cards = filteredCards.filter(c => c.column === col.id);
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
        await api('PATCH', apiUrl(`/api/cards/${dragCardId}`), { column: col.id });
      }
      // Reorder
      await api('POST', apiUrl('/api/reorder'), { column: col.id, cardIds: newOrder });
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
      await api('PATCH', apiUrl(`/api/columns/${col.id}`), { label: v });
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
      await api('DELETE', apiUrl(`/api/columns/${col.id}`));
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

  div.addEventListener('contextmenu', e => {
    if (state.boards.length > 1) showCardContextMenu(e, card);
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

  if (card.completedAt) {
    const done = document.createElement('span');
    done.className = 'complete-badge';
    done.textContent = 'Complete';
    done.title = 'Completed ' + new Date(card.completedAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    top.appendChild(done);
  }

  if (card.dueDate) {
    const due = document.createElement('span');
    due.className = 'due-badge';
    const d = new Date(card.dueDate + 'T00:00:00');
    const now = new Date(); now.setHours(0,0,0,0);
    const diff = Math.floor((d - now) / 86400000);
    if (diff < 0) due.classList.add('overdue');
    else if (diff === 0) due.classList.add('today');
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
      await api('DELETE', apiUrl(`/api/cards/${card.id}`));
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
    const stripped = preview.replace(/#{1,6}\s+/g, '').replace(/\*{1,3}([^*]+)\*{1,3}/g, '$1').replace(/`([^`]+)`/g, '$1').replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/^[-*+]\s+/gm, '').replace(/^>\s+/gm, '');
    const notesEl = document.createElement('div');
    notesEl.className = 'card-notes';
    notesEl.textContent = stripped.length > 120 ? stripped.slice(0, 120) + '…' : stripped;
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
    await api('POST', apiUrl('/api/cards'), {
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
    notesView.style.display = 'none';
    notesIn.style.display = '';
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
    tmp.innerHTML = sanitizeHtml(card.descriptionHtml);
    // Convert <font> elements to <span> (preserving monospace hints)
    tmp.querySelectorAll('font').forEach(el => {
      const span = document.createElement('span');
      const face = (el.getAttribute('face') || '').toLowerCase();
      if (face.includes('courier') || face.includes('mono') || face.includes('consolas')) {
        span.style.fontFamily = 'Cascadia Code, Consolas, monospace';
        span.style.whiteSpace = 'pre-wrap';
      }
      while (el.firstChild) span.appendChild(el.firstChild);
      el.replaceWith(span);
    });
    tmp.querySelectorAll('img').forEach(img => {
      const src = img.src;
      img.title = 'Click to enlarge';
      img.onclick = ev => { ev.stopPropagation(); showLightbox(src); };
      img.onerror = () => { img.style.display = 'none'; };
    });
    tmp.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
    descView.appendChild(tmp);
  } else if (card.description) {
    const tmp = document.createElement('div');
    const rawHtml = (typeof marked !== 'undefined' && typeof marked.parse === 'function')
      ? marked.parse(card.description)
      : '';
    if (rawHtml) {
      tmp.innerHTML = sanitizeHtml(rawHtml);
    } else {
      tmp.textContent = card.description;
    }
    tmp.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
    descView.appendChild(tmp);
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

  // Notes — rendered view (view mode) + textarea (edit mode)
  const savedNotesH = _layout.notesHeight || '100px';

  const notesView = document.createElement('div');
  notesView.className = 'notes-html-view';
  notesView.style.height = savedNotesH;
  if (card.notes) {
    const tmp = document.createElement('div');
    const rawHtml = (typeof marked !== 'undefined' && typeof marked.parse === 'function')
      ? marked.parse(card.notes)
      : '';
    if (rawHtml) {
      tmp.innerHTML = sanitizeHtml(rawHtml);
    } else {
      tmp.textContent = card.notes;
    }
    tmp.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
    notesView.appendChild(tmp);
  }
  const notesViewObs = new ResizeObserver(() => {
    if (notesView.style.height) _saveLayout({ notesHeight: notesView.style.height });
  });
  notesViewObs.observe(notesView);

  const notesIn = document.createElement('textarea');
  notesIn.value = card.notes || '';
  notesIn.placeholder = 'Notes…';
  notesIn.style.height = savedNotesH;
  notesIn.style.display = 'none';
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
    const updated = await api('PATCH', apiUrl(`/api/cards/${card.id}`), {
      ticketId:        tidIn.value.trim(),
      title:           titleIn.value.trim(),
      description:     descIn.value.trim(),
      descriptionHtml: '',
      notes:           notesIn.value.trim(),
      url:             urlIn.value.trim(),
      testingUrl:      testUrlIn.value.trim(),
      priority:        priSel.value,
      dueDate:         dueDateIn.value,
      column:          colSel.value,
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
    notesIn.style.display = 'none';
    notesView.style.display = '';
    // Re-render notes markdown
    notesView.innerHTML = '';
    if (notesIn.value.trim()) {
      const tmp = document.createElement('div');
      const rawHtml = (typeof marked !== 'undefined' && typeof marked.parse === 'function')
        ? marked.parse(notesIn.value.trim()) : '';
      if (rawHtml) { tmp.innerHTML = sanitizeHtml(rawHtml); }
      else { tmp.textContent = notesIn.value.trim(); }
      tmp.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
      notesView.appendChild(tmp);
    }
    // Always re-render description from textarea (descriptionHtml cleared on save)
    descView.innerHTML = '';
    if (descIn.value.trim()) {
      const tmp = document.createElement('div');
      const rawHtml = (typeof marked !== 'undefined' && typeof marked.parse === 'function')
        ? marked.parse(descIn.value.trim()) : '';
      if (rawHtml) { tmp.innerHTML = sanitizeHtml(rawHtml); }
      else { tmp.textContent = descIn.value.trim(); }
      tmp.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
      descView.appendChild(tmp);
    }
    render(); // bypass poll guard — modal is on <body>, not #board-wrap
  };

  const delBtn = document.createElement('button');
  delBtn.className = 'btn-danger';
  delBtn.textContent = 'Delete';
  delBtn.onclick = () => {
    showConfirm('Delete this card? This cannot be undone.', async () => {
      await api('DELETE', apiUrl(`/api/cards/${card.id}`));
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
    notesIn.style.display = 'none';
    notesView.style.display = '';
  };

  footer.appendChild(saveBtn);
  footer.appendChild(delBtn);
  footer.appendChild(cancelBtn);

  const descWrap = document.createElement('div');
  descWrap.appendChild(descView);
  descWrap.appendChild(descIn);

  const notesWrap = document.createElement('div');
  notesWrap.appendChild(notesView);
  notesWrap.appendChild(notesIn);

  modal.appendChild(header);
  modal.appendChild(field('Ticket ID', tidIn));
  modal.appendChild(field('Title', titleIn));
  modal.appendChild(field('Description', descWrap, true));
  modal.appendChild(field('Notes', notesWrap, true));
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

  // History section
  const history = card.history || [];
  if (card.completedAt || card.createdAt || history.length > 0) {
    const historyList = document.createElement('div');
    historyList.className = 'history-list';
    if (card.createdAt) {
      const item = document.createElement('div');
      item.className = 'history-item';
      const dot = document.createElement('span');
      dot.className = 'history-dot created';
      const text = document.createElement('span');
      text.textContent = 'Created';
      const time = document.createElement('span');
      time.className = 'history-time';
      time.textContent = new Date(card.createdAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
      item.appendChild(dot);
      item.appendChild(text);
      item.appendChild(time);
      historyList.appendChild(item);
    }
    history.forEach(h => {
      const item = document.createElement('div');
      item.className = 'history-item';
      const dot = document.createElement('span');
      dot.className = 'history-dot ' + h.action;
      const text = document.createElement('span');
      if (h.action === 'completed') {
        text.textContent = 'Marked complete';
      } else if (h.action === 'reopened') {
        const colLabel = state.columns.find(c => c.id === h.movedTo)?.label || h.movedTo;
        text.textContent = `Reopened \u2192 ${colLabel}`;
      } else {
        text.textContent = h.action;
      }
      const time = document.createElement('span');
      time.className = 'history-time';
      time.textContent = new Date(h.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
      item.appendChild(dot);
      item.appendChild(text);
      item.appendChild(time);
      historyList.appendChild(item);
    });
    modal.appendChild(field('History', historyList, true));
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
    await api('POST', apiUrl('/api/columns'), { label });
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
