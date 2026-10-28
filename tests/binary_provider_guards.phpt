--TEST--
Native read blocks ByteBuffer mutations and recursion and releases guards after PHP exceptions
--EXTENSIONS--
psampler
psampler_binary_test
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
function blocked($callback) {
    try { $callback(); throw new RuntimeException('mutation was allowed'); }
    catch (Error $error) {}
}
$source = new ByteBuffer(4);
$source->append("a\0bc");
$other = new ByteBuffer(1);
$other->append('x');
psampler_binary_test_read($source, function () use ($source, $other) {
    foreach ([
        fn() => $source->__construct(8), fn() => $source->append('x'),
        fn() => $source->append(''), fn() => $source->appendBuffer($other),
        fn() => $source->pop(0), fn() => $source->pop(1),
        fn() => $source->discard(0), fn() => $source->clear(),
        fn() => psampler_binary_test_read($source),
    ] as $callback) blocked($callback);
    check($source->peek(4) === "a\0bc" && $source->length() === 4);
    check($source->has(4) && $source->capacity() === 4);
    check(ByteBuffer::supportsNativeBuffer($source));
    // A different object may mutate during this synchronous read.
    $other->append('y');
});
try {
    psampler_binary_test_read($source, function () { throw new RuntimeException('hook'); });
} catch (RuntimeException $error) { check($error->getMessage() === 'hook'); }
$source->clear();
$source->append('again');
check($source->pop(5) === 'again');
$native = new PsamplerBinaryTestBuffer("\0ok");
psampler_binary_test_read($native, function () use ($native) {
    blocked(fn() => $native->__construct('bad'));
    blocked(fn() => psampler_binary_test_read($native));
});
check($native->stats() === [1, 1, false]);
$weak = WeakReference::create($native);
$other->clear();
$other->appendBuffer($native);
unset($native);
check($weak->get() === null && $other->pop(3) === "\0ok");
echo "mutation guards, reentry, exceptions and destruction OK\n";
?>
--EXPECT--
mutation guards, reentry, exceptions and destruction OK
