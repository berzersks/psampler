--TEST--
PCM result allocation failure restores invoke guard before fatal shutdown
--EXTENSIONS--
psampler
psampler_binary_test
--INI--
memory_limit=16M
display_errors=1
log_errors=0
--FILE--
<?php
$continued = false;
$p = new PcmBuffer(8000, 1); $p->append("\0\xff");
register_shutdown_function(function () use ($p, &$continued) {
    if ($continued || $p->toString() !== "\0\xff" || $p->invoke('psampler.test.lifecycle', 'success', 42) !== 42) throw new Exception('allocation cleanup failed');
    $p->clear(); echo "allocation bailout cleanup: OK\n";
});
$p->invoke('psampler.test.lifecycle', 'oom');
$continued = true;
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted %s%a
allocation bailout cleanup: OK
