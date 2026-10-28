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
fatal_error_backtraces=0
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
function refs($object) {
    ob_start();
    debug_zval_dump($object);
    $dump = ob_get_clean();
    check(preg_match('/refcount\((\d+)\)/', $dump, $match) === 1);
    return (int) $match[1];
}
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
// Force a full ring with two readable spans before the failed growth.
$dst->discard(3); $dst->append("\0z\xff");
$chunk = str_repeat('k', 64 * 1024);
$tail = str_repeat('k', strlen($chunk) - 3) . "\0z\xff";
// Free this FIRST in shutdown, before buffering refcounts or copying chunks.
$reserve = str_repeat('r', 256 * 1024);
$continued = false;
register_shutdown_function(function () use (&$source, $dst, $size, $chunk, $tail,
    &$continued, &$reserve, &$sourceRefs) {
    $reserve = null;
    check(!$continued && $dst->capacity() === $size && $dst->length() === $size);
    // The interrupted appendBuffer VM frame still owns its source argument.
    // Both native pins must be gone; the by-reference capture adds no owner.
    check(refs($source) === $sourceRefs + 1);
    check($source->getCapacity() === 2048 && $source->getLength() === 3);
    check($source->toString() === "a\0b");
    // Compare ALL bytes using bounded allocations, including the wrapped tail.
    for ($left = $size; $left > strlen($chunk); $left -= strlen($chunk)) {
        check($dst->pop(strlen($chunk)) === $chunk);
    }
    check($dst->pop(strlen($tail)) === $tail && $dst->length() === 0);
    $dst->appendBuffer($source);
    check($dst->pop(3) === "a\0b");
    $source->clear();
    check($source->getLength() === 0 && $source->getCapacity() === 2048);
    $dst->clear(); $dst->appendBuffer($source);
    check($dst->length() === 0);
    check(refs($source) === $sourceRefs + 1);
    // The producer also blocks Fiber switches for the lifetime of a view.
    $fiber = new Fiber(fn() => 42);
    $fiber->start();
    check($fiber->getReturn() === 42);
    echo "Swoole append bailout cleanup OK\n";
});
$sourceRefs = refs($source);
$dst->appendBuffer($source);
$continued = true;
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted (tried to allocate %d bytes) in %s on line %d
Swoole append bailout cleanup OK
