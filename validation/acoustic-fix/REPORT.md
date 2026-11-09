Correção acústica do psampler 0.7.0 — validação em 07/11/2026

Implementação restrita a `pcm_analyzer.c` e `pcm_analyzer.h`, com novos testes. Nenhum commit, publicação, alteração de aplicação ou do WAV original. Os sete eventos, assinaturas PHP, formato de retorno do PCMAnalyzer e histerese existentes foram mantidos.

1. Causas técnicas e correção

O Goertzel implementava corretamente a avaliação nas frequências candidatas, mas a grade de 5 Hz era larga para a janela Hann de 500 ms (resolução natural de 2 Hz). A frequência entre candidatos sofre perda pela resposta da janela: 427,5 Hz fica a 2,5 Hz dos dois candidatos mais próximos. A “pureza” é a diferença entre o nível de amplitude de pico Hann e o RMS AC integral; portanto um seno alinhado resulta em aproximadamente 3,01 dB, e não em 0 dB. O cálculo usava o pico fora de sintonia como se representasse toda a amplitude tonal.

`ring_refined_level()` avalia Goertzel em frequência contínua na janela corrente; `ring_refine()` faz dois ajustes parabólicos locais no logaritmo da amplitude. `ring_finish_frame()` usa o pico refinado tanto no nível quanto na pureza e na proeminência. Faixa 395–460 Hz, nível mínimo −48 dBFS, proeminência mínima 10 dB e pureza mínima 1,5 dB permanecem iguais. O filtro −60 dBFS/−12 dB apenas evita trabalho de refinamento em janelas sem um candidato plausível; não aceita ringback. Não se reduz o limiar de pureza.

A regra antiga de tom exigia simultaneamente regularidade dos cruzamentos e desvio de RMS inferior a 1,8 dB. Um bip com envelope variável perdia a classificação tonal; a variação do RMS então ajudava a satisfazer a regra de voz. Além disso, as oito sondas espectrais relativas não medem concentração contra toda a energia: um sinal fora dessas frequências pode apresentar indicadores enganosos.

`scan_frame()` estima a frequência pelos cruzamentos positivos interpolados e verifica a projeção coerente contra a energia AC total do frame. A verificação só ocorre em frames ativos com cruzamentos regulares. O critério de concentração é 80%. A análise completa combina maioria de frames coerentes (50%), regularidade de cruzamentos (65%) e dispersão de frequência inferior a 1,5% nos frames coerentes. Bordas moduladas podem reduzir coerência, mas não precisam invalidar todo o bip.

O streaming usa as mesmas evidências de frequência/coerência nos três frames recentes, exigindo portadora estável e coerência no frame atual. Isso evita herdar evidência tonal do ringback anterior na janela histórica de um segundo. A agregação temporal difere conforme o horizonte já existente de cada API, sem duplicar o estimador DSP. A confirmação de fala continua em três frames; silêncio continua em seis. Pausas e fragmentação de fala não foram modificadas como política.

2. Arquivos e funções

- `pcm_analyzer.c`: novas `ring_refined_level()` e `ring_refine()`; alterações em `ring_sample()`, `ring_finish_frame()`, `scan_frame()`, `classify_features()` e `acoustic_stream_frame()`.
- `pcm_analyzer.h`: buffer nativo de 4.000 amostras e evidências privadas `tone_hz`/`coherent_tone` por frame.
- `tests/acoustic_offgrid_suite.php` e `.phpt`: controles intermediários, três amplitudes, bips AM em sete frequências e vários alinhamentos, ruído e frequências inadequadas.
- `tests/rec_postal_regression.php`: lê diretamente o WAV original; oito fluxos completos e 160 alinhamentos do bip.
- `tests/acoustic_stream_asan.c`: 100.000 frames com tons, silêncio, ruído, resets e verificação de limites.

`acoustic_stream.h`, `acoustic_stream_zend.inc` e as rotinas em `psampler_dsp.inc` foram inspecionados. O DSP acústico já era compartilhado em `pcm_analyzer.c`; o include DSP citado contém o resampler. Não foi necessário alterar o adaptador Zend nem o resampler. Referências, GC, callbacks, reentrância e filas conservam a implementação existente e foram exercitados na suíte.

3. Resultado do WAV original

O WAV é PCM16 mono 8 kHz, 390.080 amostras, 48.760 ms. A extração foi comparada byte a byte com a baseline. Hashes do WAV, PCM, fontes e executáveis estão em `build-verification.json`.

