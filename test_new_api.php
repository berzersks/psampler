<?php

// Tenta carregar a extensão
if (!extension_loaded('psampler')) {
    $extPath = realpath(__DIR__ . '/.libs/psampler.so');
    if (file_exists($extPath)) {
        dl($extPath);
    }
}

try {
    echo "Inicializando Resampler vazio...\n";
    $resampler = new Resampler();

    $pcmData = str_repeat("\0", 1024); // 512 amostras de silêncio (16-bit)

    echo "Chamando sample(44100 -> 16000)...\n";
    $res1 = $resampler->sample($pcmData, 44100, 16000);
    echo "Resultado 1: " . strlen($res1) . " bytes\n";

    echo "Chamando sample(44100 -> 8000)...\n";
    $res2 = $resampler->sample($pcmData, 44100, 8000);
    echo "Resultado 2: " . strlen($res2) . " bytes\n";

    echo "Chamando sample(44100 -> 16000) novamente (deve usar contexto anterior)...\n";
    $res3 = $resampler->sample($pcmData, 44100, 16000);
    echo "Resultado 3: " . strlen($res3) . " bytes\n";

    echo "Resetando...\n";
    $resampler->reset();

    echo "Chamando sample(44100 -> 16000) pós-reset...\n";
    $res4 = $resampler->sample($pcmData, 44100, 16000);
    echo "Resultado 4: " . strlen($res4) . " bytes\n";

    echo "Teste concluído.\n";
} catch (Throwable $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
