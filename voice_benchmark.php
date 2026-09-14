<?php

declare(strict_types=1);

const CHECKSUM_MODULUS = 2147483647;
const CHECKSUM_MULTIPLIER = 65599;
const BYTE_BUFFER_INITIAL_CAPACITY = 4096;
const VARIABLE_CHUNK_SIZES = [320, 500, 700, 320, 1000, 160, 1024, 960, 300, 1920];

function usage(string $program): void
{
    $message = <<<TEXT
Usage:
  {$program} [options]

Options:
  --calls=N                 Independent calls (default: 50)
  --mode=string|bytebuffer  Accumulator implementation (default: string)
  --runtime=throughput|realtime
                            Scheduling mode (default: throughput)
  --frame=BYTES             PCM16 frame size (default: 1920)
  --chunk=BYTES|variable    Fixed or deterministic variable chunks (default: 1024)
  --ptime=MS                Packet interval in milliseconds (default: 20)
  --duration=SECONDS        Logical audio duration (default: 10)
  --help                    Show this help

TEXT;

    fwrite(STDOUT, $message);
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function positiveIntOption(array $options, string $name, int $default): int
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }

    $value = filter_var($options[$name], FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        fail("--{$name} must be a positive integer");
    }

    return $value;
}

function combineChecksum(int $checksum, int $value): int
{
    $normalized = $value % CHECKSUM_MODULUS;
    if ($normalized < 0) {
        $normalized += CHECKSUM_MODULUS;
    }

    return (int) (($checksum * CHECKSUM_MULTIPLIER + $normalized + 97)
        % CHECKSUM_MODULUS);
}

function crc32Unsigned(string $data): int
{
    return (int) sprintf('%u', crc32($data));
}

function updateFrameChecksum(int $checksum, string $frame): int
{
    $checksum = combineChecksum($checksum, strlen($frame));
    return combineChecksum($checksum, crc32Unsigned($frame));
}

/**
 * Each call gets its own deterministic binary payloads. The formula is also
 * used by voice_benchmark.go, so equivalent runs consume identical PCM bytes.
 * Chunk boundaries repeat, but each slot and call has different content.
 *
 * @return list<string>
 */
function makePcmChunks(int $callId, array $chunkSizes): array
{
    $chunks = [];

    foreach ($chunkSizes as $slot => $size) {
        $chunk = '';
        for ($i = 0; $i < $size; $i++) {
            $value = (($callId + 1) * 53)
                + ($slot * 97)
                + ($i * 29)
                + (intdiv($i, 8) * 7);
            $chunk .= chr($value & 0xff);
        }
        $chunks[] = $chunk;
    }

    return $chunks;
}

/**
 * @return array<string, int|string>
 */
function runCall(
    int $callId,
    string $mode,
    string $runtimeMode,
    int $ticks,
    int $ptimeMs,
    int $frameBytes,
    array $chunks,
    int $startNs,
    ?object $buffer
): array {
    $accumulator = '';

    $frames = 0;
    $checksum = combineChecksum(0, $callId + 1);
    $inputBytes = 0;
    $processedBytes = 0;
    $ticksProcessed = 0;
    $deadlineMisses = 0;
    $delayTotalNs = 0;
    $delayMaxNs = 0;
    $intervalNs = $ptimeMs * 1_000_000;
    $chunkCount = count($chunks);

    for ($tick = 0; $tick < $ticks; $tick++) {
        $deadlineNs = 0;

        if ($runtimeMode === 'realtime') {
            $deadlineNs = $startNs + (($tick + 1) * $intervalNs);
            $nowNs = hrtime(true);
            if ($nowNs < $deadlineNs) {
                Swoole\Coroutine::sleep(($deadlineNs - $nowNs) / 1_000_000_000);
            }

            $actualNs = hrtime(true);
            $delayNs = max(0, $actualNs - $deadlineNs);
            $delayTotalNs += $delayNs;
            $delayMaxNs = max($delayMaxNs, $delayNs);
        }

        $pcm = $chunks[$tick % $chunkCount];
        $inputBytes += strlen($pcm);

        if ($mode === 'string') {
            $accumulator .= $pcm;

            while (strlen($accumulator) >= $frameBytes) {
                $frame = substr($accumulator, 0, $frameBytes);
                $accumulator = substr($accumulator, $frameBytes);

                $checksum = updateFrameChecksum($checksum, $frame);
                $frames++;
                $processedBytes += $frameBytes;
            }
        } else {
            $buffer->append($pcm);

            while ($buffer->has($frameBytes)) {
                $frame = $buffer->pop($frameBytes);

                $checksum = updateFrameChecksum($checksum, $frame);
                $frames++;
                $processedBytes += $frameBytes;
            }
        }

        $ticksProcessed++;

        if (
            $runtimeMode === 'realtime'
            && hrtime(true) > $deadlineNs + $intervalNs
        ) {
            $deadlineMisses++;
        }
    }

    $endNs = hrtime(true);

    if ($mode === 'string') {
        $remainingBytes = strlen($accumulator);
        $remainingData = $accumulator;
    } else {
        $remainingBytes = $buffer->length();
        $remainingData = $remainingBytes > 0
            ? $buffer->peek($remainingBytes)
            : '';
    }

    return [
        'call_id' => $callId,
        'end_ns' => $endNs,
        'ticks' => $ticksProcessed,
        'input_bytes' => $inputBytes,
        'processed_bytes' => $processedBytes,
        'frames' => $frames,
        'checksum' => $checksum,
        'remaining_bytes' => $remainingBytes,
        'remaining_data' => $remainingData,
        'deadline_misses' => $deadlineMisses,
        'delay_total_ns' => $delayTotalNs,
        'delay_max_ns' => $delayMaxNs,
    ];
}

