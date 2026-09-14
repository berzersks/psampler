<?php

declare(strict_types=1);

/**
 * Fallback em PHP puro para uma futura str_repeat_chunks() nativa.
 *
 * Repete individualmente cada chunk da string.
 *
 * Exemplo:
 *
 * str_repeat_chunks("AABBCC", 2, 2)
 *
 * chunks:
 *   AA
 *   BB
 *   CC
 *
 * resultado:
 *   AAAABBBBCCCC
 *
 * @param string $string       Dados de entrada.
 * @param int    $chunkLength  Tamanho de cada chunk em bytes.
 * @param int    $times        Quantas vezes cada chunk será repetido.
 */
if (!function_exists('str_repeat_chunks')) {
    function str_repeat_chunks(
        string $string,
        int $chunkLength,
        int $times
    ): string {
        if ($chunkLength < 1) {
            throw new ValueError(
                'str_repeat_chunks(): Argument #2 ($chunkLength) must be greater than 0'
            );
        }

        if ($times < 0) {
            throw new ValueError(
                'str_repeat_chunks(): Argument #3 ($times) must be greater than or equal to 0'
            );
        }

        if ($string === '' || $times === 0) {
            return '';
        }

        if ($times === 1) {
            return $string;
        }

        $length = strlen($string);
        $result = '';

        for ($offset = 0; $offset < $length; $offset += $chunkLength) {
            /*
             * Extrai o chunk atual.
             *
             * No caso PCM16:
             *
             *   01 02 | 03 04 | 05 06
             *
             * cada substr() pega exatamente 2 bytes.
             */
            $chunk = substr(
                $string,
                $offset,
                $chunkLength
            );

            /*
             * Repete somente este chunk.
             *
             * Exemplo:
             *
             *   chunk = 01 02
             *   times = 2
             *
             * produz:
             *
             *   01 02 01 02
             */
            $result .= str_repeat(
                $chunk,
                $times
            );
        }

        return $result;
    }
}

/**
 * PCM16 mono -> PCM16 stereo.
 *
 * Cada sample possui 2 bytes.
 *
 * Mono:
 *
 *   [01 02]
 *   [03 04]
 *
 * Stereo:
 *
 *   [01 02][01 02]
 *   [03 04][03 04]
 */
function monoToStereo2(string $pcmData): string
{
    if (strlen($pcmData) < 2) {
        return $pcmData;
    }

    return str_repeat_chunks(
        $pcmData,
        2,
        2
    );
}

/**
 * Apenas para visualizar bytes em hexadecimal.
 */
function hexDump(string $data): string
{
    return implode(
        ' ',
        str_split(
            strtoupper(bin2hex($data)),
            2
        )
    );
}

/**
 * Benchmark equivalente aos anteriores.
 */
function runBenchmark(
    int $iterations,
    int $samplesPerFrame
): void {
    if ($iterations < 1) {
        $iterations = 1;
    }

    if ($samplesPerFrame < 1) {
        $samplesPerFrame = 1;
    }

    /*
     * PCM16 LE fixo:
     *
     * sample = 0x0201
     *
     * bytes:
     *   01 02
     */
    $pcmMono = str_repeat(
        "\x01\x02",
        $samplesPerFrame
    );

    $expectedInputBytes =
        $samplesPerFrame * 2;

    $expectedOutputBytes =
        $expectedInputBytes * 2;

    /*
     * Validação funcional.
     */
    $probe = monoToStereo($pcmMono);

    if (strlen($probe) !== $expectedOutputBytes) {
        throw new RuntimeException(
            'Tamanho de saída inesperado'
        );
    }

    if (
        substr($probe, 0, 4)
        !==
        "\x01\x02\x01\x02"
    ) {
        throw new RuntimeException(
            'Duplicação dos samples incorreta'
        );
    }

    /*
     * Warm-up.
     */
    $warmup = min(
        1000,
        $iterations
    );

    $warmChecksum = 0;

    for ($i = 0; $i < $warmup; $i++) {
        $stereo = monoToStereo($pcmMono);
        $warmChecksum += strlen($stereo);
    }

    if ($warmChecksum === 0) {
        throw new RuntimeException(
            'Warmup inválido'
        );
    }

    $checksum = 0;

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $stereo = monoToStereo($pcmMono);

        $checksum += strlen($stereo);
    }

    $end = hrtime(true);

    $elapsed =
        ($end - $start) / 1_000_000_000;

    $totalInputBytes =
        $expectedInputBytes *
        $iterations;

    $totalOutputBytes =
        $expectedOutputBytes *
        $iterations;

    $callsPerSecond =
        $iterations / $elapsed;

    $inputMiBPerSecond =
        ($totalInputBytes / 1048576)
        / $elapsed;

    $outputMiBPerSecond =
        ($totalOutputBytes / 1048576)
        / $elapsed;

    echo PHP_EOL;
    echo "=== str_repeat_chunks / PHP fallback ===\n";
    echo "iterations: {$iterations}\n";
    echo "samples/frame: {$samplesPerFrame}\n";
    echo "input/frame: {$expectedInputBytes} bytes\n";
    echo "output/frame: {$expectedOutputBytes} bytes\n";
    echo "elapsed: {$elapsed} s\n";
    echo "calls/s: {$callsPerSecond}\n";
    echo "input MiB/s: {$inputMiBPerSecond}\n";
    echo "output MiB/s: {$outputMiBPerSecond}\n";
    echo "checksum: {$checksum}\n";
}

/*
 * Demonstração pequena.
 */

$example =
    "\x01\x02"
    . "\x03\x04"
    . "\x05\x06";

echo "Entrada:\n";
echo hexDump($example);
echo "\n\n";

$result = str_repeat_chunks(
    $example,
    2,
    2
);

echo "str_repeat_chunks(..., 2, 2):\n";
echo hexDump($result);
echo "\n\n";

echo "Esperado:\n";
echo "01 02 01 02 03 04 03 04 05 06 05 06\n";

/*
 * Benchmark.
 */

$iterations =
    isset($argv[1])
        ? (int) $argv[1]
        : 50000;

$samplesPerFrame =
    isset($argv[2])
        ? (int) $argv[2]
        : 960;

runBenchmark(
    $iterations,
    $samplesPerFrame
);