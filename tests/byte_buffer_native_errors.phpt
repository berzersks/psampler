--TEST--
Native append rejects unsupported objects and invalid ranges without changing destination
--EXTENSIONS--
psampler
--FILE--
<?php
declare(strict_types=1);
function rejects($callback, $class) {
    try { $callback(); throw new RuntimeException('not rejected'); }
    catch (Throwable $error) { if (!$error instanceof $class) throw $error; }
}
$source = new ByteBuffer(4);
$source->append("a\0bc");
$destination = new ByteBuffer(8);
$destination->append('keep');
$capacity = $destination->capacity();
foreach ([
    [fn() => $destination->appendBuffer(new stdClass()), ValueError::class],
    [fn() => $destination->appendBuffer(new PcmBuffer(8000, 1)), ValueError::class],
    [fn() => $destination->appendBuffer($source, -1), ValueError::class],
    [fn() => $destination->appendBuffer($source, 0, -1), ValueError::class],
    [fn() => $destination->appendBuffer($source, 5), ValueError::class],
    [fn() => $destination->appendBuffer($source, 3, 2), ValueError::class],
    [fn() => $destination->appendBuffer($source, PHP_INT_MAX, PHP_INT_MAX), ValueError::class],
    [fn() => $destination->appendBuffer($source, 1, PHP_INT_MAX), ValueError::class],
    [fn() => $destination->appendBuffer($destination), ValueError::class],
    [fn() => $destination->appendBuffer($destination, 0, 0), ValueError::class],
    [fn() => $destination->appendBuffer('bytes'), TypeError::class],
    [fn() => $destination->appendBuffer($source, null), TypeError::class],
    [fn() => $destination->appendBuffer($source, 0, []), TypeError::class],
    [fn() => $destination->appendBuffer(), ArgumentCountError::class],
    [fn() => ByteBuffer::supportsNativeBuffer('bytes'), TypeError::class],
] as [$callback, $class]) {
    rejects($callback, $class);
    if ($destination->peek(4) !== 'keep' || $destination->capacity() !== $capacity) {
        throw new RuntimeException('partial destination modification');
    }
}
$destination->appendBuffer($source);
var_dump($destination->pop(8) === "keepa\0bc");
$method = new ReflectionMethod(ByteBuffer::class, 'appendBuffer');
var_dump((string) $method->getReturnType() === 'void');
var_dump($method->getParameters()[2]->isDefaultValueAvailable()
    && $method->getParameters()[2]->getDefaultValue() === null);
echo "validation is atomic and locks are released OK\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
validation is atomic and locks are released OK
