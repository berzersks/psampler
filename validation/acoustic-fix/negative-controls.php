<?php
$rows=[];
foreach(['white_noise','chord','am_chord','speech_tts'] as $kind){
 $pcm='';$seed=42;
 if($kind==='speech_tts')$pcm=file_get_contents('/home/lotus/projetos/psampler/tests/acoustic-fixtures/speech.pcm');
 else for($i=0;$i<16000;$i++){
  $seed=($seed*1103515245+12345)&0x7fffffff;$t=$i/8000;
  $s=$kind==='white_noise'?9500*($seed/1073741824-1):4000*(sin(2*M_PI*261.63*$t)+sin(2*M_PI*329.63*$t)+sin(2*M_PI*392*$t));
  if($kind==='am_chord')$s*=0.6+0.4*sin(2*M_PI*6*$t);
  $pcm.=pack('v',((int)round($s))&65535);
 }
 $a=new AcousticStreamAnalyzer;$e=[];foreach(['ring.started','speech.started'] as $n)$a->on($n,function($v)use(&$e){$e[]=$v;});foreach(str_split($pcm,320) as $b)$a->push($b);
 $rows[$kind]=['event_counts'=>array_count_values(array_column($e,'type')),'pcm_signal'=>(new PCMAnalyzer)->analyze($pcm)['signal']];
}echo json_encode($rows,JSON_PRETTY_PRINT),"\n";
