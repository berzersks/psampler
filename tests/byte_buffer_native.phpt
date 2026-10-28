--TEST--
ByteBuffer native append handles empty, binary packets, slices and source reuse
--EXTENSIONS--
psampler
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
$source = new ByteBuffer(1);
$destination = new ByteBuffer(3);
check(ByteBuffer::supportsNativeBuffer($source));
check($destination->appendBuffer($source) === null);
check($destination->length() === 0 && $destination->capacity() === 3);
$all = '';
foreach ([160, 1024, 2048] as $size) {
    $packet = substr(str_repeat("\0\xff\x80abc\0", 300), 0, $size);
    check(strlen($packet) === $size);
    $source->clear();
    $source->append($packet);
    $reference =& $source;
    $destination->appendBuffer($reference);
    $all .= $packet;
    check($source->peek($size) === $packet);
    check($destination->peek(strlen($all)) === $all);
    echo "packet $size OK\n";
}
$source->clear();
$source->append("\0a\0b\xffc");
$destination->clear();
$destination->appendBuffer(source: $source, offset: 1, length: 4);
check($destination->pop(4) === "a\0b\xff");
$destination->appendBuffer($source, 2);
check($destination->pop(4) === "\0b\xffc");
$capacity = $destination->capacity();
$destination->appendBuffer($source, 6, null);
$destination->appendBuffer($source, 6, 0);
check($destination->length() === 0 && $destination->capacity() === $capacity);
$source->clear();
$source->append('new');
check($destination->length() === 0);
$destination->appendBuffer($source);
$source->__construct(2);
check($destination->pop(3) === 'new');
echo "empty, slices and reuse OK\n";
?>
--EXPECT--
packet 160 OK
packet 1024 OK
packet 2048 OK
empty, slices and reuse OK
