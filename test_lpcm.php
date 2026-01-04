<?php

// Carrega a extensão
if (!extension_loaded('psampler')) {
    dl('./modules/psampler.so');
}

echo "=== Teste da Classe LPCM ===\n\n";

// Teste 1: LPCM Mono 16-bit Little-Endian
echo "Teste 1: LPCM Mono 16-bit Little-Endian\n";
$lpcm_mono = new LPCM(1, 16, false);
$samples_mono = [0, 1000, -1000, 32767, -32768, 0];
echo "Samples originais: " . implode(", ", $samples_mono) . "\n";

$encoded = $lpcm_mono->encodeMono($samples_mono);
echo "Encoded length: " . strlen($encoded) . " bytes\n";

$decoded = $lpcm_mono->decodeMono($encoded);
echo "Samples decodificados: " . implode(", ", $decoded) . "\n";

$match = ($samples_mono === $decoded) ? "✓ PASSOU" : "✗ FALHOU";
echo "Round-trip test: $match\n\n";

// Teste 2: LPCM Mono 8-bit
echo "Teste 2: LPCM Mono 8-bit\n";
$lpcm_8bit = new LPCM(1, 8, false);
$samples_8bit = [0, 50, -50, 127, -128];
echo "Samples originais: " . implode(", ", $samples_8bit) . "\n";

$encoded_8 = $lpcm_8bit->encodeMono($samples_8bit);
echo "Encoded length: " . strlen($encoded_8) . " bytes\n";

$decoded_8 = $lpcm_8bit->decodeMono($encoded_8);
echo "Samples decodificados: " . implode(", ", $decoded_8) . "\n";

$match_8 = ($samples_8bit === $decoded_8) ? "✓ PASSOU" : "✗ FALHOU";
echo "Round-trip test: $match_8\n\n";

// Teste 3: LPCM Stereo 16-bit Little-Endian
echo "Teste 3: LPCM Stereo 16-bit Little-Endian\n";
$lpcm_stereo = new LPCM(2, 16, false);
$left = [100, 200, 300, 400];
$right = [-100, -200, -300, -400];
echo "Left channel: " . implode(", ", $left) . "\n";
echo "Right channel: " . implode(", ", $right) . "\n";

$encoded_stereo = $lpcm_stereo->encodeStereo($left, $right);
echo "Encoded length: " . strlen($encoded_stereo) . " bytes\n";

$decoded_stereo = $lpcm_stereo->decodeStereo($encoded_stereo);
echo "Left decodificado: " . implode(", ", $decoded_stereo[0]) . "\n";
echo "Right decodificado: " . implode(", ", $decoded_stereo[1]) . "\n";

$match_stereo = ($left === $decoded_stereo[0] && $right === $decoded_stereo[1]) ? "✓ PASSOU" : "✗ FALHOU";
echo "Round-trip test: $match_stereo\n\n";

// Teste 4: LPCM Mono 24-bit Big-Endian
echo "Teste 4: LPCM Mono 24-bit Big-Endian\n";
$lpcm_24be = new LPCM(1, 24, true);
$samples_24 = [0, 1000000, -1000000, 8388607, -8388608];
echo "Samples originais: " . implode(", ", $samples_24) . "\n";

$encoded_24 = $lpcm_24be->encodeMono($samples_24);
echo "Encoded length: " . strlen($encoded_24) . " bytes\n";

$decoded_24 = $lpcm_24be->decodeMono($encoded_24);
echo "Samples decodificados: " . implode(", ", $decoded_24) . "\n";

$match_24 = ($samples_24 === $decoded_24) ? "✓ PASSOU" : "✗ FALHOU";
echo "Round-trip test: $match_24\n\n";

// Teste 5: LPCM Stereo 32-bit
echo "Teste 5: LPCM Stereo 32-bit Little-Endian\n";
$lpcm_32 = new LPCM(2, 32, false);
$left_32 = [0, 100000000, -100000000];
$right_32 = [50000000, -50000000, 0];
echo "Left channel: " . implode(", ", $left_32) . "\n";
echo "Right channel: " . implode(", ", $right_32) . "\n";

$encoded_32 = $lpcm_32->encodeStereo($left_32, $right_32);
echo "Encoded length: " . strlen($encoded_32) . " bytes\n";

$decoded_32 = $lpcm_32->decodeStereo($encoded_32);
echo "Left decodificado: " . implode(", ", $decoded_32[0]) . "\n";
echo "Right decodificado: " . implode(", ", $decoded_32[1]) . "\n";

$match_32 = ($left_32 === $decoded_32[0] && $right_32 === $decoded_32[1]) ? "✓ PASSOU" : "✗ FALHOU";
echo "Round-trip test: $match_32\n\n";

echo "=== Testes Concluídos ===\n";
