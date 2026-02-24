<?php
declare(strict_types=1);

// ─── Data helpers ────────────────────────────────────────────────────────────

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
    // Legacy migration
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

/**
 * @return array|null Reference to the board, or null if an explicit boardId was not found.
 *                    When boardId is empty, falls back to boards[0].
 */
function &getBoardById(array &$data, string $boardId): ?array {
    if ($boardId === '') return $data['boards'][0];
    foreach ($data['boards'] as &$board) {
        if ($board['id'] === $boardId) return $board;
    }
    $null = null;
    return $null;
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

function uuid4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function slugify(string $label): string {
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '', $slug);
    return $slug ?: 'col' . substr(md5($label), 0, 6);
}

// ─── MCP response builders ───────────────────────────────────────────────────

function ok(mixed $id, string $text): array {
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => ['content' => [['type' => 'text', 'text' => $text]]],
    ];
}

function err(mixed $id, int $code, string $msg): array {
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => ['code' => $code, 'message' => $msg],
    ];
}

// ─── Tool definitions ────────────────────────────────────────────────────────

function toolDefinitions(): array {
    $boardIdProp = ['type' => 'string', 'description' => 'Board id (optional, defaults to first board)'];
    return [
        [
            'name'        => 'list_boards',
            'description' => 'List all boards with their ids and labels.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name'        => 'add_board',
            'description' => 'Create a new board with default columns.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'label' => ['type' => 'string', 'description' => 'Display name for the board'],
                ],
                'required' => ['label'],
            ],
        ],
        [
            'name'        => 'delete_board',
            'description' => 'Delete a board by id. Cannot delete the last board.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Board id to delete'],
                ],
                'required' => ['id'],
            ],
        ],
        [
            'name'        => 'list_cards',
            'description' => 'List all kanban cards, optionally filtered by column id.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'column'   => ['type' => 'string', 'description' => 'Column id to filter by (optional)'],
                ],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'add_card',
            'description' => 'Add a new card to the kanban board.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'ticketId' => ['type' => 'string', 'description' => 'Ticket identifier, e.g. QA-101'],
                    'title'    => ['type' => 'string', 'description' => 'Card title'],
                    'notes'    => ['type' => 'string', 'description' => 'Optional notes/description'],
                    'priority' => ['type' => 'string', 'enum' => ['high','medium','low'], 'description' => 'Priority level'],
                    'column'   => ['type' => 'string', 'description' => 'Column id to place the card in'],
                ],
                'required' => ['title', 'priority', 'column'],
            ],
        ],
        [
            'name'        => 'move_card',
            'description' => 'Move a card to a different column by ticketId.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'ticketId' => ['type' => 'string', 'description' => 'Ticket identifier'],
                    'column'   => ['type' => 'string', 'description' => 'Target column id'],
                ],
                'required' => ['ticketId', 'column'],
            ],
        ],
        [
            'name'        => 'update_card',
            'description' => 'Update fields on an existing card identified by ticketId.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'ticketId' => ['type' => 'string', 'description' => 'Ticket identifier'],
                    'title'    => ['type' => 'string'],
                    'notes'    => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => ['high','medium','low']],
                    'column'   => ['type' => 'string'],
                ],
                'required' => ['ticketId'],
            ],
        ],
        [
            'name'        => 'delete_card',
            'description' => 'Delete a card by ticketId.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'ticketId' => ['type' => 'string', 'description' => 'Ticket identifier'],
                ],
                'required' => ['ticketId'],
            ],
        ],
        [
            'name'        => 'list_columns',
            'description' => 'List all kanban columns with their ids and labels.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['board_id' => $boardIdProp],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'add_column',
            'description' => 'Add a new column to the kanban board.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'label'    => ['type' => 'string', 'description' => 'Display name for the column'],
                ],
                'required' => ['label'],
            ],
        ],
        [
            'name'        => 'rename_column',
            'description' => 'Rename a column by id.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'id'       => ['type' => 'string', 'description' => 'Column id'],
                    'label'    => ['type' => 'string', 'description' => 'New display name'],
                ],
                'required' => ['id', 'label'],
            ],
        ],
        [
            'name'        => 'delete_column',
            'description' => 'Delete a column. Cards in it are reassigned to the first remaining column.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board_id' => $boardIdProp,
                    'id'       => ['type' => 'string', 'description' => 'Column id to delete'],
                ],
                'required' => ['id'],
            ],
        ],
    ];
}

// ─── Tool implementations ────────────────────────────────────────────────────