| Medida | Baseline | Corrigido |
|---|---:|---:|
| ring.started | 0 | 6 |
| ring.ended | 0 | 6 |
| ring.cadence_detected | 0 | 1 |
| ring.interrupted | 0 | 1 |
| speech.started | 5 | 4 |
| speech.started no bip de 1.400 Hz | 1 | 0 |
| primeiro speech.started | 31.200 ms | 31.200 ms |
| início na locução de 44.418 ms | 44.520 ms | 44.520 ms |

O sétimo pulso curto não gera `ring.started`. Os oito alinhamentos do fluxo completo preservam seis inícios/fins e a cadência. Nenhum dos 160 alinhamentos por amostra do bip isolado gera fala, em ambas as APIs.

| Frequência de controle | Pureza antes | Pureza depois |
|---|---:|---:|
| 425 Hz | 3,01 dB | 3,01 dB |
| 426,35 Hz | 0,38 dB | 3,01 dB |
| 427,5 Hz | −6,88 dB | 3,00 dB |
| 430 Hz | 3,01 dB | 3,01 dB |

Todos os quatro controles agora emitem ringback. A regressão adicional cobre 11 frequências entre 395,5 e 459,5 Hz em amplitudes 500, 8.960 e 28.000, com erro máximo permitido de estimação 0,15 Hz; 42 combinações de bip AM/frequência/alinhamento; mais ruído e tons fora de faixa. Os 75 casos principais passaram sem relaxar os critérios de aceitação dos testes existentes.

4. Linha do tempo (confirmações, em ms)

| Evento | Instantes |
|---|---|
| ring.started | 1.000; 6.000; 11.000; 16.000; 21.000; 26.000 |
| ring.ended | 1.500; 6.500; 11.500; 16.500; 21.500; 26.500 |
| ring.cadence_detected | 6.000, período 5.000 |
| ring.interrupted | 31.500, other_audio |
| speech.started | 31.200; 44.520; 44.800; 46.920 |
| speech.ended | 33.020; 44.720; 46.700 |

Os 46 eventos completos, incluindo `signal.changed`, estão em `after/events.json` e `timeline.csv`. Os tempos de ringback continuam quantizados nas janelas de 500 ms e são tempos de confirmação, não fronteiras acústicas exatas.

5. Latência de fala

No fluxo original, a primeira locução tem latência 66 ms (31.134 → 31.200), igual à baseline. A locução iniciada em 44.418 ms tem 102 ms, também igual à baseline. Nos oito deslocamentos testados, a primeira fica entre 53 e 67 ms, preservando os mesmos instantes absolutos da versão anterior. Na locução de 44.418 ms, os instantes absolutos também permanecem iguais aos anteriores em todos os deslocamentos: 83–149 ms nesta amostragem ampliada. O valor de 149 ms já aparece na baseline com deslocamento de 7 ms; não foi introduzido pela correção.

O trecho curto em torno de 37.199 ms é instável na baseline: alguns alinhamentos o classificam como fala e outros não. O novo critério mantém essa limitação e altera alguns desses resultados: com deslocamento de 7 ms, a confirmação passa de 37.267 para 37.347 ms; com 19 e 110 ms, deixa de emitir fala nesse trecho. Isso está explicitado nos arquivos `voice-alignments.json`; não se afirma preservar toda vocalização curta ou quase sinusoidal. A locução principal e a fixture TTS mantêm reconhecimento.

6. Compilação e testes

Foi criada uma cópia isolada do ambiente PHP personalizado em `/tmp/psampler-validation/php-src`, incorporando diretamente os `.c/.h/.inc` locais atualizados. A compilação musl mantém PHP 8.5.10 ZTS e os mesmos 52 módulos do ambiente anterior. Não houve `make install` nem substituição de executável de produção. A identidade das fontes copiadas foi verificada e o comportamento dos controles confirma que a implementação nova está no binário. O executável normal é `/tmp/psampler-validation/php-src/sapi/cli/php`; o executável com módulo auxiliar de testes fica separado em `/tmp/psampler-validation/bin/php-fixture`.

