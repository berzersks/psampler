--TEST--
Binary v1 releases views and source references after reader or acquire bailout
--EXTENSIONS--
psampler
psampler_binary_test
--FILE--
<?php
function check($ok, $label) { if (!$ok) throw new Exception($label); echo "$label: OK\n"; }
foreach (['valid', 'split', 'acquire-bailout'] as $mode) {
    $source = new PsamplerBinaryTestBuffer("\0ok", $mode);
    check(ByteBuffer::supportsNativeBuffer($source) && $source->stats() === [0, 0, false], "$mode capability does not acquire");
    check(psampler_binary_test_catch($source) === [true, true], "$mode bailout propagated and reference released");
    check($source->stats() === ($mode === 'acquire-bailout' ? [0, 0, false] : [1, 1, false]), "$mode balanced guards");
    $source->__construct('after');
    $dst = new ByteBuffer(4); $dst->append('keep'); $dst->appendBuffer($source);
    check($dst->pop(9) === 'keepafter', "$mode reusable buffers");
    $weak = WeakReference::create($source); unset($source);
    check($weak->get() === null, "$mode source destroyed");
}
$source = new ByteBuffer(4); $source->append('abcd'); $source->discard(3); $source->append('efg');
check(psampler_binary_test_catch($source) === [true, true], 'ring bailout reference released');
check($source->pop(4) === 'defg', 'ring data and mutation guard preserved');
$weak = WeakReference::create($source); unset($source);
check($weak->get() === null, 'ring source destroyed');
check(psampler_binary_test_registry_locked(), 'registry remains locked');
?>
--EXPECT--
valid capability does not acquire: OK
valid bailout propagated and reference released: OK
valid balanced guards: OK
valid reusable buffers: OK
valid source destroyed: OK
split capability does not acquire: OK
split bailout propagated and reference released: OK
split balanced guards: OK
split reusable buffers: OK
split source destroyed: OK
acquire-bailout capability does not acquire: OK
acquire-bailout bailout propagated and reference released: OK
acquire-bailout balanced guards: OK
acquire-bailout reusable buffers: OK
acquire-bailout source destroyed: OK
ring bailout reference released: OK
ring data and mutation guard preserved: OK
ring source destroyed: OK
registry remains locked: OK
