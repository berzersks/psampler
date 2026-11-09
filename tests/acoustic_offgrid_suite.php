<?php
declare(strict_types=1);
require __DIR__.'/acoustic_stream_suite.php';
$tested=0;
foreach ([395.5,400.25,421.25,423.75,425,426.35,427.5,428.75,430,449.5,459.5] as $hz) {
    foreach ([500,8960,28000] as $amp) {
        $pcm=tone(2000,$hz,$amp).silence(1000);
        [$e,$s]=analyzeStream($pcm,[1,319,17,640]);
        check(countEvent($e,'ring.started')===1,"off-grid $hz/$amp");
        check(countEvent($e,'speech.started')===0,"off-grid speech $hz/$amp");
        $r=(new PCMAnalyzer)->analyze($pcm);
        check($r['ring']['pulse_count']===1,"legacy off-grid $hz");
        check(abs($r['ring']['frames'][0]['ring_frequency_hz']-$hz)<0.15,"frequency estimate $hz");
        ++$tested;
    }
}
foreach ([350,426.35,700,950,1400,1800,2200] as $hz) {
    $beep='';
    for($i=0;$i<1200;$i++) {
        $t=$i/8000;
        $envelope=(0.1+0.9*min(1,$t/0.06))*(0.7+0.3*cos(2*M_PI*12*$t));
        $beep.=pack('v',((int)round(12000*$envelope*sin(2*M_PI*$hz*$t)))&65535);
    }
    foreach ([0,1,5,9,13,19] as $offset) {
        $pcm=silence($offset).$beep.silence(1200);
        [$e]=analyzeStream($pcm,[7,1,320,1001]);
        check(countEvent($e,'speech.started')===0,"AM beep speech $hz offset $offset");
        check((new PCMAnalyzer)->analyze($pcm)['signal']!=='voice_like',"legacy AM beep $hz offset $offset");
        ++$tested;
    }
}
foreach ([375,475,950,1400] as $hz) {
    [$e]=analyzeStream(tone(2000,$hz).silence(1000),[320]);
    check(countEvent($e,'ring.started')===0,"wrong frequency ring $hz");
}
foreach ([426.35,427.5] as $hz) {
    [$e]=analyzeStream(tone(2000,$hz,12000,1400).silence(1000),[320]);
    check(countEvent($e,'ring.started')===1,"noisy off-grid $hz");
}
echo "Off-grid and AM regression OK ($tested cases)\n";
