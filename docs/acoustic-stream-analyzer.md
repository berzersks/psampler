# AcousticStreamAnalyzer

Análise acústica incremental em C, PCM16LE mono a 8000 Hz. Sem dependências
operacionais de libspech, SIP, RTP, codecs, bancos ou serviços externos.
Validação e política atual de duração: [regressão rec2](acoustic-rec2.md).

```php
$a = new AcousticStreamAnalyzer(sampleRate: 8000, frameDurationMs: 20);
$a->on('ring.started', function (array $event): void { var_dump($event); });
$a->on('speech.started', function (array $event): void { var_dump($event); });
$a->push($pcm);
$state = $a->state();
$a->reset();
```

## Contrato público

```php
final class AcousticStreamAnalyzer {
    public function __construct(int $sampleRate = 8000, int $frameDurationMs = 20);
    public function on(string $event, callable $callback): AcousticStreamAnalyzer;
    public function push(string $pcm): void;
    public function state(): array;
    public function reset(): void;
}
```

Somente 8000/20 são aceitos inicialmente; outras configurações lançam ValueError.
O tamanho dos blocos é independente da duração dos frames. `push()` aceita vazio,
blocos menores que uma amostra e fragmentações em bytes ímpares: guarda até 319
bytes até completar um quadro. Uma amostra incompleta permanece pendente até o
próximo bloco ou reset. Não existe flush com padding artificial.

O limite por `push()` é **240000 bytes (15 s)**, validado antes de alterar estado.
Divida entradas maiores em chamadas sucessivas. O limite torna a fila temporária
de eventos estritamente limitada mesmo para uma chamada com muitos eventos.
Entradas de tipo incompatível lançam TypeError conforme as regras usuais do PHP.

`on()` aceita somente os sete nomes abaixo, permite múltiplos callbacks e retorna
a própria instância. Callbacks executam na ordem de registro; eventos executam na
ordem de confirmação acústica. Não há array Zend criado para cada quadro.

| Evento | Evidência |
|---|---|
| `signal.changed` | Nova classificação estável: silence, narrowband_tone, noise, music_like, voice_like ou other_audio. |
| `ring.started` | 750 ms de portadora coerente em frames de 20 ms, com confirmação espectral da janela de 500 ms; independe de cadência anterior. |
| `ring.ended` | Primeiro frame de 20 ms sem portadora após um pulso qualificado. |
| `ring.cadence_detected` | Segundo pulso qualificado com período compatível; uma emissão ao entrar no estado de cadência. |
| `ring.interrupted` | Outro áudio fora da margem protegida após ring, ou ausência de novo pulso após cadência confirmada. Não implica fala. |
| `speech.started` | Entrada estável em voice_like, inclusive sem ring anterior. Não implica gravação. |
| `speech.ended` | Saída estável de voice_like. |

Cada evento contém `type`, `at_ms`, `detected_at_ms`, `signal` e `reason`.
Cadência também contém `period_ms`. Os dois timestamps são iguais nesta versão:
representam a **confirmação**, em tempo de áudio desde o reset, e não a borda
retroativa do sinal. Isso preserva ordem cronológica mesmo com janelas diferentes.
No mesmo frame: speech.ended, signal.changed, speech.started (quando aplicáveis),
e depois transições de ring. A duração acústica da evidência pode preceder a
notificação em até uma janela ou mais, conforme a regra de confirmação.

`reason` pode ser classification_changed, voice_like, qualified_tone, tone_ended,
compatible_period, other_audio ou cadence_timeout. São evidências acústicas.
Não há decisões sobre atendimento, encerramento, faturamento ou caixa postal.

`state()` retorna processed_ms (quadros completos), pending_bytes, signal, frame_signal,
speech_active, ring_active (pulso qualificado), cadence_detected,
ring_interrupted, ring_frequency_hz, ring_level_dbfs e ring_ac_rms_dbfs.
`frame_signal` expõe a classificação candidata do último quadro, já calculada
pelo DSP, antes do debounce. `signal` é a classificação estável; `speech_active`
pode permanecer verdadeiro durante a confirmação de saída. Para medir atividade
vocal efetiva, não conte o tempo entre speech.started/speech.ended como voz: use
os quadros candidatos (e, conservadoramente, a classe estável) e uma política
separada. Publicar esse campo não altera DSP, limiares ou eventos.

