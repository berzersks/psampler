--TEST--
PCM v1 invoke results, exceptions, recursion, ownership and buffer integrity
--EXTENSIONS--
psampler
psampler_binary_test
--FILE--
<?php
function check($ok, $label) { if (!$ok) throw new Exception($label); echo "$label: OK\n"; }
const OP = 'psampler.test.lifecycle';
$p = new PcmBuffer(8000, 1);
$p->append("\0\xff\x01\x80");
$bytes = $p->toString();
$capacity = $p->capacity();
check($p->invoke(OP, 'inspect', $p) === [4, 384, 8000, 1], 'PCM v1 getters and registry');
check($p->invoke(OP, 'success') === null, 'normal null');
$o = new stdClass(); $w = WeakReference::create($o);
$result = $p->invoke(OP, 'success', $o);
unset($o);
check($w->get() === $result, 'result owns reference');
unset($result);
check($w->get() === null, 'result destruction');
foreach (['failure' => Error::class, 'exception' => Exception::class, 'exception-success' => Exception::class] as $mode => $type) {
    $o = new stdClass(); $w = WeakReference::create($o);
    try { $p->invoke(OP, $mode, $o); throw new LogicException('accepted'); }
    catch (Throwable $e) { check(get_class($e) === $type, "$mode exception"); }
    check($w->get() === $o, "$mode argument retained");
    unset($e, $o);
    check($w->get() === null, "$mode partial result freed");
    check($p->invoke(OP, 'success', 42) === 42, "$mode guard restored");
}
$mutations = [
    fn() => $p->invoke(OP, 'success'), fn() => $p->clear(),
    fn() => $p->append("\0\0"), fn() => $p->reset(16000, 2),
    fn() => $p->__construct(16000, 2), fn() => $p->toMono(),
    fn() => $p->toStereo(), fn() => $p->resample(16000), fn() => $p->flush(),
];
foreach ($mutations as $mutation) {
    try { $p->invoke(OP, 'hook', $mutation); throw new LogicException('accepted'); }
    catch (Error $e) { if (!str_contains($e->getMessage(), 'during invoke()')) throw $e; }
}
check($p->invoke(OP, 'hook', fn() => $p->toString()) === $bytes, 'read permitted and recursion rejected');
check($p->toString() === $bytes && $p->capacity() === $capacity
    && $p->sampleRate() === 8000 && $p->channels() === 1, 'PCM preserved');
unset($mutations, $mutation, $e);
$weakPcm = WeakReference::create($p);
$result = $p->invoke(OP, 'hook', function ($arg) use (&$p, $weakPcm) {
    $p = null;
    return $weakPcm->get() === $arg;
}, $p);
check($result && $weakPcm->get() === null, 'method and argument ownership');
class CleanupThrows {
    public static int $destroyed = 0;
    function __destruct() { self::$destroyed++; throw new RuntimeException('cleanup exception'); }
}
$p = new PcmBuffer(8000, 1);
try {
    $p->invoke(OP, 'hook-failure', fn() => new CleanupThrows());
    throw new LogicException('accepted');
} catch (RuntimeException $e) { check($e->getMessage() === 'cleanup exception', 'result cleanup exception'); }
check(CleanupThrows::$destroyed === 1 && $p->invoke(OP, 'success', true), 'cleanup exception guard and single destruction');
?>
--EXPECT--
PCM v1 getters and registry: OK
normal null: OK
result owns reference: OK
result destruction: OK
failure exception: OK
failure argument retained: OK
failure partial result freed: OK
failure guard restored: OK
exception exception: OK
exception argument retained: OK
exception partial result freed: OK
exception guard restored: OK
exception-success exception: OK
exception-success argument retained: OK
exception-success partial result freed: OK
exception-success guard restored: OK
read permitted and recursion rejected: OK
PCM preserved: OK
method and argument ownership: OK
result cleanup exception: OK
cleanup exception guard and single destruction: OK
