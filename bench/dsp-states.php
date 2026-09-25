<?php
declare(strict_types=1);
$a = new PCMAnalyzer(8000, 20);
foreach (['fixtures', 'ring-fixtures', 'dsp-boundaries', 'dsp-workloads'] as $dir) {
    foreach (glob(__DIR__ . "/$dir/*.pcm") as $file) {
        $r = $a->analyze(file_get_contents($file))['ring'];
        $evidence = ['states' => array_column($r['frames'], 'state'),
            'pulses' => $r['pulses'], 'matched' => $r['matched_pulses'],
            'periods' => $r['periods_ms'], 'disturbance' => $r['disturbance_at_ms'],
            'confidence' => $r['confidence']];
        echo json_encode(['fixture' => "$dir/" . basename($file),
            'evidence' => $evidence], JSON_THROW_ON_ERROR), "\n";
    }
}
