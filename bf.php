<?php

declare(strict_types=1);

/*
 * Benchmark:
 *
 * 1. PHP string tradicional:
 *
 *      $accumulator .= $pcm;
 *
 *      while (strlen($accumulator) >= $frameBytes) {
 *          $frame = substr($accumulator, 0, $frameBytes);
 *          $accumulator = substr($accumulator, $frameBytes);
 *      }
 *
 * 2. psampler ByteBuffer:
 *
 *      $buffer->append($pcm);
 *
 *      while ($buffer->has($frameBytes)) {
 *          $frame = $buffer->pop($frameBytes);
 *      }
 *
 *
 * Uso:
 *
 *   ./php benchmark_bytebuffer.php
 *
 *   ./php benchmark_bytebuffer.php 100000 1920 320
 *
 * Argumentos:
 *
 *   1 = iterations
 *   2 = frameBytes
 *   3 = chunkBytes
 */

if (!class_exists('ByteBuffer')) {
    fwrite(
        STDERR,
        "ERRO: classe ByteBuffer nao encontrada.\n"
    );

    exit(1);
}

$iterations = isset($argv[1])
    ? max(1, (int) $argv[1])
    : 100000;

$frameBytes = isset($argv[2])
    ? max(1, (int) $argv[2])
    : 1920;

$chunkBytes = isset($argv[3])
    ? max(1, (int) $argv[3])
    : 320;


/*
 * Gera dados binarios determinísticos.
 *
 * Não usamos str_repeat dentro do benchmark.
 */
function createPcmChunk(int $bytes): string
{
    $pattern = '';

    for ($i = 0; $i < 256; $i++) {
        $pattern .= chr($i);
    }

    $full = intdiv($bytes, 256);
    $rest = $bytes % 256;

    return str_repeat($pattern, $full)
        . substr($pattern, 0, $rest);
}


/*
 * Consumimos alguns bytes do frame para manter
 * o resultado observável.
 */
function updateChecksum(
    int $checksum,
    string $frame
): int {
    $length = strlen($frame);

    $checksum += $length;

    if ($length > 0) {
        $checksum += ord($frame[0]);
        $checksum += ord($frame[$length - 1]);
    }

    return $checksum;
}


/*
 * Implementação atualmente usada no PHP.
 */
function benchmarkString(
    string $pcm,
    int $iterations,
    int $frameBytes
): array {
    $accumulator = '';

    $frames = 0;
    $checksum = 0;

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        /*
         * Equivalente ao código atual:
         *
         * $this->members[$id]['pcmAccumulator']
         *     = $this->members[$id]['pcmAccumulator'] . $pcm;
         */
        $accumulator .= $pcm;

        while (strlen($accumulator) >= $frameBytes) {
            /*
             * Primeira cópia:
             * cria o frame.
             */
            $frame = substr(
                $accumulator,
                0,
                $frameBytes
            );

            /*
             * Segunda cópia:
             * recria todo o restante.
             */
            $accumulator = substr(
                $accumulator,
                $frameBytes
            );

            $checksum = updateChecksum(
                $checksum,
                $frame
            );

            $frames++;
        }
    }

    $elapsed =
        (hrtime(true) - $start)
        / 1_000_000_000;

    return [
        'elapsed' => $elapsed,
        'frames' => $frames,
        'checksum' => $checksum,
        'remaining' => strlen($accumulator),
        'remaining_data' => $accumulator,
    ];
}


/*
 * Nova implementação via extensão C.
 */
function benchmarkByteBuffer(
    string $pcm,
    int $iterations,
    int $frameBytes
): array {
    /*
     * Capacidade inicial razoável para evitar que
     * a medição seja dominada pelo primeiro crescimento.
     */
    $initialCapacity = max(
        4096,
        $frameBytes * 4
    );

    $buffer = new ByteBuffer(
        $initialCapacity
    );

    $frames = 0;
    $checksum = 0;

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        /*
         * Sem concatenação de zend_string.
         */
        $buffer->append($pcm);

        /*
         * has() não precisa criar nenhuma string.
         */
        while ($buffer->has($frameBytes)) {
            /*
             * pop() cria somente a string $frame.
             *
             * O restante permanece dentro do ByteBuffer.
             */
            $frame = $buffer->pop(
                $frameBytes
            );

            $checksum = updateChecksum(
                $checksum,
                $frame
            );

            $frames++;
        }
    }

    $elapsed =
        (hrtime(true) - $start)
        / 1_000_000_000;

    $remaining = $buffer->length();

    $remainingData = $remaining > 0
        ? $buffer->peek($remaining)
        : '';

    return [
        'elapsed' => $elapsed,
        'frames' => $frames,
        'checksum' => $checksum,
        'remaining' => $remaining,
        'remaining_data' => $remainingData,
        'capacity' => $buffer->capacity(),
    ];
}


