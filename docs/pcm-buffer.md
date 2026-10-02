# PcmBuffer e API nativa v1

`ByteBuffer` continua sendo a fila genérica de bytes existente. `PcmBuffer`
é uma classe independente, final, não clonável e não serializável. Seu storage
é contíguo e contém somente PCM signed 16-bit little-endian, intercalado em
stereo. Não mantém filas de `zval` nem arrays PHP de samples.

## API PHP

```php
stereoToMono(string $pcmData): string;

final class PcmBuffer {
    public function __construct(int $sampleRate, int $channels);
    public function append(string $pcm): void;
    public function size(): int;
    public function capacity(): int;
    public function sampleRate(): int;
    public function channels(): int;
    public function clear(): void;
    public function reset(int $sampleRate, int $channels): void;
    public function toString(): string;
    public function toMono(): PcmBuffer;
    public function toStereo(): PcmBuffer;
    public function resample(int $sampleRate): PcmBuffer;
    public function canInvoke(string $operation): bool;
    public function invoke(string $operation, mixed ...$args): mixed;
}
```

```php
$pcm = new PcmBuffer(sampleRate: 44100, channels: 2);
$pcm->append($pcm16leStereo);
$pcm->toMono()->resample(8000);
$output = $pcm->toString();
```

As taxas devem ser positivas, caber em `uint32_t` e no inteiro PHP local; os
canais são 1 ou 2. Não há conteúdo inicial no construtor. Cada `append()` exige
frames completos: múltiplos de 2 bytes para mono, 4 para stereo. Uma string
vazia é válida. `size()` e `capacity()` são medidas em bytes, cabem em um
inteiro PHP e respeitam também o limite de alocação de `zend_string`.

O objeto vazio começa sem alocação (`capacity() == 0`). O primeiro append
não vazio reserva pelo menos 4096 bytes, com crescimento geométrico.
`reset(sampleRate, channels)` zera o tamanho e atualiza taxa/canais, mantendo
o storage e a capacidade atuais, sem alocar, liberar ou copiar PCM. Valida os
dois argumentos antes de alterar o estado e segue a proteção contra mutações
durante `invoke()`. É útil para reutilizar um buffer após transformações.
`clear()` mantém capacidade e metadados; uma chamada explícita repetida ao
construtor limpa o conteúdo e atualiza os metadados, mantendo capacidade.
Argumentos inválidos deixam o estado anterior intacto. `toString()` produz
uma cópia independente; é a fronteira explícita de exportação para PHP.

Todas as transformações retornam **o mesmo objeto**, com referência Zend
adicionada para o valor de retorno. Canal/taxa já iguais são no-op.
`toMono()` compacta in-place, mantém a capacidade e usa o mesmo núcleo de
`stereoToMono()`: decodificação byte a byte, soma `int32_t`, divisão por 2
truncada em direção a zero. `stereoToMono()` aceita vazio, rejeita comprimento
não múltiplo de 4 com `ValueError` e produz exatamente metade dos bytes.
`toStereo()` reserva espaço se necessário e duplica samples de trás para
frente no próprio storage, usando o mesmo núcleo de `monoToStereo()`.

## Resampling

`Resampler::sample()` e `PcmBuffer::resample()` usam diretamente
`resample_pcm16_block()`, com sinks diferentes: o primeiro escreve uma
`zend_string`; o segundo escreve storage nativo. `Resampler::process()` mantém
seu adapter público existente para `sample()`. O algoritmo, banco de filtros,
64 taps, 256 fases, Kaiser beta 8.6, cutoff, DC removal, clipping e `lrint`
foram preservados. A leitura e escrita do PCM são explicitamente LE, sem
casts de buffers de bytes para ponteiros que exijam alinhamento.

O adapter `psampler_resample_pcm16()` cria um contexto novo por canal e por
chamada, alimenta o buffer de streaming existente conforme o espaço disponível
e acumula toda a saída em memória nativa. Stereo usa estados independentes
por canal, com saída intercalada. Os contextos temporários são liberados ao
final; não há estado de streaming persistente no `PcmBuffer`. Portanto,
resampling de vários objetos/chamadas não equivale a uma sequência contínua
de chamadas ao mesmo `Resampler`.

