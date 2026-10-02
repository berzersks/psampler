--TEST--
PcmBuffer channel transforms mutate and return the same object using shared PCM16LE kernels
--EXTENSIONS--
psampler
--FILE--
<?php
function check($label, $condition) {
    echo $label, ': ', $condition ? 'OK' : 'FAIL', "\n";
}
$stereo = pack('v*', 32767, 32767, 32768, 32768, 0, 65535, 2, 1, 32767, 32768);
$p = new PcmBuffer(44100, 2);
$p->append($stereo);
$capacity = $p->capacity();
check('mono identity', $p->toMono() === $p);
check('mono core', $p->toString() === stereoToMono($stereo));
check('mono metadata', $p->channels() === 1 && $p->size() === strlen($stereo) / 2
    && $p->sampleRate() === 44100 && $p->capacity() === $capacity);
$mono = $p->toString();
check('mono no-op', $p->toMono() === $p && $p->toString() === $mono && $p->capacity() === $capacity);
check('stereo identity', $p->toStereo() === $p);
check('stereo core', $p->toString() === monoToStereo($mono));
check('stereo metadata', $p->channels() === 2 && $p->size() === strlen($mono) * 2 && $p->sampleRate() === 44100);
$bytes = $p->toString();
$capacity = $p->capacity();
check('stereo no-op', $p->toStereo() === $p && $p->toString() === $bytes && $p->capacity() === $capacity);
$q = new PcmBuffer(8000, 1);
$source = str_repeat("\0\xff\x00\x80\xff\x7f\0\0", 512);
$q->append($source);
$capacity = $q->capacity();
$q->toStereo();
check('expansion growth', $q->capacity() > $capacity && $q->toString() === monoToStereo($source));
check('round trip', $q->toMono()->toString() === $source);
check('chained identity', $q->toStereo()->toMono()->resample(8000) === $q);
check('empty chain', ($e = new PcmBuffer(44100, 2))->toMono()->resample(8000)->toStereo() === $e);
check('empty state', $e->size() === 0 && $e->capacity() === 0 && $e->toString() === ''
    && $e->sampleRate() === 8000 && $e->channels() === 2);
?>
--EXPECT--
mono identity: OK
mono core: OK
mono metadata: OK
mono no-op: OK
stereo identity: OK
stereo core: OK
stereo metadata: OK
stereo no-op: OK
expansion growth: OK
round trip: OK
chained identity: OK
empty chain: OK
empty state: OK
