<?php

// Carrega a extensão
if (!extension_loaded('psampler')) {
    dl('./modules/psampler.so');
}

// Configurações
$INPUT_FILE = 'output_8000hz_mono.wav';
$TEST_SAMPLE_RATE = 22050; // Taxa de saída para teste
$CHUNK_SIZE = 4096;
$THRESHOLD_CLIPPING = 32000; // Threshold para detectar clipping
$THRESHOLD_HIGH_FREQ = 12000; // Valor equilibrado para detecção de chiado
$MAX_DURATION_SECONDS = 120; // Processar apenas os primeiros 5 segundos para o teste inicial

echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║         ANÁLISE DE QUALIDADE DE ÁUDIO - DETECÇÃO DE CHIADOS               ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n\n";

// Lê arquivo WAV
if (!file_exists($INPUT_FILE)) {
    die("Erro: Arquivo $INPUT_FILE não encontrado!\n");
}

$data = file_get_contents($INPUT_FILE);

// Parse RIFF header
$riffHeader = unpack('a4riff/Vsize/a4wave', substr($data, 0, 12));
if ($riffHeader['riff'] !== 'RIFF' || $riffHeader['wave'] !== 'WAVE') {
    die("Erro: Arquivo não é um WAV válido!\n");
}

// Procura chunk 'fmt '
$pos = 12;
$fmtFound = false;
$fmtData = [];
while ($pos < strlen($data) - 8) {
    $chunkHeader = unpack('a4id/Vsize', substr($data, $pos, 8));
    
    if ($chunkHeader['id'] === 'fmt ') {
        $rawFmt = substr($data, $pos + 8, 16);
        $fmtData = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $rawFmt);
        $fmtFound = true;
        $pos += 8 + $chunkHeader['size'];
        break;
    }
    
    $pos += 8 + $chunkHeader['size'];
}

if (!$fmtFound) {
    die("Erro: Chunk 'fmt' não encontrado!\n");
}

echo "Arquivo de entrada: $INPUT_FILE\n";
echo "Sample rate: {$fmtData['sampleRate']} Hz\n";
echo "Canais: {$fmtData['channels']}\n";
echo "Bits por sample: {$fmtData['bitsPerSample']}\n";
echo "Taxa de saída: $TEST_SAMPLE_RATE Hz\n\n";

// Encontra o chunk 'data'
$dataStart = null;
$dataSize = 0;
// Reset $pos para depois do RIFF header se necessário, mas aqui continuamos de onde paramos ou reiniciamos
$pos = 12; 
while ($pos < strlen($data) - 8) {
    $chunkHeader = unpack('a4id/Vsize', substr($data, $pos, 8));
    
    if ($chunkHeader['id'] === 'data') {
        $dataStart = $pos + 8;
        $dataSize = $chunkHeader['size'];
        break;
    }
    
    $pos += 8 + $chunkHeader['size'];
}

if ($dataStart === null) {
    die("Erro: Chunk 'data' não encontrado no arquivo WAV!\n");
}

$pcmData = substr($data, $dataStart, $dataSize);
$totalSamplesCount = strlen($pcmData) / ($fmtData['channels'] * ($fmtData['bitsPerSample'] / 8));

// Limita aos primeiros N segundos
$maxBytes = $MAX_DURATION_SECONDS * $fmtData['sampleRate'] * $fmtData['channels'] * ($fmtData['bitsPerSample'] / 8);
if (strlen($pcmData) > $maxBytes) {
    $pcmData = substr($pcmData, 0, $maxBytes);
}
$totalSamplesProcessed = strlen($pcmData) / ($fmtData['channels'] * ($fmtData['bitsPerSample'] / 8));

echo "Total de samples no arquivo: " . number_format($totalSamplesCount) . "\n";
echo "Duração total: " . gmdate("i:s", (int)($totalSamplesCount / $fmtData['sampleRate'])) . "\n";
echo "Processando primeiros $MAX_DURATION_SECONDS segundos (" . number_format($totalSamplesProcessed) . " samples)\n\n";

// Cria resampler
$resampler = new Resampler($fmtData['sampleRate'], $TEST_SAMPLE_RATE);

