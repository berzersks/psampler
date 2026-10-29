--TEST--
Binary slice balances provider guards on invalid ranges and malformed views
--EXTENSIONS--
psampler
psampler_binary_test
--FILE--
<?php
function check($ok) { if (!$ok) throw new RuntimeException('check failed'); }
foreach (['valid', 'split', 'second-only', 'empty-null'] as $mode) {
    $source = new PsamplerBinaryTestBuffer("a\0bc\xffz", $mode);
    $expected = $mode === 'empty-null' ? '' : "a\0bc\xffz";
    for ($offset = 0; $offset <= strlen($expected); ++$offset) {
        check(psampler_binary_slice($source, $offset) === substr($expected, $offset));
    }
    $stats = $source->stats();
    check($stats[0] === $stats[1] && $stats[2] === false);
    $source->__construct('after');
}
foreach (['null', 'capacity', 'second-overflow', 'overflow', 'capacity-overflow', 'source', 'failure', 'busy', 'exception-success'] as $mode) {
    $source = new PsamplerBinaryTestBuffer('x', $mode);
    try { psampler_binary_slice($source); throw new RuntimeException('accepted invalid view'); }
    catch (Error | Exception $error) { check(!($error instanceof RuntimeException)); }
    $acquired = in_array($mode, ['failure', 'busy'], true) ? 0 : 1;
    check($source->stats() === [$acquired, $acquired, false]);
    $source->__construct('after');
    check(psampler_binary_slice($source) === 'after');
}
$source = new PsamplerBinaryTestBuffer('abc', 'split');
foreach ([[4, 0], [2, 2], [1, PHP_INT_MAX]] as [$offset, $length]) {
    $before = $source->stats();
    try { psampler_binary_slice($source, $offset, $length); throw new RuntimeException('accepted invalid range'); }
    catch (ValueError $error) {}
    check($source->stats() === [$before[0] + 1, $before[1] + 1, false]);
}
psampler_binary_test_read($source, function () use ($source) {
    try { psampler_binary_slice($source, 0, 0); throw new RuntimeException('recursive borrow'); }
    catch (Error $error) {}
});
$uninitialized = psampler_binary_test_uninitialized($source);
try { psampler_binary_slice($uninitialized); throw new RuntimeException('uninitialized source'); }
catch (Error $error) {}
check($uninitialized->stats() === [0, 0, false]);
// An empty range at the advertised limit performs no pointer arithmetic/copy.
$source = new PsamplerBinaryTestBuffer('x', 'huge');
check(psampler_binary_slice($source, PHP_INT_MAX, 0) === '');
check($source->stats() === [1, 1, false]);
$weak = WeakReference::create($source);
unset($source);
check($weak->get() === null);
echo "provider validation, guards, string limits and source release OK\n";
?>
--EXPECT--
provider validation, guards, string limits and source release OK
