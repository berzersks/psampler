<?php
declare(strict_types=1);
$a = new PCMAnalyzer(8000, 20);
foreach (['fixtures', 'ring-fixtures', 'dsp-boundaries', 'dsp-workloads'] as $dir) {
    foreach (glob(__DIR__ . "/$dir/*.pcm") as $file) {
        $result = $a->analyze(file_get_contents($file));
        $full = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
        unset($result['ring']);
        $acoustic = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
        echo json_encode(['fixture' => "$dir/" . basename($file),
            'full' => $full, 'acoustic' => $acoustic], JSON_THROW_ON_ERROR), "\n";
    }
}
