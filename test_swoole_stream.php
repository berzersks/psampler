<?php

/**
 * Teste de Simulação de Streaming VoIP usando Swoole e Psampler
 * 
 * Este script demonstra uma arquitetura de dois processos:
 * 1. Processo de Transmissão: Lê o áudio original, faz o resampling e envia os chunks.
 * 2. Processo de Recepção: Recebe os chunks resampleados e os envia para o ffplay.
 */

use Swoole\Process;

// Configurações
$INPUT_FILE = 'audio.wav';
$SRC_RATE = 44100;
$DST_RATE = 16000; // Qualidade VoIP (Wideband)
$CHUNK_SIZE_MS = 80; // Tamanho do pacote VoIP padrão
$SAMPLES_PER_CHUNK = ($SRC_RATE * $CHUNK_SIZE_MS) / 1000;
var_dump($SAMPLES_PER_CHUNK);
exit;

if (!file_exists($INPUT_FILE)) {
    die("Erro: Arquivo $INPUT_FILE não encontrado.\n");
}

// -----------------------------------------------------------------------------
// PROCESSO DE RECEPÇÃO E REPRODUÇÃO
// -----------------------------------------------------------------------------
$receiver = new Process(function (Process $worker) use ($DST_RATE) {
    echo "[Receiver] Iniciando processo de recepção e reprodução...\n";
    
    // Abre o ffplay para reproduzir áudio bruto (S16LE, Mono, taxa de destino)
    $ffplay_cmd = "ffplay -nodisp -autoexit -f s16le -ar $DST_RATE -ac 1 -i pipe:0 > /dev/null 2>&1";
    $pipe = popen($ffplay_cmd, 'w');
    
    if (!$pipe) {
        echo "[Receiver] Erro ao abrir pipe para ffplay.\n";
        return;
    }

    while (true) {
        $data = $worker->read();
        if (empty($data)) {
            break;
        }
        fwrite($pipe, $data);
    }
    
    pclose($pipe);
    echo "[Receiver] Processo finalizado.\n";
});

// -----------------------------------------------------------------------------
// PROCESSO DE TRANSMISSÃO E RESAMPLING
// -----------------------------------------------------------------------------
$transmitter = new Process(function (Process $worker) use ($receiver, $INPUT_FILE, $SRC_RATE, $DST_RATE, $SAMPLES_PER_CHUNK, $CHUNK_SIZE_MS) {
    echo "[Transmitter] Iniciando processo de transmissão e resampling...\n";
    
    $resampler = new Resampler($SRC_RATE, $DST_RATE);
    $lpcm = new LPCM(2, 16, false); // Audio original é Stereo 16-bit
    
    $fp = fopen($INPUT_FILE, 'rb');
    fseek($fp, 44); // Pula o cabeçalho WAV
    
    $bytesPerFrame = 4; // 2 canais * 2 bytes (16-bit)
    $readSize = $SAMPLES_PER_CHUNK * $bytesPerFrame;
    
    $startTime = microtime(true);
    $packetsSent = 0;
    
    while (!feof($fp)) {
        $chunk = fread($fp, $readSize);
        if (empty($chunk)) break;
        
        // Conversão Stereo -> Mono manual para o Resampler
        $decoded = $lpcm->decodeStereo($chunk);
        $left = $decoded[0];
        $right = $decoded[1];
        
        $monoSamples = [];
        for ($i = 0; $i < count($left); $i++) {
            $monoSamples[] = (int)(($left[$i] + $right[$i]) / 2);
        }
        
        // Codifica para Mono 16-bit para o Resampler
        $lpcmMono = new LPCM(1, 16, false);
        $monoPcm = $lpcmMono->encodeMono($monoSamples);
        
        // Resampling
        $resampled = $resampler->process($monoPcm);
        
        if (!empty($resampled)) {
            // Envia o áudio resampleado para o processo receptor via Pipe
            $receiver->write($resampled);
            $packetsSent++;
        }
        
        // Controle de cadência (simula tempo real)
        $elapsed = (microtime(true) - $startTime) * 1000;
        $expected = $packetsSent * $CHUNK_SIZE_MS;
        
        if ($expected > $elapsed) {
            usleep((int)(($expected - $elapsed) * 1000));
        }
    }
    
    fclose($fp);
    echo "[Transmitter] Transmissão concluída. Pacotes enviados: $packetsSent\n";
    
    // Notifica o receptor que acabou
    $receiver->write(""); 
});

// Inicia os processos
$receiver->start();
$transmitter->start();

// Aguarda finalização
Process::wait();
Process::wait();

echo "Simulação VoIP com Swoole concluída.\n";
