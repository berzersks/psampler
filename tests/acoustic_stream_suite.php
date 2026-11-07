<?php
declare(strict_types=1);
// Standalone as well as PHPT: no services and no build steps.
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function tone(int $ms, float $hz = 425, float $amplitude = 12000, float $noise = 0, bool $clip = false): string {
    $pcm = ''; $seed = 42;
    for ($i = 0; $i < $ms * 8; ++$i) {
        $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
        $s = $amplitude * sin(2 * M_PI * $hz * $i / 8000) + $noise * ($seed / 1073741824 - 1);
        $limit = $clip ? 8000 : 32767;
        $pcm .= pack('v', ((int)round(max(-$limit, min($limit, $s)))) & 0xffff);
    }
    return $pcm;
}
function silence(int $ms): string { return str_repeat("\0", $ms * 16); }
const EVENTS = ['ring.started', 'ring.ended', 'ring.cadence_detected', 'ring.interrupted', 'speech.started', 'speech.ended', 'signal.changed'];
function analyzeStream(string $pcm, array $chunks): array {
    $a = new AcousticStreamAnalyzer;
    $events = [];
    foreach (EVENTS as $name) $a->on($name, static function (array $e) use (&$events): void { $events[] = $e; });
    $offset = $i = 0;
    while ($offset < strlen($pcm)) {
        $n = $chunks[$i++ % count($chunks)];
        $a->push(substr($pcm, $offset, $n)); $offset += $n;
    }
    $last = -1; $speech = $ring = false;
    foreach ($events as $e) {
        check($e['at_ms'] >= $last, 'events must be chronological'); $last = $e['at_ms'];
        check($e['at_ms'] === $e['detected_at_ms'], 'confirmation timestamps');
        if ($e['type'] === 'ring.started') { check(!$ring, 'duplicate ring start'); $ring = true; }
        if ($e['type'] === 'ring.ended') { check($ring, 'unpaired ring end'); $ring = false; }
        if ($e['type'] === 'speech.started') { check(!$speech, 'duplicate speech start'); $speech = true; }
        if ($e['type'] === 'speech.ended') { check($speech, 'unpaired speech end'); $speech = false; }
    }
    return [$events, $a->state()];
}
function countEvent(array $events, string $type): int { return count(array_filter($events, fn($e) => $e['type'] === $type)); }
function expectException(callable $action, string $class): void {
    try { $action(); } catch (Throwable $e) { check($e instanceof $class, "expected $class, got " . get_class($e)); return; }
    throw new RuntimeException("expected $class");
}
$voice = file_get_contents(__DIR__ . '/acoustic-fixtures/speech.pcm');
check($voice !== false && strlen($voice) > 32000, 'laboratory speech fixture');
$cadence = tone(1000) . silence(4000) . tone(1000) . silence(4000) . tone(1000) . silence(1000);
$cases = [
    'silence' => silence(3000), 'continuous' => tone(3000), 'cadence' => $cadence,
    'timeout' => tone(1000) . silence(4000) . tone(1000) . silence(8000),
    'jitter' => tone(1100) . silence(4400) . tone(1050) . silence(3950) . tone(1200) . silence(1000),
    'interrupted' => tone(1000) . silence(1000) . tone(2000, 950) . silence(1000),
    'speech' => $voice . silence(1500),
    'ring_speech' => tone(1000) . silence(1000) . $voice . silence(1500),
    'white_noise' => tone(3000, 425, 0, 9500),
    'amplitude' => tone(1000, 425, 180) . tone(1000, 425, 30000) . silence(1000),
    'clipping' => tone(2000, 425, 30000, 0, true) . silence(1000),
    'noisy_ring' => tone(2000, 425, 12000, 1400) . silence(1000),
];
foreach ([395, 420, 430, 450, 460, 950] as $hz) $cases["frequency_$hz"] = tone(2000, $hz) . silence(1000);
foreach ($cases as $name => $pcm) {
    $reference = analyzeStream($pcm, [240000]);
    foreach ([[160], [320], [480], [640], [960], [1, 7, 319, 1001, 2, 17, 511]] as $chunks)
        check(analyzeStream($pcm, $chunks) === $reference, "$name depends on fragmentation");
    [$events, $state] = $reference;
    if ($name === 'silence') check($events === [], 'silence produced events');
    if ($name === 'continuous') check(countEvent($events, 'ring.started') === 1, 'continuous ring missing');
    if ($name === 'cadence') {
        check(countEvent($events, 'ring.started') === 3, 'three pulses');
        check(countEvent($events, 'ring.cadence_detected') === 1, 'cadence transition');
    }
    if ($name === 'timeout') {
        check(countEvent($events, 'ring.interrupted') === 1 && !$state['speech_active'], 'timeout is not speech');
        $interrupts = array_values(array_filter($events, fn($e) => $e['type'] === 'ring.interrupted'));
        check($interrupts[0]['reason'] === 'cadence_timeout', 'timeout reason');
    }
    if ($name === 'jitter') check(countEvent($events, 'ring.cadence_detected') >= 1, 'jitter cadence');
    if ($name === 'interrupted') {
        check(countEvent($events, 'ring.interrupted') === 1, 'disturbance missing');
        check(countEvent($events, 'speech.started') === 0, 'other tone is not speech');
    }
    if ($name === 'speech' || $name === 'ring_speech') {
        check(countEvent($events, 'speech.started') > 0, "$name missing voice evidence");
        check(countEvent($events, 'speech.ended') > 0, "$name missing voice end");
    }
    if ($name === 'white_noise') check(countEvent($events, 'speech.started') === 0, 'noise labeled speech');
}
// Shared ring DSP must retain whole-buffer diagnostics on complete windows.
foreach ([395, 425, 450, 460, 950] as $hz) {
    $pcm = tone(2000, $hz);
    $legacy = (new PCMAnalyzer)->analyze($pcm);
    $a = new AcousticStreamAnalyzer; $a->push($pcm);
    $frames = $legacy['ring']['frames']; $last = end($frames);
    check(abs($a->state()['ring_frequency_hz'] - $last['ring_frequency_hz']) < 0.01, 'shared frequency regression');
    check(abs($a->state()['ring_level_dbfs'] - $last['ring_level_dbfs']) < 0.02, 'shared Goertzel level regression');
    check($legacy['signal'] === 'narrowband_tone', 'legacy tone classification regression');
}
$a = new AcousticStreamAnalyzer;
$order = [];
$a->on('ring.started', function ($e) use (&$order) { $order[] = 1; });
$a->on('ring.started', function ($e) use (&$order) { $order[] = 2; });
$a->push(tone(1500)); check($order === [1, 2], 'multiple callbacks order');
$a->reset(); check($a->state() === (new AcousticStreamAnalyzer)->state(), 'reset state');
$a->push(tone(1500)); check($order === [1, 2, 1, 2], 'callbacks survive reset');
$a->reset(); $a->push(''); $a->push("\0"); check($a->state()['pending_bytes'] === 1, 'odd fragment');
$a->push(str_repeat("\0", 318)); check($a->state()['processed_ms'] === 0, 'partial frame');
$a->push("\0"); check($a->state()['processed_ms'] === 20 && $a->state()['pending_bytes'] === 0, 'completed frame');
$b = new AcousticStreamAnalyzer; $b->push(tone(1500));
check(!$a->state()['ring_active'] && $b->state()['ring_active'], 'instance isolation');
expectException(fn() => new AcousticStreamAnalyzer(16000), ValueError::class);
expectException(fn() => new AcousticStreamAnalyzer(8000, 40), ValueError::class);
expectException(fn() => $a->push(str_repeat('x', 240001)), ValueError::class);
expectException(fn() => $a->push([]), TypeError::class);
expectException(fn() => $a->on('unknown', fn() => null), ValueError::class);
expectException(fn() => $a->on('ring.started', 123), TypeError::class);
expectException(fn() => clone $a, Error::class);
expectException(fn() => serialize($a), Exception::class);
$a = new AcousticStreamAnalyzer; $called = 0;
$a->on('ring.started', function () use (&$called) { ++$called; throw new RuntimeException('callback'); });
$a->on('ring.started', function () use (&$called) { $called += 100; });
expectException(fn() => $a->push(tone(1500)), RuntimeException::class);
check($called === 1 && $a->state()['processed_ms'] === 1500, 'exception delivery contract');
$a->push(silence(1000)); $a->reset(); // busy flag cleared
$a = new AcousticStreamAnalyzer;
$a->on('ring.started', function () use ($a) {
    foreach ([fn() => $a->push(''), fn() => $a->reset(), fn() => $a->on('ring.started', fn() => null), fn() => $a->__construct()] as $action)
        expectException($action, Error::class);
    check($a->state()['ring_active'], 'read-only state in callback');
});
$a->push(tone(1500));
// Cycles involving retained callbacks must be visible to Zend GC.
$a = new AcousticStreamAnalyzer; $weak = WeakReference::create($a);
$a->on('ring.started', function () use ($a) {}); unset($a); gc_collect_cycles();
check($weak->get() === null, 'callback cycle leaked');
$a = new AcousticStreamAnalyzer; $block = silence(20);
for ($i = 0; $i < 100; ++$i) $a->push($block);
$before = memory_get_usage();
for ($i = 0; $i < 20000; ++$i) $a->push($block);
check(memory_get_usage() - $before < 65536, 'memory grows with stream duration');
echo "AcousticStreamAnalyzer suite OK\n";