function tool_list_boards(): string {
    $data = readData();
    $list = array_map(fn($b) => ['id' => $b['id'], 'label' => $b['label']], $data['boards']);
    return json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function tool_add_board(array $p): string {
    $label = trim($p['label'] ?? '');
    if ($label === '') return 'label is required.';
    $data = readData();
    $id = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    $board = ['id' => $id, 'label' => $label, 'columns' => defaultColumns(), 'cards' => []];
    $data['boards'][] = $board;
    writeData($data);
    return "Board added: " . json_encode(['id' => $id, 'label' => $label], JSON_UNESCAPED_UNICODE);
}

function tool_delete_board(array $p): string {
    $id   = $p['id'] ?? '';
    $data = readData();
    if (count($data['boards']) <= 1) return 'Cannot delete the last board.';
    $before = count($data['boards']);
    $data['boards'] = array_values(array_filter($data['boards'], fn($b) => $b['id'] !== $id));
    if (count($data['boards']) === $before) return "Board '{$id}' not found.";
    writeData($data);
    return "Deleted board '{$id}'.";
}

function tool_list_cards(array $p): string {
    $data  = readData();
    $board = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    $cards = $board['cards'];
    if (!empty($p['column'])) {
        $cards = array_values(array_filter($cards, fn($c) => $c['column'] === $p['column']));
    }
    return json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function tool_add_card(array $p): string {
    $data = readData();
    $board = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    $card = [
        'id'        => uuid4(),
        'ticketId'  => $p['ticketId']  ?? '',
        'title'     => $p['title']     ?? '',
        'notes'     => $p['notes']     ?? '',
        'priority'  => $p['priority']  ?? 'medium',
        'column'    => $p['column']    ?? ($board['columns'][0]['id'] ?? 'todo'),
        'createdAt' => date('c'),
    ];
    $board['cards'][] = $card;
    writeData($data);
    return 'Card added: ' . json_encode($card, JSON_UNESCAPED_UNICODE);
}

function tool_move_card(array $p): string {
    $data   = readData();
    $board  = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    $found  = false;
    $ticket = $p['ticketId'] ?? '';
    foreach ($board['cards'] as &$card) {
        if ($card['ticketId'] === $ticket) {
            $card['column'] = $p['column'];
            $found = true;
            break;
        }
    }
    unset($card);
    if (!$found) return "Card with ticketId '{$ticket}' not found.";
    writeData($data);
    return "Moved '{$ticket}' to column '{$p['column']}'.";
}

function tool_update_card(array $p): string {
    $data   = readData();
    $board  = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    $found  = false;
    $ticket = $p['ticketId'] ?? '';
    foreach ($board['cards'] as &$card) {
        if ($card['ticketId'] === $ticket) {
            foreach (['title','notes','priority','column'] as $f) {
                if (array_key_exists($f, $p)) $card[$f] = $p[$f];
            }
            $found = true;
            break;
        }
    }
    unset($card);
    if (!$found) return "Card with ticketId '{$ticket}' not found.";
    writeData($data);
    return "Updated card '{$ticket}'.";
}

function tool_delete_card(array $p): string {
    $data   = readData();
    $board  = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    $ticket = $p['ticketId'] ?? '';
    $before = count($board['cards']);
    $board['cards'] = array_values(array_filter($board['cards'], fn($c) => $c['ticketId'] !== $ticket));
    if (count($board['cards']) === $before) return "Card with ticketId '{$ticket}' not found.";
    writeData($data);
    return "Deleted card '{$ticket}'.";
}

function tool_list_columns(array $p): string {
    $data = readData();
    $board = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    return json_encode($board['columns'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function tool_add_column(array $p): string {
    $label = trim($p['label'] ?? '');
    if ($label === '') return 'label is required.';
    $data = readData();
    $board = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    $id   = slugify($label);
    $existing = array_column($board['columns'], 'id');
    $base = $id; $i = 2;
    while (in_array($id, $existing, true)) $id = $base . $i++;
    $col = ['id' => $id, 'label' => $label];
    $board['columns'][] = $col;
    writeData($data);
    return 'Column added: ' . json_encode($col, JSON_UNESCAPED_UNICODE);
}

function tool_rename_column(array $p): string {
    $data  = readData();
    $board = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
    $found = false;
    foreach ($board['columns'] as &$col) {
        if ($col['id'] === ($p['id'] ?? '')) {
            $col['label'] = $p['label'] ?? $col['label'];
            $found = true;
            break;
        }
    }
    unset($col);
    if (!$found) return "Column '{$p['id']}' not found.";
    writeData($data);
    return "Renamed column '{$p['id']}' to '{$p['label']}'.";
}

function tool_delete_column(array $p): string {
    $id   = $p['id'] ?? '';
    $data = readData();
    $board = &getBoardById($data, $p['board_id'] ?? '');
    if ($board === null) return "Board '{$p['board_id']}' not found.";
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
    return "Deleted column '{$id}'." . ($fallback ? " Cards moved to '{$fallback}'." : ' No remaining columns; cards deleted.');
}

// ─── Dispatcher ──────────────────────────────────────────────────────────────

function dispatch(array $req): ?array {
    $id     = $req['id']     ?? null;
    $method = $req['method'] ?? '';
    $params = $req['params'] ?? [];

    switch ($method) {
        case 'initialize':
            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => [
                    'protocolVersion' => '2024-11-05',
                    'serverInfo'      => ['name' => 'specter-kanban', 'version' => '1.0.0'],
                    'capabilities'    => ['tools' => new stdClass()],
                ],
            ];

        case 'notifications/initialized':
            return null; // notifications get no response

        case 'tools/list':
            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => ['tools' => toolDefinitions()],
            ];

        case 'tools/call':
            $name  = $params['name']      ?? '';
            $args  = $params['arguments'] ?? [];
            $text  = match($name) {
                'list_boards'   => tool_list_boards(),
                'add_board'     => tool_add_board($args),
                'delete_board'  => tool_delete_board($args),
                'list_cards'    => tool_list_cards($args),
                'add_card'      => tool_add_card($args),
                'move_card'     => tool_move_card($args),
                'update_card'   => tool_update_card($args),
                'delete_card'   => tool_delete_card($args),
                'list_columns'  => tool_list_columns($args),
                'add_column'    => tool_add_column($args),
                'rename_column' => tool_rename_column($args),
                'delete_column' => tool_delete_column($args),
                default         => "Unknown tool: {$name}",
            };
            return ok($id, $text);

        default:
            return err($id, -32601, "Method not found: {$method}");
    }
}

// ─── Main loop ───────────────────────────────────────────────────────────────

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $req = json_decode($line, true);
    if (!is_array($req)) continue;
    $res = dispatch($req);
    if ($res !== null) {
        fwrite(STDOUT, json_encode($res) . "\n");
        fflush(STDOUT);
    }
}
