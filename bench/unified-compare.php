<?php
declare(strict_types=1);

$goBinary = $argv[1] ?? '/tmp/pcmgo-unified';
$analyzer = new PCMAnalyzer(8000, 20);
$keys = ['duration_ms', 'active_audio_ms', 'silence_ms', 'voice_ms', 'longest_segment_ms',
    'segment_count', 'first_voice_segment_ms', 'pause_count', 'mean_pause_ms',
    'started_at_ms', 'last_voice_ms', 'tone_ms', 'noise_ms', 'music_ms',
    'voice_ratio', 'silence_ratio', 'signal'];
$featureKeys = ['signal', 'active_frames', 'rms_mean_dbfs', 'rms_std_db',
    'crossing_mean', 'crossing_std', 'crossing_interval_cv',
    'normalized_difference', 'first_active_ms', 'dominant_tone_strength',
    'spectral_variability', 'spectral_entropy'];
$pulseKeys = ['start_ms', 'end_ms', 'duration_ms', 'tone_frames'];
$ringKeys = ['duration_ms', 'pulse_count', 'matched_pulse_count', 'has_ring_pattern',
    'has_valid_cadence', 'ring_from_start_to_end', 'disturbance_at_ms',
    'disturbance_duration_ms', 'confidence', 'reason'];
$frameKeys = ['index', 'start_ms', 'end_ms', 'state', 'rms_dbfs', 'ac_rms_dbfs',
    'ring_frequency_hz', 'ring_level_dbfs', 'prominence_db', 'tone_purity_db'];
$pick = static function (array $value, array $names): array {
    $floating = ['mean_pause_ms', 'voice_ratio', 'silence_ratio', 'rms_mean_dbfs',
        'rms_std_db', 'crossing_mean', 'crossing_std', 'crossing_interval_cv',
        'normalized_difference', 'dominant_tone_strength', 'spectral_variability',
        'spectral_entropy', 'confidence', 'rms_dbfs', 'ac_rms_dbfs', 'ring_frequency_hz',
        'ring_level_dbfs', 'prominence_db', 'tone_purity_db'];
    $out = [];
    foreach ($names as $name) {
        $v = $value[$name] ?? null;
        if (in_array($name, ['started_at_ms', 'last_voice_ms'], true) && $v === -1) $v = null;
        if ($v === null && in_array($name, ['dominant_tone_strength', 'spectral_variability', 'spectral_entropy'], true)) $v = 0.0;
        $out[$name] = in_array($name, $floating, true) && is_numeric($v) ? sprintf('%.4f', (float)$v) : $v;
    }
    return $out;
};
$canonical = static function (array $r) use ($pick, $keys, $featureKeys, $pulseKeys, $ringKeys, $frameKeys): array {
    $out = $pick($r, $keys);
    $out['signal_features'] = $pick($r['signal_features'], $featureKeys);
    $out['segments'] = [];
    foreach ($r['segments'] as $segment) {
        $s = $pick($segment, ['started_at_ms', 'ended_at_ms', 'duration_ms', 'signal', 'vad_voice_ms', 'vad_longest_ms']);
        $s['features'] = $pick($segment['features'], $featureKeys);
        $out['segments'][] = $s;
    }
    $ring = $r['ring'];
    $out['ring'] = $pick($ring, $ringKeys);
    foreach (['pulses', 'matched_pulses'] as $key)
        $out['ring'][$key] = array_map(static fn($p) => $pick($p, $pulseKeys), $ring[$key]);
    $out['ring']['periods_ms'] = $ring['periods_ms'];
    $out['ring']['frames'] = array_map(static fn($f) => $pick($f, $frameKeys), $ring['frames']);
    return $out;
};
$differences = [];
$count = 0;
$folders = array_slice($argv, 2) ?: ['fixtures', 'ring-fixtures'];
foreach ($folders as $folder) {
    $goRows = [];
    $command = escapeshellarg($goBinary) . ' -mode=results -dir=' . escapeshellarg(__DIR__ . '/' . $folder);
    exec($command, $lines, $status);
    if ($status !== 0) throw new RuntimeException("Go failed on {$folder}");
    foreach ($lines as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $goRows[$row['fixture']] = $row['result'];
    }
    $lines = [];
    foreach (glob(__DIR__ . '/' . $folder . '/*.pcm') as $file) {
        $name = basename($file);
        $c = $canonical($analyzer->analyze(file_get_contents($file)));
        $g = $canonical($goRows[$name]);
        $cd = hash('sha256', json_encode($c, JSON_THROW_ON_ERROR));
        $gd = hash('sha256', json_encode($g, JSON_THROW_ON_ERROR));
        if ($cd !== $gd) $differences[] = [$folder, $name, $cd, $gd];
        $count++;
    }
}
$result = ['fixtures' => $count, 'digest_differences' => count($differences),
    'first_differences' => array_slice($differences, 0, 20)];
$output = getenv('PSAMPLER_COMPARE_OUTPUT') ?: __DIR__ . '/results/2026-09-25/c-go-comparison.json';
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($differences ? 1 : 0);
