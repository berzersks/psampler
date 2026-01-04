<?php
$data = file_get_contents('audio.wav');
$pos = 12;
while ($pos < strlen($data) - 8) {
    $ch = unpack('a4id/Vsize', substr($data, $pos, 8));
    echo "Chunk: '{$ch['id']}' size: {$ch['size']}\n";
    if ($ch['id'] === 'fmt ') {
        echo "Hex dump of fmt chunk:\n";
        echo bin2hex(substr($data, $pos + 8, min(20, $ch['size']))) . "\n";
        $fmt = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/VbitsPerSample', substr($data, $pos + 8, 16));
        print_r($fmt);
    }
    $pos += 8 + $ch['size'];
    if ($pos > 200) break;
}
