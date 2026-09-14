# Benchmark de voz: string versus ByteBuffer

Este benchmark simula chamadas independentes que recebem chunks PCM16, acumulam
os bytes e retiram frames completos continuamente. Cada chamada mantém sua
própria coroutine ou goroutine, acumulador, contadores, checksum e bytes
restantes.

Os dois programas usam os mesmos padrões PCM determinísticos. O conteúdo muda
por chamada e por posição da sequência de chunks, o que permite detectar uso do
buffer de outra chamada. A validação final recalcula o estado esperado de cada
chamada fora do intervalo medido.

## Arquivos

- `voice_benchmark.php`: PHP com coroutines persistentes do Swoole e a classe
  `ByteBuffer` já fornecida pelo psampler.
- `voice_benchmark.go`: Go com goroutines persistentes e um ByteBuffer circular
  implementado no próprio arquivo.

Nenhum dos scripts compila, carrega ou modifica a extensão. O script PHP espera
que Swoole e a classe `ByteBuffer` já estejam disponíveis no binário PHP usado.

## Compilar somente o programa Go

Na raiz deste diretório:

```bash
go build -o voice_benchmark_go voice_benchmark.go
```

Isso compila apenas o arquivo do benchmark Go.

## Executar o benchmark PHP

Com o binário PHP local deste diretório:

```bash
./php voice_benchmark.php \
  --calls=50 \
  --mode=string \
  --runtime=throughput \
  --frame=1920 \
  --chunk=1024 \
  --ptime=20 \
  --duration=10
```

Para usar outro binário que já tenha Swoole e psampler carregados, substitua
`./php` pelo caminho correspondente.

## Executar o benchmark Go

```bash
./voice_benchmark_go \
  --calls=50 \
  --mode=string \
  --runtime=throughput \
  --frame=1920 \
  --chunk=1024 \
  --ptime=20 \
  --duration=10
```

As opções têm os mesmos nomes e significados nos dois programas:

| Opção | Padrão | Significado |
|---|---:|---|
| `--calls` | `50` | Quantidade de chamadas independentes. Aceita, por exemplo, 1, 10, 25, 50 e 100. |
| `--mode` | `string` | `string` ou `bytebuffer`. |
| `--runtime` | `throughput` | `throughput` ou `realtime`. |
| `--frame` | `1920` | Bytes por frame PCM16. Deve ser par. |
| `--chunk` | `1024` | Tamanho fixo, em bytes, ou `variable`. Deve ser par para PCM16. |
| `--ptime` | `20` | Intervalo lógico entre ticks, em milissegundos. |
| `--duration` | `10` | Duração lógica do áudio, em segundos. Pode ser fracionária. |

`--duration` determina a quantidade de ticks por chamada:

```text
ticks por chamada = ceil(duration_ms / ptime_ms)
```

Assim, `--duration=10 --ptime=20` produz 500 ticks para cada chamada nos dois
programas. No modo throughput, esses ticks são processados o mais rápido
possível e não há sleeps. No modo real-time, o tick 1 é agendado para um `ptime`
após o início e os demais usam deadlines absolutos, evitando que pequenos
atrasos sejam somados ao agendamento seguinte.

As coroutines do Swoole são cooperativas. Como o modo throughput não dorme nem
faz I/O, ele mede a capacidade agregada de um processo PHP mantendo todos os
estados de chamada alocados. O modo real-time entrega a alternância natural nos
pontos de espera. No Go, as goroutines podem executar em paralelo de acordo com
`GOMAXPROCS`.

## Fragmentação

Um chunk fixo pode usar qualquer tamanho PCM16 par. Exemplos pedidos:

```bash
--chunk=160
--chunk=300
--chunk=320
--chunk=500
--chunk=700
--chunk=960
--chunk=1000
--chunk=1024
--chunk=1920
```

O modo variável usa exatamente esta sequência cíclica nos dois programas:

```text
320,500,700,320,1000,160,1024,960,300,1920
```

Exemplo PHP em real-time:

```bash
./php voice_benchmark.php \
  --calls=50 \
  --mode=bytebuffer \
  --runtime=realtime \
  --frame=1920 \
  --chunk=variable \
  --ptime=20 \
  --duration=30
```

Exemplo Go equivalente:

```bash
./voice_benchmark_go \
  --calls=50 \
  --mode=bytebuffer \
  --runtime=realtime \
  --frame=1920 \
  --chunk=variable \
  --ptime=20 \
  --duration=30
```

## Trabalho executado em cada modo

No PHP, `string` usa diretamente:

```php
$accumulator .= $pcm;

while (strlen($accumulator) >= $frameBytes) {
    $frame = substr($accumulator, 0, $frameBytes);
    $accumulator = substr($accumulator, $frameBytes);
    processFrame($frame);
}
```

O modo `bytebuffer` usa somente a API atual da extensão:

```php
$buffer = new ByteBuffer(4096);
$buffer->append($pcm);

while ($buffer->has($frameBytes)) {
    $frame = $buffer->pop($frameBytes);
    processFrame($frame);
}
```

No Go, o modo `string` usa `strings.Clone` tanto para o frame quanto para o
restante. Isso força as cópias correspondentes às duas chamadas a `substr()` e
evita transformar o frame em uma simples view. O ByteBuffer Go possui posição
de leitura e escrita, dados válidos, crescimento geométrico e `Append`, `Has` e
`Pop`. Cada `Pop` copia apenas o frame retornado; o restante permanece no buffer
circular.