/**
 * Independent reference calculation, run after all measurements.
 *
 * @return array<string, int|string>
 */
function expectedCallState(
    int $callId,
    int $ticks,
    int $frameBytes,
    array $chunks
): array {
    $pending = '';
    $frames = 0;
    $checksum = combineChecksum(0, $callId + 1);
    $inputBytes = 0;
    $processedBytes = 0;
    $chunkCount = count($chunks);

    for ($tick = 0; $tick < $ticks; $tick++) {
        $chunk = $chunks[$tick % $chunkCount];
        $pending .= $chunk;
        $inputBytes += strlen($chunk);

        while (strlen($pending) >= $frameBytes) {
            $frame = substr($pending, 0, $frameBytes);
            $pending = substr($pending, $frameBytes);
            $checksum = updateFrameChecksum($checksum, $frame);
            $frames++;
            $processedBytes += $frameBytes;
        }
    }

    return [
        'ticks' => $ticks,
        'input_bytes' => $inputBytes,
        'processed_bytes' => $processedBytes,
        'frames' => $frames,
        'checksum' => $checksum,
        'remaining_bytes' => strlen($pending),
        'remaining_data' => $pending,
    ];
}

function validateCallResults(array $results, array $chunksByCall, int $ticks, int $frameBytes): void
{
    $fields = [
        'ticks',
        'input_bytes',
        'processed_bytes',
        'frames',
        'checksum',
        'remaining_bytes',
        'remaining_data',
    ];

    foreach ($results as $callId => $result) {
        $expected = expectedCallState(
            $callId,
            $ticks,
            $frameBytes,
            $chunksByCall[$callId]
        );

        foreach ($fields as $field) {
            if ($result[$field] !== $expected[$field]) {
                fail("validation failed for call {$callId}, field {$field}");
            }
        }

        if ($result['input_bytes'] !== $result['processed_bytes'] + $result['remaining_bytes']) {
            fail("byte accounting failed for call {$callId}");
        }
    }
}

function buildStateDigest(array $results): int
{
    $digest = 0;

    foreach ($results as $callId => $result) {
        $digest = combineChecksum($digest, $callId + 1);
        $digest = combineChecksum($digest, $result['ticks']);
        $digest = combineChecksum($digest, $result['input_bytes']);
        $digest = combineChecksum($digest, $result['processed_bytes']);
        $digest = combineChecksum($digest, $result['frames']);
        $digest = combineChecksum($digest, $result['checksum']);
        $digest = combineChecksum($digest, $result['remaining_bytes']);
        $digest = combineChecksum($digest, crc32Unsigned($result['remaining_data']));
    }

    return $digest;
}

$options = getopt('', [
    'calls:',
    'mode:',
    'runtime:',
    'frame:',
    'chunk:',
    'ptime:',
    'duration:',
    'help',
]);

