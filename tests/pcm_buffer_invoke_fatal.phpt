--TEST--
PCM invoke fatal handler preserves bailout and permits shutdown cleanup
--EXTENSIONS--
psampler
psampler_binary_test
--INI--
display_errors=1
log_errors=0
error_reporting=24575
--FILE--
<?php
$continued = false;
$p = new PcmBuffer(8000, 1); $p->append("\0\xff");
register_shutdown_function(function () use ($p, &$continued) {
    if ($continued || $p->toString() !== "\0\xff" || $p->invoke('psampler.test.lifecycle', 'success', 42) !== 42) throw new Exception('PCM corrupted or locked');
    $p->clear(); $p->append("\x12\x34");
    echo $p->toString() === "\x12\x34" ? "shutdown cleanup: OK\n" : "FAIL\n";
});
try { $p->invoke('psampler.test.lifecycle', 'hook', function () { trigger_error('PCM fatal fixture', E_USER_ERROR); }); }
catch (Throwable $e) { echo "bailout converted to exception\n"; }
$continued = true;
?>
--EXPECTF--
Fatal error: PCM fatal fixture in %s on line %d%a
shutdown cleanup: OK
