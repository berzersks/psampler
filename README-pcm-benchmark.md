# Benchmark PcmBuffer: PHP/C e Go

Este benchmark é exclusivamente o pipeline PCM16LE de storage contíguo,
downmix e resampling. Não executa codecs, SIP, RTP ou serviços. Os arquivos
`voice_benchmark.php`, `voice_benchmark.go` e `perf_benchmark.sh` continuam como
baseline histórico de string/ByteBuffer/Go best. Este benchmark não os utiliza.

## Build e execução

PHP CLI 8.1+ com a extensão psampler deste checkout, incluindo `PcmBuffer::reset()`.
O scheduler usa coroutines `Fiber` do PHP, sem dependência de Swoole. Go usa
somente a biblioteca padrão, sem cgo ou SpeexDSP; o relatório CPU usa getrusage
no Linux. Os contadores/tamanhos pressupõem um processo de 64 bits.

Para uma extensão dinâmica, com `phpize`, `php-config` e `php` da mesma versão:

```sh
phpize
./configure --enable-psampler --with-php-config="$(command -v php-config)"
make -j4
php -n -d extension="$PWD/modules/psampler.so" pcm_benchmark.php --help
```

Se a extensão já estiver carregada no CLI, use `php pcm_benchmark.php`.
Binários estáticos antigos precisam ser recompilados: o executável `./php`
existente pode não conter a nova API. Não é necessário substituir esse binário
para testar um build dinâmico separado.

```sh
go build -o pcm_benchmark_go pcm_benchmark.go
```

Compile apenas esse arquivo: o repositório também tem outros programas Go com
`main`. Para perf, esse build normal já conserva símbolos e informações DWARF;
não use `-ldflags='-s -w'`. Não desative otimizações na comparação principal.

Com a extensão já carregada, os comandos do cenário D são:

```sh
# Throughput: exatamente o mesmo trabalho nos dois programas.
php pcm_benchmark.php --runtime=throughput --calls=50 --frames=1000
./pcm_benchmark_go --runtime=throughput --calls=50 --frames=1000

# Realtime: 500 frames = 10 segundos lógicos por chamada.
php pcm_benchmark.php --runtime=realtime --calls=50 --frames=500
./pcm_benchmark_go --runtime=realtime --calls=50 --frames=500

# Volume explícito, sem limite artificial de frames nem sleep em throughput.
php pcm_benchmark.php --runtime=throughput --calls=50 --frames=100000
./pcm_benchmark_go --runtime=throughput --calls=50 --frames=100000
```

O PHP/C atual gera novamente os filtros a cada frame. Portanto volumes altos
podem levar vários minutos ou horas no PHP. Os exemplos com 100000 frames não
são uma promessa de duração curta. `--duration=10` é somente uma conveniência
para calcular `ceil(10 * 1000 / ptime)` frames **por chamada**; `--frames`
explícito prevalece, independentemente da ordem dos argumentos. A duração
configurada não determina tempo de wall em throughput. Ambos imprimem
`frames_per_call`, `total_frames` e `frames_processed`.

## Cenários

| Cenário | Entrada | Destino | Argumentos, iguais para PHP e Go |
| --- | --- | --- | --- |
| A — buffer | 8000 mono | 8000 mono | `--source-rate=8000 --source-channels=1 --target-rate=8000 --target-channels=1` |
| B — downmix | 8000 stereo | 8000 mono | `--source-rate=8000 --source-channels=2 --target-rate=8000 --target-channels=1` |
| C — resample | 44100 mono | 8000 mono | `--source-rate=44100 --source-channels=1 --target-rate=8000 --target-channels=1` |
| D — completo | 44100 stereo | 8000 mono | `--source-rate=44100 --source-channels=2 --target-rate=8000 --target-channels=1` (default) |

