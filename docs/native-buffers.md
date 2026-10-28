# Buffers binários nativos — ABI v1

A própria extensão psampler inclui uma ponte opcional para `Swoole\Buffer`.
A ABI binária v1 e o registry existentes continuam sendo o caminho de leitura.
Não há módulo de produção adicional. Esta integração foi revisada estaticamente;
os PHPTs integrados e o benchmark aguardam o novo binário.

## Escopo e API PHP

`ByteBuffer` continua sendo a única implementação de fila circular genérica.
A reserva geométrica, a normalização no crescimento e o avanço sem deslocar
bytes continuam no mesmo código. `append(string)` e `appendBuffer()` usam a
mesma escrita circular. Não há conversão para PCM, parser RTP ou DSP novos.
`PcmBuffer`, `psampler_native.h` e a ABI PCM v1 permanecem intactos.

```php
final class ByteBuffer {
    // Métodos existentes preservados.
    public function appendBuffer(object $source, int $offset = 0, ?int $length = null): void;
    public static function supportsNativeBuffer(object $source): bool;
}

$packet = new ByteBuffer(2048);
$packet->append("\x80\0\0\x01payload\0");
$queue = new ByteBuffer();
$queue->appendBuffer($packet);           // bytes nativos -> bytes nativos
$queue->appendBuffer($packet, 4, null);  // do offset até o fim
```

`supportsNativeBuffer()` consulta somente o registro da classe exata, sem
autoload, acesso ao layout ou chamada de métodos PHP. Pode retornar verdadeiro
para um objeto cuja construção foi ignorada; o estado é validado na aquisição.
Não indica que o objeto esteja inicializado ou livre de um receive pendente.
Classes PHP com nomes semelhantes não são produtores nativos.

`appendBuffer()` aceita uma origem registrada: `ByteBuffer` (outro objeto) ou
`Swoole\Buffer` quando a ponte encontra a ABI compatível no startup. Valida offset e comprimento em bytes; `null`
significa todos os bytes restantes. O offset no fim e comprimento zero são
válidos. Zero bytes não alocam nem aumentam a capacidade. Valores negativos,
faixas fora do comprimento válido, origem não registrada e autoappend geram
`ValueError`. Autoappend é rejeitado inclusive para comprimento zero, para
evitar aliasing e invalidação da origem durante crescimento. Objetos de origem
sem storage inicializado ou já emprestados geram `Error`.

A origem nunca é consumida. Os bytes copiados pertencem ao destino e resistem
à reutilização, limpeza, reconstrução e destruição da origem. Destinos criados
sem construtor mantêm a semântica anterior: o primeiro append não vazio pode
inicializar storage. Uma origem `ByteBuffer` sem storage é recusada, mesmo vazia;
um `ByteBuffer` construído e vazio é válido.

## Interface C separada

Header instalado: `ext/psampler/psampler_binary.h`.
`PSAMPLER_BINARY_API_VERSION == 1`; símbolos com linkage C em consumidores C++.
Esta API compartilha a ABI Zend/PHP do processo; não promete compatibilidade
entre builds PHP com versões, arquitetura ou opções ZTS diferentes.

- `psampler_binary_api_version()` informa a versão binária independente da PCM.
- `psampler_binary_register_provider()` registra uma classe interna exata,
  validando versão, tamanho do descritor e callbacks obrigatórios. Copia o
  descritor; duplicatas não substituem o produtor anterior.
- `psampler_binary_unregister_provider()` remove a classe na fase permitida.
- `psampler_binary_source_supported()` consulta suporte, aceitando referências
  Zend a objetos e retornando falso para valores não suportados/NULL.
- `psampler_binary_with_view()` adquire uma view, valida, chama um consumidor C
  síncrono e libera o empréstimo antes de retornar. Não copia payload.

`psampler_binary_view` contém o `zend_object *source` exato, duas regiões
`{ const unsigned char *data; size_t length; }`, a capacidade e um token opaco
do produtor. O comprimento válido é a soma dos comprimentos das regiões.
Os bytes lógicos são a primeira região seguida da segunda; o consumidor não
deve assumir contiguidade entre elas. A segunda região permite ler o ring
existente após wraparound sem linearizar ou copiar. Produtores contíguos usam
a primeira região e deixam a segunda vazia. Regiões vazias podem ter ponteiro
NULL e jamais devem ser dereferenciadas ou usadas em aritmética de ponteiros.
Bytes NUL são conteúdo normal; não há terminador implícito.

