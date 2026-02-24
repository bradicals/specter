<?php
declare(strict_types=1);

// ─── Shared helpers (included by server.php and mcp.php) ────────────────────

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

function validDate(mixed $val): string {
    if (!is_string($val) || $val === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return '';
    $d = \DateTime::createFromFormat('Y-m-d', $val);
    return ($d && $d->format('Y-m-d') === $val) ? $val : '';
}

/**
 * Determine the "done" column ID for a board.
 * Accepts a board array (with 'columns' key) or a columns array directly.
 * Checks for a column with isDone flag, then falls back to a column
 * whose id matches 'done' (case-insensitive). Returns empty string if neither exists.
 */
function doneColumnId(array $boardOrColumns): string {
    $columns = isset($boardOrColumns['columns']) ? $boardOrColumns['columns'] : $boardOrColumns;
    foreach ($columns as $col) {
        if (!empty($col['isDone'])) return $col['id'];
    }
    foreach ($columns as $col) {
        if (strtolower($col['id']) === 'done') return $col['id'];
    }
    return '';
}

/**
 * Check if a column ID represents the "done" column.
 * Returns false if the board has no identifiable done column.
 */
function isDoneColumn(string $colId, array $boardOrColumns): bool {
    $doneId = doneColumnId($boardOrColumns);
    if ($doneId === '') return false;
    return strtolower($colId) === strtolower($doneId);
}