Defaults dos programas: `calls=50`, `frames=1000`, `runtime=throughput`,
`ptime=20`, cenário D. `ptime` é em ms inteiros e deve corresponder a um número
inteiro de samples na taxa de origem. Em D: 882 frames de samples por canal,
3528 bytes por frame de benchmark. Em A/B/C: 320/640/1764 bytes respectivamente.
Taxas positivas até uint32, canais 1 ou 2. Também é possível conservar stereo
com `--target-channels=2`. Upmix não faz parte deste benchmark e é rejeitado.

## Fixture e downmix

Uma sequência de oito fixtures imutáveis é gerada antes da medição, compartilhada
somente para leitura entre chamadas. Todas as chamadas recebem a mesma sequência
`bank[frame % 8]`. Cada fixture tem o tamanho completo correspondente ao ptime;
não há arquivos externos nem RNG. O índice global é `i = slot * samples + j`.

```text
phase(i, hz) = floor(i * hz * 4096 / source_rate) mod 4096
T(i, hz) = phase - 1024, se phase < 2048; caso contrário, 3072 - phase
gain = 6 + (slot mod 3) * 5 + floor(j * 3 / samples) * 3
L = trunc0((3*T(i,440) + T(i,997)) * gain / 4)
R = trunc0((3*T(i+17,659) - T(i,123)) * gain / 4)
```

Samples entre `floor(samples/3)` e `floor(samples/2)` são silêncio; slot 7 é
inteiramente silêncio. Esses tons triangulares, os ganhos variáveis e canais
diferentes exercitam samples positivos/negativos e transições de amplitude.
São codificados como signed PCM16LE. Em mono usa-se L.

`fixture_sha256` é o SHA-256 da concatenação das oito fixtures. No cenário D
20 ms deve ser:
`0eb4aa0d2359c6fa429325157b94a2c217158d5c843d90f2843aa9e23800e427`.

O downmix soma L/R em int32 e divide por 2 truncando em direção a zero;
`(-32768 + 32767) / 2` resulta em 0, e `(-3 + 0) / 2` resulta em -1.
A validação compara uma referência independente com `stereoToMono()` e
`PcmBuffer::toMono()` no PHP e com `PCMBuffer.ToMono` no Go, incluindo extremos
e somas ímpares. `downmix_sha256` inclui primeiro o vetor de extremos, seguido
dos oito downmixes para fonte stereo. Para fonte mono inclui somente o vetor.
O teste de integração compara ambos contra uma terceira referência em Python.

## Storage, reset e resampler

O PHP cria um `PcmBuffer` por chamada. Cada frame medido faz:

```php
$pcm->reset($sourceRate, $sourceChannels);
$pcm->append($frame);
if ($sourceChannels === 2 && $targetChannels === 1) {
    $pcm->toMono();
}
$pcm->resample($targetRate);
$outputBytes += $pcm->size();
```

Não há exportação nem strings intermediárias entre transformações. A fixture
PHP é a string de entrada inevitável para `append`, construída uma única vez
fora da medição. A/B usam resample na mesma taxa, um no-op nativo.

`reset(int $sampleRate, int $channels): void` é uma operação de produção:
valida ambos os argumentos, zera `size`, muda taxa/canais e conserva ponteiro e
capacidade atuais. Não aloca, não libera nem copia PCM. Valores inválidos
preservam todo o estado anterior. Exige objeto construído e rejeita mutação
durante `invoke()`, como `clear()`. Permite reutilizar um buffer depois de
qualquer transformação sem chamar novamente o construtor.

Isso não elimina as alocações já feitas por `resample()` em C: o adapter atual
cria contextos temporários e nova saída nativa, libera contextos e substitui o
storage anterior. O benchmark mantém esse comportamento real. `reset()`
conserva a capacidade deixada pela última operação; um append maior pode crescer.