A cauda retida pelo filtro não é preenchida com zeros nem flushed: é descartada
junto com o contexto temporário. Blocos curtos podem produzir vazio. A duração
não é prometida como `frames * dstRate / srcRate`; o comportamento de borda é
o do DSP existente. Para longos buffers, o adapter consome todos os frames,
em várias alimentações, sem o truncamento de entrada de uma única chamada
legada acima de 8192 samples. A API legada mantém seu limite por chamada.

Uma razão extremamente baixa que encha o buffer de streaming sem permitir
avanço, ainda com entrada por consumir, gera `ValueError`. Estimativas/alocações excessivas também são
rejeitadas. Em falhas recuperáveis, a saída temporária é liberada e o objeto
original mantém PCM, tamanho, capacidade e taxa. Após sucesso, o novo storage
substitui o anterior e `sampleRate()` muda. No vazio, somente a taxa muda e a
capacidade existente é mantida. Na mesma taxa, não há processamento nem troca.

Os contextos agora guardam input/filter em uma única alocação nativa, evitando
alocações parciais sem owner. O adapter legado preserva sua reserva antecipada
de saída e o estado de streaming, incluindo a entrada vazia que não drena a
cauda. Acrescenta limites antes de conversões `double -> size_t` e satura
`pending_samples` em `INT_MAX` em vez de estreitar contagens excessivas.

## Integração C

O header instalado é `ext/psampler/psampler_native.h`. Ele define
`PSAMPLER_NATIVE_API_VERSION 1`, exporta símbolos da extensão e importa-os
nos consumidores Windows. O layout do objeto é privado.

```c
uint32_t psampler_native_api_version(void);

zend_bool psampler_register_pcm_operation(
    const char *name, psampler_pcm_operation_handler handler);
zend_bool psampler_unregister_pcm_operation(const char *name);

psampler_pcm_buffer *psampler_pcm_from_zval(zval *value);
const unsigned char *psampler_pcm_data(const psampler_pcm_buffer *pcm);
size_t psampler_pcm_size(const psampler_pcm_buffer *pcm);
uint32_t psampler_pcm_sample_rate(const psampler_pcm_buffer *pcm);
uint16_t psampler_pcm_channels(const psampler_pcm_buffer *pcm);

typedef zend_result (*psampler_pcm_operation_handler)(
    psampler_pcm_buffer *pcm,
    uint32_t argc,
    zval *argv,
    zval *return_value
);
```

`psampler_pcm_from_zval()` aceita também um `zval` de referência ao objeto;
retorna `NULL` para outro tipo/classe ou objeto sem construtor. Os getters
exigem um handle válido. O handle, os bytes e os argumentos são **emprestados**.
`data` pode ser `NULL` quando `size == 0`; ninguém deve dereferenciá-lo nesse
caso. Não escreva/libere os bytes. Não retenha ponteiros após o fim do handler,
mutação ou destruição. Se o consumidor precisar manter o objeto PHP, deve
adquirir/liberar a referência Zend e obter handle/data novamente a cada uso.
Esta versão não expõe API de escrita; uma futura API deverá validar capacidade,
frames e commit de tamanho/metadados, sem publicar campos ou endereços no PHP.

O handler é síncrono, interpreta seus próprios argumentos/tipos/estado e
preenche `return_value` segundo o ownership normal Zend. Para retornar um
objeto/string emprestado, deve adquirir a referência apropriada. Retorna
`SUCCESS`, ou `FAILURE` com exceção. O dispatcher propaga exceções e limpa um
resultado parcial; `FAILURE` sem exceção vira `Error`. Não há integração com
classes de codecs no psampler.

Durante `invoke()`, o PCM do objeto permanece estável: constructor, append,
clear, transformações e invoke recursivo no mesmo objeto geram `Error`.
Leituras e consulta `canInvoke()` são permitidas. Objetos PHP não devem ser
compartilhados simultaneamente entre threads sem sincronização pelo chamador.

## Registry e lifecycle

