--TEST--
Resampler shares bounded FIR banks safely across more live rate pairs than the cache limit
--EXTENSIONS--
psampler
--FILE--
<?php
$pcm = str_repeat(pack('v*', 0, 12000, 65536 - 12000, 32767, 32768, 17, 65519, 0), 64);
$resamplers = [];
for ($i = 0; $i < 40; $i++) {
    $dst = 6000 + $i * 137;
    $resampler = new Resampler(44100, $dst);
    $actual = $resampler->sample($pcm);
    $expected = (new Resampler(44100, $dst))->sample($pcm);
    if ($actual === '' || $actual !== $expected) {
        echo "rate $dst: FAIL\n";
        exit;
    }
    $resamplers[] = $resampler;
}
echo "40 live rate pairs: OK\n";
?>
--EXPECT--
40 live rate pairs: OK
