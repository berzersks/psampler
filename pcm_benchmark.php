#!/usr/bin/env php
<?php
declare(strict_types=1);

const PCM_FIXTURE_SLOTS = 8;

function pcmAssert(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function pcmOptions(array $argv): array {
    $c = ['calls'=>50, 'frames'=>1000, 'ptime'=>20, 'source-rate'=>44100,
        'source-channels'=>2, 'target-rate'=>8000, 'target-channels'=>1,
        'runtime'=>'throughput', 'verify'=>false];
    $explicitFrames = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Uso: php pcm_benchmark.php [--calls=50] [--frames=1000] [--duration=SECONDS]\n",
                "  [--runtime=throughput|realtime] [--ptime=20] [--source-rate=44100]\n",
                "  [--source-channels=2] [--target-rate=8000] [--target-channels=1] [--verify]\n",
                "--frames prevalece sobre --duration; frames são por chamada.\n";
            exit(0);
        }
        if ($arg === '--verify') { $c['verify'] = true; continue; }
        pcmAssert((bool)preg_match('/^--([a-z-]+)=(.+)$/D', $arg, $m), "argumento inválido: $arg");
        [$unused, $key, $value] = $m;
        pcmAssert(array_key_exists($key, $c) || $key === 'duration', "opção desconhecida: $key");
        if ($key === 'runtime') { $c[$key] = $value; continue; }
        if ($key === 'duration') {
            pcmAssert(is_numeric($value) && is_finite((float)$value) && (float)$value > 0, 'duration inválida');
            $c[$key] = (float)$value; continue;
        }
        pcmAssert($key !== 'verify' && (bool)preg_match('/^[0-9]+$/D', $value) && (float)$value < (float)PHP_INT_MAX && (int)$value > 0, "$key deve ser inteiro positivo");
        $c[$key] = (int)$value;
        if ($key === 'frames') { $explicitFrames = true; }
    }
    pcmAssert(in_array($c['runtime'], ['throughput','realtime'], true), 'runtime inválido');
    pcmAssert($c['ptime'] <= 2147483647, 'ptime fora de alcance');
    if (isset($c['duration']) && !$explicitFrames) {
        $frames = ceil($c['duration'] * 1000 / $c['ptime']);
        pcmAssert($frames < (float)PHP_INT_MAX, 'duration causa overflow');
        $c['frames'] = (int)$frames;
    }
    pcmAssert($c['source-rate'] <= 4294967295 && $c['target-rate'] <= 4294967295, 'taxas fora de alcance');
    pcmAssert(in_array($c['source-channels'], [1,2], true) && in_array($c['target-channels'], [1,2], true)
        && $c['target-channels'] <= $c['source-channels'], 'somente mono/stereo e downmix são suportados');
    $samplesMs = $c['source-rate'] * $c['ptime'];
    pcmAssert(is_int($samplesMs) && $samplesMs % 1000 === 0, 'ptime deve conter número inteiro de samples');
    $c['samples'] = intdiv($samplesMs, 1000);
    $c['frame-bytes'] = $c['samples'] * $c['source-channels'] * 2;
    pcmAssert($c['samples'] > 0 && is_int($c['frame-bytes']) &&
        $c['calls'] <= intdiv(PHP_INT_MAX, $c['frames']) &&
        $c['calls'] * $c['frames'] <= intdiv(PHP_INT_MAX, $c['frame-bytes']), 'contagem de bytes causa overflow');
    return $c;
}
// Integer triangle tones: identical formula and truncation in Go.
function pcmTone(int $index, int $hz, int $rate): int {
    $phase = intdiv($index * $hz * 4096, $rate) % 4096;
    return $phase < 2048 ? $phase - 1024 : 3072 - $phase;
}
function pcmFixture(int $rate, int $channels, int $n, int $slot): string {
    $b = '';
    for ($j=0; $j<$n; $j++) {
        $index = $slot * $n + $j;
        $gain = 6 + ($slot % 3) * 5 + intdiv($j * 3, $n) * 3;
        $l = intdiv((pcmTone($index,440,$rate)*3 + pcmTone($index,997,$rate))*$gain, 4);
        $r = intdiv((pcmTone($index+17,659,$rate)*3 - pcmTone($index,123,$rate))*$gain, 4);
        if ($slot === 7 || ($j >= intdiv($n,3) && $j < intdiv($n,2))) { $l=0; $r=0; }
        $b .= pack('v', $l & 65535);
        if ($channels === 2) { $b .= pack('v', $r & 65535); }
    }
    return $b;
}
function pcmPipeline(PcmBuffer $p, string $b, array $c): void {
    if ($c['source-rate'] === $c['target-rate']) {
        $p->reset($c['source-rate'], $c['source-channels']);
    } else {
        $p->clear();
    }
    $p->append($b);
    if ($c['source-channels'] === 2 && $c['target-channels'] === 1) { $p->toMono(); }
    $p->resample($c['target-rate']);
}
final class PcmCallState {
    public PcmBuffer $pcm;
    public int $frames = 0, $bytes = 0, $misses = 0, $delay = 0, $maxDelay = 0;
    public function __construct(array $c) { $this->pcm = new PcmBuffer($c['source-rate'], $c['source-channels']); }
}
function pcmCpu(): array {
    $r = getrusage();
    pcmAssert(is_array($r), 'getrusage indisponível');
    return [$r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec']/1e6,
        $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec']/1e6];
}
function pcmReferenceDownmix(string $b): string {
    $out = '';
    for ($i=0; $i<strlen($b); $i+=4) {
        $l = ord($b[$i]) | (ord($b[$i+1]) << 8);
        $r = ord($b[$i+2]) | (ord($b[$i+3]) << 8);
        if ($l >= 32768) { $l -= 65536; }
        if ($r >= 32768) { $r -= 65536; }
        $out .= pack('v', intdiv($l+$r,2) & 65535);
    }
    return $out;
}
function pcmVerifyDownmix(array $bank, array $c): string {
    $edges = pack('v*',32768,32768,32767,32767,32768,32767,65533,0,3,0,32768,0,32767,0);
    $inputs = [$edges];
    if ($c['source-channels'] === 2) { $inputs = array_merge($inputs, $bank); }
    $h = hash_init('sha256');
    foreach ($inputs as $b) {
        $expected = pcmReferenceDownmix($b);
        pcmAssert(stereoToMono($b) === $expected, 'stereoToMono: downmix não bit-exact');
        $p = new PcmBuffer($c['source-rate'],2); $p->append($b); $p->toMono();
        pcmAssert($p->toString() === $expected, 'PcmBuffer: downmix não bit-exact');
        hash_update($h,$expected);
    }
    return hash_final($h);
}
function pcmValidate(PcmBuffer $p, string $b, array $c): string {
    pcmAssert($p->sampleRate() === $c['target-rate'] && $p->channels() === $c['target-channels'], 'metadata final inválida');
    $output = $p->toString(); // Only called AFTER the measured interval.
    pcmAssert(strlen($output) === $p->size() && $p->capacity() >= $p->size()
        && $p->size() % (2 * $c['target-channels']) === 0, 'size/alinhamento/capacity inválidos');
    $n = intdiv(strlen($b), 2 * $c['source-channels']);
    $out = intdiv(strlen($output), 2 * $c['target-channels']);
    $tolerance = 64 / $c['source-rate'] + 2 / $c['target-rate'];
    pcmAssert(abs($out/$c['target-rate'] - $n/$c['source-rate']) <= $tolerance, 'duração de saída inválida');
    if ($c['source-rate'] === $c['target-rate'] || max($n-32,0)*$c['target-rate']/$c['source-rate'] >= 1) {
        pcmAssert($out > 0, 'saída inesperadamente vazia');
    }
    return hash('sha256',$output);
}
function pcmMain(array $argv): void {
    $c = pcmOptions($argv);
    pcmAssert(class_exists('PcmBuffer') && method_exists('PcmBuffer','reset'), 'recompile/carregue psampler com PcmBuffer::reset()');
    pcmAssert(extension_loaded('swoole') && class_exists(Swoole\Coroutine\Channel::class), 'a extensão Swoole com coroutines é necessária');
    pcmAssert(function_exists('getrusage'), 'getrusage é necessário');
    $bank = []; $fixtureHash = hash_init('sha256');
    for ($i=0; $i<PCM_FIXTURE_SLOTS; $i++) {
        $bank[$i] = pcmFixture($c['source-rate'],$c['source-channels'],$c['samples'],$i);
        hash_update($fixtureHash,$bank[$i]);
    }
    $fixtureHash = hash_final($fixtureHash);
    $states = [];
    for ($i=0; $i<$c['calls']; $i++) {
        $state = new PcmCallState($c); $states[] = $state;
        foreach ($bank as $b) { pcmPipeline($state->pcm,$b,$c); } // Warm capacity/DSP paths before barrier.
        $state->pcm->reset($c['source-rate'], $c['source-channels']);
    }
    $initial = $initialReal = $peak = $peakReal = $final = $finalReal = 0;
    $u0 = $s0 = $u1 = $s1 = $elapsed = 0.0;
    Swoole\Coroutine\run(static function() use ($states,$bank,$c,
        &$initial,&$initialReal,&$peak,&$peakReal,&$final,&$finalReal,
        &$u0,&$s0,&$u1,&$s1,&$elapsed): void {
        $ready = new Swoole\Coroutine\Channel($c['calls']);
        $startGate = new Swoole\Coroutine\Channel($c['calls']);
        $completed = new Swoole\Coroutine\Channel($c['calls']);
        foreach ($states as $callId => $state) {
            Swoole\Coroutine::create(static function() use ($callId,$state,$bank,$c,$ready,$startGate,$completed): void {
                $ready->push(true);
                $start = $startGate->pop();
                try {
                    $tick = $c['ptime'] * 1000000;
                    for ($frame=0; $frame<$c['frames']; $frame++) {
                        if ($c['runtime'] === 'realtime') {
                            $deadlineStart = $start + $frame * $tick;
                            while (($wait = $deadlineStart - hrtime(true)) > 0) {
                                Swoole\Coroutine::sleep(max(0.001, $wait / 1e9));
                            }
                        }
                        pcmPipeline($state->pcm,$bank[$frame % PCM_FIXTURE_SLOTS],$c);
                        $state->frames++; $state->bytes += $state->pcm->size();
                        if ($c['runtime'] === 'realtime') {
                            $late = hrtime(true) - $deadlineStart;
                            $state->delay += $late; $state->maxDelay = max($state->maxDelay,$late);
                            if ($late > $tick) { $state->misses++; }
                        }
                    }
                    $completed->push(['call_id'=>$callId, 'error'=>null]);
                } catch (Throwable $error) {
                    $completed->push(['call_id'=>$callId, 'error'=>$error]);
                }
            });
        }
        for ($i=0; $i<$c['calls']; $i++) { $ready->pop(); }
        if (function_exists('memory_reset_peak_usage')) { memory_reset_peak_usage(); }
        $initial = memory_get_usage(); $initialReal = memory_get_usage(true);
        [$u0,$s0] = pcmCpu(); $start = hrtime(true);
        for ($i=0; $i<$c['calls']; $i++) { $startGate->push($start); }
        for ($i=0; $i<$c['calls']; $i++) {
            $result = $completed->pop();
            if ($result['error'] instanceof Throwable) {
                throw new RuntimeException("coroutine {$result['call_id']} falhou", 0, $result['error']);
            }
        }
        $elapsed = (hrtime(true)-$start)/1e9; [$u1,$s1] = pcmCpu();
        $final = memory_get_usage(); $peak = memory_get_peak_usage();
        $finalReal = memory_get_usage(true); $peakReal = memory_get_peak_usage(true);
    });
    // Everything below, including byte export, reference DSP and hashes, is untimed.
    $frames=0; $bytes=0; $misses=0; $delay=0; $maxDelay=0;
    foreach ($states as $state) {
        pcmAssert($state->frames === $c['frames'], 'contagem de frames inválida');
        pcmAssert($state->pcm->sampleRate() === $c['target-rate'] && $state->pcm->channels() === $c['target-channels'], 'metadata final inválida');
        $frames += $state->frames; $bytes += $state->bytes; $misses += $state->misses;
        $delay += $state->delay; $maxDelay = max($maxDelay,$state->maxDelay);
    }
    $downmixHash=pcmVerifyDownmix($bank,$c);
    $reference = new PcmBuffer($c['source-rate'],$c['source-channels']); $referenceBytes=0;
    for ($frame=0; $frame<$c['frames']; $frame++) {
        pcmPipeline($reference,$bank[$frame % PCM_FIXTURE_SLOTS],$c);
        $referenceBytes += $reference->size();
    }
    $expectedBytes = $c['calls'] * $referenceBytes;
    pcmAssert($frames === $c['calls']*$c['frames'] && $bytes === $expectedBytes, 'contagem de frames/bytes inválida');
    $hashes=[];
    for ($i=0; $i<min($c['calls'],3); $i++) {
        $hashes[]=pcmValidate($states[$i]->pcm,$bank[($c['frames']-1)%PCM_FIXTURE_SLOTS],$c);
        pcmAssert($states[$i]->pcm->toString() === $reference->toString(), 'resultado não determinístico entre streams');
    }
    if ($c['verify']) {
        $second = new PcmBuffer($c['source-rate'],$c['source-channels']);
        for ($frame=0; $frame<$c['frames']; $frame++) {
            pcmPipeline($second,$bank[$frame % PCM_FIXTURE_SLOTS],$c);
        }
        pcmAssert($second->toString() === $reference->toString(), 'reprodução completa da stream divergiu');
    }
    $audio = $frames * $c['samples'] / $c['source-rate']; $cpu = $u1-$u0+$s1-$s0;
    $report = ['language'=>'PHP','implementation'=>'psampler PcmBuffer / cached native sinc-Kaiser FIR',
        'scheduler'=>'Swoole Coroutine',
        'runtime_mode'=>$c['runtime'],'calls'=>$c['calls'],'frames_per_call'=>$c['frames'],
        'total_frames'=>$c['calls']*$c['frames'],'ptime_ms'=>$c['ptime'],
        'source_rate'=>$c['source-rate'],'source_channels'=>$c['source-channels'],'source_frame_bytes'=>$c['frame-bytes'],
        'target_rate'=>$c['target-rate'],'target_channels'=>$c['target-channels'],
        'elapsed_seconds'=>$elapsed,'frames_processed'=>$frames,'frames_per_second'=>$frames/$elapsed,
        'input_bytes'=>$frames*$c['frame-bytes'],'output_bytes'=>$bytes,'audio_seconds_processed'=>$audio,
        'audio_seconds_per_wall_second'=>$audio/$elapsed,'cpu_user_seconds'=>$u1-$u0,'cpu_system_seconds'=>$s1-$s0,
        'cpu_total_seconds'=>$cpu,'average_cpu_percent'=>$cpu/$elapsed*100,
        'memory_initial_bytes'=>$initial,'memory_peak_bytes'=>$peak,'memory_final_bytes'=>$final,
        'memory_real_initial_bytes'=>$initialReal,'memory_real_peak_bytes'=>$peakReal,'memory_real_final_bytes'=>$finalReal,
        'deadline_misses'=>$c['runtime']==='realtime'?$misses:'n/a',
        'max_delay_ms'=>$c['runtime']==='realtime'?$maxDelay/1e6:'n/a',
        'average_delay_ms'=>$c['runtime']==='realtime'?$delay/$frames/1e6:'n/a',
        'fixture_sha256'=>$fixtureHash,'downmix_sha256'=>$downmixHash,'output_sha256'=>implode(',',$hashes),
        'verify'=>$c['verify']?'true':'false','validation'=>'ok'];
    foreach ($report as $key=>$value) { printf("%s: %s\n",$key,is_float($value)?sprintf('%.6f',$value):$value); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { pcmMain($argv); } catch (Throwable $e) { fwrite(STDERR,"ERROR: {$e->getMessage()}\n"); exit(1); }
}
