--TEST--
Native append allocation bailout releases source view and preserves destination atomically
--EXTENSIONS--
psampler
psampler_binary_test
--INI--
memory_limit=24M
display_errors=1
log_errors=0
--FILE--
<?php
$source = new PsamplerBinaryTestBuffer(str_repeat('x', 8 * 1024 * 1024), 'split');
$dst = new ByteBuffer(4); $dst->append('keep');
$continued = false;
register_shutdown_function(function () use ($source, $dst, &$continued) {
    if ($continued || $source->stats() !== [1, 1, false]
        || $dst->capacity() !== 4 || $dst->pop(4) !== 'keep') throw new Exception('append bailout cleanup failed');
    $source->__construct('after'); $dst->appendBuffer($source);
    if ($dst->pop(5) !== 'after' || $source->stats() !== [2, 2, false]) throw new Exception('buffers remained locked');
    echo "append bailout atomicity and cleanup: OK\n";
});
$dst->appendBuffer($source);
$continued = true;
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted %s%a
append bailout atomicity and cleanup: OK
