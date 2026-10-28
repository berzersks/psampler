--TEST--
PCM invoke propagates native bailout, clears result and restores guard at a C boundary
--EXTENSIONS--
psampler
psampler_binary_test
--FILE--
<?php
function check($ok, $label) { if (!$ok) throw new Exception($label); echo "$label: OK\n"; }
const OP = 'psampler.test.lifecycle';
class PartialResult {
    public static int $destroyed = 0;
    function __destruct() { self::$destroyed++; }
}
$p = new PcmBuffer(8000, 1); $p->append("\0\xff\x01\x80");
$bytes = $p->toString(); $capacity = $p->capacity();
for ($i = 0; $i < 50; $i++) {
    if (psampler_pcm_test_catch($p, OP, 'bailout') !== [true, true]) throw new Exception('bailout or result slot');
    if ($p->invoke(OP, 'inspect', $p) !== [4, 384, 8000, 1]) throw new Exception('guard or data');
}
check(true, 'bailout propagated and slot cleared');
$weak = null;
check(psampler_pcm_test_catch($p, OP, 'hook-bailout', function () use (&$weak) {
    $result = new PartialResult(); $weak = WeakReference::create($result); return $result;
}) === [true, true], 'partial result bailout propagated');
check(PartialResult::$destroyed === 1 && $weak->get() === null, 'partial result destroyed once');
$o = new stdClass(); $w = WeakReference::create($o);
check(psampler_pcm_test_catch($p, OP, 'bailout', $o) === [true, true] && $w->get() === $o, 'borrowed argument survives');
unset($o); check($w->get() === null, 'no argument reference leak');
check($p->toString() === $bytes && $p->capacity() === $capacity, 'PCM integrity after bailout');
$p->append("\x12\x34"); check($p->size() === 6, 'mutation after bailout');
$w = WeakReference::create($p); unset($p);
check($w->get() === null, 'no PCM reference leak');
?>
--EXPECT--
bailout propagated and slot cleared: OK
partial result bailout propagated: OK
partial result destroyed once: OK
borrowed argument survives: OK
no argument reference leak: OK
PCM integrity after bailout: OK
mutation after bailout: OK
no PCM reference leak: OK
