--TEST--
Built-in Swoole bridge releases the view when ByteBuffer growth causes allocation bailout
--EXTENSIONS--
psampler
swoole
--SKIPIF--
<?php
if (!class_exists('Swoole\\Buffer', false)
    || !method_exists('Swoole\\Coroutine\\Socket', 'recvfromInto')) {
    die('skip requires Swoole Buffer and recvfromInto with read ABI v1');
}
?>
--INI--
memory_limit=24M
display_errors=1
log_errors=0
--FILE--
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
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted %s%a
Swoole append bailout cleanup OK
