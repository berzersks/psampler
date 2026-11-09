--TEST--
Streaming ring duration is measured on carrier frames across window alignments
--EXTENSIONS--
psampler
--FILE--
<?php
require __DIR__.'/acoustic_stream_suite.php';
// A nominal 760 ms pulse may lose partial boundary frames. 800 ms is
// sufficient at these phases; 740 ms cannot meet 750 ms of carrier evidence.
foreach([0,1,19,110,249,499] as $shift){
 foreach([700,740,800,1000] as $duration){
  [$events]=analyzeStream(silence($shift).tone($duration).silence(1000),[1,7,319,1001]);
  check(countEvent($events,'ring.started')===($duration>=750?1:0),"duration $duration shift $shift");
 }
}
echo "Carrier duration OK\n";
?>
--EXPECT--
AcousticStreamAnalyzer suite OK
Carrier duration OK