Exemplo de consumidor direto (função de uma extensão nativa):

```c
static zend_result count_nonzero(const psampler_binary_view *view, void *context)
{
    size_t *count = context;
    unsigned int i;
    for (i = 0; i < 2; ++i) {
        size_t j;
        for (j = 0; j < view->spans[i].length; ++j) {
            *count += view->spans[i].data[j] != 0;
        }
    }
    return SUCCESS;
}

/* Dentro de um handler PHP/C, com source válido e count inicializado a zero:
 * psampler_binary_with_view(source, count_nonzero, &count);
 * Tratar FAILURE e a exceção antes de devolver o resultado. */
```

Não há registro de operações artificiais na extensão de produção nem callback
PHP público para receber endereços. Consumidores C podem validar cabeçalhos
ou examinar bytes dentro do callback, sem materializar `zend_string`.

## Ownership, estabilidade e falhas

O produtor mantém a posse da alocação. `with_view()` incrementa a referência
Zend da origem **antes** de adquirir o empréstimo e a libera **depois** de
devolver o empréstimo. O consumidor pode remover a última referência PHP
durante uma reentrada síncrona sem destruir a origem durante a leitura.
Ponteiros e descritor só valem dentro do callback; não podem ser retidos em
contextos, objetos, globals, threads ou operações assíncronas. Para usos
posteriores, mantenha uma referência Zend normal e adquira uma view nova.

Produtores confiáveis devem verificar classe e handlers antes de acessar seu
próprio layout, inicialização, limites e exclusão de escritores. Aquisição
bem-sucedida ativa um guard por objeto. `ByteBuffer` verifica comprimento,
capacidade, posições e relação circular entre read/write; bloqueia construtor,
append, appendBuffer, pop, discard e clear durante o empréstimo, inclusive
chamadas de tamanho zero. Peek, has, length e capacity permanecem disponíveis.
Aquisição recursiva no mesmo objeto é recusada. Outros objetos continuam livres.

Callbacks de aquisição/liberação são C puros, não chamam PHP nem suspendem.
Liberação não aloca e não lança exceção. Falha de aquisição deve desfazer seus
próprios guards e lançar exceção. Cada aquisição bem-sucedida tem exatamente
uma liberação, inclusive quando a view é inválida, o consumidor lança exceção,
retorna falha ou sofre bailout Zend. O caminho de bailout libera o empréstimo
e a referência e propaga o bailout; não tenta recuperar o request.
Falhas de produtor/consumidor sem exceção tornam-se `Error`.

O consumidor pode reentrar PHP síncrono, mas não pode ceder à coroutine/Fiber,
mutar os bytes ou transferir o empréstimo para outra thread. Coroutines podem
continuar usando `recvfromInto()` antes/depois da operação. Os guards impedem
que uma reentrada altere a origem durante o callback.

A validação central exige identidade da origem, ponteiros não NULL para
regiões não vazias e `len0 <= capacity`, `len1 <= capacity - len0`,
`capacity <= ZEND_LONG_MAX`. Assim, a soma é segura em `size_t`. Não tenta
provar que um endereço nativo arbitrário está mapeado: produtores são código C
confiável, responsáveis pela validade e exclusividade reais da memória.
Storage exportado não deve criar aliases mutáveis para outro ByteBuffer;
um produtor que delegue um empréstimo deve manter os guards da origem real.

No append, os intervalos são verificados por subtração antes de formar
offset/comprimento. O destino é verificado novamente após adquirir a origem.
O limite de destino usa `additional <= ZEND_LONG_MAX - length`; crescimento
geométrico verifica a multiplicação antes de dobrar. A reserva total acontece
antes de copiar qualquer região; falhas de validação não alteram conteúdo,
posições ou capacidade. A reserva aloca antes de substituir o storage antigo.
Depois da reserva, a cópia/commit não chama PHP nem contém operações falíveis.
Isso pressupõe callbacks de liberação conformes ao contrato.

## Cópias e custo