/*
 * Warm-up fora da medição principal.
 */
function warmup(
    string $pcm,
    int $frameBytes
): void {
    $iterations = 1000;

    benchmarkString(
        $pcm,
        $iterations,
        $frameBytes
    );

    benchmarkByteBuffer(
        $pcm,
        $iterations,
        $frameBytes
    );
}


/*
 * Formatação.
 */
function printResult(
    string $name,
    array $result,
    int $iterations,
    int $totalBytes
): void {
    $elapsed = $result['elapsed'];

    $operationsPerSecond =
        $iterations / $elapsed;

    $mibPerSecond =
        ($totalBytes / 1048576)
        / $elapsed;

    $framesPerSecond =
        $result['frames'] / $elapsed;

    echo "\n=== {$name} ===\n";

    printf(
        "elapsed: %.9f s\n",
        $elapsed
    );

    printf(
        "append calls/s: %.2f\n",
        $operationsPerSecond
    );

    printf(
        "input MiB/s: %.2f\n",
        $mibPerSecond
    );

    printf(
        "frames: %d\n",
        $result['frames']
    );

    printf(
        "frames/s: %.2f\n",
        $framesPerSecond
    );

    printf(
        "remaining bytes: %d\n",
        $result['remaining']
    );

    printf(
        "checksum: %d\n",
        $result['checksum']
    );

    if (isset($result['capacity'])) {
        printf(
            "buffer capacity: %d\n",
            $result['capacity']
        );
    }
}


/*
 * ==========================================================
 * EXECUÇÃO
 * ==========================================================
 */

$pcm = createPcmChunk(
    $chunkBytes
);

$totalInputBytes =
    $iterations * $chunkBytes;

$expectedFrames =
    intdiv(
        $totalInputBytes,
        $frameBytes
    );

$expectedRemaining =
    $totalInputBytes
    % $frameBytes;

echo "=== ByteBuffer benchmark ===\n";

echo "iterations: {$iterations}\n";
echo "chunk/input: {$chunkBytes} bytes\n";
echo "frame: {$frameBytes} bytes\n";
echo "total input: {$totalInputBytes} bytes\n";
echo "expected frames: {$expectedFrames}\n";
echo "expected remaining: {$expectedRemaining} bytes\n";

echo "\nWarm-up...\n";

warmup(
    $pcm,
    $frameBytes
);


/*
 * STRING
 */

$stringResult = benchmarkString(
    $pcm,
    $iterations,
    $frameBytes
);


/*
 * BYTEBUFFER
 */

$bufferResult = benchmarkByteBuffer(
    $pcm,
    $iterations,
    $frameBytes
);


/*
 * Validação.
 */

echo "\n=== VALIDACAO ===\n";

$valid = true;

if (
    $stringResult['frames']
    !==
    $bufferResult['frames']
) {
    echo "FAIL: numero de frames diferente\n";
    $valid = false;
}

if (
    $stringResult['checksum']
    !==
    $bufferResult['checksum']
) {
    echo "FAIL: checksum diferente\n";
    $valid = false;
}

if (
    $stringResult['remaining']
    !==
    $bufferResult['remaining']
) {
    echo "FAIL: tamanho restante diferente\n";
    $valid = false;
}

if (
    $stringResult['remaining_data']
    !==
    $bufferResult['remaining_data']
) {
    echo "FAIL: conteudo restante diferente\n";
    $valid = false;
}

if (
    $stringResult['frames']
    !==
    $expectedFrames
) {
    echo "FAIL: quantidade de frames inesperada\n";
    $valid = false;
}

if (
    $stringResult['remaining']
    !==
    $expectedRemaining
) {
    echo "FAIL: quantidade de bytes restantes inesperada\n";
    $valid = false;
}

echo $valid
    ? "PASS: resultados equivalentes\n"
    : "FAIL\n";


/*
 * Resultados.
 */

printResult(
    'PHP STRING',
    $stringResult,
    $iterations,
    $totalInputBytes
);

printResult(
    'PSAMPLER BYTEBUFFER',
    $bufferResult,
    $iterations,
    $totalInputBytes
);


/*
 * Comparação.
 */

$stringTime =
    $stringResult['elapsed'];

$bufferTime =
    $bufferResult['elapsed'];

$speedup =
    $stringTime / $bufferTime;

$reduction =
    (1 - ($bufferTime / $stringTime))
    * 100;

echo "\n=== COMPARACAO ===\n";

printf(
    "ByteBuffer speedup: %.2fx\n",
    $speedup
);

printf(
    "tempo reduzido: %.2f%%\n",
    $reduction
);

echo "\n";