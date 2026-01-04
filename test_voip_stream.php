<?php

/**
 * Script de Simulação de Streaming VoIP Real
 * 
 * Este script simula a transmissão de áudio em um cenário VoIP:
 * 1. Lê áudio de alta qualidade (audio.wav)
 * 2. Faz o resampling para uma taxa comum de VoIP (ex: 8kHz ou 16kHz)
 * 3. Envia os chunks em tempo real para o ffplay via pipe
 */

// Carrega a extensão
if (!extension_loaded('psampler')) {
    $extPath = realpath(__DIR__ . '/.libs/psampler.so');
    if (!dl($extPath)) {
        die("Erro: Não foi possível carregar a extensão psampler em $extPath\n");
    }
}

// Configurações
$INPUT_FILE = 'audio.wav';
$TARGET_SAMPLE_RATE = 16000; // Taxa típica de VoIP (Wideband)
$CHANNELS = 1;               // VoIP é geralmente mono
$CHUNK_SAMPLES = 320;        // 20ms de áudio a 16kHz (comum em VoIP/RTP)

if (!file_exists($INPUT_FILE)) {
    die("Erro: Arquivo de entrada $INPUT_FILE não encontrado.\n");
}

echo "=== Simulação de Streaming VoIP Real ===\n";
echo "Entrada: $INPUT_FILE\n";
echo "Saída Simulada: $TARGET_SAMPLE_RATE Hz, Mono, PCM 16-bit\n";
echo "Pressione Ctrl+C para parar.\n\n";

// Abre o ffplay para reprodução em tempo real
// Parâmetros:
// -f s16le: Formato PCM 16-bit Little Endian
// -ar: Sample rate
// -ac: Canais
// -nodisp: Não abre janela de vídeo/ondas (opcional)
// -autoexit: Sai quando o pipe fecha
$ffplayCmd = sprintf(
    "ffplay -f s16le -ar %d -ac %d -nodisp -autoexit -i pipe:0 2>/dev/null",
    $TARGET_SAMPLE_RATE,
    $CHANNELS
);

$descriptorspec = [
    0 => ["pipe", "r"], // stdin
];

$process = proc_open($ffplayCmd, $descriptorspec, $pipes);

if (!is_resource($process)) {
    die("Erro: Não foi possível iniciar o ffplay.\n");
}

// Inicializa Resampler
// O arquivo original audio.wav é 44100Hz Stereo
$resampler = new Resampler(44100, $TARGET_SAMPLE_RATE);

// Abre o arquivo de entrada
$fp = fopen($INPUT_FILE, 'rb');

// Pula o cabeçalho WAV (44 bytes simplificado)
fseek($fp, 44);

$bytesPerSampleInput = 2 * 2; // 16-bit stereo
$readSize = (int)($CHUNK_SAMPLES * (44100 / $TARGET_SAMPLE_RATE) * $bytesPerSampleInput);

$startTime = microtime(true);
$samplesSent = 0;

while (!feof($fp) && is_resource($process)) {
    $data = fread($fp, $readSize);
    if (empty($data)) break;

    // Converte Stereo para Mono antes do resample
    $samples = unpack('s*', $data);
    $monoData = '';
    for ($i = 1; $i <= count($samples); $i += 2) {
        if (isset($samples[$i+1])) {
            $avg = (int)(($samples[$i] + $samples[$i+1]) / 2);
            $monoData .= pack('s', $avg);
        } else {
            $monoData .= pack('s', $samples[$i]);
        }
    }

    // Resampling
    $resampled = $resampler->process($monoData);

    if (strlen($resampled) > 0) {
        // Envia para o ffplay
        fwrite($pipes[0], $resampled);
        
        $outputSamplesCount = strlen($resampled) / 2;
        $samplesSent += $outputSamplesCount;

        // Simulação de tempo real (VoIP)
        // Calcula quanto tempo de áudio já foi enviado e espera se estiver adiantado
        $elapsedAudioTime = $samplesSent / $TARGET_SAMPLE_RATE;
        $actualElapsedTime = microtime(true) - $startTime;

        if ($elapsedAudioTime > $actualElapsedTime) {
            $sleepUs = (int)(($elapsedAudioTime - $actualElapsedTime) * 1000000);
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }
    }
}

fclose($fp);
fclose($pipes[0]);
proc_close($process);

echo "\nStreaming concluído.\n";
