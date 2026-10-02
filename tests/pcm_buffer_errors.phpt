--TEST--
PcmBuffer rejects invalid rates, channels, incomplete frames and incorrect parameter types
--EXTENSIONS--
psampler
--FILE--
<?php
declare(strict_types=1);
function rejected($label, $callback, $type) {
    try {
        $callback();
        echo $label, ": FAIL\n";
    } catch (Throwable $e) {
        echo $label, ': ', $e instanceof $type ? 'OK' : get_class($e), "\n";
    }
}
rejected('rate zero', fn() => new PcmBuffer(0, 1), ValueError::class);
rejected('rate negative', fn() => new PcmBuffer(-1, 1), ValueError::class);
foreach ([0, -1, 3, 65537] as $channels) {
    rejected("channels $channels", fn() => new PcmBuffer(8000, $channels), ValueError::class);
}
rejected('construct missing', fn() => new PcmBuffer(8000), ArgumentCountError::class);
rejected('construct initial data', fn() => new PcmBuffer(8000, 1, ''), ArgumentCountError::class);
rejected('construct type', fn() => new PcmBuffer('8000', 1), TypeError::class);
$p = new PcmBuffer(8000, 2);
$p->append("\0\0\0\0");
foreach ([1, 2, 3, 5, 6, 7] as $length) {
    rejected("stereo frame $length", fn() => $p->append(str_repeat("\0", $length)), ValueError::class);
}
echo 'preserves bytes: ', $p->toString() === "\0\0\0\0" ? 'OK' : 'FAIL', "\n";
$p->toMono();
rejected('mono frame', fn() => $p->append("\0"), ValueError::class);
rejected('append type', fn() => $p->append([]), TypeError::class);
rejected('resample zero', fn() => $p->resample(0), ValueError::class);
rejected('resample negative', fn() => $p->resample(-8000), ValueError::class);
rejected('resample type', fn() => $p->resample([]), TypeError::class);
rejected('size arguments', fn() => $p->size(1), ArgumentCountError::class);
if (PHP_INT_SIZE === 8) {
    rejected('rate uint32 overflow', fn() => new PcmBuffer(4294967296, 1), ValueError::class);
    rejected('resample uint32 overflow', fn() => $p->resample(4294967296), ValueError::class);
} else {
    echo "rate uint32 overflow: not representable\nresample uint32 overflow: not representable\n";
}
?>
--EXPECTREGEX--
rate zero: OK
rate negative: OK
channels 0: OK
channels -1: OK
channels 3: OK
channels 65537: OK
construct missing: OK
construct initial data: OK
construct type: OK
stereo frame 1: OK
stereo frame 2: OK
stereo frame 3: OK
stereo frame 5: OK
stereo frame 6: OK
stereo frame 7: OK
preserves bytes: OK
mono frame: OK
append type: OK
resample zero: OK
resample negative: OK
resample type: OK
size arguments: OK
rate uint32 overflow: (OK|not representable)
resample uint32 overflow: (OK|not representable)