// Arrays para estatísticas
$clippingCount = 0;
$highFreqCount = 0;
$totalOutputSamples = 0;
$maxAmplitude = 0;
$sumSquares = 0;

// Processa em chunks e analisa
echo "════════════════════════════════════════════════════════════════════════════\n";
echo "ANÁLISE DE ONDAS:\n";
echo "\033[0;32m███\033[0m = Áudio Normal (Verde)\n";
echo "\033[1;31m███\033[0m = Possível Chiado/Distorção (Vermelho)\n";
echo "════════════════════════════════════════════════════════════════════════════\n\n";

$offset = 0;
$bytesPerSample = ($fmtData['bitsPerSample'] / 8) * $fmtData['channels'];
$displayCounter = 0;
$previousSample = 0;

while ($offset < strlen($pcmData)) {
    $chunkData = substr($pcmData, $offset, $CHUNK_SIZE * $bytesPerSample);
    if (strlen($chunkData) == 0) break;
    
    // Converte para mono se necessário (Resampler atual suporta apenas mono internamente para o processamento de 16-bit)
    if ($fmtData['channels'] == 2) {
        $samples = unpack('s*', $chunkData);
        $monoData = '';
        for ($i = 1; $i <= count($samples); $i += 2) {
            $mono = (int)(($samples[$i] + $samples[$i + 1]) / 2);
            $monoData .= pack('s', $mono);
        }
        $chunkData = $monoData;
    }
    
    // Processa com resampler
    $resampled = $resampler->process($chunkData);
    
    if (strlen($resampled) > 0) {
        $outputSamples = unpack('s*', $resampled);
        
        foreach ($outputSamples as $sample) {
            $totalOutputSamples++;
            $absValue = abs($sample);
            $maxAmplitude = max($maxAmplitude, $absValue);
            $sumSquares += (float)$sample * $sample;
            
            // Detecta clipping
            $isClipping = $absValue >= $THRESHOLD_CLIPPING;
            if ($isClipping) $clippingCount++;
            
            // Detecta mudanças bruscas (high-frequency artifacts)
            $delta = abs($sample - $previousSample);
            $isHighFreq = $delta > $THRESHOLD_HIGH_FREQ;
            if ($isHighFreq) $highFreqCount++;
            
            // Exibe visualização a cada N samples (tempo real simulado)
            if ($displayCounter % 150 == 0) {
                $normalized = $sample / 32768.0;
                $barLength = (int)(abs($normalized) * 35);
                $bar = str_repeat('█', $barLength);
                
                // Determina cor (verde ou vermelho)
                $isArtifact = $isClipping || $isHighFreq;
                $color = $isArtifact ? "\033[1;31m" : "\033[0;32m"; // Vermelho Brilhante ou Verde Normal
                $reset = "\033[0m";
                
                $label = '';
                if ($isClipping) $label .= ' [CLIPPING]';
                if ($isHighFreq) $label .= ' [CHIADO/ARTEFATO]';
                
                // Simula tempo real baseado no sample rate (aproximadamente)
                // 150 samples a 22050 Hz = ~6.8ms
                usleep(4000); 

                // Calcula o tempo atual baseado no número de samples processados
                $currentTimeSeconds = $displayCounter / $TEST_SAMPLE_RATE;
                $timeStr = sprintf("[%02d:%02d.%03d]", 
                    (int)($currentTimeSeconds / 60), 
                    (int)$currentTimeSeconds % 60,
                    (int)(($currentTimeSeconds - (int)$currentTimeSeconds) * 1000)
                );

                printf("%s%s %6d: %s%s%s %8d%s%s%s\n", 
                    $color,
                    $timeStr,
                    $displayCounter,
                    $normalized < 0 ? str_repeat(' ', 35 - $barLength) : str_repeat(' ', 35),
                    $bar,
                    $normalized < 0 ? '' : str_repeat(' ', 35 - $barLength),
                    $sample,
                    str_repeat(' ', 8 - strlen((string)$sample)),
                    $label,
                    $reset
                );
            }
            
            $previousSample = $sample;
            $displayCounter++;
        }
    }
    
    $offset += ($fmtData['channels'] * ($fmtData['bitsPerSample'] / 8)) * ($CHUNK_SIZE);
}

