<?php

// Carrega a extensão
if (!extension_loaded('psampler')) {
    dl('./modules/psampler.so');
}

// ============================================================================
// Configurações de Teste
// ============================================================================

// Define se deve salvar os arquivos WAV ou apenas processar
$SAVE_OUTPUT = true; // Mude para false para apenas processar sem salvar

// Sample rates para testar
$TEST_SAMPLE_RATES = [
    8000,    // 8 kHz - Telefonia
    11025,   // 11.025 kHz - Baixa qualidade
    16000,   // 16 kHz - Wideband
    22050,   // 22.05 kHz - Média qualidade
    32000,   // 32 kHz - Broadcast
    44100,   // 44.1 kHz - CD Quality (mesmo do original)
    48000,   // 48 kHz - Professional
];

// Configurações de canais
$TEST_CHANNELS = [
    'mono' => 1,
    'stereo' => 2,
];

// Tamanho do chunk para simular streaming (em samples por canal)
$CHUNK_SIZE = 4096;

// ============================================================================
// Funções Auxiliares
// ============================================================================

function readWavHeader($fp) {
    // Lê header RIFF
    $riff = fread($fp, 4);
    if ($riff !== 'RIFF') {
        throw new Exception("Não é um arquivo RIFF válido");
    }
    
    $fileSize = unpack('V', fread($fp, 4))[1];
    
    $wave = fread($fp, 4);
    if ($wave !== 'WAVE') {
        throw new Exception("Não é um arquivo WAVE válido");
    }
    
    // Procura pelo chunk 'fmt '
    while (!feof($fp)) {
        $chunkId = fread($fp, 4);
        $chunkSize = unpack('V', fread($fp, 4))[1];
        
        if ($chunkId === 'fmt ') {
            $fmtData = fread($fp, $chunkSize);
            $fmt = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $fmtData);
            
            // Continua lendo até encontrar 'data'
            while (!feof($fp)) {
                $dataId = fread($fp, 4);
                $dataSize = unpack('V', fread($fp, 4))[1];
                
                if ($dataId === 'data') {
                    $fmt['dataSize'] = $dataSize;
                    $fmt['dataOffset'] = ftell($fp);
                    return $fmt;
                } else {
                    // Pula outros chunks
                    fseek($fp, $dataSize, SEEK_CUR);
                }
            }
        } else {
            // Pula chunks desconhecidos
            fseek($fp, $chunkSize, SEEK_CUR);
        }
    }
    
    throw new Exception("Chunk 'data' não encontrado");
}

function writeWavHeader($fp, $sampleRate, $channels, $bitsPerSample, $dataSize) {
    $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
    $blockAlign = $channels * ($bitsPerSample / 8);
    
    // RIFF header
    fwrite($fp, 'RIFF');
    fwrite($fp, pack('V', 36 + $dataSize)); // File size - 8
    fwrite($fp, 'WAVE');
    
    // fmt chunk
    fwrite($fp, 'fmt ');
    fwrite($fp, pack('V', 16)); // fmt chunk size
    fwrite($fp, pack('v', 1)); // Audio format (1 = PCM)
    fwrite($fp, pack('v', $channels));
    fwrite($fp, pack('V', $sampleRate));
    fwrite($fp, pack('V', $byteRate));
    fwrite($fp, pack('v', $blockAlign));
    fwrite($fp, pack('v', $bitsPerSample));
    
    // data chunk
    fwrite($fp, 'data');
    fwrite($fp, pack('V', $dataSize));
}

function formatBytes($bytes) {
    if ($bytes >= 1048576) {
        return sprintf("%.2f MB", $bytes / 1048576);
    } elseif ($bytes >= 1024) {
        return sprintf("%.2f KB", $bytes / 1024);
    }
    return "$bytes bytes";
}

function formatDuration($samples, $sampleRate) {
    $seconds = $samples / $sampleRate;
    $minutes = floor($seconds / 60);
    $seconds = $seconds - ($minutes * 60);
    return sprintf("%d:%05.2f", $minutes, $seconds);
}

// ============================================================================
// Função Principal de Teste
// ============================================================================

