<?php
function rss(){preg_match('/^VmRSS:\s+(\d+)/m',file_get_contents('/proc/self/status'),$m);return (int)$m[1];}
$pcm=file_get_contents('/tmp/psampler-validation/after/audio.s16le');$chunks=str_split($pcm,320);$a=new AcousticStreamAnalyzer;
foreach($chunks as $b)$a->push($b);$a->reset();gc_collect_cycles();$first=rss();$mem=memory_get_usage();
$t=hrtime(true);for($i=0;$i<100;$i++){foreach($chunks as $b)$a->push($b);$a->reset();}
echo json_encode(['repetitions'=>100,'rss_before_kb'=>$first,'rss_after_kb'=>rss(),'php_heap_delta'=>memory_get_usage()-$mem,'processing_ms'=>(hrtime(true)-$t)/1e6]),"\n";