if (isset($options['help'])) {
    usage($argv[0]);
    exit(0);
}

if (PHP_INT_SIZE < 8) {
    fail('a 64-bit PHP build is required');
}

if (!extension_loaded('swoole')) {
    fail('the Swoole extension is not loaded');
}

if (!class_exists('ByteBuffer')) {
    fail('the psampler ByteBuffer class is not available');
}

$calls = positiveIntOption($options, 'calls', 50);
$frameBytes = positiveIntOption($options, 'frame', 1920);
$ptimeMs = positiveIntOption($options, 'ptime', 20);
$mode = strtolower((string) ($options['mode'] ?? 'string'));
$runtimeMode = strtolower((string) ($options['runtime'] ?? 'throughput'));
$chunkOption = strtolower((string) ($options['chunk'] ?? '1024'));
$durationOption = (string) ($options['duration'] ?? '10');

if (!in_array($mode, ['string', 'bytebuffer'], true)) {
    fail('--mode must be string or bytebuffer');
}

if (!in_array($runtimeMode, ['throughput', 'realtime'], true)) {
    fail('--runtime must be throughput or realtime');
}

if (!is_numeric($durationOption) || (float) $durationOption <= 0) {
    fail('--duration must be greater than zero');
}

$durationMs = (int) round(((float) $durationOption) * 1000);
if ($durationMs < 1) {
    fail('--duration is too small');
}

if (($frameBytes % 2) !== 0) {
    fail('--frame must be even for PCM16');
}

if ($chunkOption === 'variable') {
    $chunkSizes = VARIABLE_CHUNK_SIZES;
    $chunkMode = 'variable';
} else {
    if (!ctype_digit($chunkOption) || (int) $chunkOption < 1) {
        fail('--chunk must be a positive integer or variable');
    }

    $chunkBytes = (int) $chunkOption;
    if (($chunkBytes % 2) !== 0) {
        fail('--chunk must be even for PCM16');
    }

    $chunkSizes = [$chunkBytes];
    $chunkMode = "fixed:{$chunkBytes}";
}

$ticks = intdiv($durationMs + $ptimeMs - 1, $ptimeMs);
$chunksByCall = [];
for ($callId = 0; $callId < $calls; $callId++) {
    $chunksByCall[$callId] = makePcmChunks($callId, $chunkSizes);
}

$results = [];
$memoryInitial = 0;
$memoryFinal = 0;
$memoryPeak = 0;
$benchmarkStartNs = 0;

Swoole\Coroutine\run(function () use (
    $calls,
    $mode,
    $runtimeMode,
    $ticks,
    $ptimeMs,
    $frameBytes,
    $chunksByCall,
    &$results,
    &$memoryInitial,
    &$memoryFinal,
    &$memoryPeak,
    &$benchmarkStartNs
): void {
    $ready = new Swoole\Coroutine\Channel($calls);
    $startGate = new Swoole\Coroutine\Channel($calls);
    $completed = new Swoole\Coroutine\Channel($calls);

    for ($callId = 0; $callId < $calls; $callId++) {
        Swoole\Coroutine::create(function () use (
            $callId,
            $mode,
            $runtimeMode,
            $ticks,
            $ptimeMs,
            $frameBytes,
            $chunksByCall,
            $ready,
            $startGate,
            $completed
        ): void {
            $readySent = false;

            try {
                /* Allocate the independent call state before the start barrier. */
                $chunks = $chunksByCall[$callId];
                $buffer = $mode === 'bytebuffer'
                    ? new ByteBuffer(BYTE_BUFFER_INITIAL_CAPACITY)
                    : null;
                $ready->push(true);
                $readySent = true;
                $startNs = (int) $startGate->pop();

                $completed->push(runCall(
                    $callId,
                    $mode,
                    $runtimeMode,
                    $ticks,
                    $ptimeMs,
                    $frameBytes,
                    $chunks,
                    $startNs,
                    $buffer
                ));
            } catch (Throwable $error) {
                if (!$readySent) {
                    $ready->push(true);
                }

                $completed->push([
                    'call_id' => $callId,
                    'error' => $error->getMessage(),
                ]);
            }
        });
    }

    for ($i = 0; $i < $calls; $i++) {
        $ready->pop();
    }

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }

    $memoryInitial = memory_get_usage(true);
    $benchmarkStartNs = hrtime(true);

    for ($i = 0; $i < $calls; $i++) {
        $startGate->push($benchmarkStartNs);
    }

    for ($i = 0; $i < $calls; $i++) {
        $result = $completed->pop();
        $results[$result['call_id']] = $result;
    }

    $memoryFinal = memory_get_usage(true);
    $memoryPeak = memory_get_peak_usage(true);
});

