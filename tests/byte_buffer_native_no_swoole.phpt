--TEST--
Native byte infrastructure loads and operates without Swoole
--EXTENSIONS--
psampler
--SKIPIF--
<?php
if (extension_loaded('swoole')) die('skip run in a PHP process without Swoole');
?>
--FILE--
<?php
var_dump(class_exists('Swoole\\Buffer', false));
// A user class with this name cannot masquerade as a native provider.
eval('namespace Swoole; class Buffer { public function toString() { throw new \\RuntimeException("must not be called"); } }');
$source = new Swoole\Buffer();
var_dump(ByteBuffer::supportsNativeBuffer($source));
$destination = new ByteBuffer();
try { $destination->appendBuffer($source); }
catch (ValueError $error) { echo "unsupported producer\n"; }
$source = new ByteBuffer();
$source->append("\0ok");
$destination->appendBuffer($source);
var_dump($destination->pop(3) === "\0ok");
?>
--EXPECT--
bool(false)
bool(false)
unsupported producer
bool(true)
