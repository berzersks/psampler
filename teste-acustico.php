<?php

declare(strict_types=1);

if (!class_exists('AcousticStreamAnalyzer')) {
    exit("AcousticStreamAnalyzer não disponível. Compile o psampler primeiro.\n");
}

function silencio(int $ms): string
{
    return str_repeat("\0", $ms * 16);
}

function tom425(int $ms, int $amplitude = 12000): string
{
    $pcm = '';
    $samples = $ms * 8;

    for ($i = 0; $i < $samples; $i++) {
        $sample = (int) round(
            $amplitude * sin(2 * M_PI * 425 * $i / 8000)
        );

        $pcm .= pack('v', $sample & 0xffff);
    }

    return $pcm;
}

$voiceFile = __DIR__ . '/tests/acoustic-fixtures/speech.pcm';

if (!is_file($voiceFile)) {
    exit("Fixture de fala não encontrado: $voiceFile\n");
}

$voice = file_get_contents($voiceFile);

if ($voice === false || strlen($voice) % 2 !== 0) {
    exit("Arquivo PCM inválido.\n");
}

// Dois toques completos, seguidos de fala.
$audio =
    tom425(1000) .
    silencio(4000) .
    tom425(1000) .
    silencio(4000) .
    $voice .
    silencio(1500);

$analyzer = new AcousticStreamAnalyzer(8000, 20);

$events = [
    'ring.started',
    'ring.ended',
    'ring.cadence_detected',
    'ring.interrupted',
    'speech.started',
    'speech.ended',
    'signal.changed',
];

$counts = array_fill_keys($events, 0);

foreach ($events as $name) {
    $analyzer->on($name, function (array $event) use (&$counts): void {
        $type = $event['type'];
        $counts[$type]++;

        printf(
            "[%8d ms] %-25s sinal=%-17s motivo=%s\n",
            $event['at_ms'],
            $type,
            $event['signal'] ?? '-',
            $event['reason'] ?? '-'
        );

        if (isset($event['period_ms'])) {
            printf("             Período: %d ms\n", $event['period_ms']);
        }
    });
}

$durationMs = strlen($audio) / 16;

printf("Duração total: %.0f ms\n", $durationMs);
echo "Iniciando análise incremental...\n\n";

$start = hrtime(true);

// 20 ms por push = 320 bytes de PCM16 mono 8 kHz.
$frameSize = 320;

for ($offset = 0, $length = strlen($audio);
     $offset < $length;
     $offset += $frameSize) {

    $analyzer->push(substr($audio, $offset, $frameSize));
}

$elapsedMs = (hrtime(true) - $start) / 1_000_000;

echo "\n===== RESULTADO =====\n";

foreach ($counts as $event => $count) {
    printf("%-25s %d\n", $event, $count);
}

echo "\nEstado final:\n";
print_r($analyzer->state());

printf("\nTempo de processamento: %.3f ms\n", $elapsedMs);
printf("Duração do áudio: %.0f ms\n", $durationMs);
