<?php
declare(strict_types=1);

// Keep the upstream function untouched. Pass its path explicitly for reproducibility.
$source = $argv[1] ?? '/home/lotus/projetos/libspech/plugins/Utils/libspech/functionsTrunkController.php';
require_once $source;
$rows = [];
foreach (glob(__DIR__ . '/ring-fixtures/*.pcm') as $file) {
    $raw = \libspech\Sip\analyzeRingPcm(file_get_contents($file));
    unset($raw['version']);
    $rows[basename($file)] = $raw;
}
file_put_contents(__DIR__ . '/results/2026-09-25/ring-reference.json',
    json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($rows), " reference fixtures\n";