Go usa `PCMBuffer` com `[]byte` PCM16LE contíguo, metadados, size/capacity em
bytes, downmix in-place, dois backing arrays alternados para entrada/saída e
scratch de streaming reutilizável. `Reset`, `Clear`, `Append`, `ToMono` e
`Resample` mantêm a capacidade quando suficiente. Cada goroutine tem seu próprio
objeto, scratch e banco de filtros. Depois do warmup, os cenários A/B/C/D passam
no teste de zero allocations por pipeline Go; runtime/scheduler/timers ainda
podem alocar na janela inteira, conforme os contadores de memória.

Não há infraestrutura Go/SpeexDSP de resampling nos benchmarks históricos
deste checkout. A implementação Go isolada em `pcm_benchmark.go` usa FIR sinc
polyphase, 64 taps, 256 fases, janela Kaiser beta 8.6,
cutoff `0.95 * min(dst/src, 1)`, normalização por fase, remoção DC de um polo
(0.9995/0.0005), saturação int16 e arredondamento ties-to-even. O processamento
usa blocos internos de até 8192 samples, estado de fase/DC novo a cada
`Resample`, zero padding somente na borda esquerda implícita e nenhuma descarga
da cauda direita, seguindo o comportamento de `psampler.c`.

Diferenças a considerar:

* PHP/C gera o banco de filtros e aloca contexto/saída por resampling. Go conserva
  os coeficientes para o par de taxas e reutiliza storage. São custos reais das
  duas implementações; o Go não emula Zend nem alocações evitáveis do C.
* A geração Go pré-calcula a janela Kaiser uma vez por banco. Bibliotecas
  matemáticas, avaliação em ponto flutuante e arredondamento podem divergir
  entre builds. Não se exige hash igual do resampling entre linguagens.
* PHP executa DSP em um thread com Fibers cooperativas; Go permite paralelismo
  de goroutines conforme `GOMAXPROCS`, que aparece no output. Registre essa
  configuração ao comparar wall/CPU. `GOMAXPROCS=1 ./pcm_benchmark_go ...` permite
  uma comparação adicional com um único core, preservando o mesmo workload.

A validação exige entrada/downmix iguais, taxas/canais iguais, mesmos frames,
saída alinhada, determinística por implementação e duração aproximada. A
tolerância por frame é `64/source_rate + 2/target_rate` segundos. Blocos curtos
podem produzir vazio conforme a borda do filtro; exige-se saída não vazia quando
`floor((samples-32)*dst/src) >= 1`, ou se não há resampling. No caso D padrão,
154 samples mono = 308 bytes = 19.25 ms; o filtro retém a cauda e não promete os
160 samples de uma sequência contínua. São operações independentes de buffer,
sem estado DSP contínuo entre frames.

## Medição e validação

Fixture, objetos, Fibers/goroutines, timers, warmup de oito fixtures por chamada
e chegada à barreira precedem a janela. A liberação da barreira, processamento,
contadores mínimos e sincronização de término pertencem à janela. Em throughput
não há pacing nem sleep. Em realtime o primeiro frame vence em t=0, os seguintes
em `frame * ptime`, usando hrtime/Fiber/usleep no PHP e timers reutilizados no Go.

`deadline_misses` conta conclusões após `due + ptime`. `max_delay_ms` e
`average_delay_ms` medem conclusão menos `due`, incluindo tempo de DSP e atraso
de scheduling. Não se espera ptime adicional depois do último frame; uma execução
de N frames dura aproximadamente `(N-1)*ptime + processamento final` em realtime.

Depois de parar wall/CPU/memória, são verificados os metadados de todas as
chamadas e exportadas no máximo três saídas finais para validar tamanho,
alinhamento, capacidade, duração e hash contra reexecução independente. A
contagem de bytes é conferida contra um ciclo de oito pipelines fora da janela.
`--verify` verifica todas as fixtures, repetição/reuso e, no PHP, a API pública
`Resampler` como oráculo independente para blocos de até 8192 samples por canal.
Blocos maiores passam pelos checks de reexecução, duração e contadores, sem usar
o oráculo legado que truncaria esses blocos. Go não exporta a cada frame nem
calcula hash na janela. PHP chama `toString()` somente nas funções de validação
e no bloco final posterior à captura de métricas. O teste também verifica essa
separação no código do hot path.

