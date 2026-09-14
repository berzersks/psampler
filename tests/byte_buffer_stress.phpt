--TEST--
ByteBuffer preserves FIFO order through repeated wraparound and growth
--EXTENSIONS--
psampler
--FILE--
<?php
$buffer = new ByteBuffer(3);
$expected = '';

for ($i = 0; $i < 5000; $i++) {
    $chunk = pack('V', $i) . chr($i & 0xff);
    $buffer->append($chunk);
    $expected .= $chunk;

    if (($i % 3) === 0 && $expected !== '') {
        $bytes = min(($i % 13) + 1, strlen($expected));
        if ($buffer->peek($bytes) !== substr($expected, 0, $bytes)) {
            throw new RuntimeException("peek mismatch at iteration $i");
        }
    }

    if (($i % 5) === 0 && $expected !== '') {
        $bytes = min(($i % 17) + 1, strlen($expected));
        if ($buffer->pop($bytes) !== substr($expected, 0, $bytes)) {
            throw new RuntimeException("pop mismatch at iteration $i");
        }
        $expected = substr($expected, $bytes);
    }

    if (($i % 11) === 0 && $expected !== '') {
        $bytes = min(($i % 7) + 1, strlen($expected));
        $buffer->discard($bytes);
        $expected = substr($expected, $bytes);
    }

    if ($buffer->length() !== strlen($expected)) {
        throw new RuntimeException("length mismatch at iteration $i");
    }
}

if ($buffer->pop($buffer->length()) !== $expected) {
    throw new RuntimeException('final content mismatch');
}

for ($i = 0; $i < 1000; $i++) {
    $temporary = new ByteBuffer(1);
    $temporary->append(str_repeat('x', 1024));
    $temporary->pop(1024);
}
unset($temporary);

echo "ok\n";
?>
--EXPECT--
ok