`processFrame` calcula CRC32 sobre todo o frame e combina esse valor em um
checksum determinístico por chamada. O mesmo algoritmo é usado em PHP e Go.

## Exemplos de comparação

Use exatamente os mesmos argumentos para os dois modos da mesma linguagem:

```bash
./php voice_benchmark.php --calls=50 --mode=string     --runtime=throughput --frame=1920 --chunk=1024 --ptime=20 --duration=60
./php voice_benchmark.php --calls=50 --mode=bytebuffer --runtime=throughput --frame=1920 --chunk=1024 --ptime=20 --duration=60
```

```bash
./voice_benchmark_go --calls=50 --mode=string     --runtime=throughput --frame=1920 --chunk=1024 --ptime=20 --duration=60
./voice_benchmark_go --calls=50 --mode=bytebuffer --runtime=throughput --frame=1920 --chunk=1024 --ptime=20 --duration=60
```

Para real-time com fragmentação variável:

```bash
./php voice_benchmark.php --calls=50 --mode=string     --runtime=realtime --frame=1920 --chunk=variable --ptime=20 --duration=30
./php voice_benchmark.php --calls=50 --mode=bytebuffer --runtime=realtime --frame=1920 --chunk=variable --ptime=20 --duration=30

./voice_benchmark_go --calls=50 --mode=string     --runtime=realtime --frame=1920 --chunk=variable --ptime=20 --duration=30
./voice_benchmark_go --calls=50 --mode=bytebuffer --runtime=realtime --frame=1920 --chunk=variable --ptime=20 --duration=30
```

Faça algumas execuções de aquecimento manualmente antes das medições que serão
guardadas. Os scripts não fazem warm-up oculto, para que cada execução seja
explícita e reproduzível.

## Métricas

| Campo | Significado |
|---|---|
| `language` | PHP ou Go. |
| `mode` | Implementação medida. |
| `runtime_mode` | Throughput ou real-time. |
| `calls` | Número de estados de chamada independentes. |
| `ptime_ms` | Intervalo configurado entre ticks. |
| `frame_bytes` | Tamanho de um frame PCM16. |
| `chunk_mode` / `chunk_sequence_bytes` | Fragmentação configurada e sequência efetiva. |
| `duration_configured_seconds` | Duração lógica usada para calcular os ticks. |
| `elapsed_seconds` | Tempo medido do disparo comum até a última chamada terminar. Não inclui a validação. |
| `ticks_expected` / `ticks_processed` | Total agregado para todas as chamadas. Devem ser iguais. |
| `input_bytes` | Bytes PCM entregues aos acumuladores. |
| `bytes_processed` | Bytes pertencentes a frames completos processados. |
| `frames_processed` | Frames completos processados. |
| `frames_per_second` | Frames agregados divididos pelo tempo medido. |
| `mib_per_second` | Bytes de frames completos divididos pelo tempo medido, em MiB/s. |
| `checksum_global` | Combinação ordenada dos checksums de frame de cada chamada. |
| `call_state_digest` | Digest dos contadores, checksum e resto de todas as chamadas, na ordem dos IDs. |
| `bytes_remaining` | Soma dos bytes incompletos retidos ao final. |
| `validation` | `ok` somente quando todos os estados finais conferem com a referência. |

O PHP informa `memory_initial_bytes`, `memory_peak_bytes` e
`memory_final_bytes` com `memory_get_usage(true)` e
`memory_get_peak_usage(true)`. Quando disponível, o pico é reiniciado logo antes
da medição.

O Go usa `HeapAlloc` nos campos comuns `memory_initial_bytes`,
`memory_peak_bytes` e `memory_final_bytes`. Também informa `HeapAlloc` e
`HeapSys` com nomes detalhados e o delta de `TotalAlloc`, todos obtidos com
`runtime.ReadMemStats`. O pico de `HeapAlloc` é uma amostra coletada a cada 10
ms e também no fim, por isso o nome detalhado
`memory_peak_heap_alloc_sampled_bytes`; picos mais curtos podem ficar entre duas
amostras.

No real-time, os campos adicionais são:

- `deadline_misses`: ticks cujo processamento terminou depois do deadline do
  tick seguinte;
- `max_delay_ms`: maior atraso para iniciar um tick em relação ao horário
  agendado;
- `average_delay_ms`: atraso médio de início, considerando todas as chamadas.

No throughput, esses três campos aparecem como `n/a`.

## O que deve coincidir

Para a mesma linguagem e configuração, compare `string` com `bytebuffer`. Estes
campos precisam ser idênticos:

- `ticks_expected`;
- `ticks_processed`;
- `input_bytes`;
- `bytes_processed`;
- `frames_processed`;
- `checksum_global`;
- `call_state_digest`;
- `bytes_remaining`;
- `validation: ok`.

Esses campos também devem coincidir entre PHP e Go, pois a entrada e os
checksums são equivalentes. Compare desempenho usando `elapsed_seconds`,
`frames_per_second`, `mib_per_second` e as métricas de memória. No real-time,
compare ainda os misses e atrasos. Como `elapsed_seconds` fica próximo da
duração configurada no real-time, `deadline_misses` e atrasos costumam ser mais
informativos do que throughput bruto nesse modo.