- Suíte com fixture auxiliar: 42 passaram, zero falhas, cinco skips. Os skips exigem PHP sem Swoole, Swoole sem Buffer ou emulação de ABI; essas variantes não são o ambiente instalado.
- Suíte acústica existente: passou, incluindo fragmentação em bytes ímpares, callbacks, exceções, GC, reset e memória limitada.
- Regressão intermediários/AM: passou; WAV completo e 160 alinhamentos: passaram.
- `pcm_analyzer_stress.php`: 100.000 chamadas e 1.024 casos fuzz, passou.
- `test_pcm_benchmark.py` e `test_pcm_standalone.py`: passaram; PCM mono/estéreo, taxas até 96 kHz, resampling, hashes, contadores e erros de argumentos.
- AddressSanitizer + UndefinedBehaviorSanitizer + LeakSanitizer: 100.000 análises completas e 100.000 frames incrementais, sem erros.
- Verificação de módulos, PHP ZTS, Opus, G.729 e SQLite: passou.

Os quatro testes UDP inicialmente bloqueados por `Operation not permitted` passaram ao executar fora do sandbox restrito. LeakSanitizer também precisou dessa execução por sua inspeção de threads. O verificador histórico `verify.php` continha um hash antigo do PHP global que já não corresponde ao arquivo com mtime 14:56 deste dia, anterior ao trabalho. Esse resultado foi registrado, sem alterar o global ou o critério histórico. A verificação desta execução está em `verification.json`.

7. CPU e memória

Medições de parede com `hrtime`, mesma máquina, 30 repetições após aquecimento, incluindo callbacks no streaming. São uma aproximação do custo de CPU, não contadores de ciclos. I/O e polling de `state()` são excluídos.

| Caminho, todo o WAV | Baseline histórica | Baseline reexecutada | Corrigido |
|---|---:|---:|---:|
| Streaming, mediana | 7,588 ms | 7,311 ms | 8,480 ms |
| PCMAnalyzer, cobertura em blocos | 3,069 ms | 2,979 ms | 4,067 ms |

O custo aumenta aproximadamente 16,0% no streaming e 36,5% na análise completa contra a reexecução. Há custo real de refinamento e evidência coerente, sem alegar desempenho idêntico. Ainda corresponde a cerca de 0,0174% de um núcleo em tempo real para este fluxo de 48,76 s. Não há alocações PHP adicionais por frame, nem reanálise do histórico a cada push. Apenas a janela atual de 500 ms é relida um número limitado de vezes quando existe candidato tonal.

| Estado nativo | Antes | Depois |
|---|---:|---:|
| pcm_analyzer | 56.688 bytes | 70.688 bytes |
| acoustic_stream | 64.776 bytes | 79.176 bytes |

O streaming acrescenta 14.400 bytes fixos por instância: 8.000 bytes da janela e o aumento das estruturas de evidências. O teste de 100 repetições de todo o WAV (81 minutos de áudio) registrou delta de heap PHP de 376 bytes em ambas as versões; RSS após aquecimento cresceu 176 KiB antes e 76 KiB depois, sem crescimento proporcional à duração. Os níveis absolutos de RSS variam com o layout do executável e não medem isoladamente a estrutura DSP.

No stress de PCMAnalyzer, RSS cresceu 1.864 KiB antes e 1.780 KiB depois, estabilizando. Dados brutos em `before/`, `after/` e logs de stress/sanitizadores.

8. Limitações

A classificação continua heurística. Um acorde de três notas com modulação de amplitude ainda produz um início de fala no streaming, tanto antes quanto depois; na análise completa é `music_like`. Ruído branco e acorde sem modulação não produzem fala nem ringback; a fixture TTS conserva quatro inícios de fala e `voice_like` na análise completa. Não houve aumento de falsos positivos nesses controles, mas isto não substitui um corpus extenso de vozes, música e codecs.

Portadoras com deriva rápida, modulação extrema, baixa relação sinal/ruído e vozes quase sinusoidais podem exceder os limites da evidência atual. A restrição de PCMAnalyzer a 15 segundos por chamada permanece; o WAV completo foi coberto em blocos e também analisado incrementalmente. A política de pausas permanece igual. O custo adicional de CPU e a sensibilidade do trecho de 37,2 s são limitações explícitas desta correção.

Para repetir o teste real: executar o PHP de validação com `tests/rec_postal_regression.php` e, opcionalmente, passar o caminho do WAV como primeiro argumento. Para repetir os controles: `tests/acoustic_offgrid_suite.php`. Os scripts originais de benchmark da baseline, os controles e os dados brutos foram preservados nesta pasta para auditoria.
