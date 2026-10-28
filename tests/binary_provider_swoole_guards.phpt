--TEST--
Swoole producer must exclude pending receive and borrowed read mutations
--EXTENSIONS--
psampler
psampler_binary_test
swoole
--SKIPIF--
<?php
if (!class_exists('Swoole\\Buffer', false)
    || !method_exists('Swoole\\Coroutine\\Socket', 'recvfromInto')) {
    die('skip requires patched Swoole recvfromInto');
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
    $sender = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    check($socket->bind('127.0.0.1', 0));
    $source = new Swoole\Buffer(16);
    $destination = new ByteBuffer(4);
    check(ByteBuffer::supportsNativeBuffer($source));
    $destination->append('keep');
    check($sender->sendto('127.0.0.1', $socket->getsockname()['port'], "a\0b") === 3);
    psampler_binary_test_read($source, function () use ($source, $socket, $destination) {
        blocked(fn() => $source->clear());
        blocked(fn() => $source->__construct(16));
        blocked(fn() => $destination->appendBuffer($source));
        blocked(fn() => psampler_binary_test_read($source));
        if (class_exists('Fiber')) {
            // Zend must reject the switch before entering this Fiber.
            blocked(fn() => (new Fiber(fn() => null))->start());
        }
        // Must reject synchronously, BEFORE receive can suspend the coroutine.
        blocked(function () use ($source, $socket) {
            $peer = ['unchanged'];
            try {
                $socket->recvfromInto($source, $peer, 0.01);
            } finally {
                check($peer === ['unchanged'] && $source->getLength() === 0);
            }
        });
    });
    $peer = null;
    check($socket->recvfromInto($source, $peer, 0.5) === 3);
    $destination->appendBuffer($source);
    check($destination->pop(7) === "keepa\0b");
    $destination->append('keep');
    $capacity = $destination->capacity();
    $source->clear();
    $cid = Swoole\Coroutine::create(function () use ($source, $socket) {
        $peer = null;
        check($socket->recvfromInto($source, $peer, 1.0) === false);
    });
    blocked(fn() => $destination->appendBuffer($source));
    check($destination->peek(4) === 'keep' && $destination->capacity() === $capacity);
    check(Swoole\Coroutine::cancel($cid));
    Swoole\Coroutine::sleep(0.001);
    $destination->appendBuffer($source);
    $source->clear();
    $socket->close();
    $sender->close();
    echo "read/write exclusion and cancellation cleanup OK\n";
});
?>
--EXPECT--
read/write exclusion and cancellation cleanup OK