`getrusage(RUSAGE_SELF)` mede CPU de todo o processo (todos os threads Go):

```text
cpu_total_seconds = delta(user) + delta(system)
average_cpu_percent = 100 * cpu_total_seconds / elapsed_seconds
frames_per_second = frames_processed / elapsed_seconds
audio_seconds_processed = frames_processed * samples_per_channel / source_rate
audio_seconds_per_wall_second = audio_seconds_processed / elapsed_seconds
```

CPU média pode ultrapassar 100% com múltiplos cores. A relação áudio/wall é a
capacidade deste **pipeline PCM isolado**, não a quantidade de chamadas SIP
suportadas. `input_bytes`/`output_bytes` são acumulados de todos os frames,
incluindo o silêncio. `output_sha256` lista hashes finais de até três chamadas.

PHP relata `memory_get_usage()` e `memory_get_peak_usage()` em bytes, e também
as variantes `true` (blocos reservados pelo allocator). Go relata HeapAlloc,
HeapSys, TotalAlloc, Mallocs, Frees e NumGC antes/depois; os últimos quatro são
cumulativos e sua diferença descreve a janela. Go não tem amostragem de peak
neste benchmark; inicial/final não representam RSS nem pico de todo o processo.
Leituras de memória, hashes, validação, formatação e relatório ficam fora da
medição. CPU/wall são capturados próximos entre si, com diferença inevitável
mínima das chamadas de observação.

## Perf

```sh
./perf_pcm_benchmark.sh --language=php --runtime=throughput --calls=50 --frames=10000
./perf_pcm_benchmark.sh --language=go --runtime=throughput --calls=50 --frames=10000

# Extensão dinâmica/binário específicos:
./perf_pcm_benchmark.sh --language=php --php-bin=/usr/bin/php8.5 --extension="$PWD/modules/psampler.so"
# Conferir exatamente o comando sem executar:
./perf_pcm_benchmark.sh --language=go --dry-run
```

Defaults: throughput, 50 chamadas, 10000 frames/chamada, frequência 999,
callgraph dwarf, `perf-pcm.data` e `perf-pcm-flat.txt`. Dependências do script:
bash, perf e python3. Argumentos de taxas/canais/ptime também são aceitos.
A execução seguinte sobrescreve os mesmos destinos; use `--data` e `--flat`
para conservar relatórios PHP/Go separadamente.

Antes de executar, o script imprime o comando completo com escaping, total de
frames, tempo lógico agregado/por chamada e bytes por frame. O relatório flat
usa self cost, sem children, agrupado por comm/dso/symbol. O parser aceita
`Samples: N`, separadores de milhares e sufixos K/M/G. Quando há vários eventos,
a orientação usa o menor número de samples entre eles.

Abaixo de 1000:

```text
WARNING: perfil possui poucas amostras; percentuais de hotspots não são confiáveis.
Aumente --frames.
```

A partir de 5000: `perfil possui amostragem adequada para análise de hotspots`.
É orientação prática, sem garantia estatística. Sem número reconhecível, pede
conferência manual. Nenhum aviso reduz o volume de trabalho automaticamente.

O perf cobre o processo inteiro, incluindo setup/warmup/validação, enquanto as
métricas dos programas cobrem somente a janela delimitada. Use workload longo
para que DSP repetido domine o perfil, em especial no Go. `--verify` não é
habilitado pelo script. Os defaults podem durar vários minutos no PHP/C atual.
Aumentar frames para perf Go também aumenta o trabalho PHP na comparação justa;
conserve os argumentos iguais ao comparar números, mesmo com wall diferente.

