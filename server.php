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
    $data = readData();
    $found = null;
    foreach ($data['cards'] as &$card) {
        if ($card['id'] === $id) {
            foreach (['ticketId','title','description','descriptionHtml','notes','url','testingUrl','priority','column','attachments','links'] as $f) {
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
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:         rgba(14, 16, 24, 0.92);
    --surface:    rgba(22, 26, 40, 0.85);
    --border:     rgba(120, 130, 200, 0.18);
    --glow:       rgba(100, 120, 255, 0.12);
    --glow-hover: rgba(100, 120, 255, 0.22);
    --text:       #c8cde8;
    --text-dim:   #6b7094;
    --accent:     #7b8fff;
    --high:       #ff5f72;
    --med:        #ffb347;
    --low:        #5bc8fa;
    --add-bg:     rgba(30, 35, 55, 0.7);

    /* Glass variables — all slider/picker-controlled */
    --glass-frost:        2px;
    --glass-frost-modal:  3px;
    --glass-tint:         rgba(255, 255, 255, 0.04);
    --glass-tint-opacity: 0.04;
    --inner-highlight:    inset 0 1px 20px -5px rgba(255,255,255,0.5),
                          inset 0 -1px 0 rgba(0,0,0,0.20);
    --outer-glow:         0 4px 24px rgba(0,0,0,0.28),
                          0 0 0 1px rgba(123,143,255,0.18);
  }

  html {
    width: 100%; height: 100%;
    background: transparent;
    overflow: hidden;
  }
  body {
    width: 100%; height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 13px;
    overflow: hidden;
    user-select: none;
  }
  body.theme-glass { background: transparent !important; }

  /* ── Title bar ── */
  #titlebar {
    display: flex;
    align-items: center;
    height: 36px;
    padding: 0 10px;
    background: rgba(10, 12, 20, 0.72);
    border-bottom: 1px solid var(--border);
    -webkit-app-region: drag;
    flex-shrink: 0;
  }
  body.theme-glass #titlebar {
    background: transparent;
    border-bottom: 1px solid rgba(123,143,255,0.20);
    position: relative;
    overflow: hidden;
  }
  body.theme-glass #titlebar::before {
    content: '';
    position: absolute; inset: 0; pointer-events: none;
    backdrop-filter: blur(var(--glass-frost)) saturate(1.6);
    filter: url(#glass-distortion);
    z-index: 0;
  }
  body.theme-glass #titlebar::after {
    content: '';
    position: absolute; inset: 0; pointer-events: none;
    background: var(--glass-tint);
    box-shadow: var(--inner-highlight);
    z-index: 1;
  }
  body.theme-glass #titlebar > * { position: relative; z-index: 2; }
  #titlebar .ghost-title {
    flex: 1;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.04em;
    color: var(--accent);
    text-shadow: 0 0 12px rgba(123,143,255,0.5);
  }
  #titlebar .win-btns {
    display: flex;
    gap: 4px;
    -webkit-app-region: no-drag;
  }
  #titlebar .win-btns button {
    width: 28px; height: 22px;
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text-dim);
    cursor: pointer;
    font-size: 11px;
    transition: background 0.15s, color 0.15s;
  }
  #titlebar .win-btns button:hover { background: var(--glow-hover); color: var(--text); }
  #titlebar .win-btns button.close-btn:hover { background: rgba(255,80,80,0.25); color: #ff5f72; }

  /* ── Board layout ── */
  #app {
    display: flex;
    flex-direction: column;
    height: 100vh;
    background: transparent;
  }

  /* ── Stash tab ── */
  #stash-tab {
    position: fixed;
    top: 50%; left: 0;
    transform: translateY(-50%);
    width: 42px;
    height: 160px;
    background: rgba(14, 18, 40, 0.95);
    border-top:    1px solid rgba(123, 143, 255, 0.4);
    border-bottom: 1px solid rgba(123, 143, 255, 0.28);
    border-left:   1px solid rgba(123, 143, 255, 0.5);
    border-right:  none;
    border-radius: 16px 0 0 16px;
    box-shadow:
      -6px 0 32px rgba(100, 120, 255, 0.28),
      -2px 0 10px rgba(123, 143, 255, 0.18);
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    cursor: pointer;
    z-index: 2000;
    color: var(--accent);
    transition: box-shadow 0.25s;
    overflow: hidden;
  }
  #stash-tab::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(to bottom, rgba(123,143,255,0.06) 0%, transparent 50%);
    border-radius: inherit;
    pointer-events: none;
  }
  body.theme-glass #stash-tab { background: transparent; }
  body.theme-glass #stash-tab::before {
    content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    background: linear-gradient(160deg,
      rgba(123,143,255,0.22) 0%, rgba(14,18,40,0.55) 45%, rgba(80,60,180,0.16) 100%);
    box-shadow: inset 1px 1px 0 rgba(255,255,255,0.1);
    z-index: 1;
  }
  body.theme-glass #stash-tab::after {
    content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    backdrop-filter: blur(16px) saturate(1.8);
    filter: url(#glass-distortion);
    z-index: 0;
  }
  body.theme-glass #stash-tab > * { position: relative; z-index: 2; }
  #stash-tab.visible { display: flex; }
  #stash-tab:hover {
    box-shadow:
      -8px 0 40px rgba(100, 120, 255, 0.42),
      -3px 0 12px rgba(123, 143, 255, 0.28);
  }
  #stash-tab:hover::before {
    background: linear-gradient(160deg,
      rgba(123, 143, 255, 0.32) 0%,
      rgba(18, 24, 52, 0.65) 45%,
      rgba(100, 80, 210, 0.26) 100%);
  }
  #stash-tab .tab-label {
    writing-mode: vertical-rl;
    transform: rotate(180deg);
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    opacity: 0.65;
  }
  #board-wrap {
    flex: 1;
    display: flex;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 14px 12px 12px;
    gap: 12px;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
    background: transparent;
  }
  #board-wrap::-webkit-scrollbar { height: 6px; }
  #board-wrap::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

  /* ── Column ── */
  .column {
    flex-shrink: 0;
    width: 230px;
    min-width: 160px;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--outer-glow);
    overflow: hidden;
    transition: box-shadow 0.2s;
    position: relative;
  }
  body.theme-glass .column { background: transparent; }
  body.theme-glass .column::before {
    content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    backdrop-filter: blur(var(--glass-frost)) saturate(1.7);
    filter: url(#glass-distortion);
    z-index: 0;
  }
  body.theme-glass .column::after {
    content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    background: var(--glass-tint);
    box-shadow: var(--inner-highlight);
    z-index: 1;
  }
  body.theme-glass .col-header,
  body.theme-glass .cards-list,
  body.theme-glass .add-card-btn { position: relative; z-index: 2; }
  .col-resize-handle {
    position: absolute; top: 0; right: -3px;
    width: 6px; height: 100%;
    cursor: col-resize; z-index: 10;
    border-radius: 3px;
  }
  .col-resize-handle:hover, .col-resize-handle.dragging {
    background: rgba(123,143,255,0.35);
  }
  .column.drag-over {
    box-shadow: 0 0 28px var(--glow-hover), 0 0 0 1px rgba(120,130,200,0.38);
    border-color: rgba(120,130,200,0.38);
  }

  .col-header {
    display: flex;
    align-items: center;
    padding: 9px 10px 8px;
    border-bottom: 1px solid var(--border);
    gap: 6px;
    background: rgba(0,0,0,0.12);
  }
  .col-title {
    flex: 1;
    font-weight: 600;
    font-size: 12px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--accent);
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 3px;
    border: 1px solid transparent;
    background: transparent;
    outline: none;
    min-width: 0;
  }
  .col-title:focus {
    border-color: var(--accent);
    background: rgba(123,143,255,0.08);
    cursor: text;
  }
  .col-count {
    font-size: 10px;
    color: var(--text-dim);
    min-width: 16px;
    text-align: center;
  }
  .col-del {
    background: transparent;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    padding: 1px 3px;
    border-radius: 3px;
    transition: color 0.15s;
  }
  .col-del:hover { color: var(--high); }

  /* ── Cards list ── */
  .cards-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px 8px 4px;
    display: flex;
    flex-direction: column;
    gap: 7px;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
    min-height: 40px;
  }
  .cards-list::-webkit-scrollbar { width: 4px; }
  .cards-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

  /* ── Card ── */
  .card {
    background: rgba(18, 22, 38, 0.8);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 8px 9px;
    cursor: grab;
    transition: box-shadow 0.15s, transform 0.1s;
    position: relative;
    border-left-width: 3px;
  }
  body.theme-glass .card { background: transparent; overflow: hidden; }
  body.theme-glass .card::before {
    content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    backdrop-filter: blur(8px) saturate(1.5);
    filter: url(#glass-distortion);
    z-index: 0;
  }
  body.theme-glass .card::after {
    content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    background: var(--glass-tint);
    box-shadow: var(--inner-highlight);
    z-index: 1;
  }
  body.theme-glass .card-top,
  body.theme-glass .card-title,
  body.theme-glass .card-notes { position: relative; z-index: 2; }
  .card:hover {
    box-shadow: 0 0 14px var(--glow);
    transform: translateY(-1px);
  }
  .card.dragging { opacity: 0.45; cursor: grabbing; }
  .card[data-priority="high"]   { border-left-color: var(--high); }
  .card[data-priority="medium"] { border-left-color: var(--med); }
  .card[data-priority="low"]    { border-left-color: var(--low); }

  .card-top {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
  }
  .ticket-id {
    font-family: 'Cascadia Code', 'Consolas', monospace;
    font-size: 10px;
    background: rgba(123,143,255,0.12);
    color: var(--accent);
    padding: 1px 5px;
    border-radius: 3px;
    border: 1px solid rgba(123,143,255,0.2);
    flex-shrink: 0;
  }
  .pri-badge {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 1px 5px;
    border-radius: 3px;
    flex-shrink: 0;
  }
  .pri-badge.high   { background: rgba(255,95,114,0.15); color: var(--high); }
  .pri-badge.medium { background: rgba(255,179,71,0.15); color: var(--med); }
  .pri-badge.low    { background: rgba(91,200,250,0.15); color: var(--low); }
  .card-del {
    margin-left: auto;
    background: transparent;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    font-size: 13px;
    line-height: 1;
    opacity: 0;
    transition: opacity 0.15s, color 0.15s;
    padding: 0 2px;
  }
  .card:hover .card-del { opacity: 1; }
  .card-del:hover { color: var(--high); }

  .card-title {
    font-size: 12px;
    font-weight: 500;
    color: var(--text);
    line-height: 1.35;
    word-break: break-word;
  }
  .card-notes {
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 4px;
    line-height: 1.4;
    word-break: break-word;
  }

  /* ── Add card form ── */
  .add-card-btn {
    margin: 4px 8px 8px;
    width: calc(100% - 16px);
    background: transparent;
    border: 1px dashed rgba(120,130,200,0.22);
    border-radius: 6px;
    color: var(--text-dim);
    padding: 5px;
    cursor: pointer;
    font-size: 12px;
    transition: border-color 0.15s, color 0.15s, background 0.15s;
  }
  .add-card-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: rgba(123,143,255,0.06);
  }

  .add-card-form {
    margin: 0 8px 8px;
    display: flex;
    flex-direction: column;
    gap: 5px;
  }
  .add-card-form input,
  .add-card-form textarea,
  .add-card-form select {
    background: rgba(10,12,22,0.6);
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--text);
    padding: 5px 7px;
    font-size: 12px;
    font-family: inherit;
    outline: none;
    transition: border-color 0.15s;
    width: 100%;
  }
  .add-card-form input:focus,
  .add-card-form textarea:focus,
  .add-card-form select:focus { border-color: var(--accent); }
  .add-card-form textarea { resize: vertical; min-height: 46px; }
  .add-card-form select option { background: #141828; }
  .form-row { display: flex; gap: 5px; }
  .form-btns { display: flex; gap: 5px; }
  .btn-save {
    flex: 1;
    background: rgba(123,143,255,0.15);
    border: 1px solid rgba(123,143,255,0.3);
    border-radius: 5px;
    color: var(--accent);
    padding: 5px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: background 0.15s;
  }
  .btn-save:hover { background: rgba(123,143,255,0.28); }
  .btn-cancel {
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--text-dim);
    padding: 5px 9px;
    cursor: pointer;
    font-size: 12px;
    transition: border-color 0.15s;
  }
  .btn-cancel:hover { border-color: var(--text-dim); }

  /* ── Add column ── */
  #add-col-btn {
    flex-shrink: 0;
    align-self: flex-start;
    margin-top: 2px;
    width: 42px;
    height: 42px;
    background: var(--surface);
    border: 1px dashed rgba(120,130,200,0.22);
    border-radius: 10px;
    color: var(--text-dim);
    font-size: 22px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color 0.15s, color 0.15s, background 0.15s;
  }
  #add-col-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: rgba(123,143,255,0.08);
  }

  /* ── Resize handles ── */
  .rsz {
    position: fixed; z-index: 999;
    /* transparent — just a hit zone */
  }
  .rsz-n  { top:0;    left:6px;   right:6px;  height:5px; cursor:n-resize; }
  .rsz-s  { bottom:0; left:6px;   right:6px;  height:5px; cursor:s-resize; }
  .rsz-w  { left:0;   top:6px;    bottom:6px; width:5px;  cursor:w-resize; }
  .rsz-e  { right:0;  top:6px;    bottom:6px; width:5px;  cursor:e-resize; }
  .rsz-nw { top:0;    left:0;     width:10px; height:10px; cursor:nw-resize; }
  .rsz-ne { top:0;    right:0;    width:10px; height:10px; cursor:ne-resize; }
  .rsz-sw { bottom:0; left:0;     width:10px; height:10px; cursor:sw-resize; }
  .rsz-se { bottom:0; right:0;    width:10px; height:10px; cursor:se-resize; }
  body.is-stashed .rsz { display: none; }
  body.is-stashed #app { display: none; }
  body.is-stashed { background: transparent !important; }

  /* ── Drop placeholder ── */
  .drop-placeholder {
    height: 52px;
    border: 1px dashed rgba(123,143,255,0.3);
    border-radius: 7px;
    background: rgba(123,143,255,0.04);
  }

  /* ── Modal ── */
  .modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(6, 8, 16, 0.72);
    backdrop-filter: blur(6px);
    z-index: 1000;
    display: flex; align-items: center; justify-content: center;
  }
  body.theme-glass .modal-backdrop { backdrop-filter: blur(10px) saturate(1.3); }
  .modal {
    background: rgba(18, 22, 38, 0.97);
    border: 1px solid rgba(120, 130, 200, 0.28);
    border-radius: 12px;
    box-shadow: 0 0 40px rgba(100, 120, 255, 0.18);
    overflow-y: auto;
    width: 460px; max-width: 95vw;
    max-height: 90vh;
    padding: 22px 24px 20px;
    display: flex; flex-direction: column; gap: 12px;
    position: relative;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
  }
  body.theme-glass .modal {
    background: rgba(18, 22, 38, 0.58);
    backdrop-filter: blur(var(--glass-frost-modal)) saturate(2.0);
    box-shadow: var(--outer-glow);
  }
  .modal-header {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 2px;
  }
  .modal-title {
    flex: 1; font-size: 13px; font-weight: 700;
    color: var(--accent); letter-spacing: 0.04em;
  }
  .modal-close {
    background: transparent; border: none;
    color: var(--text-dim); cursor: pointer;
    font-size: 18px; line-height: 1; padding: 2px 5px;
    border-radius: 4px; transition: color 0.15s;
  }
  .modal-close:hover { color: var(--high); }
  .modal label {
    font-size: 10px; letter-spacing: 0.06em;
    text-transform: uppercase; color: var(--text-dim);
    display: block; margin-bottom: 3px;
  }
  .modal input, .modal textarea, .modal select {
    width: 100%;
    background: rgba(10,12,22,0.7);
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--text); padding: 6px 8px;
    font-size: 12px; font-family: inherit; outline: none;
    transition: border-color 0.15s;
  }
  .modal input:focus, .modal textarea:focus, .modal select:focus {
    border-color: var(--accent);
  }
  .modal textarea { resize: vertical; min-height: 70px; overflow-y: auto; }
  .modal select option { background: #141828; }
  .modal-row { display: flex; gap: 10px; }
  .modal-row > div { flex: 1; }
  .modal-footer { display: flex; gap: 8px; margin-top: 4px; }
  .modal-footer .btn-save { flex: 1; }
  .btn-danger {
    background: rgba(255,95,114,0.12);
    border: 1px solid rgba(255,95,114,0.3);
    border-radius: 5px; color: var(--high);
    padding: 5px 12px; cursor: pointer; font-size: 12px;
    transition: background 0.15s;
  }
  .btn-danger:hover { background: rgba(255,95,114,0.25); }
  /* View-only mode */
  .modal.view-mode input,
  .modal.view-mode textarea,
  .modal.view-mode select {
    background: transparent;
    border-color: transparent;
    color: var(--text);
    cursor: default;
    pointer-events: none;
    resize: none;
  }
  .modal.view-mode textarea {
    pointer-events: auto;
    cursor: default;
    user-select: text;
    resize: vertical;
  }
  .modal.view-mode select { -webkit-appearance: none; appearance: none; }
  .modal.view-mode .btn-save,
  .modal.view-mode .btn-cancel { display: none; }
  .modal:not(.view-mode) .btn-edit { display: none; }
  .btn-edit {
    background: rgba(123,143,255,0.1);
    border: 1px solid rgba(123,143,255,0.25);
    border-radius: 4px; color: var(--accent);
    cursor: pointer; font-size: 11px; font-weight: 600;
    padding: 3px 9px; transition: background 0.15s; letter-spacing: 0.03em;
  }
  .btn-edit:hover { background: rgba(123,143,255,0.22); }
  /* Confirmation modal */
  .confirm-backdrop {
    position: fixed; inset: 0;
    background: rgba(6, 8, 16, 0.55);
    z-index: 1100;
    display: flex; align-items: center; justify-content: center;
  }
  .confirm-modal {
    background: rgba(18, 22, 38, 0.98);
    border: 1px solid rgba(120, 130, 200, 0.28);
    border-radius: 10px;
    box-shadow: 0 0 30px rgba(100, 120, 255, 0.15);
    padding: 20px 22px;
    width: 300px; max-width: 90vw;
    display: flex; flex-direction: column; gap: 14px;
  }
  body.theme-glass .confirm-modal {
    background: rgba(18, 22, 38, 0.62);
    backdrop-filter: blur(var(--glass-frost-modal)) saturate(2.0);
    box-shadow: var(--outer-glow);
  }
  .confirm-msg {
    font-size: 13px; color: var(--text); line-height: 1.5;
  }
  .confirm-btns { display: flex; gap: 8px; justify-content: flex-end; }
  .modal-url-row { display: flex; gap: 6px; align-items: flex-end; }
  .modal-url-row > div { flex: 1; }
  .btn-link {
    background: rgba(123,143,255,0.1);
    border: 1px solid rgba(123,143,255,0.25);
    border-radius: 5px; color: var(--accent);
    padding: 6px 9px; cursor: pointer; font-size: 14px;
    line-height: 1; flex-shrink: 0; transition: background 0.15s, opacity 0.15s;
    text-decoration: none; display: flex; align-items: center;
  }
  .btn-link:hover { background: rgba(123,143,255,0.22); }
  .btn-link[disabled] { opacity: 0.28; pointer-events: none; }

  /* ── Description HTML view ── */
  .desc-html-view {
    font-size: 12px; color: var(--text); line-height: 1.55;
    overflow-y: auto; min-height: 70px;
    padding: 6px 8px;
    background: rgba(10,12,22,0.3);
    border: 1px solid var(--border);
    border-radius: 5px;
    word-break: break-word;
    resize: vertical;
  }
  .desc-html-view h1, .desc-html-view h2,
  .desc-html-view h3, .desc-html-view h4 {
    color: var(--text); margin: 8px 0 4px; font-weight: 600; line-height: 1.3;
  }
  .desc-html-view h1 { font-size: 14px; }
  .desc-html-view h2 { font-size: 13px; }
  .desc-html-view h3, .desc-html-view h4 { font-size: 12px; }
  .desc-html-view p { margin: 4px 0; }
  .desc-html-view ul, .desc-html-view ol { margin: 4px 0; padding-left: 18px; }
  .desc-html-view li { margin: 2px 0; }
  .desc-html-view strong, .desc-html-view b { color: var(--text); font-weight: 600; }
  .desc-html-view em, .desc-html-view i { font-style: italic; color: var(--text-dim); }
  .desc-html-view code {
    font-family: 'Cascadia Code', 'Consolas', monospace;
    font-size: 11px; background: rgba(123,143,255,0.12);
    border: 1px solid rgba(123,143,255,0.2);
    border-radius: 3px; padding: 1px 4px;
  }
  .desc-html-view pre {
    background: rgba(0,0,0,0.3); border-radius: 4px;
    padding: 8px; overflow-x: auto; font-size: 11px; margin: 6px 0;
    white-space: pre-wrap; word-break: break-all;
    font-family: 'Cascadia Code', 'Consolas', monospace;
  }
  .desc-html-view pre code { background: none; border: none; padding: 0; }
  .desc-html-view blockquote {
    border-left: 2px solid var(--accent); margin: 4px 0;
    padding: 2px 8px; color: var(--text-dim);
  }
  .desc-html-view hr { border: none; border-top: 1px solid var(--border); margin: 8px 0; }
  .desc-html-view img {
    max-width: 120px; max-height: 84px;
    object-fit: contain; border-radius: 4px;
    border: 1px solid var(--border);
    cursor: zoom-in; vertical-align: middle;
    margin: 3px 4px; transition: border-color 0.15s, box-shadow 0.15s;
    background: rgba(0,0,0,0.18);
  }
  .desc-html-view img:hover {
    border-color: var(--accent);
    box-shadow: 0 0 0 1px var(--accent);
  }
  .desc-html-view a { color: var(--accent); text-decoration: none; }
  .desc-html-view a:hover { text-decoration: underline; }

  /* ── Attachments ── */
  .att-grid {
    display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;
  }
  .att-thumb {
    width: 80px; height: 60px; object-fit: cover;
    border-radius: 4px; border: 1px solid var(--border);
    cursor: pointer; transition: opacity 0.15s;
  }
  .att-thumb:hover { opacity: 0.8; }

  /* ── Links list ── */
  .links-list {
    display: flex; flex-direction: column; gap: 4px; margin-top: 4px;
  }
  .card-link {
    font-size: 11px; color: var(--accent);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    text-decoration: none;
  }
  .card-link:hover { text-decoration: underline; }

  /* ── Lightbox ── */
  .lightbox-backdrop {
    position: fixed; inset: 0; z-index: 2000;
    background: rgba(4, 5, 12, 0.92);
    backdrop-filter: blur(8px);
    display: flex; align-items: center; justify-content: center;
    cursor: zoom-out;
  }
  .lightbox-backdrop img {
    max-width: 92vw; max-height: 92vh;
    border-radius: 6px;
    box-shadow: 0 0 60px rgba(0,0,0,0.8);
    cursor: default;
  }
  .lightbox-close {
    position: fixed; top: 16px; right: 20px;
    background: transparent; border: none;
    color: #fff; font-size: 28px; cursor: pointer;
    opacity: 0.7; line-height: 1;
  }
  .lightbox-close:hover { opacity: 1; }

  /* ── Attachment side panel ── */
  .modal-wrapper {
    display: flex; gap: 10px; align-items: flex-start;
  }
  .att-panel {
    width: 120px; flex-shrink: 0;
    display: flex; flex-direction: column; gap: 6px;
    max-height: 90vh; overflow-y: auto;
    scrollbar-width: thin; scrollbar-color: var(--border) transparent;
    padding-top: 2px;
  }
  .att-panel-thumb {
    width: 100%; border-radius: 6px;
    border: 1px solid var(--border);
    cursor: pointer; object-fit: cover;
    transition: opacity 0.15s, border-color 0.15s;
  }
  .att-panel-thumb:hover { opacity: 0.85; border-color: var(--accent); }

  /* ── Settings modal ── */
  .btn-primary {
    flex: 1;
    background: rgba(123,143,255,0.15);
    border: 1px solid rgba(123,143,255,0.3);
    border-radius: 5px;
    color: var(--accent);
    padding: 5px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: background 0.15s;
  }
  .btn-primary:hover { background: rgba(123,143,255,0.28); }
  .btn-secondary {
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--text-dim);
    padding: 5px 9px;
    cursor: pointer;
    font-size: 12px;
    transition: border-color 0.15s;
  }
  .btn-secondary:hover { border-color: var(--text-dim); color: var(--text); }
  .settings-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
  }
  .settings-row label {
    font-size: 11px;
    color: var(--text-dim);
    width: 120px;
    flex-shrink: 0;
  }
  .settings-row input[type="range"] {
    flex: 1;
    accent-color: var(--accent);
    cursor: pointer;
  }
  .settings-row input[type="color"] {
    width: 36px; height: 24px;
    border: 1px solid var(--border);
    border-radius: 4px;
    cursor: pointer;
    background: none;
    padding: 1px 2px;
    flex-shrink: 0;
  }
  .settings-row input[type="text"] {
    flex: 1;
    background: rgba(10,12,22,0.6);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    padding: 4px 7px;
    font-size: 11px;
    font-family: inherit;
    outline: none;
  }
  .settings-row input[type="text"]:focus { border-color: var(--accent); }
  .settings-row .val-display {
    font-size: 11px;
    color: var(--text);
    width: 36px;
    text-align: right;
  }
  .settings-section-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-dim);
    margin: 16px 0 8px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 4px;
  }
