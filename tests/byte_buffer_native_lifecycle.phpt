--TEST--
Native append retains no source reference or borrowed bytes after return
--EXTENSIONS--
psampler
--FILE--
<?php
$destination = new ByteBuffer(1);
for ($i = 0; $i < 200; ++$i) {
    $source = new ByteBuffer(4);
    $source->append("a\0bc");
    $weak = WeakReference::create($source);
    $destination->appendBuffer($source);
    $source->clear();
    $source->append('new');
    unset($source);
    if ($weak->get() !== null || $destination->pop(4) !== "a\0bc") {
        throw new RuntimeException('source retained or destination aliases source');
    }
}
echo "independent data and source destruction OK\n";
?>
--EXPECT--
independent data and source destruction OK