O script usa as permissões perf do usuário, sem sudo automático nem mudanças de
sysctl. Neste ambiente a gravação real foi tentada e bloqueada por
`kernel.perf_event_paranoid=4`; por isso não foi produzido um perfil real.
O parser e a geração de comandos foram testados com um executável perf simulado.

## Testes

```sh
go test pcm_benchmark.go pcm_benchmark_test.go
go test -race pcm_benchmark.go pcm_benchmark_test.go
go vet pcm_benchmark.go pcm_benchmark_test.go
python3 tests/test_pcm_benchmark.py --php='php -n -d extension=/path/modules/psampler.so'

TEST_PHP_EXECUTABLE="$(command -v php)" php -n run-tests.php -n \
  -d extension=/path/modules/psampler.so \
  tests/pcm_buffer_reset.phpt tests/pcm_buffer.phpt tests/pcm_buffer_errors.phpt \
  tests/pcm_buffer_transforms.phpt tests/pcm_buffer_resample.phpt \
  tests/pcm_buffer_invoke.phpt tests/stereo_to_mono.phpt
```

Executados nesta entrega: build dinâmico PHP 8.5.11 NTS com `-g -O2`, sete
PHPTs aprovados, build/test/race/vet Go e integração dos quatro cenários nos dois
runtimes aprovada. A integração verifica hashes contra a referência Python,
contadores exatos de frames/bytes, destino, duração, CPU, argumentos inválidos,
precedência frames/duration e warnings perf. O teste Go também verifica bordas
curtas/longas, stereo, silêncio, reuso e zero allocations no pipeline aquecido.

## Exemplos reais de output

Trechos de smoke tests neste ambiente com **2 chamadas, 1000 frames por chamada**,
cenário D throughput, mesmo input e mesmo trabalho. Estes trechos não são
perfis perf nem medições controladas: o caso Go termina rápido demais para um
perfil útil. Não inferem superioridade de linguagem. Os programas também
imprimem todas as métricas de memória, CPU user/system, hashes e configuração.

PHP:

```text
language: PHP
implementation: psampler PcmBuffer / native sinc-Kaiser FIR
runtime_mode: throughput
calls: 2
frames_per_call: 1000
total_frames: 2000
ptime_ms: 20
source_rate: 44100
source_channels: 2
source_frame_bytes: 3528
target_rate: 8000
target_channels: 1
elapsed_seconds: 3.609923
frames_processed: 2000
frames_per_second: 554.028451
input_bytes: 7056000
output_bytes: 616000
audio_seconds_processed: 40
audio_seconds_per_wall_second: 11.080569
cpu_user_seconds: 3.607378
cpu_system_seconds: 0.000000
cpu_total_seconds: 3.607378
average_cpu_percent: 99.929502
memory_initial_bytes: 651072
memory_peak_bytes: 806720
memory_final_bytes: 616752
validation: ok
```

Go:

```text
language: Go
implementation: native PCMBuffer / cached sinc-Kaiser FIR
runtime_mode: throughput
calls: 2
frames_per_call: 1000
total_frames: 2000
ptime_ms: 20
gomaxprocs: 16
source_rate: 44100
source_channels: 2
source_frame_bytes: 3528
target_rate: 8000
target_channels: 1
elapsed_seconds: 0.010577
frames_processed: 2000
frames_per_second: 189097.758
input_bytes: 7056000
output_bytes: 616000
audio_seconds_processed: 40.000000
audio_seconds_per_wall_second: 3781.955
cpu_user_seconds: 0.020938
cpu_system_seconds: 0.000000
cpu_total_seconds: 0.020938
average_cpu_percent: 197.966
HeapAlloc_initial: 664520
HeapAlloc_final: 664520
TotalAlloc_initial: 664520
TotalAlloc_final: 664520
Mallocs_initial: 294
Mallocs_final: 294
NumGC_initial: 0
NumGC_final: 0
validation: ok
```
