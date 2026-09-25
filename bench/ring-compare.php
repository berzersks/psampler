<?php
declare(strict_types=1);

$reference = json_decode(file_get_contents(__DIR__ . '/results/2026-09-25/ring-reference.json'), true, 512, JSON_THROW_ON_ERROR);
$analyzer = new PCMAnalyzer(8000, 20);
$go = [];
$command = escapeshellarg($argv[1] ?? '/tmp/pcmgo-unified') . ' -mode=results -dir=' . escapeshellarg(__DIR__ . '/ring-fixtures');
exec($command, $lines, $status);
if ($status !== 0) throw new RuntimeException('Go runner failed');
foreach ($lines as $line) {
    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $go[$row['fixture']] = $row['result']['ring'];
}
$oldFrameFields = ['index', 'start_ms', 'end_ms', 'rms_dbfs',
    'ring_frequency_hz', 'ring_level_dbfs', 'prominence_db'];
$newFields = ['duration_ms', 'pulse_count', 'matched_pulse_count', 'has_ring_pattern',
    'has_valid_cadence', 'ring_from_start_to_end', 'reason', 'confidence',
    'pulses', 'matched_pulses', 'periods_ms', 'disturbance_at_ms',
    'disturbance_duration_ms'];
$failures = [];
$changes = [];
$nativeRows = [];
$changedFrames = 0;
$maxNumericDifference = 0.0;
foreach ($reference as $name => $old) {
    $new = $analyzer->analyze(file_get_contents(__DIR__ . '/ring-fixtures/' . $name))['ring'];
    $nativeRows[$name] = $new;
    $g = $go[$name] ?? throw new RuntimeException("Missing Go result for {$name}");
    if (count($old['frames']) !== count($new['frames'])) $failures[] = [$name, 'frame_count'];
    $localChanges = 0;
    foreach ($old['frames'] as $i => $frame) {
        $actual = $new['frames'][$i] ?? [];
        foreach ($oldFrameFields as $field) {
            $delta = abs(($frame[$field] ?? 0) - ($actual[$field] ?? 0));
            $maxNumericDifference = max($maxNumericDifference, $delta);
            if ($delta > 0.011) $failures[] = [$name, "frame.{$i}.{$field}", $delta];
        }
        if ($frame['state'] !== ($actual['state'] ?? null)) {
            $localChanges++;
            $purity = $actual['tone_purity_db'] ?? -999;
            $ac = $actual['ac_rms_dbfs'] ?? 999;
            $explained = ($frame['state'] === 'ring' && $actual['state'] === 'other' && $purity < 1.5)
                || ($frame['state'] === 'other' && $actual['state'] === 'silence' && $ac <= -50);
            if (!$explained) $failures[] = [$name, "frame.{$i}.state", $frame['state'], $actual['state'] ?? null];
        }
        foreach ($actual as $field => $value) {
            $goValue = $g['frames'][$i][$field] ?? null;
            if (is_numeric($value) && is_numeric($goValue)) {
                if (abs($value - $goValue) > 0.011) $failures[] = [$name, "c_go.frame.{$i}.{$field}"];
            } elseif ($value !== $goValue) $failures[] = [$name, "c_go.frame.{$i}.{$field}"];
        }
    }
    foreach ($newFields as $field) {
        $left = $new[$field];$right = $g[$field] ?? null;
        if ($field === 'confidence' && is_numeric($right)) {
            if (abs($left - $right) > 0.00001) $failures[] = [$name, "c_go.{$field}"];
        } elseif ($left !== $right) $failures[] = [$name, "c_go.{$field}"];
    }
    if ($localChanges === 0) {
        foreach (['has_ring_pattern', 'ring_from_start_to_end', 'reason', 'confidence',
            'pulses', 'matched_pulses', 'periods_ms', 'disturbance_at_ms',
            'disturbance_duration_ms'] as $field) {
            if ($new[$field] != $old[$field]) $failures[] = [$name, "unchanged_frame_result.{$field}"];
        }
    } else {
        $changedFrames += $localChanges;
        $changes[] = ['fixture' => $name, 'changed_frames' => $localChanges,
            'pulses_before' => count($old['pulses']), 'pulses_after' => $new['pulse_count'],
            'matched_before' => count($old['matched_pulses']), 'matched_after' => $new['matched_pulse_count'],
            'disturbance_before' => $old['disturbance_at_ms'],
            'disturbance_after' => $new['disturbance_at_ms'],
            'confidence_before' => $old['confidence'], 'confidence_after' => $new['confidence']];
    }
}
$report = ['fixtures' => count($reference), 'changed_fixtures' => count($changes),
    'unchanged_fixtures' => count($reference) - count($changes),
    'changed_frame_states' => $changedFrames,
    'max_unchanged_frame_numeric_difference' => $maxNumericDifference,
    'unexpected_differences' => count($failures),
    'changes' => $changes, 'first_failures' => array_slice($failures, 0, 30)];
file_put_contents(__DIR__ . '/results/2026-09-25/ring-comparison.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents(__DIR__ . '/results/2026-09-25/ring-native.json',
    json_encode($nativeRows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(array_diff_key($report, ['changes' => true]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($failures ? 1 : 0);
