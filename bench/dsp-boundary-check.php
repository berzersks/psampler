<?php
declare(strict_types=1);
$a = new PCMAnalyzer(8000, 20);
$dir = __DIR__ . '/dsp-boundaries';
$targets = json_decode(file_get_contents($dir . '/targets.json'), true, 512, JSON_THROW_ON_ERROR);
$failures = [];
$count = 0;
foreach (glob($dir . '/*.pcm') as $file) {
    $name = basename($file, '.pcm');
    $ring = $a->analyze(file_get_contents($file))['ring'];
    $f = $ring['frames'][0];
    $count++;
    if (isset($targets[$name])) {
        $field = str_starts_with($name, 'prominence_') ? 'prominence_db' : 'tone_purity_db';
        $targetField = $field === 'tone_purity_db' ? 'purity_db' : $field;
        if (abs($f[$field] - $targets[$name][$targetField]) > .025)
            $failures[] = [$name, $field, $f[$field], $targets[$name][$targetField]];
    }
    if (str_starts_with($name, 'purity_')) {
        $expected = (float)substr($name, 7) >= 1.5 ? 'ring' : 'other';
        if ($f['state'] !== $expected) $failures[] = [$name, 'state', $f['state'], $expected];
    }
    if (str_starts_with($name, 'level_')) {
        $expected = (float)substr($name, 6) > -48.0 ? 'ring' : 'silence';
        if ($f['state'] !== $expected) $failures[] = [$name, 'state', $f['state'], $expected];
    }
    if (str_starts_with($name, 'frequency_')) {
        $expected = in_array((int)substr($name, 10), [390, 465], true) ? 'other' : 'ring';
        if ($f['state'] !== $expected) $failures[] = [$name, 'state', $f['state'], $expected];
    }
    if (str_starts_with($name, 'cadence_')) {
        $gap = (int)substr($name, 8);
        $expected = abs($gap-5000) <= 600 ? 2 : 1;
        if ($ring['matched_pulse_count'] !== $expected)
            $failures[] = [$name, 'matched', $ring['matched_pulse_count'], $expected];
    }
}
echo json_encode(['fixtures' => $count, 'failures' => $failures], JSON_THROW_ON_ERROR), "\n";
exit($failures ? 1 : 0);
