--TEST--
PcmBuffer owns contiguous binary PCM with metadata, growth, clear and destruction
--EXTENSIONS--
psampler
--FILE--
<?php
function check($label, $condition) {
    echo $label, ': ', $condition ? 'OK' : 'FAIL', "\n";
}
$pcm = new PcmBuffer(sampleRate: 44100, channels: 2);
check('construct', $pcm->sampleRate() === 44100 && $pcm->channels() === 2);
check('initial storage', $pcm->size() === 0 && $pcm->capacity() === 0 && $pcm->toString() === '');
check('append empty', $pcm->append('') === null && $pcm->capacity() === 0);
$data = "\0\xff\x80\0\xff\0\0\x80";
check('append void', $pcm->append($data) === null);
check('binary', $pcm->toString() === $data && $pcm->size() === 8);
$capacity = $pcm->capacity();
check('capacity', $capacity >= $pcm->size());
$snapshot = $pcm->toString();
$block = str_repeat("\x01\0\0\xff", intdiv($capacity, 4) + 1);
$pcm->append($block);
check('growth', $pcm->capacity() > $capacity && $pcm->capacity() >= $pcm->size());
check('preserve bytes', $pcm->toString() === $data . $block && $snapshot === $data);
$capacity = $pcm->capacity();
check('clear void', $pcm->clear() === null);
check('clear storage', $pcm->size() === 0 && $pcm->toString() === '' && $pcm->capacity() === $capacity);
check('clear metadata', $pcm->sampleRate() === 44100 && $pcm->channels() === 2);
$pcm->append($data);
$pcm->__construct(8000, 1);
check('reconstruct', $pcm->size() === 0 && $pcm->capacity() === $capacity
    && $pcm->sampleRate() === 8000 && $pcm->channels() === 1);
try {
    $pcm->__construct(0, 2);
} catch (ValueError $e) {
    check('invalid reconstruct preserves state', $pcm->sampleRate() === 8000 && $pcm->channels() === 1);
}
check('final', (new ReflectionClass(PcmBuffer::class))->isFinal());
try {
    clone $pcm;
} catch (Error $e) {
    echo "clone rejected: OK\n";
}
try {
    serialize($pcm);
} catch (Exception $e) {
    echo "serialize rejected: OK\n";
}
try {
    unserialize('O:9:"PcmBuffer":0:{}');
} catch (Exception $e) {
    echo "unserialize rejected: OK\n";
}
$pcm->append(str_repeat("\0\xff", 10000));
$weak = WeakReference::create($pcm);
unset($pcm);
check('destructor', $weak->get() === null);
// Repeated create/grow/convert/free exercises ownership on every storage path.
for ($i = 0; $i < 20; $i++) {
    $p = new PcmBuffer(8000, 1);
    $p->append(str_repeat("\0\xff", 3000));
    $p->toStereo()->toMono()->resample(16000);
    unset($p);
}
echo "lifecycle exercised\n";
?>
--EXPECT--
construct: OK
initial storage: OK
append empty: OK
append void: OK
binary: OK
capacity: OK
growth: OK
preserve bytes: OK
clear void: OK
clear storage: OK
clear metadata: OK
reconstruct: OK
invalid reconstruct preserves state: OK
final: OK
clone rejected: OK
serialize rejected: OK
unserialize rejected: OK
destructor: OK
lifecycle exercised
