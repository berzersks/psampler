<?php
declare(strict_types=1);

function must(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rssKb(): int {
    $status = file_get_contents('/proc/self/status');
    must($status !== false && preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $m) === 1,
        'VmRSS unavailable');
    return (int) $m[1];
}

$pairs = [[48000,8000],[44100,8000],[8000,48000],[8000,44100],
    [48000,44100],[44100,48000]];
foreach ([10,60] as $seconds) {
    foreach ($pairs as [$src,$dst]) {
        $p = new PcmBuffer($src,1);
        $frame = str_repeat("\0\0", intdiv($src,50));
        $output = 0;
        for ($i=0; $i<$seconds*50; $i++) {
            $p->clear(); $p->append($frame); $p->resample($dst);
            $output += intdiv($p->size(),2);
        }
        $p->flush(); $output += intdiv($p->size(),2);
        must($output === $seconds*$dst, "$seconds s $src->$dst: $output samples");
        echo "$seconds s $src->$dst: $output samples OK\n";
    }
}

$before = memory_get_usage(true);
$rssBefore = rssKb();
$rssRounds = [];
for ($round=0; $round<10; $round++) {
    $streams = [];
    for ($i=0; $i<80; $i++) {
        [$src,$dst] = $pairs[$i % count($pairs)];
        $streams[] = [$src,$dst,new PcmBuffer($src, ($i & 1) + 1)];
    }
    for ($frame=0; $frame<200; $frame++) {
        foreach ($streams as $index => [$src,$dst,$pcm]) {
            $channels = ($index & 1) + 1;
            if ($frame === 100) $pcm->reset($src, $channels);
            $pcm->clear();
            $pcm->append(str_repeat("\0\0", intdiv($src,50)*$channels));
            if ($channels === 2) $pcm->toMono();
            $pcm->resample($dst);
            must($pcm->size() % 2 === 0, 'unaligned output');
        }
    }
    foreach ($streams as [,,$pcm]) $pcm->flush();
    unset($streams, $pcm);
    gc_collect_cycles();
    $rssRounds[] = rssKb();
}
gc_collect_cycles();
$after = memory_get_usage(true);
$rssAfter = rssKb();
must($after <= $before + 8*1024*1024, "memory grew: $before -> $after");
must($rssAfter <= $rssBefore + 16*1024, "RSS grew: $rssBefore -> $rssAfter KiB");
must(max(array_slice($rssRounds, -5)) - min(array_slice($rssRounds, -5)) <= 1024,
    'RSS did not plateau: ' . implode(',', $rssRounds));
echo "stress 160000 interleaved frames, reset, flush, destroy: OK; "
    . "memory $before -> $after; RSS $rssBefore -> $rssAfter KiB; "
    . "last rounds " . implode(',', array_slice($rssRounds, -5)) . " KiB\n";
