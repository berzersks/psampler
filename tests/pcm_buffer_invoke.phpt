--TEST--
PcmBuffer dispatch rejects unavailable operations and validates invoke argument parsing
--EXTENSIONS--
psampler
--FILE--
<?php
declare(strict_types=1);
function rejected($label, $callback, $type) {
    try {
        $callback();
        echo $label, ": FAIL\n";
    } catch (Throwable $e) {
        echo $label, ': ', $e instanceof $type ? 'OK' : get_class($e), "\n";
    }
}
$p = new PcmBuffer(8000, 1);
$p->append("\0\xff");
var_dump($p->canInvoke('psampler.test.unregistered.operation'));
var_dump($p->canInvoke(''));
var_dump($p->canInvoke("psampler.test.unregistered.operation\0extra"));
rejected('missing operation', fn() => $p->invoke('psampler.test.unregistered.operation'), ValueError::class);
rejected('variadic arguments', fn() => $p->invoke('psampler.test.unregistered.operation', new stdClass(), 42, null, ['option' => true]), ValueError::class);
rejected('named operation', fn() => $p->invoke(operation: 'psampler.test.unregistered.operation'), ValueError::class);
rejected('empty operation', fn() => $p->invoke(''), ValueError::class);
rejected('invoke missing', fn() => $p->invoke(), ArgumentCountError::class);
rejected('invoke type', fn() => $p->invoke([]), TypeError::class);
rejected('canInvoke missing', fn() => $p->canInvoke(), ArgumentCountError::class);
rejected('canInvoke type', fn() => $p->canInvoke([]), TypeError::class);
rejected('extra named argument', fn() => $p->invoke('psampler.test.unregistered.operation', option: true), Error::class);
echo 'state preserved: ', $p->toString() === "\0\xff" && $p->sampleRate() === 8000 && $p->channels() === 1 ? 'OK' : 'FAIL', "\n";
$method = new ReflectionMethod(PcmBuffer::class, 'invoke');
echo 'variadic arginfo: ', $method->getParameters()[1]->isVariadic() && (string) $method->getReturnType() === 'mixed' ? 'OK' : 'FAIL', "\n";
?>
--EXPECT--
bool(false)
bool(false)
bool(false)
missing operation: OK
variadic arguments: OK
named operation: OK
empty operation: OK
invoke missing: OK
invoke type: OK
canInvoke missing: OK
canInvoke type: OK
extra named argument: OK
state preserved: OK
variadic arginfo: OK
