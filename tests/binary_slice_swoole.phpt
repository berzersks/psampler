--TEST--
Binary slice reads Swoole UDP storage and survives reuse, timeout and cancellation
--EXTENSIONS--
psampler
swoole
--SKIPIF--
<?php
if (!class_exists('Swoole\\Buffer', false) || !method_exists('Swoole\\Coroutine\\Socket', 'recvfromInto')
    || !ByteBuffer::supportsNativeBuffer(new Swoole\Buffer(16))) die('skip requires native Swoole bridge');
?>
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
Swoole\Coroutine::set(['hook_flags' => 0]);
Swoole\Coroutine\run(function () {
    $rx = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    $tx = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    check($rx->bind('127.0.0.1', 0));
    $port = $rx->getsockname()['port'];
    $source = new Swoole\Buffer(16);
    check(psampler_binary_slice($source) === '');
    $tx->sendto('127.0.0.1', $port, "head\0payload\xff");
    $peer = null;
    check($rx->recvfromInto($source, $peer, .5) === 13);
    $payload = psampler_binary_slice($source, 4);
    check($payload === "\0payload\xff");
    $source->clear();
    $cid = Swoole\Coroutine::create(function () use ($rx, $source) {
        $peer = null;
        check($rx->recvfromInto($source, $peer, 1) === false);
    });
    try { psampler_binary_slice($source, 0, 0); throw new RuntimeException('read during receive'); }
    catch (Error $error) {}
    check(Swoole\Coroutine::cancel($cid));
    Swoole\Coroutine::sleep(.001);
    check(psampler_binary_slice($source) === '');
    check($rx->recvfromInto($source, $peer, .001) === false);
    $tx->sendto('127.0.0.1', $port, str_repeat('x', 17));
    check($rx->recvfromInto($source, $peer, .5) === false && $rx->errCode === SOCKET_EMSGSIZE);
    check(psampler_binary_slice($source) === '');
    $tx->sendto('127.0.0.1', $port, 'next');
    check($rx->recvfromInto($source, $peer, .5) === 4);
    check(psampler_binary_slice($source) === 'next' && $payload === "\0payload\xff");
    $rx->close(); $tx->close();
    echo "native UDP slices and read lifecycle OK\n";
});
?>
--EXPECT--
native UDP slices and read lifecycle OK
