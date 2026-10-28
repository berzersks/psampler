--TEST--
PCM failed result destructor bailout clears guard and never destroys the result twice
--EXTENSIONS--
psampler
psampler_binary_test
--INI--
display_errors=1
log_errors=0
--FILE--
<?php
class CleanupBailout {
    public static int $destroyed = 0;
    function __destruct() { self::$destroyed++; psampler_test_bailout(); }
}
$p = new PcmBuffer(8000, 1); $p->append("\0\xff");
$weak = null; $continued = false;
register_shutdown_function(function () use ($p, &$weak, &$continued) {
    if (CleanupBailout::$destroyed !== 1 || $continued || $p->toString() !== "\0\xff"
        || $p->invoke('psampler.test.lifecycle', 'success', 42) !== 42) throw new Exception('cleanup failed');
    $p->clear(); echo "result destructor bailout cleanup: OK\n";
});
$p->invoke('psampler.test.lifecycle', 'hook-failure', function () use (&$weak) {
    $o = new CleanupBailout(); $weak = WeakReference::create($o); return $o;
});
$continued = true;
?>
--EXPECT--
result destructor bailout cleanup: OK