O registry é um `HashTable` persistente, com nomes case-sensitive e entradas
que guardam ponteiros de função tipados. Os nomes são copiados pelo Zend;
não dependem da memória do consumidor. Nomes C vazios, handler `NULL`,
registro duplicado ou alteração fora da fase permitida retornam falso.
Não há substituição silenciosa de handlers. Consulta PHP usa o comprimento
inteiro da string; bytes NUL não são aliases de nomes registrados em C.

`MINIT(psampler)` inicializa o registry e registra a classe. A extensão
consumidora deve declarar dependência obrigatória:

```c
static const zend_module_dep codec_deps[] = {
    ZEND_MOD_REQUIRED("psampler")
    ZEND_MOD_END
};
```

Deve conectar essa tabela ao seu `zend_module_entry` com
`STANDARD_MODULE_HEADER_EX, NULL, codec_deps`, verificar a versão nativa,
registrar em seu `MINIT` e desregistrar **somente seus próprios nomes** em
`MSHUTDOWN`. Uma falha de registro deve ser tratada pelo consumidor, inclusive
para desfazer registros anteriores durante startup se necessário.

A dependência garante MINIT após psampler e MSHUTDOWN antes dele. Em builds
dinâmicos, carregue a biblioteca psampler antes da biblioteca consumidora
(também na ordem dos arquivos `.ini`), para resolver os símbolos C públicos.
Registro
é permitido apenas enquanto `php_during_module_startup()` for verdadeiro;
remoção apenas no startup ou shutdown global. `dl()` durante requests não é
suportado como mecanismo de integração: o registro é recusado nessa fase.
Não há registry mutável por request nem API PHP de registro.

A premissa é o lifecycle PHP normal: extensões persistentes ficam carregadas
durante os requests e são encerradas/descarregadas no shutdown do processo,
após o encerramento dos workers. Unload personalizado durante requests não
faz parte do contrato. Os consumidores removem handlers antes do unload;
`MSHUTDOWN(psampler)` destrói entradas e nomes restantes sem chamar handlers.
Nenhum handler é chamado durante shutdown.

Em ZTS, todos os workers leem a mesma tabela persistente imutável; a inicialização,
registro e destruição ocorrem exclusivamente no thread de startup/shutdown.
Não há gravações no registry durante requests, portanto não precisa de globals
mutáveis por thread nem de locks no lookup. As alocações dos objetos e DSP
continuam sendo do request e pertencem ao respectivo objeto/contexto.

`canInvoke()` retorna falso para uma operação ausente. `invoke()` procura a
entrada e chama o handler C diretamente, sem materializar PCM ou expor
ponteiros ao PHP. Operação ausente gera `ValueError`. `$operation` pode ser
nomeado; argumentos adicionais são posicionais, inclusive objetos, arrays
ou outros valores de configuração do codec. Argumentos variádicos nomeados
extras são rejeitados pelo parser Zend desta API v1.

Nenhum codec ou operação artificial está registrado nesta implementação.

## Validação pendente

Os seis PHPTs novos foram **escritos, não executados**:
`stereo_to_mono.phpt`, `pcm_buffer.phpt`, `pcm_buffer_errors.phpt`,
`pcm_buffer_transforms.phpt`, `pcm_buffer_resample.phpt` e
`pcm_buffer_invoke.phpt`. Os testes incluem comparação do novo adapter com a
API legada, bounds/frames, identidade das transformações, dados binários,
growth, rejeição de clone/serialização e ausência/parsing de operações.

Integração real do registry (handler com sucesso, exceções, argumentos de
codec, referências de retorno e dependência/load/unload) fica para a primeira
extensão consumidora. Não foi criado handler artificial de produção.

**REQUIRES LOCAL BUILD VALIDATION**: compilar/linkar os novos sources nos
modos estático/dinâmico, confirmar instalação/exportação do header/símbolos,
compatibilidade dos macros Zend/arginfo e flags com o PHP alvo, executar PHPTs
e validar a integração/lifecycle ZTS com um consumidor nativo real. Windows
e plataformas 32-bit/big-endian exigem validação local própria.

A revisão feita aqui foi exclusivamente estática. Nenhum build, teste,
benchmark, PHP, Go, perf ou sanitizer foi executado. Os benchmarks baseline
existentes e os resultados históricos foram preservados. A versão permanece
0.6.0; nenhum commit foi criado.
