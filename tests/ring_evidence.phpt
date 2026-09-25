--TEST--
PCMAnalyzer returns ring evidence and resets it between calls
--EXTENSIONS--
psampler
--FILE--
<?php
$a = new PCMAnalyzer();
$tone = '';
for ($i = 0; $i < 8000; $i++) {
    $sample = (int)round(12000 * sin(2 * M_PI * 425 * $i / 8000));
    $tone .= pack('v', $sample & 0xffff);
}
$silence = str_repeat("\0", 8000);
$pcm = $tone . str_repeat($silence, 8) . $tone;
$ring = $a->analyze($pcm)['ring'];
var_dump($ring['pulse_count'] === 2 && $ring['matched_pulse_count'] === 2);
var_dump($ring['periods_ms'] === [5000] && $ring['confidence'] === 0.79);
$empty = $a->analyze('')['ring'];
var_dump($empty['pulse_count'] === 0 && $empty['reason'] === 'pcm_vazio');
$short = $a->analyze("\0\0")['ring'];
var_dump($short['reason'] === 'pcm_muito_curto' && $short['frames'] === []);
var_dump($a->analyze(str_repeat("\0", 320))['ring']['pulse_count'] === 0);
var_dump($a->analyze(str_repeat(pack('v', 4000), 8000))['ring']['disturbance_at_ms'] === null);
var_dump((new PCMAnalyzer())->analyze($pcm)['ring'] === $ring);
$active = str_repeat(pack('v', 10000) . pack('v', 55536), 80);
$many = str_repeat($active . str_repeat("\0", 3200), 65);
var_dump(count($a->analyze(substr($many, 0, 64 * 3520))['segments']) === 64);
try { $a->analyze(substr($many, 0, 240000)); echo "FAILED\n"; }
catch (ValueError) { echo "ValueError\n"; }
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
ValueError