| Operação | Payload copiado |
| --- | --- |
| `psampler_binary_with_view()` | Nenhum; view e contexto locais |
| `ByteBuffer::appendBuffer()` | Uma passagem sobre os bytes selecionados para adquirir storage próprio; pode usar vários memcpy nos limites dos rings |
| Crescimento do destino | Copia também os bytes já enfileirados para a nova alocação, como antes |
| `append(string)` | Cópia existente da string para o ring |
| `peek()` / `pop()` | Cópia existente para a string de retorno PHP |
| `discard()` / `clear()` | Nenhuma cópia do payload |

O caminho nativo não chama `toString()`, não cria string PHP intermediária,
não exporta ponteiros numéricos e não conhece o layout de objetos Swoole.

## Lifecycle e ZTS

`MINIT(psampler)` inicializa uma tabela persistente e registra o produtor
ByteBuffer. Extensões produtoras registram em seu MINIT com dependência
`ZEND_MOD_REQUIRED("psampler")`, usando `STANDARD_MODULE_HEADER_EX` e a tabela
de dependências em `zend_module_entry`. Devem verificar a versão, tratar falhas
de registro e remover apenas suas próprias classes no MSHUTDOWN.
Nomes das classes e descritores são copiados para a tabela persistente.

Registro é permitido somente durante `php_during_module_startup()`; remoção
somente durante startup/shutdown. Não há registro PHP, `dl()` em requests nem
unload personalizado com requests ativos. O código do produtor permanece
carregado até depois de sua remoção; psampler só destrói entradas restantes,
sem executar callbacks no shutdown.

O thread principal faz todas as mutações da tabela antes/depois dos requests;
workers ZTS leem a tabela imutável sem escrita por pacote. Estado por operação
fica na stack; guards e storage pertencem ao objeto/request. Objetos não podem
ser compartilhados entre threads sem um protocolo externo compatível.
O guard booleano de leitura não substitui sincronização entre threads.

## Ponte incorporada à psampler

`swoole_bridge.c` é compilado em ambos os ramos de `config.m4`, estático e
compartilhado, dentro do único módulo psampler. `MINIT(psampler)` cria o registry,
registra ByteBuffer e chama `psampler_swoole_bridge_init()` uma única vez.
`ZEND_MOD_OPTIONAL("swoole")` ordena o MINIT depois do Swoole, se presente,
e o MSHUTDOWN na ordem inversa. O startup Zend carrega os módulos dos INIs
antes de ordenar seus MINITs; a ordem dos INIs não precisa determinar a ordem
de inicialização. Não há registro tardio em RINIT ou em appendBuffer.

O adaptador verifica módulo iniciado, classe interna exata pertencente ao módulo
Swoole, função de versão igual a 1, getter não NULL, versão/tamanho exatos da
tabela, identidade da classe e ambos os callbacks. Só então registra o produtor.
No MSHUTDOWN remove seu próprio produtor antes de zerar o ponteiro da tabela
e antes de destruir o registry. Não altera nem substitui produtores existentes.
`php --ri psampler` informa o estado em `Swoole Buffer bridge`, inclusive o motivo
de indisponibilidade, sem emitir warning ou falhar o carregamento.

Sem Swoole, sem Buffer, sem símbolos, com ABI incompatível ou com a ponte
desabilitada, ByteBuffer continua funcionando e objetos não registrados retornam
false em supportsNativeBuffer. appendBuffer rejeita esses objetos com ValueError.
O teste positivo exige o produtor compatível; não dá SKIP só porque a ponte
falhou, evitando esconder uma regressão de link/startup.

### Header e assinaturas conferidos

O header interno [swoole_buffer_c_api.h](../swoole_buffer_c_api.h) é uma cópia
exata do header público do patch versionado
`src/globals/patch/swoole_native_buffer_v630rc1.patch` de `spechshop/pcg729`
(revisão que contém a ABI v1 e os reparos de lifecycle). O caminho antigo
[swoole-buffer-c-api-v1.h](swoole-buffer-c-api-v1.h) apenas inclui essa cópia;
não é mais uma proposta. Não é necessário ter Swoole instalado para compilar
psampler e nenhum header privado C++ é incluído. Esse header não é instalado
como uma API de propriedade da psampler.

Foram conferidos no patch e em `docs/swoole-buffer-c-api.md` do produtor:

