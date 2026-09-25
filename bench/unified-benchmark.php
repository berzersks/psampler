<?php
declare(strict_types=1);

$runs = max(1000, (int)($argv[1] ?? 1000));
$analyzer = new PCMAnalyzer(8000, 20);
function benchRss(): int {
    preg_match('/^VmRSS:\s+(\d+)/m', file_get_contents('/proc/self/status'), $matches);
    return (int)($matches[1] ?? 0);
}
function benchCpu(): float {
    $r = getrusage();
    return 1000 * ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) +
        ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
}
function benchPercentile(array $v, float $p): float {
    return $v[max(0, (int)ceil(count($v) * $p) - 1)];
}
$checksum = 0;
foreach ([1, 3, 5, 10, 15] as $seconds) {
    $files = glob(sprintf('%s/ring-fixtures/%02ds_*.pcm', __DIR__, $seconds));
    $inputs = array_map('file_get_contents', $files);
    for ($i = 0; $i < 25; $i++) {
        $r = $analyzer->analyze($inputs[$i % count($inputs)]);
        $checksum += $r['active_audio_ms'] + ($r['ring']['pulse_count'] ?? 0);
    }
    $rssInitial = benchRss();
    $cpuStart = benchCpu();
    $wallStart = hrtime(true);
    $times = [];
    $rssPeak = $rssInitial;
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $r = $analyzer->analyze($inputs[$i % count($inputs)]);
        $times[] = (hrtime(true) - $start) / 1e6;
        $checksum += $r['active_audio_ms'] + ($r['ring']['pulse_count'] ?? 0);
        if (($i & 31) === 0) $rssPeak = max($rssPeak, benchRss());
    }
    $wallSeconds = (hrtime(true) - $wallStart) / 1e9;
    $wallEnd = hrtime(true);
    $cpu = benchCpu() - $cpuStart;
    $samplesDir = getenv('UNIFIED_BENCH_SAMPLES_DIR');
    if (is_string($samplesDir) && $samplesDir !== '') {
        file_put_contents($samplesDir . '/' . getmypid() . '-' . $seconds . '.json',
            json_encode(['times_ms' => $times, 'wall_start_ns' => $wallStart,
                'wall_end_ns' => $wallEnd, 'cpu_ms' => $cpu], JSON_THROW_ON_ERROR));
    }
    sort($times, SORT_NUMERIC);
    echo json_encode(['duration_s' => $seconds, 'jobs' => $runs,
        'p50_ms' => benchPercentile($times, .50), 'p95_ms' => benchPercentile($times, .95),
        'p99_ms' => benchPercentile($times, .99), 'max_ms' => max($times),
        'cpu_ms_per_job' => $cpu / $runs, 'jobs_s' => $runs / $wallSeconds,
        'rss_initial_kb' => $rssInitial, 'rss_peak_kb' => max($rssPeak, benchRss()),
        'rss_final_kb' => benchRss(), 'checksum' => $checksum], JSON_THROW_ON_ERROR), PHP_EOL;
    fflush(STDOUT);
}
