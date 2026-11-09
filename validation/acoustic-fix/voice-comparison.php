<?php
$pcm=file_get_contents('/tmp/psampler-validation/after/audio.s16le');$rows=[];
foreach([0,1,7,19,40,80,110,200] as $off){$a=new AcousticStreamAnalyzer;$e=[];$a->on('speech.started',function($v)use(&$e,$off){$e[]=$v['at_ms']+$off;});foreach(str_split(substr($pcm,$off*16),320) as $b)$a->push($b);$rows[]=['offset_ms'=>$off,'starts'=>$e];}echo json_encode($rows,JSON_PRETTY_PRINT),"\n";