```c
uint32_t swoole_buffer_read_api_version(void);
const swoole_buffer_read_api_v1 *swoole_buffer_get_read_api(
    uint32_t api_version, size_t expected_struct_size);
zend_result swoole_buffer_with_read_view_v1(zval *source,
    size_t offset, size_t length, swoole_buffer_read_handler_v1 handler, void *context);
```

A tabela tem, nesta ordem, `uint32_t api_version`, `size_t struct_size`,
`zend_class_entry *buffer_ce`, `acquire(zend_object *, swoole_buffer_read_view_v1 *)`
e `release(zend_object *, void *)`. A view tem origem, ponteiro const,
length, capacity e token. O getter exige versão e tamanho exatos e só publica
a tabela durante a vida do módulo. `with_read_view_v1()` é o wrapper de leitura
com pin/validação/cleanup do Swoole; `SIZE_MAX` seleciona os bytes restantes.
A ponte usa os callbacks de baixo nível porque `psampler_binary_with_view()`
já fornece pin, validação, callback síncrono e cleanup para o consumidor.
Não é necessário aninhar dois wrappers nem alocar descritores por pacote.

Acquire usa um descritor local na stack e transfere os campos para o primeiro
span; o segundo fica NULL/zero. Preserva a identidade retornada pelo produtor,
mesmo se a metadata estiver inválida, para o dispatcher rejeitar e liberar.
Release repassa a origem original e o token opaco. Há uma cópia direta dos bytes
selecionados para o ring do destino, além da cópia dos bytes antigos quando
há crescimento. Não há payload temporário, `zend_string`, `toString()`, lookup
de símbolos ou alocação do adaptador por pacote. A consulta ao registry que já
existia no dispatcher continua existindo, uma vez por appendBuffer.

### Resolução estática e dinâmica

| Build | Resolução no startup |
| --- | --- |
| PHP ELF com as duas extensões estáticas, GCC/Clang | Referências indefinidas fracas ao getter e à função de versão, resolvidas no link final; não depende de dynsym, dlsym ou --export-dynamic |
| PHP ELF estático só com psampler | Referências fracas ausentes viram NULL; psampler carrega normalmente |
| Ambos os módulos compartilhados | Referências fracas quando disponíveis; fallback `DL_FETCH_SYMBOL` no handle do módulo Swoole, uma vez no MINIT |
| psampler estática e Swoole compartilhado | Fallback pelo handle Swoole no MINIT, quando o PHP suporta carregar DSOs |
| psampler compartilhada e Swoole estático | Símbolos do executável precisam estar exportados para o loader; fallback RTLD_DEFAULT quando disponível |
| Outros formatos/toolchains sem weak ELF | Resolução pelo handle em builds compartilhados; estático requer a opção de link direto abaixo |

O Makefile local do pcg729 liga `PHP_GLOBAL_OBJS` diretamente no executável,
incluindo `ext/swoole/ext-src/swoole_buffer.o`. Além disso, o MINIT do Swoole
faz referência forte a `php_swoole_buffer_minit()`, na mesma unidade que os
exports C. Assim, a unidade não depende da referência fraca para entrar no link.
Uma referência fraca **sozinha** não extrai um membro não referenciado de um
arquivo `.a`. Se outro build arquivar/remover essa unidade, precisa preservar
essa referência forte, usar whole-archive/undefined apropriado ou link direto.
Visibilidade default de um símbolo não implica exportação na dynsym de um
executável; por isso dlsym não é a solução para o PHP completamente estático.
Essas propriedades foram conferidas nos fontes/Makefile, sem relink ou teste.

A opção padrão é a detecção opcional, sem dependência de link obrigatória.
`--disable-psampler-swoole-bridge` elimina a resolução/registro Swoole.
`--enable-psampler-swoole-static` força referências fortes aos dois exports,
para toolchains sem weak ELF ou exigência explícita de resolver no link. Nessa
opção o Swoole modificado deve estar ligado ao processo, e um símbolo ausente
vira erro de link: a garantia de independência aplica-se ao build padrão.
Windows estático requer essa opção e a configuração de build/imports apropriada;
o repositório não possui config.w32. Windows e formatos não ELF não foram validados.

