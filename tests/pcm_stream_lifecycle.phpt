--TEST--
PCM stream clear/reset/flush and invalid inputs remain safe and atomic
--EXTENSIONS--
psampler
--FILE--
<?php
function check($name, $ok) { echo "$name: ", $ok ? 'OK' : 'FAIL', "\n"; }
function rejects($name, $fn) {
    try { $fn(); check($name, false); }
    catch (ValueError $e) { check($name, true); }
}
$p = new PcmBuffer(48000, 1);
$input = str_repeat(pack('v*', 100, 500, 65500, 0), 240);
$p->append($input);
$p->resample(8000);
$first = $p->toString();
$p->clear();
check('clear retains metadata', $p->size() === 0 && $p->sampleRate() === 8000 && $p->channels() === 1);
$p->clear();
$p->append($input);
$p->resample(8000);
check('continued frame', $p->size() > 0 && $p->toString() !== $first);
$p->flush();
check('flush tail', $p->sampleRate() === 8000 && $p->size() > 0);
$p->flush();
check('repeated flush', $p->size() === 0);
$p->clear();
$p->append($input);
rejects('resample after flush', fn() => $p->resample(8000));
$p->reset(48000, 1);
$p->append($input);
$p->resample(8000);
check('reset restarts stream', $p->toString() === $first);
$p->reset(44100, 2);
check('reset reconfigures', $p->size() === 0 && $p->sampleRate() === 44100 && $p->channels() === 2);
rejects('truncated stereo PCM', fn() => $p->append("\0\0"));
$p->append("\0\0\0\0");
rejects('invalid target', fn() => $p->resample(0));
check('valid after error', $p->size() === 4 && $p->sampleRate() === 44100);
$p->reset(4294967295, 1);
$p->append("\0\0");
rejects('absurd source rate', fn() => $p->resample(8000));
check('source preserved', $p->sampleRate() === 4294967295 && $p->toString() === "\0\0");
$p->reset(8000, 1);
$p->append("\0\0");
rejects('absurd target rate', fn() => $p->resample(4294967295));
check('target failure preserved', $p->sampleRate() === 8000 && $p->toString() === "\0\0");
$large = str_repeat("\0\0", 50000);
$p->reset(1000, 1);
$p->append($large);
rejects('large output preflight', fn() => $p->resample(768000));
check('large output atomic', $p->toString() === $large && $p->sampleRate() === 1000);
foreach ([[0, 8000], [-1, 8000], [8000, 0], [8000, 4294967295]] as [$src,$dst]) {
    rejects('legacy invalid rates', fn() => new Resampler($src, $dst));
}
$r = new Resampler(8000, 16000);
rejects('legacy odd PCM', fn() => $r->sample("\0"));
check('legacy valid after error', is_string($r->sample(str_repeat("\0\0", 160))));
?>
--EXPECT--
clear retains metadata: OK
continued frame: OK
flush tail: OK
repeated flush: OK
resample after flush: OK
reset restarts stream: OK
reset reconfigures: OK
truncated stereo PCM: OK
invalid target: OK
valid after error: OK
absurd source rate: OK
source preserved: OK
absurd target rate: OK
target failure preserved: OK
large output preflight: OK
large output atomic: OK
legacy invalid rates: OK
legacy invalid rates: OK
legacy invalid rates: OK
legacy invalid rates: OK
legacy odd PCM: OK
legacy valid after error: OK
