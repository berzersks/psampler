<?php
declare(strict_types=1);
$dir = $argv[1] ?? __DIR__ . '/dsp-workloads';
$runs = max(100, (int)($argv[2] ?? 1000));
$a = new PCMAnalyzer(8000, 20);
function cpuMs(): float {
    $r = getrusage();
    return 1000 * ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) +
        ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
}
$sink = 0;
foreach (glob($dir . '/*.pcm') as $file) {
    $pcm = file_get_contents($file);
    for ($i = 0; $i < 25; $i++) {
        $r = $a->analyze($pcm);
        $sink += $r['active_audio_ms'] + $r['ring']['pulse_count'];
    }
    $times = [];
    $cs = cpuMs();
    $ws = hrtime(true);
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $r = $a->analyze($pcm);
        $times[] = (hrtime(true) - $t) / 1e6;
        $sink += $r['active_audio_ms'] + $r['ring']['pulse_count'];
    }
    $elapsed = (hrtime(true) - $ws) / 1e9;
    $used = cpuMs() - $cs;
    sort($times, SORT_NUMERIC);
    echo json_encode(['category' => basename($file, '.pcm'), 'jobs' => $runs,
        'cpu_ms_per_job' => $used / $runs, 'p50_ms' => $times[(int)ceil($runs*.5)-1],
        'p95_ms' => $times[(int)ceil($runs*.95)-1],
        'p99_ms' => $times[(int)ceil($runs*.99)-1],
        'jobs_s' => $runs / $elapsed, 'rss_peak_kb' => getrusage()['ru_maxrss'],
        'sink' => $sink], JSON_THROW_ON_ERROR), "\n";
    flush();
}
