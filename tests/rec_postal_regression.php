<?php
require __DIR__.'/acoustic_stream_suite.php';
$wav=file_get_contents($argv[1] ?? '/home/lotus/projetos/libspech/rec_postal.wav');
check(substr($wav,0,4)==='RIFF' && substr($wav,8,4)==='WAVE','WAV header');
$pcm=null;
for($at=12;$at+8<=strlen($wav);) {
    $id=substr($wav,$at,4);$n=unpack('V',substr($wav,$at+4,4))[1];
    if($id==='fmt ') {
        $fmt=unpack('vformat/vchannels/Vrate/Vbytes/vstride/vbits',substr($wav,$at+8,16));
        check($fmt['format']===1 && $fmt['channels']===1 && $fmt['rate']===8000 && $fmt['bits']===16,'PCM16 mono 8k required');
    }
    if($id==='data')$pcm=substr($wav,$at+8,$n);
    $at+=8+$n+($n%2);
}
check($pcm!==null,'WAV data missing');$rows=[];
foreach ([0,1,7,19,40,80,110,200] as $offset) {
 [$events]=analyzeStream(substr($pcm,$offset*16),[1,7,319,1001]);
 $starts=array_column(array_values(array_filter($events,fn($e)=>$e['type']==='speech.started')),'at_ms');
 check(countEvent($events,'ring.started')===6,"real ring starts offset $offset");
 check(countEvent($events,'ring.ended')===6,"real ring ends offset $offset");
 check(countEvent($events,'ring.interrupted')===1,"real interruption $offset");
 if($offset===0) check($starts===[31200,44520,44800,46920],'approved postal speech transitions');
 check(countEvent($events,'ring.cadence_detected')>=1,"real cadence $offset");
 foreach($starts as $t) check($t+$offset<33333 || $t+$offset>33600,"real beep $offset");
 $rows[]=['offset_ms'=>$offset,'speech_absolute_ms'=>array_map(fn($t)=>$t+$offset,$starts)];
}
// Shift a standalone real bip by every possible sample offset of a 20ms frame.
for($shift=0;$shift<160;$shift++) {
 $b=str_repeat("\0",$shift*2).substr($pcm,33320*16,400*16).silence(1000);
 [$e]=analyzeStream($b,[17,319,1]);
 check(countEvent($e,'speech.started')===0,"real bip sample alignment $shift");
 check((new PCMAnalyzer)->analyze($b)['signal']!=='voice_like',"legacy real bip alignment $shift");
}
echo json_encode($rows,JSON_PRETTY_PRINT),"\n";
echo "Real WAV alignment OK: 8 full streams, 160 bip alignments\n";
