--TEST--
Built-in Swoole bridge: slices, NUL bytes, repeated receives, wraparound, growth and lifetime
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
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
Swoole\Coroutine\run(function () {
    $receiver = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    $sender = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
    check($receiver->bind('127.0.0.1', 0));
    $port = $receiver->getsockname()['port'];
    $source = new Swoole\Buffer(2048);
    check(ByteBuffer::supportsNativeBuffer($source));
    $dst = new ByteBuffer(16);
    $dst->append('123456789abc'); $dst->discard(10);
    $expected = 'bc';
    foreach (range(0, 199) as $i) {
        $size = [0, 160, 172, 1024, 2048][$i % 5];
        $packet = substr(str_repeat("\0\xff" . chr($i % 256) . "abc\x80", 300), 0, $size);
        check($sender->sendto('127.0.0.1', $port, $packet) === $size);
        $peer = null;
        check($receiver->recvfromInto($source, $peer, 1.0) === $size);
        $offset = min(3, $size); $length = min(97, $size - $offset);
        $dst->appendBuffer($source, $offset, $length);
        $dst->appendBuffer($source, $offset, null);
        $dst->appendBuffer($source, $size, 0);
        // Strings below belong only to the integrity oracle.
        $expected .= substr($packet, $offset, $length) . substr($packet, $offset);
        if ($size > 0) {
            $before = $dst->length(); $capacity = $dst->capacity();
            try { $dst->appendBuffer($source, $size + 1); throw new Exception('range accepted'); }
            catch (ValueError $error) {}
            check($dst->length() === $before && $dst->capacity() === $capacity);
        }
        // Release must have happened even after an invalid slice.
        $source->clear();
        check($source->getLength() === 0);
        $consume = intdiv(strlen($expected), 3);
        check($dst->pop($consume) === substr($expected, 0, $consume));
        $expected = substr($expected, $consume);
        check($dst->peek($dst->length()) === $expected);
    }
    check($dst->capacity() > 16);
    $weak = WeakReference::create($source); unset($source);
    check($weak->get() === null);
    check($dst->pop($dst->length()) === $expected);
    // Existing self-append rejection must precede any ring growth.
    $dst->append('self'); $capacity = $dst->capacity();
    try { $dst->appendBuffer($dst); throw new Exception('self append accepted'); }
    catch (ValueError $error) {}
    check($dst->capacity() === $capacity && $dst->pop(4) === 'self');
    $dst->append('released'); check($dst->pop(8) === 'released');
    $sender->close(); $receiver->close();
});
echo "slices, repeated UDP, ring and ownership OK\n";
?>
--EXPECT--
slices, repeated UDP, ring and ownership OK