Diagnósticos de ring refletem a última janela completa de 500 ms. Antes dela,
níveis são −120 dBFS e frequência é zero. A classificação começa em silence.

`reset()` descarta todo o histórico, recorrências, contadores e bytes pendentes,
reinicia o relógio e preserva callbacks. Clonagem e serialização são proibidas.

## Callbacks, exceções e ciclo de vida

Todo o PCM de uma chamada é processado antes do primeiro callback. `state()`
dentro de um callback vê o estado ao **fim de push**, não uma fotografia do instante
do evento. O payload do evento contém a classificação no instante da emissão.
Callbacks são síncronos no thread PHP chamador, nunca em threads de DSP.

Durante push, chamadas ao mesmo objeto para push/reset/on/__construct lançam Error.
state e operações em outros analyzers são permitidas. A exceção de um callback
propaga ao chamador, interrompe o despacho e descarta os eventos ainda não entregues.
O áudio inteiro já foi consumido; não envie o mesmo bloco novamente para tentar
repetir callbacks. O próximo push continua a análise normalmente.

Referências a callbacks e temporários são enumeradas por get_gc, permitindo ciclos
closure → analyzer → closure. O destrutor libera as referências e a fila nativa.
O caminho de bailout limpa temporários e restaura o bloqueio antes de propagar o
bailout do Zend. Esses caminhos ainda precisam de validação em PHP/ASan/ZTS.

## Núcleo incremental e reaproveitamento

O núcleo fica em pcm_analyzer.c para compartilhar as funções internas existentes;
acoustic_stream.h define o estado nativo sem dependências Zend. O adapter em
acoustic_stream_zend.inc é incluído por psampler.c. Não há fonte adicional para
linkar nem integração com outro projeto.

Cada quadro completo de 160 amostras passa uma vez pelo scan existente de RMS AC,
crossings, dispersão de intervalos e diferença normalizada. Nesse scan cada amostra
alimenta também as 24 recorrências Goertzel de ring, com Hann de 4000 amostras.
O espectro acústico de oito frequências é calculado somente no **novo** quadro,
pela mesma rotina de potências compartilhada com PCMAnalyzer.

São guardados 50 quadros de **features e proporções espectrais**, não áudio. Uma
janela deslizante de no máximo um segundo agrega essas features para as regras
existentes de classificação: RMS/variância, crossings, estabilidade, diferença,
dominância, entropia e variabilidade espectral. As regras e seus limiares são
compartilhados, mas a seleção espectral contínua difere da seleção esparsa sobre
regiões completas do PCMAnalyzer. Não se promete igualdade entre classificação
online e classificação final de uma gravação.

PCMAnalyzer continua usando sua segmentação, seleção espectral, DP de pulsos e
contrato originais. A refatoração mantém as expressões e ordem aritmética existentes.
A equivalência numérica ainda precisa ser confirmada pelos testes anteriores.

O ring reutiliza exatamente os bins 395..460 Hz em passos de 5 Hz, dez bins de
fundo, Hann, RMS AC, mediana do fundo, proeminência ≥10 dB, nível ≥−48 dBFS e
pureza ≥1,5 dB. O legado analyzeRingPcm da libspech foi lido como referência:
retêm-se pulsos mínimos de duas janelas, cadência e proteção de bordas. Não foi
copiado DSP para PHP e não há chamadas à libspech.

A máquina temporal usa apenas o último pulso qualificado. Aceita períodos de
4400..6600 ms, cobrindo toque de 1 s e silêncio de 4..5 s com tolerância.
Difere intencionalmente do DP retrospectivo do legado (5000 ±600 ms): não procura
a maior cadeia passada, e não precisa esperar pelo término da gravação.
Uma nova cadência incompatível desativa a evidência anterior; uma sequência
compatível pode voltar a emitir cadence_detected depois de perder esse estado.

Perturbação exige janela other de 500 ms cuja confirmação esteja pelo menos
1000 ms após o fim do pulso: equivale a manter o centro da janela fora da margem
legada de 400 ms. Após cadência, ausência de retorno até mais de 7100 ms do início
do último pulso gera cadence_timeout (inclui tempo para qualificar novo pulso).
Um episódio emite uma interrupção; novo pulso qualificado rearma o detector.
Não existe timeout de relógio de parede: silêncio deve ser enviado como PCM.

