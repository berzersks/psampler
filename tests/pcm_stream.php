<?php
declare(strict_types=1);

function ensure(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function fixture(int $rate, int $channels, int $seconds): string {
    $pcm = '';
    for ($i = 0, $n = $rate * $seconds; $i < $n; $i++) {
        $left = (($i * 127 + intdiv($i, 47) * 103) % 24001) - 12000;
        $pcm .= pack('v', $left & 65535);
        if ($channels === 2) {
            $right = (($i * 71 + intdiv($i, 29) * 211) % 28001) - 14000;
            $pcm .= pack('v', $right & 65535);
        }
    }
    return $pcm;
}

function convert(string $input, int $src, int $dst, int $channels, int $chunkMs): string {
    $pcm = new PcmBuffer($src, $channels);
    $result = '';
    $chunkBytes = intdiv($src * $chunkMs, 1000) * $channels * 2;
    ensure($chunkBytes > 0, 'empty chunk');
    for ($offset = 0, $length = strlen($input); $offset < $length; $offset += $chunkBytes) {
        $pcm->clear();
        $pcm->append(substr($input, $offset, $chunkBytes));
        $pcm->resample($dst);
        $result .= $pcm->toString();
    }
    $pcm->flush();
    $result .= $pcm->toString();
    $pcm->flush();
    ensure($pcm->size() === 0, 'repeated flush');
    return $result;
}

$pairs = [
    [48000, 8000], [44100, 8000], [32000, 8000], [24000, 8000], [16000, 8000],
    [8000, 16000], [8000, 24000], [8000, 32000], [8000, 44100], [8000, 48000],
    [44100, 48000], [48000, 44100], [16000, 44100], [44100, 16000],
];
foreach ($pairs as [$src, $dst]) {
    foreach ([1, 2] as $channels) {
        $input = fixture($src, $channels, 1);
        $whole = convert($input, $src, $dst, $channels, 1000);
        $expected = (int) round($src * $dst / $src) * $channels * 2;
        ensure(strlen($whole) === $expected, "$src->$dst/$channels duration: " . strlen($whole) . " != $expected");
        foreach ([5, 10, 20, 30, 40] as $ms) {
            $chunks = convert($input, $src, $dst, $channels, $ms);
            ensure($whole === $chunks, "$src->$dst/$channels/{$ms}ms mismatch: "
                . hash('sha256', $whole) . ' != ' . hash('sha256', $chunks));
        }
    }
    echo "$src->$dst mono/stereo and chunks: OK\n";
}

/* Interleaving must produce exactly the same bytes as isolated streams. */
$configs = [[48000,8000],[44100,8000],[8000,48000],[48000,44100]];
$inputs = $isolated = $active = $interleaved = [];
foreach ($configs as $index => [$src,$dst]) {
    $inputs[$index] = fixture($src, 1, 1);
    $isolated[$index] = convert($inputs[$index], $src, $dst, 1, 20);
    $active[$index] = new PcmBuffer($src, 1);
    $interleaved[$index] = '';
}
for ($frame = 0; $frame < 50; $frame++) {
    foreach ($configs as $index => [$src,$dst]) {
        $pcm = $active[$index];
        $pcm->clear();
        $pcm->append(substr($inputs[$index], $frame * $src / 50 * 2, $src / 50 * 2));
        $pcm->resample($dst);
        $interleaved[$index] .= $pcm->toString();
    }
}
foreach ($configs as $index => $_) {
    $active[$index]->flush();
    $interleaved[$index] .= $active[$index]->toString();
    ensure($interleaved[$index] === $isolated[$index], "interleaved stream $index");
}
echo "interleaved streams: OK\n";

foreach ([[48000,8000],[44100,8000],[8000,48000],[8000,44100]] as [$src,$dst]) {
    foreach ([0,1,2,3,31,32,33,63,64,65] as $samples) {
        $input = str_repeat(pack('v', 1234), $samples);
        $actual = convert($input,$src,$dst,1,20);
        $expected = (int) round($samples*$dst/$src);
        ensure(strlen($actual) === $expected*2,
            "short input $src->$dst/$samples: " . intdiv(strlen($actual),2) . " != $expected");
    }
}
echo "short/empty inputs and flush: OK\n";
