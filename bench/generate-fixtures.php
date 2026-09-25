<?php

declare(strict_types=1);

$root = __DIR__ . '/fixtures';
if (!is_dir($root)) mkdir($root, 0775, true);
$kinds = ['silence', 'tone425', 'tone950', 'tone1000', 'alternating_tones',
    'white_noise', 'comfort_noise', 'voice_synthetic', 'short_voice', 'long_voice',
    'tone_voice', 'tone_silence_voice', 'music_like', 'jingle', 'mixed_noise_voice',
    'dc_constant', 'clipping', 'low_volume', 'high_volume'];
foreach ([1, 3, 5, 10, 15] as $seconds) {
    foreach ($kinds as $kind) {
        $state = 0x12345678;
        $pcm = '';
        for ($i = 0, $n = $seconds * 8000; $i < $n; $i++) {
            $ms = $i / 8;
            $state = ($state * 1664525 + 1013904223) & 0xffffffff;
            $noise = ((($state >> 16) & 0xffff) / 32767.5 - 1.0);
            $syllable = (int)floor($ms / 140);
            $fundamentals = [165, 205, 145, 235, 180, 215];
            $formants = [690, 1040, 820, 1260, 760, 1120];
            $f0 = $fundamentals[$syllable % 6];
            $f1 = $formants[$syllable % 6];
            $envelope = 0.38 + 0.52 * (0.5 + 0.5 * sin(2 * M_PI * 4.3 * $ms / 1000));
            $voice = $envelope * (9200 * sin(2 * M_PI * $f0 * $i / 8000)
                + 3900 * sin(2 * M_PI * $f1 * $i / 8000)
                + 1800 * sin(2 * M_PI * ($f1 + 430) * $i / 8000));
            $tone = 9000 * sin(2 * M_PI * 425 * $i / 8000);
            $value = match ($kind) {
                'silence' => 0,
                'tone425' => $tone,
                'tone950' => 9000 * sin(2 * M_PI * 950 * $i / 8000),
                'tone1000' => 9000 * sin(2 * M_PI * 1000 * $i / 8000),
                'alternating_tones' => 9000 * sin(2 * M_PI * ((int)floor($ms / 500) % 2 ? 950 : 425) * $i / 8000),
                'white_noise' => 9000 * $noise,
                'comfort_noise' => 700 * $noise,
                'voice_synthetic' => $voice,
                'short_voice' => $ms < 350 ? $voice : 0,
                'long_voice' => $ms < 300 ? 0 : $voice,
                'tone_voice' => $ms < 600 ? $tone : $voice,
                'tone_silence_voice' => $ms < 600 ? $tone : ($ms < 1800 ? 0 : $voice),
                'music_like' => 5000 * sin(2 * M_PI * 523 * $i / 8000) + 4000 * sin(2 * M_PI * 659 * $i / 8000),
                'jingle' => 4300 * sin(2 * M_PI * 523 * $i / 8000)
                    + 3500 * sin(2 * M_PI * 659 * $i / 8000)
                    + 3000 * sin(2 * M_PI * 784 * $i / 8000)
                    + (fmod($ms, 500) < 70 ? (1 - fmod($ms, 500) / 70) * 1600 * $noise : 0),
                'mixed_noise_voice' => $voice + 1600 * $noise,
                'dc_constant' => 12000,
                'clipping' => $voice >= 0 ? 32767 : -32768,
                'low_volume' => $voice * 0.04,
                'high_volume' => $voice * 2.0,
            };
            $sample = max(-32768, min(32767, (int)$value));
            $pcm .= pack('v', $sample & 0xffff);
        }
        file_put_contents(sprintf('%s/%02ds_%s.pcm', $root, $seconds, $kind), $pcm);
    }
}
echo count($kinds) * 5, " deterministic PCM fixtures generated\n";
