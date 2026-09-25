--TEST--
PCMAnalyzer validates PCM16 and isolates consecutive analyses
--EXTENSIONS--
psampler
--FILE--
<?php
$a = new PCMAnalyzer(sampleRate: 8000, frameDurationMs: 20);
$empty = $a->analyze('');
var_dump($empty['duration_ms'] === 0 && $empty['signal'] === 'silence');
var_dump($a->analyze("\0\0")['duration_ms'] === 0);
var_dump($a->analyze(str_repeat("\0", 318))['signal'] === 'silence');
var_dump($a->analyze(str_repeat("\0", 322))['duration_ms'] === 20);
foreach ([1, 7999, 20000] as $samples) {
    $r = $a->analyze(str_repeat("\0\0", $samples));
    var_dump($r['signal'] === 'silence' && $r['voice_ms'] === 0);
}
foreach ([425, 950, 1000] as $frequency) {
    $pcm = '';
    for ($i = 0; $i < 8000; $i++) $pcm .= pack('v', ((int)(9000 * sin(2 * M_PI * $frequency * $i / 8000))) & 0xffff);
    var_dump($a->analyze($pcm)['signal'] === 'narrowband_tone');
}
var_dump($a->analyze(str_repeat(pack('v', 12000), 8000))['signal'] === 'silence');
foreach (["\0", str_repeat("\0", 240001), str_repeat("\0", 240002)] as $pcm) {
    try { $a->analyze($pcm); echo "FAILED\n"; } catch (ValueError $error) { echo "ValueError\n"; }
}
foreach ([[16000, 20], [8000, 0], [0, 20], [8000, 40]] as [$rate, $frame]) {
    try { new PCMAnalyzer($rate, $frame); echo "FAILED\n"; } catch (ValueError $error) { echo "ValueError\n"; }
}
$bad = str_repeat(str_repeat(pack('v', 10000) . pack('v', (-10000) & 0xffff), 80)
    . str_repeat("\0", 160 * 10 * 2), 69);
try { $a->analyze(substr($bad, 0, 240000)); echo "FAILED\n"; } catch (ValueError $error) { echo "ValueError\n"; }
var_dump($a->analyze(str_repeat("\0", 320))['signal'] === 'silence');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
ValueError
ValueError
ValueError
ValueError
ValueError
ValueError
ValueError
ValueError
bool(true)
