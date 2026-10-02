--TEST--
Legacy Resampler consumes long input and preserves FIR state across chunks
--EXTENSIONS--
psampler
--FILE--
<?php
foreach ([[44100,8000],[8000,48000],[48000,44100]] as [$src,$dst]) {
    $input = '';
    for ($i=0; $i<$src; $i++) $input .= pack('v', (($i*73)%20001-10000) & 65535);
    $whole = (new Resampler($src,$dst))->sample($input);
    $r = new Resampler($src,$dst); $chunks = '';
    $chunkBytes = intdiv($src,50)*2;
    for ($offset=0; $offset<strlen($input); $offset+=$chunkBytes) {
        $chunks .= $r->sample(substr($input,$offset,$chunkBytes));
    }
    $p = new PcmBuffer($src,1); $p->append($input); $p->resample($dst);
    echo "$src->$dst: ", $whole === $chunks && $whole === $p->toString() ? 'OK' : 'FAIL', "\n";
}
?>
--EXPECT--
44100->8000: OK
8000->48000: OK
48000->44100: OK
