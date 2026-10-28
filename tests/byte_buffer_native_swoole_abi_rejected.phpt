--TEST--
Built-in bridge refuses incompatible Swoole ABI at startup without breaking psampler
--EXTENSIONS--
psampler
--SKIPIF--
<?php
if (!extension_loaded('swoole') || !method_exists('Swoole\\Buffer', 'stats')
    || !getenv('PSAMPLER_SWOOLE_TEST_ABI_MODE')
    || getenv('PSAMPLER_SWOOLE_TEST_ABI_MODE') === 'valid') {
    die('skip requires existing fixture built in Swoole ABI emulation mode, without real Swoole');
}
?>
--FILE--
<?php
$source = new Swoole\Buffer("a\0b");
var_dump(ByteBuffer::supportsNativeBuffer($source));
$dst = new ByteBuffer(4); $dst->append('keep');
try { $dst->appendBuffer($source); throw new Exception('incompatible ABI accepted'); }
catch (ValueError $error) { echo "incompatible ABI rejected\n"; }
var_dump($source->stats() === [0, 0, false]);
var_dump($dst->pop(4) === 'keep');
$native = new ByteBuffer(4); $native->append("a\0b"); $dst->appendBuffer($native);
var_dump($dst->pop(3) === "a\0b");
?>
--EXPECT--
bool(false)
incompatible ABI rejected
bool(true)
bool(true)
bool(true)
