<?php

if (!extension_loaded('psampler')) {
    dl('./modules/psampler.so');
}

echo "=== Teste de Tamanho de Pacote (Packet Size) ===\n\n";

function testPacketSize($size) {
    echo "Testando tamanho: $size bytes (" . ($size / 2) . " amostras)\n";
    
    // Testa via construtor
    $resampler = new Resampler(44100, 16000, $size);
    
    $dummyInput = str_repeat("\0", 100); // 50 amostras
    $totalOutput = 0;
    $packets = 0;
    
    echo "Enviando pequenos chunks de 100 bytes...\n";
    for ($i = 0; $i < 50; $i++) {
        $out = $resampler->process($dummyInput);
        if ($resampler->returnEmpty() === false) {
            $packets++;
            $outLen = strlen($out);
            $totalOutput += $outLen;
            echo "  [Pacote $packets] Recebido: $outLen bytes\n";
            // No cenário real o usuário pegaria esses bytes
        }
    }
    
    echo "Total de pacotes válidos: $packets\n";
    echo "Média de tamanho: " . ($packets > 0 ? $totalOutput / $packets : 0) . " bytes\n\n";
}

// Teste com 320 bytes
testPacketSize(320);

// Teste com 640 bytes
testPacketSize(640);

// Teste com setPacketSize
echo "Testando setPacketSize(400)...\n";
$resampler = new Resampler(44100, 16000);
$resampler->setPacketSize(400);

$dummyInput = str_repeat("\0", 200);
$out = $resampler->process($dummyInput);
if ($resampler->returnEmpty() === "") {
    echo "✓ returnEmpty() funcionou para 200 bytes (abaixo de 400)\n";
}

$out2 = $resampler->process($dummyInput);
if ($resampler->returnEmpty() === false) {
    echo "✓ returnEmpty() retornou false após completar 400 bytes\n";
}

echo "\n=== Testes Concluídos ===\n";
