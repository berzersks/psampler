--TEST--
Built-in Swoole bridge releases real views and references on exceptions and native reader bailout
--EXTENSIONS--
psampler
swoole
psampler_binary_test
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
foreach (['failure', 'exception', 'exception-success'] as $outcome) {
    try { psampler_binary_test_read($source, null, $outcome); throw new Exception('failure expected'); }
    catch (Error | Exception $error) { check($error->getMessage() !== 'failure expected'); }
    check(psampler_binary_test_read($source) === ['length' => 3, 'checksum' => 195, 'spans' => 1]);
}
check(psampler_binary_test_catch($source) === [true, true]);
check(psampler_binary_test_read($source)['length'] === 3);
unset($error); // Exception traces own their source until discarded.
$dst = new ByteBuffer(1); $dst->appendBuffer($source);
// Remove the only PHP owner inside the reader, then checksum real payload.
$weak = WeakReference::create($source);
check(psampler_binary_test_pin_source($source) === 195);
check($source === null && $weak->get() === null);
$source = new Swoole\Buffer(16);
$source->clear();
check($dst->pop(3) === "a\0b");
$invalid = psampler_binary_test_uninitialized($source);
try { $dst->appendBuffer($invalid); throw new Exception('unconstructed accepted'); }
catch (Error $error) {}
unset($invalid);
$weak = WeakReference::create($source); unset($source);
check($weak->get() === null);
echo "exceptions, bailout, guards and references OK\n";
?>
--EXPECT--
exceptions, bailout, guards and references OK