</style>
</head>
<body>
<svg id="glass-svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">
  <defs>
    <filter id="glass-distortion" x="-20%" y="-20%" width="140%" height="140%"
            color-interpolation-filters="sRGB">
      <feTurbulence id="glass-noise" type="fractalNoise"
                    baseFrequency="0.008 0.008" numOctaves="2" seed="92" result="noise"/>
      <feGaussianBlur in="noise" stdDeviation="2" result="blurred"/>
      <feDisplacementMap id="glass-displace" in="SourceGraphic" in2="blurred"
                         scale="60" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
  </defs>
</svg>
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
      <select id="theme-select" title="Theme" onchange="applyTheme(this.value)" style="-webkit-app-region:no-drag;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-dim);font-size:11px;padding:1px 6px;cursor:pointer;height:22px;margin-right:2px;">
        <option value="normal">Normal</option>
        <option value="glass">Liquid Glass</option>
      </select>
      <button id="glass-settings-btn" onclick="showGlassSettings()" title="Glass settings">&#9881;</button>
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
    if (e.button === 0 && !e.target.closest('button, select')) nativeMsg('drag');
  });
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

  // Drag-over handlers
  div.addEventListener('dragover', e => {
    e.preventDefault();
    div.classList.add('drag-over');
    // show placeholder
    const list = div.querySelector('.cards-list');
    if (!list.querySelector('.drop-placeholder')) {
      const ph = document.createElement('div');
      ph.className = 'drop-placeholder';
      list.appendChild(ph);
    }
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
    div.querySelector('.drop-placeholder')?.remove();
    if (dragCardId) {
      await api('PATCH', `/api/cards/${dragCardId}`, { column: col.id });
      dragCardId = null;
      await poll();
    }
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

  // Fields helper
  const field = (labelText, el) => {
    const wrap = document.createElement('div');
    const lbl = document.createElement('label');
    lbl.textContent = labelText;
    wrap.appendChild(lbl);
    wrap.appendChild(el);
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
  const _layoutKey = 'specter-layout-' + (card.ticketId || card.id);
  const _layout    = JSON.parse(localStorage.getItem(_layoutKey) || '{}');
  const _saveLayout = (patch) => {
    const current = JSON.parse(localStorage.getItem(_layoutKey) || '{}');
    localStorage.setItem(_layoutKey, JSON.stringify(Object.assign(current, patch)));
  };

  const savedDescH = _layout.descHeight || '140px';

  const descView = document.createElement('div');
  descView.className = 'desc-html-view';
  descView.style.height = savedDescH;
  if (card.descriptionHtml) {
    const tmp = document.createElement('div');
    tmp.innerHTML = card.descriptionHtml;
    // Sanitize inline styles: strip visual overrides but keep layout/whitespace props
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
  const urlLbl = document.createElement('label');
  urlLbl.textContent = 'URL';
  urlFieldWrap.appendChild(urlLbl);
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
  const testUrlLbl = document.createElement('label');
  testUrlLbl.textContent = 'Testing Doc';
  testUrlFieldWrap.appendChild(testUrlLbl);
  testUrlFieldWrap.appendChild(testUrlIn);
  testUrlRow.appendChild(testUrlFieldWrap);
  testUrlRow.appendChild(testLinkBtn);

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
  modal.appendChild(field('Description', descWrap));
  modal.appendChild(field('Notes', notesIn));
  modal.appendChild(urlRow);
  modal.appendChild(testUrlRow);
  modal.appendChild(row);

  // Links list (read-only, stays inside modal)
  if (card.links && card.links.length > 0) {
    const linksWrap = document.createElement('div');
    const linksLabel = document.createElement('label');
    linksLabel.textContent = 'Links';
    const linksList = document.createElement('div');
    linksList.className = 'links-list';
    card.links.forEach(({text, href}) => {
      const a = document.createElement('a');
      a.href = href; a.target = '_blank';
      a.textContent = text || href;
      a.className = 'card-link';
      linksList.appendChild(a);
    });
    linksWrap.appendChild(linksLabel);
    linksWrap.appendChild(linksList);
    modal.appendChild(linksWrap);
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
  if (theme === 'glass') {
    document.body.classList.add('theme-glass');
    const btn = document.getElementById('glass-settings-btn');
    if (btn) btn.style.display = '';
    initGlassSettings();
  } else {
    document.body.classList.remove('theme-glass');
    const btn = document.getElementById('glass-settings-btn');
    if (btn) btn.style.display = 'none';
  }
  localStorage.setItem('specter-theme', theme);
}

function initTheme() {
  const saved = localStorage.getItem('specter-theme') || 'normal';
  const sel = document.getElementById('theme-select');
  if (sel) sel.value = saved;
  applyTheme(saved);
}

// ── Glass Settings ────────────────────────────────────────────────────────────
const GLASS_DEFAULTS = {
  frost:           2,
  distort:         77,
  noise:           0.008,
  'tint-color':    '#ffffff',
  'tint-opacity':  4,
  'shadow-color':  '#ffffff',
  'shadow-blur':   20,
  'shadow-spread': -5,
  'bg-url':        ''
};

function hexToRgb(hex) {
  const h = hex.replace('#', '');
  return {
    r: parseInt(h.slice(0, 2), 16),
    g: parseInt(h.slice(2, 4), 16),
    b: parseInt(h.slice(4, 6), 16)
  };
}

function loadGlassSettings() {
  const s = {};
  for (const [k, def] of Object.entries(GLASS_DEFAULTS)) {
    const stored = localStorage.getItem('specter-glass-' + k);
    s[k] = stored !== null ? (typeof def === 'number' ? parseFloat(stored) : stored) : def;
  }
  return s;
}

function saveGlassSettings(key, val) {
  localStorage.setItem('specter-glass-' + key, val);
}

function _rebuildTint(s) {
  const { r, g, b } = hexToRgb(s['tint-color']);
  const a = (s['tint-opacity'] / 100).toFixed(2);
  document.documentElement.style.setProperty('--glass-tint', `rgba(${r},${g},${b},${a})`);
  document.documentElement.style.setProperty('--glass-tint-opacity', a);
}

function _rebuildShadow(s) {
  const { r, g, b } = hexToRgb(s['shadow-color']);
  document.documentElement.style.setProperty(
    '--inner-highlight',
    `inset 0 1px ${s['shadow-blur']}px ${s['shadow-spread']}px rgba(${r},${g},${b},0.5),` +
    `inset 0 -1px 0 rgba(0,0,0,0.20)`
  );
}

function applyGlassSetting(key, val) {
  saveGlassSettings(key, val);
  const s = loadGlassSettings();
  const root = document.documentElement;

  if (key === 'frost') {
    root.style.setProperty('--glass-frost', parseFloat(val) + 'px');
    root.style.setProperty('--glass-frost-modal', Math.round(parseFloat(val) * 1.4) + 'px');
  } else if (key === 'distort') {
    document.getElementById('glass-displace')?.setAttribute('scale', val);
  } else if (key === 'noise') {
    document.getElementById('glass-noise')?.setAttribute('baseFrequency', `${val} ${val}`);
  } else if (key === 'tint-color' || key === 'tint-opacity') {
    _rebuildTint(s);
  } else if (key === 'shadow-color' || key === 'shadow-blur' || key === 'shadow-spread') {
    _rebuildShadow(s);
  } else if (key === 'bg-url') {
    if (val.trim()) {
      document.body.style.backgroundImage = `url(${JSON.stringify(val.trim())})`;
      document.body.style.backgroundSize = 'cover';
      document.body.style.backgroundPosition = 'center';
      document.body.style.backgroundAttachment = 'fixed';
    } else {
      document.body.style.backgroundImage = '';
    }
  }
}

function resetGlassSettings() {
  for (const k of Object.keys(GLASS_DEFAULTS)) localStorage.removeItem('specter-glass-' + k);
  initGlassSettings();
  showGlassSettings();
}

function initGlassSettings() {
  // Clear settings saved by old version (before tint-color/shadow keys existed)
  if (!localStorage.getItem('specter-glass-version')) {
    for (const k of Object.keys(GLASS_DEFAULTS)) localStorage.removeItem('specter-glass-' + k);
    localStorage.setItem('specter-glass-version', '3');
  }
  const s = loadGlassSettings();
  const root = document.documentElement;
  root.style.setProperty('--glass-frost', s.frost + 'px');
  root.style.setProperty('--glass-frost-modal', Math.round(s.frost * 1.4) + 'px');
  document.getElementById('glass-displace')?.setAttribute('scale', s.distort);
  document.getElementById('glass-noise')?.setAttribute('baseFrequency', `${s.noise} ${s.noise}`);
  _rebuildTint(s);
  _rebuildShadow(s);
  if (s['bg-url']) {
    document.body.style.backgroundImage = `url(${JSON.stringify(s['bg-url'])})`;
    document.body.style.backgroundSize = 'cover';
    document.body.style.backgroundPosition = 'center';
    document.body.style.backgroundAttachment = 'fixed';
  }
}

function showGlassSettings() {
  document.getElementById('glass-settings-backdrop')?.remove();
  const s = loadGlassSettings();

  const backdrop = document.createElement('div');
  backdrop.id = 'glass-settings-backdrop';
  backdrop.className = 'modal-backdrop';
  backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.remove(); });

  backdrop.innerHTML = `
    <div class="modal-wrapper">
      <div class="modal" style="width:360px" onclick="event.stopPropagation()">
        <div class="modal-header">
          <div class="modal-title">&#9881; Glass Settings</div>
          <button class="modal-close" onclick="document.getElementById('glass-settings-backdrop').remove()">&#10005;</button>
        </div>

        <div class="settings-section-label">Blur &amp; Distortion</div>

        <div class="settings-row">
          <label>Frost blur</label>
          <input type="range" min="0" max="30" value="${s.frost}"
                 oninput="applyGlassSetting('frost',this.value);this.nextElementSibling.textContent=this.value+'px'">
          <span class="val-display">${s.frost}px</span>
        </div>
        <div class="settings-row">
          <label>Distortion</label>
          <input type="range" min="0" max="200" value="${s.distort}"
                 oninput="applyGlassSetting('distort',this.value);this.nextElementSibling.textContent=this.value">
          <span class="val-display">${s.distort}</span>
        </div>
        <div class="settings-row">
          <label>Noise frequency</label>
          <input type="range" min="0" max="0.02" step="0.001" value="${s.noise}"
                 oninput="applyGlassSetting('noise',this.value);this.nextElementSibling.textContent=parseFloat(this.value).toFixed(3)">
          <span class="val-display">${parseFloat(s.noise).toFixed(3)}</span>
        </div>

        <div class="settings-section-label">Tint</div>

        <div class="settings-row">
          <label>Tint color</label>
          <input type="color" value="${s['tint-color']}"
                 oninput="applyGlassSetting('tint-color',this.value)">
        </div>
        <div class="settings-row">
          <label>Tint opacity</label>
          <input type="range" min="0" max="100" value="${s['tint-opacity']}"
                 oninput="applyGlassSetting('tint-opacity',this.value);this.nextElementSibling.textContent=this.value+'%'">
          <span class="val-display">${s['tint-opacity']}%</span>
        </div>

        <div class="settings-section-label">Shadow / Highlight</div>

        <div class="settings-row">
          <label>Shadow color</label>
          <input type="color" value="${s['shadow-color']}"
                 oninput="applyGlassSetting('shadow-color',this.value)">
        </div>
        <div class="settings-row">
          <label>Shadow blur</label>
          <input type="range" min="0" max="20" value="${s['shadow-blur']}"
                 oninput="applyGlassSetting('shadow-blur',this.value);this.nextElementSibling.textContent=this.value+'px'">
          <span class="val-display">${s['shadow-blur']}px</span>
        </div>
        <div class="settings-row">
          <label>Shadow spread</label>
          <input type="range" min="-10" max="10" value="${s['shadow-spread']}"
                 oninput="applyGlassSetting('shadow-spread',this.value);this.nextElementSibling.textContent=this.value+'px'">
          <span class="val-display">${s['shadow-spread']}px</span>
        </div>

        <div class="settings-section-label">Background</div>

        <div class="settings-row">
          <label>Image URL</label>
          <input type="text" placeholder="https://…" value="${s['bg-url']}"
                 oninput="applyGlassSetting('bg-url',this.value)">
        </div>

        <div class="modal-footer" style="margin-top:16px">
          <button class="btn-secondary" onclick="resetGlassSettings()">Reset defaults</button>
          <button class="btn-primary" onclick="document.getElementById('glass-settings-backdrop').remove()">Done</button>
        </div>
      </div>
    </div>`;

  document.body.appendChild(backdrop);
}
</script>
</body>
</html>
HTML;
}
