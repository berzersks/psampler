--TEST--
Legacy Resampler LPCM and stereo helpers remain available
--EXTENSIONS--
psampler
--FILE--
<?php
$r = new Resampler(8000, 8000);
var_dump(is_string($r->sample(str_repeat("\0\0", 1024))));
$r->reset();
var_dump(is_string($r->sample(str_repeat("\0\0", 1024), 8000, 8000)));
$l = new LPCM(1, 16);
var_dump($l->decodeMono($l->encodeMono([-32768, 0, 32767])) === [-32768, 0, 32767]);
var_dump(bin2hex(monoToStereo("\x01\x02\x03\x04")) === '0102010203040304');
var_dump(bin2hex(interleavePcmStereo("\x01\x02", "\x03\x04")) === '01020304');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
