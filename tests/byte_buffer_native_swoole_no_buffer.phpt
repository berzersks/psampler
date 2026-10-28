--TEST--
psampler remains independent with upstream Swoole without Buffer
--EXTENSIONS--
psampler
swoole
--SKIPIF--
<?php
if (class_exists('Swoole\\Buffer', false)) die('skip requires Swoole without Buffer');
?>
--FILE--
<?php
var_dump(ByteBuffer::supportsNativeBuffer(new stdClass()));
$source = new ByteBuffer(4); $source->append("a\0b");
$dst = new ByteBuffer(1); $dst->appendBuffer($source);
var_dump($dst->pop(3) === "a\0b");
?>
--EXPECT--
bool(false)
bool(true)
