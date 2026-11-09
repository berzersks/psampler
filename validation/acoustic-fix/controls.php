<?php
// Synthetic controls and shifted excerpts; only diagnostic artifacts are written.
$names=['signal.changed','ring.started','ring.ended','ring.cadence_detected','ring.interrupted','speech.started','speech.ended'];
function streamEvents(string $pcm): array {
    global $names;
    $a=new AcousticStreamAnalyzer; $events=[];
    foreach($names as $name) $a->on($name,function($e)use(&$events){$events[]=$e;});
    foreach(str_split($pcm,320) as $chunk) $a->push($chunk);
    return $events;
}
$r=[];
foreach([425.0,426.349585,427.5,430.0] as $frequency) {
    $pcm='';
    for($i=0;$i<56000;$i++) {
        $t=$i/8000;
        $v=($t<1||($t>=5&&$t<6))?(int)round(8960*sin(2*M_PI*$frequency*$t)):0;
        $pcm.=pack('v',$v&65535);
    }
    $old=(new PCMAnalyzer)->analyze($pcm);
    $r['synthetic'][]=['frequency'=>$frequency,'events'=>streamEvents($pcm),'ring_frames'=>array_slice($old['ring']['frames'],0,2)];
}
$original=file_get_contents(__DIR__.'/audio.s16le');
foreach([[0,40000],[110,40000],[200,40000],[33320,2000],[37180,2000]] as [$start,$duration]) {
    $part=substr($original,$start*16,$duration*16);
    $r['excerpts'][]=['offset_ms'=>$start,'events_relative_ms'=>streamEvents($part)];
}
file_put_contents(__DIR__.'/controls.json',json_encode($r,JSON_PRETTY_PRINT));
foreach($r['synthetic'] as $row) echo $row['frequency'],' Hz ',json_encode(array_column($row['events'],'type')),' purity=', $row['ring_frames'][0]['tone_purity_db'],"\n";
foreach($r['excerpts'] as $row) echo 'offset ',$row['offset_ms'],': ',json_encode($row['events_relative_ms']),"\n";