As transições de sinal requerem três quadros (60 ms); silêncio requer seis
(120 ms). O silêncio usa a atividade do quadro novo, evitando que o histórico de
voz retenha indefinidamente speech_active. Frames ativos usam a janela de features.

Custo O(novas amostras), com trabalho adicional limitado a 50 registros por frame.
Memória nativa fixa para DSP e histórico, até 319 bytes de PCM pendente, mais fila
nativa que cresce geometricamente somente quando há eventos (máximo 6008 slots
por instância). A capacidade é reutilizada. Sem eventos não há alocação PHP em
push. Callbacks são referências explicitamente registradas pelo usuário: seu número
não é limitado pela extensão. Todo estado acústico e callbacks pertencem à instância;
não há acumuladores globais mutáveis, inclusive em PHP ZTS.

## Limitações e validação pendente

- Voice_like é heurístico. Música, voz tonal, ruído colorido e clipping podem
  confundir classificação; não é ASR, VAD treinado ou detector de gravação.
- A janela de features inclui até um segundo de contexto: mudanças e misturas
  podem retardar ou oscilar a classificação, mesmo com debounce.
- Ring mede duração em frames de 20 ms e exige 750 ms coerentes e validação espectral de 500 ms. Pulsos curtos ou bordas
  desalinhadas podem não qualificar; sinais misturados podem perder pureza.
- Timeout não cobre um pulso contínuo excessivamente longo enquanto continua
  qualificado como tom. ring.started indica tom compatível, não prova cadência.
- Frequências próximas entram na banda tolerada; 425 Hz exatos não são requisito.
- Cadência é evidência local entre pulsos, sem classificação de país ou operadora.
- Só houve revisão de código, lint PHP, análise sintática Python e verificações de
  diff. Nenhum build, teste nativo, ASan, stress em execução ou benchmark foi feito.
  Compatibilidade numérica de PCMAnalyzer e comportamento em ZTS permanecem pendentes.

## Testes manuais após compilar

Exemplo de compilação dinâmica, **não executado durante esta implementação**:

```sh
phpize
./configure --enable-psampler
make -j2
```

Use o PHP correspondente ao phpize, com a extensão recém-compilada:

```sh
php -d extension="$PWD/modules/psampler.so" tests/acoustic_stream_suite.php
php /caminho/php-src/run-tests.php -p /caminho/php \
  -d extension="$PWD/modules/psampler.so" \
  tests/acoustic_stream.phpt tests/pcm_analyzer.phpt tests/ring_evidence.phpt
```

Para o PHP estático já recompilado com psampler, omita `-d extension=...`.
Execute também os demais PHPTs existentes para cobrir APIs preservadas; eles podem
ter requisitos próprios (por exemplo Swoole), distintos desta suíte autônoma.

A suíte nova gera silêncio, tom, cadência, jitter, interrupção por outro tom,
ruído, amplitudes, clipping, mistura de ruído e frequências próximas. Repete cada
caso em blocos de 10/20/30/40/60 ms e fragmentos ímpares/irregulares, comparando
eventos e estado integralmente. Cobre callbacks múltiplos, ordem, duplicatas,
reset, instâncias, buffers incompletos, tipos inválidos, exceções, reentrância,
GC de ciclos, estabilidade de memória e diagnósticos compartilhados com PCMAnalyzer.
Os testes de fala direta e após ring usam speech.pcm, fala articulada TTS de
laboratório, seguida de silêncio. Não usam os fixtures antigos de senoides como voz.

O fixture foi gerado com eSpeak NG instalado localmente, voz pt-br e o texto:
“Olá. Esta é uma mensagem de laboratório para testar atividade de voz.
Por favor, aguarde um momento.” PCM16LE mono 8 kHz, cerca de 6,7 s.
Não contém gravações de pessoas ou chamadas. A geração não compila código.

```sh
python3 tests/generate-acoustic-speech.py
# Ou substituir por WAV mono PCM16 consentido e rotulado de laboratório:
python3 tests/generate-acoustic-speech.py /caminho/fala-laboratorio.wav
```

TTS é um teste de laboratório reproduzível, não validação de fala humana real.
Avaliação com corpus humano consentido e rotulado, métricas de falsos positivos,
latência e perdas com ruído/telefonia continua necessária antes de produção.
