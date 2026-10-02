--TEST--
PcmBuffer resampling reuses the legacy DSP for mono and independent stereo channels
--EXTENSIONS--
psampler
--FILE--
<?php
function check($label, $condition) {
    echo $label, ': ', $condition ? 'OK' : 'FAIL', "\n";
}
$left = str_repeat(pack('v*', 0, 12000, 65536 - 12000, 32767, 32768, 17, 65519, 0), 256);
$right = str_repeat(pack('v*', 32767, 0, 15, 65521, 32768, 6000, 65536 - 6000, 0), 256);
$p = new PcmBuffer(44100, 1);
$p->append($left);
$capacity = $p->capacity();
check('same rate', $p->resample(44100) === $p && $p->toString() === $left
    && $p->capacity() === $capacity && $p->sampleRate() === 44100);
$expectedLeft = (new Resampler(44100, 8000))->process($left);
check('downsample identity', $p->resample(8000) === $p);
check('legacy mono parity', $p->toString() === $expectedLeft && $p->size() === strlen($expectedLeft));
check('downsample metadata', $p->sampleRate() === 8000 && $p->channels() === 1 && $p->capacity() >= $p->size());
$expectedUp = (new Resampler(8000, 16000))->sample($expectedLeft);
$p->resample(16000);
check('upsample parity', $p->toString() === $expectedUp && $p->sampleRate() === 16000);
$expectedRight = (new Resampler(44100, 8000))->sample($right);
$s = new PcmBuffer(44100, 2);
$s->append(interleavePcmStereo($left, $right));
$s->resample(8000);
check('independent stereo parity', $s->toString() === interleavePcmStereo($expectedLeft, $expectedRight)
    && $s->channels() === 2 && $s->size() % 4 === 0);
$c = new PcmBuffer(44100, 2);
$input = interleavePcmStereo($left, $right);
$c->append($input);
$expectedChain = (new Resampler(44100, 8000))->sample(stereoToMono($input));
check('chain identity', $c->toMono()->resample(8000)->toStereo() === $c);
check('chain result', $c->toString() === monoToStereo($expectedChain)
    && $c->channels() === 2 && $c->sampleRate() === 8000);
$long = new PcmBuffer(44100, 1);
$long->append(str_repeat("\0\0", 20000));
$long->resample(8000);
check('whole input beyond 8192', $long->size() > 6000 && $long->size() < 7400
    && $long->toString() === str_repeat("\0", $long->size()));
$short = new PcmBuffer(44100, 1);
$short->append(str_repeat("\0\0", 32));
$short->resample(8000);
check('legacy short block', $short->size() === 0 && $short->sampleRate() === 8000);
$fail = new PcmBuffer(44100, 1);
$data = str_repeat("\0\0", 9000);
$fail->append($data);
$capacity = $fail->capacity();
try {
    $fail->resample(1);
    echo "unadvanceable ratio: FAIL\n";
} catch (ValueError $e) {
    check('unadvanceable ratio', $fail->toString() === $data && $fail->sampleRate() === 44100
        && $fail->channels() === 1 && $fail->capacity() === $capacity);
}
?>
--EXPECT--
same rate: OK
downsample identity: OK
legacy mono parity: OK
downsample metadata: OK
upsample parity: OK
independent stereo parity: OK
chain identity: OK
chain result: OK
whole input beyond 8192: OK
legacy short block: OK
unadvanceable ratio: OK
