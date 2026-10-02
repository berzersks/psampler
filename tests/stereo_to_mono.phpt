--TEST--
stereoToMono averages signed PCM16LE without overflow and truncates toward zero
--EXTENSIONS--
psampler
--FILE--
<?php
function check($label, $condition) {
    echo $label, ': ', $condition ? 'OK' : 'FAIL', "\n";
}
check('empty', stereoToMono('') === '');
check('silence', stereoToMono(str_repeat("\0", 16)) === str_repeat("\0", 8));
check('equal', stereoToMono(pack('v*', 12345, 12345, 65536 - 12345, 65536 - 12345))
    === pack('v*', 12345, 65536 - 12345));
check('opposite', stereoToMono(pack('v*', 30000, 65536 - 30000)) === "\0\0");
check('extremes', stereoToMono(pack('v*', 32767, 32767, 32768, 32768, 32767, 32768))
    === pack('v*', 32767, 32768, 0));
check('truncate', stereoToMono(pack('v*', 0, 1, 0, 65535, 2, 1, 65534, 65535))
    === pack('v*', 0, 0, 1, 65535));
$input = "\x00\x01\x00\x03\x00\xff\x00\xfd\x00\x80\x00\x00";
$output = stereoToMono($input);
check('binary multiple frames', $output === "\x00\x02\x00\xfe\x00\xc0");
check('exact length', strlen($output) === strlen($input) / 2);
foreach ([1, 2, 3, 5, 6, 7] as $length) {
    try {
        stereoToMono(str_repeat("\0", $length));
        echo "invalid $length: FAIL\n";
    } catch (ValueError $e) {
        echo "invalid $length: OK\n";
    }
}
try {
    stereoToMono([]);
} catch (TypeError $e) {
    echo "type: OK\n";
}
?>
--EXPECT--
empty: OK
silence: OK
equal: OK
opposite: OK
extremes: OK
truncate: OK
binary multiple frames: OK
exact length: OK
invalid 1: OK
invalid 2: OK
invalid 3: OK
invalid 5: OK
invalid 6: OK
invalid 7: OK
type: OK
