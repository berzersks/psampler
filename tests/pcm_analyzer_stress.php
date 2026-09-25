<?php

declare(strict_types=1);

if (!class_exists('PCMAnalyzer')) throw new RuntimeException('PCMAnalyzer unavailable');
function rssKb(): int
{
    preg_match('/^VmRSS:\s+(\d+)/m', file_get_contents('/proc/self/status'), $matches);
    return (int)($matches[1] ?? 0);
}
$initial = rssKb();
$peak = $initial;
$seed = 0x12345678;
$analyzers = [new PCMAnalyzer(), new PCMAnalyzer()];
$short = ["", "\0\0", "\xff\x7f", "\x00\x80", "\xff\xff", str_repeat("\0", 318), str_repeat("\xff", 320)];
$long = file_get_contents(dirname(__DIR__) . '/bench/fixtures/15s_mixed_noise_voice.pcm');
if ($long === false) throw new RuntimeException('Generate fixtures first');
for ($i = 0; $i < 100000; $i++) {
    $seed = ($seed * 1664525 + 1013904223) & 0xffffffff;
    $bytes = $short[$i % count($short)];
    if (($i % 97) === 0) {
        $n = ($seed % 16000) * 2;
        $bytes = substr($long, 0, $n);
    }
    $a = $analyzers[$i % 2];
    $r = $a->analyze($bytes);
    if ($r['duration_ms'] !== intdiv(strlen($bytes), 16)) throw new RuntimeException('duration mismatch');
    if (($i % 250) === 0) {
        $r = $a->analyze($long);
        if ($r['duration_ms'] !== 15000) throw new RuntimeException('long duration mismatch');
        $peak = max($peak, rssKb());
    }
    if (($i % 1000) === 0) {
        $temporary = new PCMAnalyzer();
        $temporary->analyze($bytes);
        unset($temporary);
    }
    if (($i % 2000) === 0) {
        try { $a->analyze(substr($bytes, 0, 1)); } catch (ValueError) {}
    }
}
foreach (["\0\0", "\xff\x7f", "\0\x80", "\xff\xff"] as $extreme) {
    $analyzers[0]->analyze(str_repeat($extreme, 8000));
}
for ($i = 0; $i < 1024; $i++) {
    $size = ($i * 7919) % 240003;
    $bytes = $size === 0 ? '' : random_bytes($size);
    try {
        $result = $analyzers[$i % 2]->analyze($bytes);
        if ($result['duration_ms'] !== intdiv($size, 16)) throw new RuntimeException('fuzz duration mismatch');
    } catch (ValueError $error) {
        if ($size % 2 === 0 && $size <= 240000
            && !str_contains($error->getMessage(), 'segment limit')) throw $error;
    }
    if (($i % 16) === 0) $peak = max($peak, rssKb());
}
$final = rssKb();
echo json_encode(['calls' => 100000, 'initial_rss_kb' => $initial, 'peak_rss_kb' => $peak,
    'final_rss_kb' => $final, 'delta_rss_kb' => $final - $initial,
    'fuzz_cases' => 1024], JSON_THROW_ON_ERROR), PHP_EOL;