// Calcula estatísticas
$rms = sqrt($sumSquares / max($totalOutputSamples, 1));
$snr = 20 * log10(32768 / max($rms, 1));
$clippingPercent = ($clippingCount / max($totalOutputSamples, 1)) * 100;
$highFreqPercent = ($highFreqCount / max($totalOutputSamples, 1)) * 100;

echo "\n════════════════════════════════════════════════════════════════════════════\n";
echo "ESTATÍSTICAS DE QUALIDADE\n";
echo "════════════════════════════════════════════════════════════════════════════\n\n";

printf("Total de samples processados: %s\n", number_format($totalOutputSamples));
printf("Amplitude máxima: %d (%.1f%%)\n", $maxAmplitude, ($maxAmplitude / 32768) * 100);
printf("RMS: %.2f\n", $rms);
printf("SNR estimado: %.2f dB\n", $snr);
printf("\n");

printf("Clipping detectado: %d samples (%.3f%%)\n", $clippingCount, $clippingPercent);
printf("High-frequency artifacts: %d samples (%.3f%%)\n", $highFreqCount, $highFreqPercent);

echo "\n════════════════════════════════════════════════════════════════════════════\n";
echo "DIAGNÓSTICO\n";
echo "════════════════════════════════════════════════════════════════════════════\n\n";

$issues = [];

if ($clippingPercent > 0.1) {
    $issues[] = "⚠️  CLIPPING DETECTADO: {$clippingPercent}% dos samples estão próximos do limite";
    $issues[] = "   Causa provável: Ganho excessivo no filtro ou falta de normalização";
}

if ($highFreqPercent > 1.0) {
    $issues[] = "⚠️  ARTIFACTS DE ALTA FREQUÊNCIA: {$highFreqPercent}% dos samples têm mudanças bruscas";
    $issues[] = "   Causa provável: Aliasing, descontinuidades no buffer, ou filtro inadequado";
}

if ($maxAmplitude > 32700) {
    $issues[] = "⚠️  AMPLITUDE MUITO ALTA: Máximo de {$maxAmplitude} (próximo do limite de 32768)";
    $issues[] = "   Causa provável: Falta de headroom ou normalização incorreta";
}

if (empty($issues)) {
    echo "✓ Nenhum problema grave detectado!\n";
    echo "  O áudio processado parece estar dentro dos parâmetros normais.\n\n";
} else {
    foreach ($issues as $issue) {
        echo "$issue\n";
    }
    echo "\n";
}

echo "════════════════════════════════════════════════════════════════════════════\n";
echo "RECOMENDAÇÕES\n";
echo "════════════════════════════════════════════════════════════════════════════\n\n";

if ($clippingPercent > 0.1 || $maxAmplitude > 32700) {
    echo "1. Reduzir ganho do filtro:\n";
    echo "   - Ajustar normalização do filtro polyphase\n";
    echo "   - Adicionar margem de segurança (headroom) de -3dB\n\n";
}

if ($highFreqPercent > 1.0) {
    echo "2. Melhorar anti-aliasing:\n";
    echo "   - Reduzir cutoff frequency (atualmente 95% Nyquist)\n";
    echo "   - Aumentar número de taps do filtro\n";
    echo "   - Verificar continuidade do buffer entre chamadas\n\n";
    
    echo "3. Verificar descontinuidades:\n";
    echo "   - Garantir que o buffer interno mantém contexto correto\n";
    echo "   - Verificar se frac_pos está sendo atualizado corretamente\n\n";
}

echo "4. Testes adicionais recomendados:\n";
echo "   - Testar com tom puro (sine wave) para verificar distorção harmônica\n";
echo "   - Testar com sweep de frequência para verificar resposta do filtro\n";
echo "   - Comparar com FFmpeg usando mesmos parâmetros\n\n";

echo "════════════════════════════════════════════════════════════════════════════\n";
echo "Análise concluída!\n";
echo "════════════════════════════════════════════════════════════════════════════\n";
