<?php
// Shared helper: parse a downloaded filename into [board, date_iso] or null.
// Board types returned: 'council', 'planning', 'zoning'.
// Only returns a result for files that are minutes (not agendas, ordinances, etc.).

function parseMinutesFilename(string $basename): ?array
{
    // URL-decode (handles %20, %2C, etc.)
    $name = pathinfo(urldecode($basename), PATHINFO_FILENAME);

    if (!stripos($name, 'minutes')) return null;

    $board = null;
    if (stripos($name, 'council') !== false)       $board = 'council';
    elseif (stripos($name, 'planning') !== false)  $board = 'planning';
    elseif (preg_match('/zon+[gi]/i', $name))      $board = 'zoning'; // handles "Zoning" and "Zonng" typo
    else return null;

    // Normalise separators: comma → period, underscore → period
    $name = str_replace(['_', ','], '.', $name);

    // Format 1: MM.DD.YY at the start — "01.08.25 Planning Board Minutes"
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2})\b/', $name, $m)) {
        return [$board, '20'.$m[3].'-'.$m[1].'-'.$m[2]];
    }

    // Format 2: MM.DD YY at the start (space before year) — "12.14 23 Zoning Board Minutes"
    if (preg_match('/^(\d{2})\.(\d{2})\s+(\d{2})\b/', $name, $m)) {
        return [$board, '20'.$m[3].'-'.$m[1].'-'.$m[2]];
    }

    // Format 3: date at the end — "Council meeting minutes 01.02.24"
    if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{2})(?:\s+\S+)*$/', $name, $m)) {
        return [$board, '20'.$m[3].'-'.$m[1].'-'.$m[2]];
    }

    return null;
}