ksort($results);

foreach ($results as $result) {
    if (isset($result['error'])) {
        fail("call {$result['call_id']} failed: {$result['error']}");
    }
}

$latestEndNs = $benchmarkStartNs;
$totalTicks = 0;
$totalInputBytes = 0;
$totalProcessedBytes = 0;
$totalFrames = 0;
$totalRemainingBytes = 0;
$totalDeadlineMisses = 0;
$totalDelayNs = 0;
$maxDelayNs = 0;
$globalChecksum = 0;

foreach ($results as $callId => $result) {
    $latestEndNs = max($latestEndNs, $result['end_ns']);
    $totalTicks += $result['ticks'];
    $totalInputBytes += $result['input_bytes'];
    $totalProcessedBytes += $result['processed_bytes'];
    $totalFrames += $result['frames'];
    $totalRemainingBytes += $result['remaining_bytes'];
    $totalDeadlineMisses += $result['deadline_misses'];
    $totalDelayNs += $result['delay_total_ns'];
    $maxDelayNs = max($maxDelayNs, $result['delay_max_ns']);
    $globalChecksum = combineChecksum($globalChecksum, $callId + 1);
    $globalChecksum = combineChecksum($globalChecksum, $result['checksum']);
}

$elapsedSeconds = ($latestEndNs - $benchmarkStartNs) / 1_000_000_000;
$framesPerSecond = $elapsedSeconds > 0 ? $totalFrames / $elapsedSeconds : 0.0;
$mibPerSecond = $elapsedSeconds > 0
    ? ($totalProcessedBytes / 1048576) / $elapsedSeconds
    : 0.0;
$averageDelayMs = $totalTicks > 0
    ? ($totalDelayNs / $totalTicks) / 1_000_000
    : 0.0;

/* Validation is deliberately outside the measured interval and memory report. */
validateCallResults($results, $chunksByCall, $ticks, $frameBytes);
$stateDigest = buildStateDigest($results);

printf("language: PHP\n");
printf("mode: %s\n", $mode);
printf("runtime_mode: %s\n", $runtimeMode);
printf("calls: %d\n", $calls);
printf("ptime_ms: %d\n", $ptimeMs);
printf("frame_bytes: %d\n", $frameBytes);
printf("chunk_mode: %s\n", $chunkMode);
printf("chunk_sequence_bytes: %s\n", implode(',', $chunkSizes));
printf("duration_configured_seconds: %.3f\n", $durationMs / 1000);
printf("elapsed_seconds: %.6f\n", $elapsedSeconds);
printf("ticks_expected: %d\n", $calls * $ticks);
printf("ticks_processed: %d\n", $totalTicks);
printf("input_bytes: %d\n", $totalInputBytes);
printf("bytes_processed: %d\n", $totalProcessedBytes);
printf("frames_processed: %d\n", $totalFrames);
printf("frames_per_second: %.3f\n", $framesPerSecond);
printf("mib_per_second: %.3f\n", $mibPerSecond);
printf("checksum_global: %d\n", $globalChecksum);
printf("call_state_digest: %d\n", $stateDigest);
printf("bytes_remaining: %d\n", $totalRemainingBytes);
printf("memory_initial_bytes: %d\n", $memoryInitial);
printf("memory_peak_bytes: %d\n", $memoryPeak);
printf("memory_final_bytes: %d\n", $memoryFinal);

if ($runtimeMode === 'realtime') {
    printf("deadline_misses: %d\n", $totalDeadlineMisses);
    printf("max_delay_ms: %.3f\n", $maxDelayNs / 1_000_000);
    printf("average_delay_ms: %.3f\n", $averageDelayMs);
} else {
    printf("deadline_misses: n/a\n");
    printf("max_delay_ms: n/a\n");
    printf("average_delay_ms: n/a\n");
}

printf("validation: ok\n");
