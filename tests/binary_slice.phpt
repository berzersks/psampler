--TEST--
Binary slice copies only the requested logical range, including wrapped rings
--EXTENSIONS--
psampler
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
$source = new ByteBuffer(8);
check(psampler_binary_slice($source) === '');
check(psampler_binary_slice($source, 0, 0) === '');
$source->append('abcdef');
$source->discard(5);
$source->append("g\0\xffjk"); // Physical wrap; logical contents: f g NUL FF j k.
$expected = "fg\0\xffjk";
for ($offset = 0; $offset <= strlen($expected); ++$offset) {
    check(psampler_binary_slice(source: $source, offset: $offset) === substr($expected, $offset));
    for ($length = 0; $length <= strlen($expected) - $offset; ++$length) {
        check(psampler_binary_slice($source, $offset, $length) === substr($expected, $offset, $length));
    }
}
$copy = psampler_binary_slice($source, 1, 4);
check($source->length() === 6 && $source->capacity() === 8);
foreach ([[-1, null], [0, -1], [7, null], [6, 1], [1, PHP_INT_MAX], [PHP_INT_MAX, 0]] as [$offset, $length]) {
    try { psampler_binary_slice($source, $offset, $length); throw new RuntimeException('accepted invalid range'); }
    catch (ValueError $error) {}
    check($source->peek(6) === $expected);
}
try { psampler_binary_slice(new stdClass(), 0, 0); throw new RuntimeException('accepted unsupported source'); }
catch (ValueError $error) {}
$source->clear();
$source->append('reused');
unset($source);
check($copy === "g\0\xffj");
echo "ranges, wrapped spans, empty buffers, errors and ownership OK\n";
?>
--EXPECT--
ranges, wrapped spans, empty buffers, errors and ownership OK
