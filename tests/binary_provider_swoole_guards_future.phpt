--TEST--
FUTURE INTEGRATION: Swoole producer must exclude pending receive and borrowed read mutations
--EXTENSIONS--
psampler
psampler_binary_test
swoole
psampler_swoole
--SKIPIF--
<?php
if (!class_exists('Swoole\\Buffer', false)
    || !method_exists('Swoole\\Coroutine\\Socket', 'recvfromInto')) {
    die('skip requires patched Swoole recvfromInto');
}
if (!ByteBuffer::supportsNativeBuffer(new Swoole\Buffer(16))) {
    die('skip future public Swoole borrow ABI and registered bridge required');
}
?>
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
function blocked($callback) {
    try { $callback(); throw new RuntimeException('guard missing'); }
    catch (Error $error) {}
}
Swoole\Coroutine\run(function () {
    $socket = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    check($socket->bind('127.0.0.1', 0));
    $source = new Swoole\Buffer(16);
    $destination = new ByteBuffer(4);
    $destination->append('keep');
    psampler_binary_test_read($source, function () use ($source, $socket) {
        blocked(fn() => $source->clear());
        blocked(fn() => $source->__construct(16));
        // Must reject synchronously, BEFORE receive can suspend the coroutine.
        blocked(function () use ($source, $socket) {
            $peer = null;
            $socket->recvfromInto($source, $peer, 0.01);
        });
    });
    $source->clear();
    $cid = Swoole\Coroutine::create(function () use ($source, $socket) {
        $peer = null;
        check($socket->recvfromInto($source, $peer, 1.0) === false);
    });
    blocked(fn() => $destination->appendBuffer($source));
    check($destination->peek(4) === 'keep' && $destination->capacity() === 4);
    check(Swoole\Coroutine::cancel($cid));
    Swoole\Coroutine::sleep(0.001);
    $destination->appendBuffer($source);
    $source->clear();
    $socket->close();
    echo "read/write exclusion and cancellation cleanup OK\n";
});
?>
--EXPECT--
read/write exclusion and cancellation cleanup OK
