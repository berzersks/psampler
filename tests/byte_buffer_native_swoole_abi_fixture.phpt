--TEST--
Built-in bridge startup control using compatible Swoole ABI emulation in existing fixture
--EXTENSIONS--
psampler
--SKIPIF--
<?php
if (!extension_loaded('swoole') || !method_exists('Swoole\\Buffer', 'stats')
    || getenv('PSAMPLER_SWOOLE_TEST_ABI_MODE') !== 'valid') {
    die('skip requires compatible ABI emulation in existing fixture, without real Swoole');
}
?>
--FILE--
<?php
$source = new Swoole\Buffer("a\0b");
var_dump(ByteBuffer::supportsNativeBuffer($source));
$dst = new ByteBuffer(1); $dst->appendBuffer($source);
var_dump($dst->pop(3) === "a\0b");
var_dump($source->stats() === [1, 1, false]);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
