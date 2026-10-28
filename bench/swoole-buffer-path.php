<?php
declare(strict_types=1);

// Full UDP receive -> ByteBuffer paths. Run each mode in a fresh process.
// Sender uses only standard PHP streams and runs outside the measured process.
function rtpPacket(int $sequence): string
{
    return pack('CCnNN', 0x80, 0, $sequence & 0xffff,
        ($sequence * 160) & 0xffffffff, 0x12345678)
        . str_repeat("\0\xff\x80a", 40);
}

function cpuSeconds(array $usage): float
{
    return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']
        + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
}

function rss(): array
{
    $status = @file_get_contents('/proc/self/status');
    $result = ['rss_bytes' => null, 'peak_rss_bytes' => null];
    if ($status !== false) {
        foreach (['VmRSS' => 'rss_bytes', 'VmHWM' => 'peak_rss_bytes'] as $key => $field) {
            if (preg_match('/^' . $key . ':\s+(\d+) kB/m', $status, $match)) {
                $result[$field] = (int) $match[1] * 1024;
            }
        }
    }
    return $result;
}

$options = getopt('', ['sender:', 'mode:', 'packets:', 'batch:', 'warmup:', 'timeout:']);
if (isset($options['sender'])) {
    $socket = stream_socket_client('udp://127.0.0.1:' . (int) $options['sender'], $errno, $error, 5);
    if ($socket === false) throw new RuntimeException($error, $errno);
    $usage = getrusage(); $sent = 0; $errors = 0; $sequence = 0;
    while (($line = fgets(STDIN)) !== false) {
        $count = (int) trim($line);
        for ($i = 0; $i < $count; ++$i) {
            $packet = rtpPacket($sequence++);
            if (fwrite($socket, $packet) === 172) ++$sent; else ++$errors;
        }
    }
    fclose($socket);
    echo json_encode(['sent' => $sent, 'send_errors' => $errors,
        'cpu_seconds' => cpuSeconds(getrusage()) - cpuSeconds($usage)] + rss(), JSON_THROW_ON_ERROR), "\n";
    exit;
}

$mode = $options['mode'] ?? 'traditional';
$packets = (int) ($options['packets'] ?? 1000000);
$batch = (int) ($options['batch'] ?? 32);
$warmup = (int) ($options['warmup'] ?? 10000);
$timeout = (float) ($options['timeout'] ?? 0.5);
if (!in_array($mode, ['traditional', 'native'], true) || $packets < 1
    || $batch < 1 || $batch > 128 || $warmup < 0 || $timeout <= 0) {
    throw new InvalidArgumentException('Use --mode=traditional|native --packets=N --batch=1..128 --warmup=N --timeout=seconds');
}
if (!extension_loaded('swoole') || !class_exists('ByteBuffer', false)) {
    throw new RuntimeException('Requires psampler and Swoole in the receiver');
}

Swoole\Coroutine\run(function () use ($mode, $packets, $batch, $warmup, $timeout) {
    $receiver = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    if (!$receiver->bind('127.0.0.1', 0)) throw new RuntimeException('bind failed');
    $port = $receiver->getsockname()['port'];
    $destination = new ByteBuffer($batch * 172 * 2);
    $source = $mode === 'native' ? new Swoole\Buffer(2048) : null;
    if ($source !== null && !ByteBuffer::supportsNativeBuffer($source)) {
        throw new RuntimeException('Compatible built-in psampler Swoole bridge required');
    }
    $process = proc_open([PHP_BINARY, '-n', __FILE__, '--sender=' . $port],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start sender');
    $sequence = 0; $received = 0; $losses = 0; $errors = 0; $integrityErrors = 0;
    $wallNs = 0; $cpu = 0.0;
    try {
        foreach ([$warmup, $packets] as $phase => $target) {
            for ($done = 0; $done < $target;) {
                $count = min($batch, $target - $done);
                $got = 0; $bad = 0; $peer = null;
                $usage = getrusage(); $start = hrtime(true);
                if (fwrite($pipes[0], "$count\n") !== strlen("$count\n")) {
                    throw new RuntimeException('Sender control pipe failed');
                }
                if ($mode === 'native') {
                    for ($i = 0; $i < $count; ++$i) {
                        $bytes = $receiver->recvfromInto($source, $peer, $timeout);
                        if ($bytes === false) { ++$bad; break; }
                        $destination->appendBuffer($source);
                        ++$got;
                        if ($bytes !== 172) ++$bad;
                    }
                } else {
                    for ($i = 0; $i < $count; ++$i) {
                        $packet = $receiver->recvfrom($peer, $timeout);
                        if ($packet === false) { ++$bad; break; }
                        $destination->append($packet);
                        ++$got;
                        if (strlen($packet) !== 172) ++$bad;
                    }
                    unset($packet);
                }
                $elapsed = hrtime(true) - $start;
                $usedCpu = cpuSeconds(getrusage()) - cpuSeconds($usage);
                // Integrity strings and draining are OUTSIDE the measured path.
                $expected = '';
                for ($i = 0; $i < $count; ++$i) $expected .= rtpPacket($sequence++);
                $actual = $destination->pop($destination->length());
                $corrupt = $actual !== $expected;
                if ($phase === 1) {
                    $received += $got; $losses += $count - $got; $errors += $bad;
                    $integrityErrors += (int) $corrupt;
                    $wallNs += $elapsed; $cpu += $usedCpu;
                } elseif ($corrupt || $bad || $got !== $count) {
                    throw new RuntimeException('Warmup lost or corrupted datagrams');
                }
                $done += $count;
            }
        }
        fclose($pipes[0]);
        $sender = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process); $process = null;
        if ($exit !== 0) throw new RuntimeException('Sender failed: ' . $stderr);
        $senderMetrics = json_decode($sender, true, 512, JSON_THROW_ON_ERROR);
        $receiver->close();
        $result = [
            'mode' => $mode, 'payload_bytes' => 172, 'attempted' => $packets,
            'valid' => $received === $packets && $losses === 0 && $errors === 0
                && $integrityErrors === 0 && $senderMetrics['send_errors'] === 0
                && $senderMetrics['sent'] === $warmup + $packets,
            'received' => $received, 'losses' => $losses, 'receive_errors' => $errors,
            'integrity_error_batches' => $integrityErrors,
            'batch' => $batch, 'warmup_packets' => $warmup,
            'wall_seconds' => $wallNs / 1e9, 'cpu_seconds' => $cpu,
            'packets_per_second' => $wallNs ? $received * 1e9 / $wallNs : null,
            'cpu_seconds_per_million_packets' => $received ? $cpu * 1e6 / $received : null,
            'mean_nanoseconds_per_packet' => $received ? $wallNs / $received : null,
            'zend_memory_bytes' => memory_get_usage(false),
            'zend_peak_bytes' => memory_get_peak_usage(false),
            'zend_reserved_bytes' => memory_get_usage(true),
            'zend_peak_reserved_bytes' => memory_get_peak_usage(true),
            'allocations' => null,
            'sender_including_warmup' => $senderMetrics,
            'php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS,
            'swoole' => phpversion('swoole'), 'psampler' => phpversion('psampler'),
            'os' => php_uname(),
        ] + rss();
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } finally {
        if (is_resource($process)) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_terminate($process); proc_close($process);
        }
    }
});