Não usamos `PHP_ADD_EXTENSION_DEP(psampler, swoole)`: mesmo com dependência
opcional de configuração essa macro PHP rejeita psampler estática com Swoole
compartilhado. A dependência Zend opcional é suficiente para o MINIT e não
força presença nem link do Swoole. Também foi removido o define incondicional
COMPILE_DL_PSAMPLER: PHP_NEW_EXTENSION define get_module somente para build
compartilhado, evitando export indevido no executável estático.
Não há suporte a dl()/unload de módulos durante requests nem descoberta tardia.

### Ownership, guards, bailout e ZTS

O dispatcher mantém a origem viva antes de acquire até depois de release.
O Swoole mantém sua referência adicional e o guard/token por thread; a ponte
não toma posse do storage nem modifica esse protocolo. Acquire mal-sucedido
não chama release; todo acquire bem-sucedido chama release uma vez, inclusive
metadata inválida, exceção ou bailout do consumidor/reserva do ring. O caminho
de bailout existente não foi alterado. Após release não há ponteiro retido.

O guard do Swoole bloqueia clear, reconstrução e recvfromInto antes de alterar
bytes ou suspender; acquire recusa receive pendente. O produtor também bloqueia
switch de Fiber e protege as trocas de corrotinas. A cópia em appendBuffer é
síncrona, sem PHP, I/O, callback assíncrono ou suspensão dentro do empréstimo.
ByteBuffer continua recusando autoappend antes de reservar/crescer o ring,
inclusive comprimento zero; seus métodos existentes e o caminho PCM/DSP não
foram alterados.

A tabela/cache e o registry são escritos apenas no startup/shutdown e lidos
nos requests ZTS. Só contêm metadados de classe e funções. Views ficam na stack;
storage, guards e referências pertencem ao request/thread. Não há objetos Zend
nem payload compartilhados entre workers. Isso não autoriza compartilhar um
objeto entre threads. Concorrência ZTS com objetos independentes ainda precisa
de validação em runtime.

## Testes preparados, ainda não executados nesta etapa

Os testes anteriores de ByteBuffer, registry, PCM e DSP permanecem aplicáveis.
Foram promovidos os dois testes Swoole antes chamados `_future` e removida a
exigência de módulo separado. Testes positivos usam objetos reais e exigem
Swoole compatível; o status de suporte é uma asserção, não motivo de SKIP.

| PHPT | Cobertura |
| --- | --- |
| byte_buffer_native_swoole_udp | UDP loopback real, supportsNativeBuffer, vazio, NUL, 160/172/1024/2048 bytes, reutilização e overflow de datagrama |
| byte_buffer_native_swoole_ring | 200 recepções, slices/offset no fim, falha de slice atômica, wraparound, crescimento, integridade, destruição da origem e autoappend |
| binary_provider_swoole_guards | Mutações/nova aquisição/Fiber recusadas, receive não consome datagrama durante borrow, receive pendente/cancelamento e cleanup |
| binary_provider_swoole_lifecycle | Fixture C existente lê objeto Swoole real, exceções/FAILURE/SUCCESS com exceção, bailout com refcount balanceado, reconstrução, não construído e WeakReference |
| byte_buffer_native_swoole_oom | OOM ao crescer destino fora de coroutine, guard devolvido, estado antigo preservado e reutilização em shutdown |
| byte_buffer_native_no_swoole | Já existente: psampler sem Swoole e classe PHP impostora |
| byte_buffer_native_swoole_unavailable | Já existente: Buffer sem ABI/provider é rejeitado sem conversão |
| byte_buffer_native_swoole_abi_rejected | Variante isolada da fixture: versão, tamanho, tabela/classe/callbacks incompatíveis não registram produtor |
| byte_buffer_native_swoole_abi_fixture | Controle positivo da mesma matriz, sem substituir os testes de objetos reais |

A fixture `tests/native_binary_provider` continua fora do build de produção.
Sua opção alternativa `--enable-psampler-binary-test-swoole-abi` reutiliza o
mesmo module_entry e o nome de módulo `swoole` para simular exclusivamente os
contratos de startup. Nunca carregar essa variante com Swoole real. O modo
normal da fixture, usado nos testes reais, continua dependendo da psampler.

Veja [validação e benchmark](swoole-bridge-validation.md) para comandos,
matriz de build, medições e limitações. Nenhum PHPT integrado ou benchmark foi
executado nesta etapa; não há resultado de desempenho nem alegação de ganho.
