--TEST--
Binary slice allocation bailout releases the borrowed view
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
// Leave less than one result's space, including builds with Swoole's overhead.
$pressure = str_repeat('y', max(1, 24 * 1024 * 1024 - memory_get_usage(true) - 4 * 1024 * 1024));
$continued = false;
register_shutdown_function(function () use ($source, &$pressure, &$continued) {
    $pressure = '';
    if ($continued || $source->stats() !== [1, 1, false]) throw new Exception('slice bailout guard leak');
    $source->__construct('after');
    if (psampler_binary_slice($source) !== 'after' || $source->stats() !== [2, 2, false]) {
        throw new Exception('source not reusable after allocation bailout');
    }
    echo "slice allocation bailout cleanup OK\n";
});
psampler_binary_slice($source);
$continued = true;
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted %s%a
slice allocation bailout cleanup OK
