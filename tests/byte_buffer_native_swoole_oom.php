<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
$source = new Swoole\Buffer(2048);
check(ByteBuffer::supportsNativeBuffer($source));
Swoole\Coroutine\run(function () use ($source) {
    $rx = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    $tx = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    check($rx->bind('127.0.0.1', 0));
    check($tx->sendto('127.0.0.1', $rx->getsockname()['port'], "a\0b") === 3);
    $peer = null; check($rx->recvfromInto($source, $peer, 1.0) === 3);
    $tx->close(); $rx->close();
});
$size = 8 * 1024 * 1024;
$dst = new ByteBuffer($size); $dst->append(str_repeat('k', $size));
$continued = false;
register_shutdown_function(function () use ($source, $dst, $size, &$continued) {
    check(!$continued && $dst->capacity() === $size && $dst->length() === $size);
    check($dst->peek(4) === 'kkkk');
    // A live guard or leaked source pin would prevent mutation/destruction.
    $source->clear(); $source->__construct(16);
    $dst->clear(); $dst->appendBuffer($source);
    check($dst->length() === 0);
    echo "Swoole append bailout cleanup OK\n";
});
$dst->appendBuffer($source);
$continued = true;
?>
