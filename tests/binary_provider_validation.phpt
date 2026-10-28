--TEST--
Malformed native views fail before touching destination and every acquired view is released
--EXTENSIONS--
psampler
psampler_binary_test
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
$source = new PsamplerBinaryTestBuffer('x');
$destination = new ByteBuffer(8);
$destination->append('keep');
foreach (['null', 'capacity', 'second-overflow', 'overflow', 'capacity-overflow', 'source', 'huge', 'failure', 'busy', 'exception-success'] as $mode) {
    $source->__construct('x', $mode);
    $before = $source->stats();
    try { $destination->appendBuffer($source); throw new RuntimeException('not rejected'); }
    catch (Error | Exception $error) { check(!($error instanceof RuntimeException)); }
    check($destination->peek(4) === 'keep' && $destination->capacity() === 8);
    $after = $source->stats();
    $acquired = in_array($mode, ['failure', 'busy'], true) ? 0 : 1;
    check($after === [$before[0] + $acquired, $before[1] + $acquired, false]);
    // Mutation after failure must be possible; no leaked read guard.
    $source->__construct("\0ok");
    $destination->appendBuffer($source);
    check($destination->pop(7) === "keep\0ok");
    $destination->append('keep');
    echo "$mode OK\n";
}
$uninitialized = psampler_binary_test_uninitialized($source);
try { $destination->appendBuffer($uninitialized); throw new RuntimeException('not rejected'); }
catch (Error $error) {}
check($uninitialized->stats() === [0, 0, false]);
$uninitializedRing = psampler_binary_test_uninitialized($destination);
// Capability is registration only, not construction or readiness.
check(ByteBuffer::supportsNativeBuffer($uninitializedRing));
try { $destination->appendBuffer($uninitializedRing); throw new RuntimeException('not rejected'); }
catch (Error $error) {}
check($destination->peek(4) === 'keep' && $destination->capacity() === 8);
// Legacy destination behavior: append initializes native storage lazily.
$uninitializedRing->append('ready');
check(psampler_binary_test_read($uninitializedRing)['length'] === 5);
foreach (['failure', 'exception', 'exception-success'] as $outcome) {
    $before = $source->stats();
    try { psampler_binary_test_read($source, null, $outcome); throw new RuntimeException('not rejected'); }
    catch (Error | Exception $error) {
        check(!($error instanceof RuntimeException));
    }
    check($source->stats() === [$before[0] + 1, $before[1] + 1, false]);
}
echo "uninitialized and reader failures OK\n";
?>
--EXPECT--
null OK
capacity OK
second-overflow OK
overflow OK
capacity-overflow OK
source OK
huge OK
failure OK
busy OK
exception-success OK
uninitialized and reader failures OK
