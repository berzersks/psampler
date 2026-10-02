--TEST--
PcmBuffer reset restores metadata, retains storage, validates atomically and returns void
--EXTENSIONS--
psampler
--FILE--
<?php
function check($label, $ok) { echo $label, ': ', $ok ? 'OK' : 'FAIL', "\n"; }
$p = new PcmBuffer(44100,2);
check('empty reset', $p->reset(sampleRate:8000, channels:1) === null && $p->capacity() === 0);
$p->reset(44100,2);
$p->append(str_repeat(pack('v*',3000,65533),882));
$p->toMono()->resample(8000);
$capacity = $p->capacity();
check('processed', $p->sampleRate() === 8000 && $p->channels() === 1 && $p->size() > 0);
check('void', $p->reset(44100,2) === null);
check('storage', $p->size() === 0 && $p->capacity() === $capacity && $p->toString() === '');
check('metadata', $p->sampleRate() === 44100 && $p->channels() === 2);
$bytes = pack('v*',32768,32767,65533,0);
$p->append($bytes);
foreach ([[0,2],[-1,2],[4294967296,2],[8000,0],[8000,3]] as [$rate,$channels]) {
    try { $p->reset($rate,$channels); echo "invalid: FAIL\n"; }
    catch (ValueError $e) { check('atomic invalid', $p->sampleRate() === 44100 && $p->channels() === 2
        && $p->size() === 8 && $p->capacity() === $capacity && $p->toString() === $bytes); }
}
$p->clear();
check('clear retains metadata', $p->sampleRate() === 44100 && $p->channels() === 2);
for ($i=0;$i<20;$i++) { $p->reset(44100,2); $p->append($bytes); $p->toMono(); }
check('reused', $p->toString() === stereoToMono($bytes) && $p->capacity() === $capacity);
?>
--EXPECT--
empty reset: OK
processed: OK
void: OK
storage: OK
metadata: OK
atomic invalid: OK
atomic invalid: OK
atomic invalid: OK
atomic invalid: OK
atomic invalid: OK
clear retains metadata: OK
reused: OK
