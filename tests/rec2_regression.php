<?php
declare(strict_types=1);
require __DIR__.'/acoustic_stream_suite.php';
$path=$argv[1] ?? getenv('PSAMPLER_REC2_8K');
check(is_string($path) && is_file($path), 'supply normalized PCM16 mono 8k WAV');
$wav=file_get_contents($path);$pcm=null;
for($at=12;$at+8<=strlen($wav);){
 $id=substr($wav,$at,4);$n=unpack('V',substr($wav,$at+4,4))[1];
 if($id==='fmt '){$f=unpack('vformat/vchannels/Vrate/Vbytes/vstride/vbits',substr($wav,$at+8,16));check($f['format']===1&&$f['channels']===1&&$f['rate']===8000&&$f['bits']===16,'PCM16 mono 8k');}
 if($id==='data')$pcm=substr($wav,$at+8,$n);$at+=8+$n+($n%2);
}
check($pcm!==null,'data chunk');
$reference=null;
foreach([[320],[160],[640],[960],[1,7,319,1001]] as $chunks){
 [$events]=analyzeStream($pcm,$chunks);
 if($reference===null)$reference=$events;else check($events===$reference,'chunk invariance');
}
// Prepend rather than trim: preserve the already short initial pulse.
foreach([0,1,7,19,40,80,110,200,270,330,499] as $shift){
 [$e]=analyzeStream(silence($shift).$pcm,[1,319,1001]);
 check(countEvent($e,'ring.started')===3,"three real pulses shift $shift");
 check(countEvent($e,'ring.ended')===3,"three real pulse ends shift $shift");
 check(countEvent($e,'ring.cadence_detected')===1,"cadence shift $shift");
 check(countEvent($e,'ring.interrupted')===1,"one real interruption shift $shift");
 $starts=array_column(array_values(array_filter($e,fn($v)=>$v['type']==='speech.started')),'at_ms');
 check(count($starts)>0,'real speech');
 if($shift===0)check($starts[0]===14240,'preserve true speech confirmation latency');
 foreach($starts as $t)check($t-$shift>=14100 && $t-$shift<16400,"tone is not speech shift $shift at $t");
}
// Every sample phase of the problematic attacks; no inherited complete windows.
foreach([[4700,1100],[9640,1100],[16460,600]] as [$at,$duration]){
 for($shift=0;$shift<160;$shift++){
  [$e]=analyzeStream(str_repeat("\0",$shift*2).substr($pcm,$at*16,$duration*16).silence(1000),[17,319,1]);
  check(countEvent($e,'speech.started')===0,"attack $at sample shift $shift");
 }
}
echo "rec2 regression OK: 3 rings, cadence, real speech, 480 attack phases\n";
