<?php
// Compare /downloaded against web/files/{council,planning,zoning} and report
// which minutes PDFs need to be copied, converting filenames to YYYY-MM-DD.pdf.
//
// Usage:
//   php sync_downloaded.php            # dry run (default)
//   php sync_downloaded.php --execute  # actually copy files

$execute = in_array('--execute', $argv ?? []);

define('DOWNLOADED_DIR', __DIR__.'/../downloaded');
define('FILES_DIR',      __DIR__.'/../web/files');

require(__DIR__.'/minutes_filename.php');

// --- collect and deduplicate ---
$candidates = []; // target_path => [board, date, source_file, already_exists]
$skipped    = []; // files that contain "minutes" but couldn't be parsed
$non_minutes = 0;

$files = glob(DOWNLOADED_DIR.'/*.pdf') ?: [];
$files = array_merge($files, glob(DOWNLOADED_DIR.'/*.PDF') ?: []);

foreach ($files as $src) {
    $basename = basename($src);

    // Quick skip: no "minutes" in name at all
    if (!stripos($basename, 'minutes')) {
        $non_minutes++;
        continue;
    }

    $parsed = parseMinutesFilename($basename);
    if ($parsed === null) {
        $skipped[] = $basename;
        continue;
    }

    [$board, $date] = $parsed;
    $target = FILES_DIR.'/'.$board.'/'.$date.'.pdf';

    // Deduplicate: if two source files map to the same target (e.g. . vs _ variant),
    // prefer the one already recorded, or keep the first seen.
    if (!isset($candidates[$target])) {
        $candidates[$target] = [
            'board'   => $board,
            'date'    => $date,
            'source'  => $src,
            'exists'  => file_exists($target),
        ];
    }
}

// --- split into new vs already present ---
$new     = array_filter($candidates, fn($c) => !$c['exists']);
$present = array_filter($candidates, fn($c) =>  $c['exists']);

// --- report ---
$mode = $execute ? 'EXECUTE' : 'DRY RUN';
echo "=== sync_downloaded.php — $mode ===\n\n";

echo "Already in web/files: ".count($present)."\n";
echo "Non-minutes files skipped: $non_minutes\n";
echo "Minutes files unparseable: ".count($skipped)."\n\n";

if ($skipped) {
    echo "--- UNPARSEABLE (contains 'minutes' but no recognised date/board) ---\n";
    foreach ($skipped as $f) echo "  $f\n";
    echo "\n";
}

if (empty($new)) {
    echo "Nothing new to copy.\n";
    exit(0);
}

// Sort by date then board for readable output
uasort($new, fn($a, $b) => $a['date'] <=> $b['date'] ?: $a['board'] <=> $b['board']);

echo "--- NEW FILES (".count($new).") ---\n";
printf("%-10s  %-12s  %s\n", 'BOARD', 'DATE', 'SOURCE FILE');
printf("%s\n", str_repeat('-', 70));
foreach ($new as $target => $c) {
    printf("%-10s  %-12s  %s\n", $c['board'], $c['date'], basename($c['source']));
}
echo "\n";

if (!$execute) {
    echo "Run with --execute to copy these files.\n";
    exit(0);
}

// --- execute ---
$copied = 0;
$errors = 0;
echo "--- COPYING ---\n";
foreach ($new as $target => $c) {
    $dir = dirname($target);
    if (!is_dir($dir)) {
        echo "ERROR: target directory does not exist: $dir\n";
        $errors++;
        continue;
    }
    if (copy($c['source'], $target)) {
        echo "  copied: ".$c['board'].'/'.$c['date'].'.pdf'."  ←  ".basename($c['source'])."\n";
        $copied++;
    } else {
        echo "  ERROR copying ".basename($c['source'])." → $target\n";
        $errors++;
    }
}

echo "\nDone. Copied: $copied  Errors: $errors\n";
if ($copied > 0) {
    echo "Run scripts/import_files.php to register the new files in the database.\n";
}