function testStreamSimulation($inputFile, $targetRate, $targetChannels, $saveOutput, $chunkSize) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "Teste: {$targetRate} Hz, " . ($targetChannels == 1 ? "Mono" : "Stereo") . "\n";
    echo str_repeat("=", 80) . "\n";
    
    $startTime = microtime(true);
    
    // Abre arquivo de entrada
    $inputFp = fopen($inputFile, 'rb');
    if (!$inputFp) {
        throw new Exception("Não foi possível abrir o arquivo de entrada");
    }
    
    // Lê header do WAV
    $wavInfo = readWavHeader($inputFp);
    $srcRate = $wavInfo['sampleRate'];
    $srcChannels = $wavInfo['channels'];
    $srcBitsPerSample = $wavInfo['bitsPerSample'];
    $dataSize = $wavInfo['dataSize'];
    
    echo "Entrada: {$srcRate} Hz, {$srcChannels} canais, {$srcBitsPerSample} bits\n";
    echo "Saída: {$targetRate} Hz, {$targetChannels} canais, 16 bits\n";
    
    $totalSamples = $dataSize / ($srcChannels * ($srcBitsPerSample / 8));
    echo "Duração: " . formatDuration($totalSamples, $srcRate) . "\n";
    echo "Tamanho do chunk: {$chunkSize} samples\n";
    
    // Cria resamplers (um para cada canal se necessário)
    $resamplerLeft = new Resampler($srcRate, $targetRate);
    $resamplerRight = null;
    if ($srcChannels == 2 && $targetChannels == 2) {
        $resamplerRight = new Resampler($srcRate, $targetRate);
    }
    
    // Cria LPCM para decode da entrada
    $lpcmInput = new LPCM($srcChannels, $srcBitsPerSample, false);
    
    // Cria LPCM para encode/decode mono (para o resampler)
    $lpcmMono = new LPCM(1, 16, false);
    
    // Cria LPCM para encode stereo se necessário
    $lpcmStereo = null;
    if ($targetChannels == 2) {
        $lpcmStereo = new LPCM(2, 16, false);
    }
    
    // Prepara arquivo de saída se necessário
    $outputFp = null;
    $outputFile = null;
    if ($saveOutput) {
        $channelStr = $targetChannels == 1 ? "mono" : "stereo";
        $outputFile = "output_{$targetRate}hz_{$channelStr}.wav";
        $outputFp = fopen($outputFile, 'wb');
        
        // Escreve header temporário (será atualizado no final)
        writeWavHeader($outputFp, $targetRate, $targetChannels, 16, 0);
    }
    
    $totalBytesRead = 0;
    $totalBytesWritten = 0;
    $chunksProcessed = 0;
    $totalOutputSamples = 0;
    
    echo "\nProcessando";
    $lastProgress = 0;
    
    // Processa em chunks
    while (!feof($inputFp)) {
        $bytesToRead = $chunkSize * $srcChannels * ($srcBitsPerSample / 8);
        $chunk = fread($inputFp, $bytesToRead);
        
        if (strlen($chunk) == 0) break;
        
        $totalBytesRead += strlen($chunk);
        
        // Decodifica chunk
        if ($srcChannels == 2) {
            $decoded = $lpcmInput->decodeStereo($chunk);
            $leftChannel = $decoded[0];
            $rightChannel = $decoded[1];
        } else {
            $leftChannel = $lpcmInput->decodeMono($chunk);
            $rightChannel = null;
        }
        
        // Processa canal esquerdo (ou mono)
        $leftPcm = $lpcmMono->encodeMono($leftChannel);
        $resampledLeft = $resamplerLeft->process($leftPcm);
        
        $resampledRight = '';
        
        // Processa canal direito se necessário
        if ($srcChannels == 2 && $targetChannels == 2 && $resamplerRight) {
            $rightPcm = $lpcmMono->encodeMono($rightChannel);
            $resampledRight = $resamplerRight->process($rightPcm);
        } elseif ($targetChannels == 1 && $srcChannels == 2) {
            // Mix down para mono: processa canal direito e faz média
            $rightPcm = $lpcmMono->encodeMono($rightChannel);
            $resampledRight = $resamplerLeft->process($rightPcm);
        }
        
        // Combina os canais resampleados
        $finalOutput = '';
        
        if ($targetChannels == 1) {
            if ($srcChannels == 2 && strlen($resampledRight) > 0) {
                // Mix down: decodifica ambos e faz média
                $leftSamples = $lpcmMono->decodeMono($resampledLeft);
                $rightSamples = $lpcmMono->decodeMono($resampledRight);
                $mixedSamples = [];
                $minLen = min(count($leftSamples), count($rightSamples));
                for ($i = 0; $i < $minLen; $i++) {
                    $mixedSamples[] = (int)(($leftSamples[$i] + $rightSamples[$i]) / 2);
                }
                $finalOutput = $lpcmMono->encodeMono($mixedSamples);
            } else {
                $finalOutput = $resampledLeft;
            }
        } else {
            // Stereo output
            if ($srcChannels == 1) {
                // Duplica canal mono para stereo
                $leftSamples = $lpcmMono->decodeMono($resampledLeft);
                $finalOutput = $lpcmStereo->encodeStereo($leftSamples, $leftSamples);
            } else {
                // Combina canais L/R
                $leftSamples = $lpcmMono->decodeMono($resampledLeft);
                $rightSamples = $lpcmMono->decodeMono($resampledRight);
                $minLen = min(count($leftSamples), count($rightSamples));
                $leftSamples = array_slice($leftSamples, 0, $minLen);
                $rightSamples = array_slice($rightSamples, 0, $minLen);
                $finalOutput = $lpcmStereo->encodeStereo($leftSamples, $rightSamples);
            }
        }
        
        if (strlen($finalOutput) > 0) {
            $totalBytesWritten += strlen($finalOutput);
            $samplesInChunk = strlen($finalOutput) / ($targetChannels * 2);
            $totalOutputSamples += $samplesInChunk;
            
            if ($saveOutput && $outputFp) {
                fwrite($outputFp, $finalOutput);
            }
        }
        
        $chunksProcessed++;
        
        // Mostra progresso
        $progress = (int)(($totalBytesRead / $dataSize) * 100);
        if ($progress > $lastProgress && $progress % 10 == 0) {
            echo " {$progress}%";
            $lastProgress = $progress;
        }
    }
    
    echo " 100%\n";
    
    fclose($inputFp);
    
    // Atualiza header do WAV se salvou
    if ($saveOutput && $outputFp) {
        fseek($outputFp, 0);
        writeWavHeader($outputFp, $targetRate, $targetChannels, 16, $totalBytesWritten);
        fclose($outputFp);
    }
    
    $endTime = microtime(true);
    $elapsedTime = $endTime - $startTime;
    
    // Estatísticas
    echo "\n--- Estatísticas ---\n";
    echo "Chunks processados: {$chunksProcessed}\n";
    echo "Bytes lidos: " . formatBytes($totalBytesRead) . "\n";
    echo "Bytes escritos: " . formatBytes($totalBytesWritten) . "\n";
    echo "Samples de saída: " . number_format($totalOutputSamples) . "\n";
    echo "Duração de saída: " . formatDuration($totalOutputSamples, $targetRate) . "\n";
    echo "Tempo de processamento: " . sprintf("%.3f", $elapsedTime) . " segundos\n";
    
    $realTimeFactor = ($totalSamples / $srcRate) / $elapsedTime;
    echo "Fator tempo real: " . sprintf("%.2fx", $realTimeFactor) . "\n";
    
    if ($saveOutput) {
        echo "Arquivo salvo: {$outputFile}\n";
    } else {
        echo "Modo: Apenas processamento (sem salvamento)\n";
    }
}

