<?php
// Run only with the requested, already compiled PHP. No dependencies or writes outside this directory.
$dir = __DIR__;
$pcm = file_get_contents("$dir/audio.s16le");
$names = ['signal.changed','ring.started','ring.ended','ring.cadence_detected','ring.interrupted','speech.started','speech.ended'];
$a = new AcousticStreamAnalyzer(8000,20);
$events = []; $states = []; $pushEnd = 0;
foreach ($names as $name) $a->on($name, function(array $e) use (&$events,&$pushEnd) {
    $e['push_end_ms'] = $pushEnd; $events[] = $e;
});
$t = hrtime(true);
for ($i=0;$i<strlen($pcm);$i+=320) {
    $pushEnd = ($i+320)/16;
    $a->push(substr($pcm,$i,320));
    $states[] = $a->state();
}
$elapsed = (hrtime(true)-$t)/1e6;
file_put_contents("$dir/events.json",json_encode($events,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents("$dir/states.json",json_encode($states));
$old = new PCMAnalyzer(8000,20);
try { $old->analyze($pcm); $full = 'unexpected success'; } catch (ValueError $e) { $full = $e->getMessage(); }
$windows = [];
foreach ([[0,15000],[15000,15000],[30000,15000],[45000,3760], [25000,15000],[33760,15000]] as [$start,$duration]) {
    $t=hrtime(true); $r=$old->analyze(substr($pcm,$start*16,$duration*16));
    $windows[]=['offset_ms'=>$start,'processing_ms'=>(hrtime(true)-$t)/1e6,'result'=>$r];
}
file_put_contents("$dir/pcm-analyzer.json",json_encode(['full_input_error'=>$full,'windows'=>$windows],JSON_PRETTY_PRINT));
// Timings exclude file I/O and state() polling; include callbacks for streaming.
$bench=['stream_ms'=>[],'pcm_full_coverage_ms'=>[]];
$chunks=str_split($pcm,320); $pieces=str_split($pcm,240000);
for($repeat=0;$repeat<31;$repeat++) {
    $b=new AcousticStreamAnalyzer(8000,20);
    foreach($names as $name) $b->on($name,static function(array $e) {});
    $t=hrtime(true); foreach($chunks as $chunk) $b->push($chunk);
    $ms=(hrtime(true)-$t)/1e6;
    $t=hrtime(true); foreach($pieces as $piece) $old->analyze($piece);
    $legacy=(hrtime(true)-$t)/1e6;
    if($repeat>0) { $bench['stream_ms'][]=$ms; $bench['pcm_full_coverage_ms'][]=$legacy; }
}
$summary=['php'=>PHP_VERSION,'psampler'=>phpversion('psampler'),'pcm_bytes'=>strlen($pcm),'chunks'=>count($chunks),'event_count'=>count($events),'event_counts'=>array_count_values(array_column($events,'type')),'instrumented_ms'=>$elapsed,'final_state'=>$a->state(),'benchmark'=>$bench];
file_put_contents("$dir/summary.json",json_encode($summary,JSON_PRETTY_PRINT));
echo json_encode($summary,JSON_PRETTY_PRINT),"\n";
