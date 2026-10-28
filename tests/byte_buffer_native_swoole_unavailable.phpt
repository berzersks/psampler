--TEST--
Swoole Buffer without a registered public C provider is rejected without conversion
--EXTENSIONS--
psampler
swoole
--SKIPIF--
<?php
if (!class_exists('Swoole\\Buffer', false)) die('skip Swoole build has no Buffer');
$source = new Swoole\Buffer(16);
if (ByteBuffer::supportsNativeBuffer($source)) die('skip native provider already installed');
?>
--FILE--
<?php
$source = new Swoole\Buffer(16);
$destination = new ByteBuffer(4);
$destination->append('keep');
var_dump(ByteBuffer::supportsNativeBuffer($source));
try { $destination->appendBuffer($source); }
catch (ValueError $error) { echo "public native provider required\n"; }
var_dump($destination->peek(4) === 'keep' && $destination->capacity() === 4);
?>
--EXPECT--
bool(false)
public native provider required
bool(true)