// ============================================================================
// Execução dos Testes
// ============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║         TESTE DE SIMULAÇÃO DE STREAM - RESAMPLER + LPCM                   ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";

$inputFile = 'audio.wav';

if (!file_exists($inputFile)) {
    die("Erro: Arquivo '{$inputFile}' não encontrado!\n");
}

echo "\nArquivo de entrada: {$inputFile}\n";
echo "Modo de salvamento: " . ($SAVE_OUTPUT ? "ATIVADO" : "DESATIVADO") . "\n";
echo "Sample rates a testar: " . implode(', ', $TEST_SAMPLE_RATES) . " Hz\n";
echo "Configurações de canais: Mono, Stereo\n";

if (!$SAVE_OUTPUT) {
    echo "\n⚠️  ATENÇÃO: Modo de salvamento DESATIVADO\n";
    echo "   Os arquivos não serão salvos, apenas processados.\n";
    echo "   Para salvar, altere \$SAVE_OUTPUT = true no script.\n";
}

$totalTests = count($TEST_SAMPLE_RATES) * count($TEST_CHANNELS);
$currentTest = 0;

$overallStartTime = microtime(true);

foreach ($TEST_SAMPLE_RATES as $sampleRate) {
    foreach ($TEST_CHANNELS as $channelName => $channelCount) {
        $currentTest++;
        echo "\n\n[Teste {$currentTest}/{$totalTests}]\n";
        
        try {
            testStreamSimulation($inputFile, $sampleRate, $channelCount, $SAVE_OUTPUT, $CHUNK_SIZE);
        } catch (Exception $e) {
            echo "ERRO: " . $e->getMessage() . "\n";
        }
    }
}

$overallEndTime = microtime(true);
$totalElapsed = $overallEndTime - $overallStartTime;

echo "\n\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                          RESUMO DOS TESTES                                 ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\nTotal de testes executados: {$totalTests}\n";
echo "Tempo total: " . sprintf("%.2f", $totalElapsed) . " segundos\n";
echo "Tempo médio por teste: " . sprintf("%.2f", $totalElapsed / $totalTests) . " segundos\n";

if ($SAVE_OUTPUT) {
    echo "\n✓ Arquivos WAV salvos no diretório atual\n";
    echo "  Padrão de nome: output_<rate>hz_<mono|stereo>.wav\n";
} else {
    echo "\n✓ Todos os testes executados com sucesso (sem salvamento)\n";
}

echo "\n=== Testes Concluídos ===\n";
