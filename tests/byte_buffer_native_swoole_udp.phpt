--TEST--
recvfromInto native append uses the built-in psampler bridge
--EXTENSIONS--
psampler
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
Swoole\Coroutine\run(function () {
    $receiver = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    $sender = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    check($receiver->bind('127.0.0.1', 0));
    $port = $receiver->getsockname()['port'];
    $native = new Swoole\Buffer(2048);
    $destination = new ByteBuffer(8);
    check(ByteBuffer::supportsNativeBuffer($native));
    check($destination->appendBuffer($native) === null);
    foreach ([0, 160, 172, 1024, 2048, 160] as $size) {
        $packet = substr(str_repeat("\0\xffabc\0\x80", 300), 0, $size);
        check($sender->sendto('127.0.0.1', $port, $packet) === $size);
        $peer = null;
        check($receiver->recvfromInto($native, $peer, 0.5) === $size);
        $destination->appendBuffer($native);
        // Consumer retains neither borrow nor source; mutation must work now.
        $native->clear();
        check($native->getLength() === 0);
        check($destination->pop($size) === $packet);
        echo "UDP $size OK\n";
    }
    check($sender->sendto('127.0.0.1', $port, str_repeat('x', 2049)) === 2049);
    check($receiver->recvfromInto($native, $peer, 0.5) === false);
    check($native->getLength() === 0);
    $destination->appendBuffer($native);
    check($destination->length() === 0);
    $receiver->close();
    $sender->close();
});
?>
--EXPECT--
UDP 0 OK
UDP 160 OK
UDP 172 OK
UDP 1024 OK
UDP 2048 OK
UDP 160 OK
