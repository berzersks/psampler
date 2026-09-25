<?php
declare(strict_types=1);

// Expected acoustic evidence from the deterministic generator, independent of
// the upstream PHP algorithm. These are deliberately not call/policy labels.
$expected = [];
foreach ([1, 3, 5, 10, 15] as $seconds) {
    $tag = sprintf('%02ds_', $seconds);
    $expected[$tag . 'silence.pcm'] = [0, 0, null];
    foreach (['voice_only', 'noise_only', 'music_only'] as $kind)
        $expected[$tag . $kind . '.pcm'] = [0, 0, 0];
    $expected[$tag . '425_continuous.pcm'] = [1, 1, null];
    $count = (int)ceil($seconds / 5);
    $expected[$tag . 'cadence.pcm'] = [$count, $count, null];
}
foreach ([
    'pulse_short' => [0, 0, null],
    'pulse_long' => [1, 1, null],
    'two_pulses' => [2, 2, null],
    'three_pulses' => [3, 3, null],
    'jitter' => [3, 3, null],
    'outside_tolerance' => [3, 1, null],
    'short_gap' => [2, 1, null],
    'long_gap' => [2, 1, null],
    'late_start' => [1, 1, null],
    'early_end' => [1, 1, null],
    'ring_voice' => [1, 1, 2000],
    'ring_noise' => [1, 1, 2000],
    'ring_music' => [1, 1, 2000],
    'ring_other_tone' => [1, 1, 2000],
    'short_disturbance' => [1, 1, 2000],
    'long_disturbance' => [1, 1, 2000],
    'partial_ring' => [0, 0, 500],
    'interrupted_by_voice' => [1, 1, 1500],
    'low_amplitude' => [1, 1, null],
    'high_amplitude' => [1, 1, null],
    'dc_offset' => [1, 1, null],
    'clipping' => [1, 1, null],
    'noisy_ring' => [1, 1, 1500],
    'shifted_395' => [1, 1, null],
    'shifted_450' => [1, 1, null],
    'shifted_460' => [1, 1, null],
] as $name => $value) $expected[$name . '.pcm'] = $value;
$analyzer = new PCMAnalyzer(8000, 20);
$failures = [];
foreach ($expected as $name => [$pulses, $matched, $disturbance]) {
    $path = __DIR__ . '/ring-fixtures/' . $name;
    if (!is_file($path)) throw new RuntimeException("Missing {$name}");
    $ring = $analyzer->analyze(file_get_contents($path))['ring'];
    $actual = [$ring['pulse_count'], $ring['matched_pulse_count'], $ring['disturbance_at_ms']];
    if ($actual !== [$pulses, $matched, $disturbance]) $failures[] = [$name, [$pulses, $matched, $disturbance], $actual];
    if ($ring['has_valid_cadence'] !== ($matched >= 2)) $failures[] = [$name, 'cadence'];
}
$files = glob(__DIR__ . '/ring-fixtures/*.pcm');
if (count($files) !== count($expected)) throw new RuntimeException('Ground truth does not cover every fixture');
$report = ['fixtures' => count($expected), 'failures' => count($failures), 'first_failures' => array_slice($failures, 0, 20)];
$output = getenv('PSAMPLER_GROUND_TRUTH_OUTPUT') ?: __DIR__ . '/results/2026-09-25/ring-ground-truth.json';
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($failures ? 1 : 0);
