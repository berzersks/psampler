<?php

declare(strict_types=1);

use SpechSip\Rtp\Analysis\EarlyGreetingAnalysis;
use SpechSip\Rtp\Analysis\MailboxAcousticClassifier;
use SpechSip\Rtp\Analysis\MailboxDecisionPolicy;
use SpechSip\Rtp\Analysis\NativeGreetingAnalysis;

require_once dirname(__DIR__, 2) . '/sipswoole/src/autoload.php';

$fixtures = glob(__DIR__ . '/fixtures/*.pcm');
if ($fixtures === false || $fixtures === []) throw new RuntimeException('Run generate-fixtures.php first');
if (!class_exists('PCMAnalyzer')) throw new RuntimeException('PCMAnalyzer is unavailable');
$goBinary = $argv[1] ?? '/tmp/pcmgo';
$goRows = [];
$command = escapeshellarg($goBinary) . ' -mode=results -dir=' . escapeshellarg(__DIR__ . '/fixtures');
exec($command, $lines, $code);
if ($code !== 0) throw new RuntimeException('Go result runner failed');
foreach ($lines as $line) {
    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $goRows[$row['fixture']] = $row['result'];
}
$analyzer = new PCMAnalyzer(8000, 20);
$ring = ['has_valid_ring_cadence' => false, 'has_ring_pattern' => false];
$diffs = [];
$rows = [];
$maxNumericDiff = ['php/c' => 0.0, 'c/go' => 0.0];
foreach ($fixtures as $file) {
    $pcm = file_get_contents($file);
    $php = EarlyGreetingAnalysis::analyze($pcm);
    $c = NativeGreetingAnalysis::fromFeatures($analyzer->analyze($pcm));
    $go = $goRows[basename($file)] ?? throw new RuntimeException('Missing Go result');
    $go['started_at_ms'] = $go['started_at_ms'] < 0 ? null : $go['started_at_ms'];
    $go['last_voice_ms'] = $go['last_voice_ms'] < 0 ? null : $go['last_voice_ms'];
    $go = NativeGreetingAnalysis::fromFeatures($go);
    $row = ['fixture' => basename($file), 'php_signal' => $php['signal'], 'c_signal' => $c['signal'],
        'go_signal' => $go['signal'], 'php_voice_ms' => $php['voice_ms'], 'c_voice_ms' => $c['voice_ms'],
        'go_voice_ms' => $go['voice_ms'], 'php_longest_ms' => $php['longest_segment_ms'],
        'c_longest_ms' => $c['longest_segment_ms'], 'go_longest_ms' => $go['longest_segment_ms']];
    foreach (['php' => $php, 'c' => $c, 'go' => $go] as $label => $features) {
        $row[$label . '_class'] = MailboxAcousticClassifier::classify($ring, $features);
        $row[$label . '_decision'] = MailboxDecisionPolicy::decide($ring, $features, 'unknown')['mailbox_detected'];
    }
    foreach (['signal', 'voice_ms', 'longest_segment_ms', 'segment_count', 'tone_ms', 'noise_ms',
        'music_ms', 'active_audio_ms', 'silence_ms', 'started_at_ms', 'last_voice_ms', 'pause_count'] as $key) {
        if (($php[$key] ?? null) !== ($c[$key] ?? null)) $diffs[] = [basename($file), 'php/c', $key, $php[$key] ?? null, $c[$key] ?? null];
        if (($c[$key] ?? null) !== ($go[$key] ?? null)) $diffs[] = [basename($file), 'c/go', $key, $c[$key] ?? null, $go[$key] ?? null];
    }
    foreach (['rms_mean_dbfs', 'rms_std_db', 'crossing_mean', 'crossing_std',
        'crossing_interval_cv', 'normalized_difference', 'dominant_tone_strength',
        'spectral_variability', 'spectral_entropy'] as $key) {
        $p = $php['signal_features'][$key] ?? 0.0;
        $n = $c['signal_features'][$key] ?? 0.0;
        $g = $go['signal_features'][$key] ?? 0.0;
        $maxNumericDiff['php/c'] = max($maxNumericDiff['php/c'], abs($p - $n));
        $maxNumericDiff['c/go'] = max($maxNumericDiff['c/go'], abs($n - $g));
        if (abs($p - $n) > 0.002) $diffs[] = [basename($file), 'php/c', $key, $p, $n];
        if (abs($n - $g) > 0.002) $diffs[] = [basename($file), 'c/go', $key, $n, $g];
    }
    foreach (['php/c' => [$php, $c], 'c/go' => [$c, $go]] as $pair => [$left, $right]) {
        if (count($left['segments']) !== count($right['segments'])) {
            $diffs[] = [basename($file), $pair, 'segments count', count($left['segments']), count($right['segments'])];
            continue;
        }
        foreach ($left['segments'] as $i => $segment) {
            $other = $right['segments'][$i];
            foreach (['started_at_ms', 'ended_at_ms', 'duration_ms', 'signal'] as $key) {
                if ($segment[$key] !== $other[$key]) {
                    $diffs[] = [basename($file), $pair, "segments.{$i}.{$key}", $segment[$key], $other[$key]];
                }
            }
            if ($segment['signal'] === 'voice_like') {
                foreach (['vad_voice_ms', 'vad_longest_ms'] as $key) {
                    if ($segment[$key] !== $other[$key]) {
                        $diffs[] = [basename($file), $pair, "segments.{$i}.{$key}", $segment[$key], $other[$key]];
                    }
                }
            }
            foreach (['rms_mean_dbfs', 'rms_std_db', 'crossing_mean', 'crossing_std',
                'crossing_interval_cv', 'normalized_difference', 'dominant_tone_strength',
                'spectral_variability', 'spectral_entropy'] as $key) {
                $a = $segment['features'][$key] ?? 0.0;
                $b = $other['features'][$key] ?? 0.0;
                $maxNumericDiff[$pair] = max($maxNumericDiff[$pair], abs($a - $b));
                if (abs($a - $b) > 0.002) {
                    $diffs[] = [basename($file), $pair, "segments.{$i}.features.{$key}", $a, $b];
                }
            }
        }
    }
    if ($row['php_decision'] !== $row['c_decision'] || $row['c_decision'] !== $row['go_decision']) {
        $diffs[] = [basename($file), 'decision', $row['php_decision'], $row['c_decision'], $row['go_decision']];
    }
    if ($row['php_class'] !== $row['c_class'] || $row['c_class'] !== $row['go_class']) {
        $diffs[] = [basename($file), 'classifier', $row['php_class'], $row['c_class'], $row['go_class']];
    }
    $canonical = static function (array $v): string {
        $parts = [$v['signal'], $v['voice_ms'], $v['longest_segment_ms'], $v['active_audio_ms']];
        foreach ($v['segments'] as $s) {
            $parts[] = [$s['started_at_ms'], $s['ended_at_ms'], $s['signal'],
                $s['features']['dominant_tone_strength'] ?? 0.0];
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    };
    $row['result_digest'] = $canonical($c);
    if ($canonical($c) !== $canonical($go)) $diffs[] = [basename($file), 'c/go', 'result_digest'];
    $rows[] = $row;
}
$performance = null;
if (in_array('--bench', $argv, true)) {
    $performance = [];
    $phpCommand = escapeshellarg(PHP_BINARY);
    $module = getenv('PSAMPLER_EXTENSION_PATH');
    if (is_string($module) && $module !== '') {
        $phpCommand .= ' -d ' . escapeshellarg('extension=' . $module);
    }
    foreach (['php', 'native'] as $mode) {
        $command = $phpCommand . ' ' . escapeshellarg(__DIR__ . '/dsp-benchmark.php')
            . ' ' . $mode . ' 200';
        exec($command, $output, $code);
        if ($code !== 0) throw new RuntimeException("{$mode} shadow benchmark failed");
        $performance[$mode] = array_map(static fn(string $line): array =>
            json_decode($line, true, 512, JSON_THROW_ON_ERROR), $output);
        $output = [];
    }
    exec(escapeshellarg($goBinary) . ' -mode=bench -dir=' . escapeshellarg(__DIR__ . '/fixtures')
        . ' -runs=200 -workers=1', $output, $code);
    if ($code !== 0) throw new RuntimeException('Go shadow benchmark failed');
    $performance['go'] = array_map(static fn(string $line): array =>
        json_decode($line, true, 512, JSON_THROW_ON_ERROR), $output);
}
$report = ['fixtures' => count($rows), 'differences' => count($diffs),
    'max_numeric_difference' => $maxNumericDiff,
    'first_differences' => array_slice($diffs, 0, 100), 'rows' => $rows,
    'performance' => $performance];
file_put_contents(__DIR__ . '/shadow-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'fixtures=', count($rows), ' differences=', count($diffs), PHP_EOL;
foreach (array_slice($diffs, 0, 30) as $diff) echo json_encode($diff, JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($diffs === [] ? 0 : 1);
