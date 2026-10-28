--TEST--
Isolated native provider reads borrowed bytes without payload strings and pins source lifetime
--EXTENSIONS--
psampler
psampler_binary_test
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
check(psampler_binary_test_registry_locked());
check(psampler_binary_test_pin());
$source = new PsamplerBinaryTestBuffer('', 'empty-null');
check(psampler_binary_test_read($source) === ['length' => 0, 'checksum' => 0, 'spans' => 0]);
check($source->stats() === [1, 1, false]);
foreach ([160, 1024, 2048] as $size) {
    $bytes = substr(str_repeat("\0\xff\x80abc\0", 300), 0, $size);
    $checksum = 0;
    foreach (unpack('C*', $bytes) as $byte) $checksum = ($checksum + $byte) % 65521;
    foreach (['valid', 'split', 'second-only'] as $mode) {
        $source->__construct($bytes, $mode);
        $result = psampler_binary_test_read($source);
        check($result === ['length' => $size, 'checksum' => $checksum, 'spans' => $mode === 'split' ? 2 : 1]);
        $destination = new ByteBuffer(3);
        $destination->appendBuffer($source, 1, $size - 2);
        check($destination->pop($size - 2) === substr($bytes, 1, -1));
    }
    echo "direct read and copy $size OK\n";
}
$ring = new ByteBuffer(4);
$ring->append("a\0bc");
$ring->discard(3);
$ring->append('de');
check(psampler_binary_test_read($ring) === ['length' => 3, 'checksum' => ord('c') + ord('d') + ord('e'), 'spans' => 2]);
$ring->clear();
check(psampler_binary_test_read($ring)['length'] === 0);
echo "registry, NULL empty spans, ring read and sole owner pin OK\n";
?>
--EXPECT--
direct read and copy 160 OK
direct read and copy 1024 OK
direct read and copy 2048 OK
registry, NULL empty spans, ring read and sole owner pin OK
