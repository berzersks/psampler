--TEST--
Native append preserves two-span source order and destination wraparound and growth
--EXTENSIONS--
psampler
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
$source = new ByteBuffer(8);
$source->append('abcdefgh');
$source->discard(5);
$source->append("\0ijk"); // two spans: fgh + NUL ijk
$expected = "fgh\0ijk";
$destination = new ByteBuffer(8);
$destination->append('123456');
$destination->discard(5);
$destination->appendBuffer($source); // exact fit, wraps destination
check($destination->capacity() === 8);
check($destination->peek(8) === '6' . $expected);
$destination->appendBuffer($source, 2, 4); // growth of wrapped destination
check($destination->capacity() === 16);
check($destination->pop(12) === '6' . $expected . "h\0ij");
check($source->peek(7) === $expected);
foreach ([[0, 3], [3, 4], [2, 4], [7, 0]] as [$offset, $length]) {
    $destination->appendBuffer($source, $offset, $length);
    check($destination->pop($length) === substr($expected, $offset, $length));
}
$model = '';
for ($i = 0; $i < 300; ++$i) {
    $source->clear();
    $source->append(pack('V', $i) . "\0\xff");
    $destination->appendBuffer($source);
    $model .= pack('V', $i) . "\0\xff";
    if ($i % 3 === 0) {
        check($destination->pop(5) === substr($model, 0, 5));
        $model = substr($model, 5);
    }
    check($destination->peek(strlen($model)) === $model);
}
check($destination->pop(strlen($model)) === $model);
echo "ring order, exact capacity, growth and repeated reuse OK\n";
?>
--EXPECT--
ring order, exact capacity, growth and repeated reuse OK
