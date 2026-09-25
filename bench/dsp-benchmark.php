<?php

declare(strict_types=1);

use SpechSip\Rtp\Analysis\EarlyGreetingAnalysis;

require_once dirname(__DIR__, 2) . '/sipswoole/src/autoload.php';

$mode = $argv[1] ?? 'php';
$runs = max(1, (int)($argv[2] ?? 200));
if (!in_array($mode, ['php', 'native'], true)) throw new InvalidArgumentException('Mode must be php or native');
if ($mode === 'native' && !class_exists('PCMAnalyzer')) throw new RuntimeException('PCMAnalyzer unavailable');
$analyzer = $mode === 'native' ? new PCMAnalyzer(8000, 20) : null;
function rssKb(): int
{
    preg_match('/^VmRSS:\s+(\d+)/m', file_get_contents('/proc/self/status'), $matches);
    return (int)($matches[1] ?? 0);
}
function cpuMs(): float
{
    $r = getrusage();
    return 1000 * ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec'])
        + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
}
function percentile(array $sorted, float $p): float
{
    return $sorted[max(0, min(count($sorted) - 1, (int)ceil(count($sorted) * $p) - 1))];
}
$checksum = 0;
foreach ([1, 3, 5, 10, 15] as $seconds) {
    $files = glob(sprintf('%s/fixtures/%02ds_*.pcm', __DIR__, $seconds));
    if ($files === false || $files === []) throw new RuntimeException('Run generate-fixtures.php first');
    $inputs = array_map('file_get_contents', $files);
    $run = static function (string $pcm) use ($analyzer): array {
        return $analyzer === null ? EarlyGreetingAnalysis::analyze($pcm) : $analyzer->analyze($pcm);
    };
    for ($i = 0; $i < 25; $i++) {
        $r = $run($inputs[$i % count($inputs)]);
        $checksum += $r['active_audio_ms'] + $r['voice_ms'] + $r['longest_segment_ms'];
    }
    $initialRss = rssKb();
    $cpuStart = cpuMs();
    $wallStart = hrtime(true);
    $times = [];
    $peakRss = $initialRss;
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $r = $run($inputs[$i % count($inputs)]);
        $times[] = (hrtime(true) - $start) / 1e6;
        $checksum += $r['active_audio_ms'] + $r['voice_ms'] + $r['longest_segment_ms'];
        if (($i & 31) === 0) $peakRss = max($peakRss, rssKb());
    }
    $wallEnd = hrtime(true);
    $wallSeconds = ($wallEnd - $wallStart) / 1e9;
    $cpu = cpuMs() - $cpuStart;
    $max = max($times);
    $samplesDir = getenv('DSP_BENCH_SAMPLES_DIR');
    if (is_string($samplesDir) && $samplesDir !== '') {
        file_put_contents($samplesDir . '/' . getmypid() . '-' . $seconds . '.json',
            json_encode(['times_ms' => $times, 'wall_start_ns' => $wallStart,
                'wall_end_ns' => $wallEnd, 'cpu_ms' => $cpu], JSON_THROW_ON_ERROR));
    }
    sort($times, SORT_NUMERIC);
    echo json_encode(['mode' => $mode, 'duration_s' => $seconds, 'jobs' => $runs,
        'p50_ms' => percentile($times, 0.50), 'p95_ms' => percentile($times, 0.95),
        'p99_ms' => percentile($times, 0.99), 'max_ms' => $max,
        'cpu_ms_per_job' => $cpu / $runs, 'jobs_s' => $runs / $wallSeconds,
        'rss_initial_kb' => $initialRss, 'rss_peak_kb' => max($peakRss, rssKb()),
        'rss_final_kb' => rssKb(), 'checksum' => $checksum], JSON_THROW_ON_ERROR), PHP_EOL;
    fflush(STDOUT);
}
