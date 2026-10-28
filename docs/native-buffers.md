# Buffers binários nativos — ABI v1

Implementação **escrita e revisada estaticamente, sem compilação ou execução**.
O suporte real ao `Swoole\Buffer` ainda depende da API pública e da ponte
descritas abaixo. A release `pcg729 v1.1.3` sozinha não é suficiente.

## Arquivos desta alteração

Modificados: `byte_buffer.c`, `byte_buffer.h`, `psampler.c`, `php_psampler.h`,
`config.m4` e `README.md`.

Criados: `binary_buffer.c`, `binary_buffer.h`, `psampler_binary.h`,
`docs/native-buffers.md`, `docs/swoole-buffer-c-api-v1.h`,
`tests/native_binary_provider/config.m4`,
`tests/native_binary_provider/psampler_binary_test.c`,
`tests/native_binary_provider/README.md` e os onze arquivos PHPT relacionados
na tabela de testes ao fim deste documento. São seis modificados e dezenove
criados. Os arquivos de PCM e os PHPTs anteriores não foram alterados.

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

`appendBuffer()` aceita uma origem registrada, inicialmente o próprio
`ByteBuffer` (outro objeto). Valida offset e comprimento em bytes; `null`
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

## O que falta no Swoole/pcg729

Foi examinado o [patch publicado na release v1.1.3](https://github.com/spechshop/pcg729/blob/v1.1.3/src/globals/patch/swoole_native_buffer_v630rc1.patch),
inclusive `ext-src/php_swoole_buffer.h`, `ext-src/swoole_buffer.cc` e o caminho
`recvfromInto()`. Esse header define um objeto privado C++, sem ABI C pública
versionada de empréstimo. O flag `receiving` protege receive pendente; falta
um guard de leitura nativa e seu respeito em todos os escritores. Não é seguro
consumir o layout privado diretamente. Nenhum arquivo do pcg729 foi alterado.

A proposta exata para o produtor está em
[swoole-buffer-c-api-v1.h](swoole-buffer-c-api-v1.h). É uma **especificação
proposta**, não um header que já existe no Swoole e não é instalado pelo build
da psampler. O produtor deverá:

1. Exportar `swoole_buffer_get_read_api(uint32_t version, size_t expected_size)`
   com linkage C e visibilidade pública. Retornar NULL para versão/tamanho
   incompatíveis e uma tabela imutável somente após seu MINIT.
2. Publicar na tabela versão, tamanho, classe exata e callbacks `acquire` e
   `release`, sem expor o layout C++. A view pública contém origem, ponteiro
   const, length, capacity e token opaco, emprestados.
3. Em acquire, validar classe/handlers antes do cast privado, construção,
   `length <= capacity <= ZEND_LONG_MAX` e nenhuma leitura/recepção pendente;
   ativar guard antes de entregar os bytes. Nunca retornar o byte privado de
   detecção de overflow como parte do comprimento válido.
4. Em clear, construtor e qualquer escritor futuro, recusar empréstimo ativo.
   Em `recvfromInto()`, recusar **antes** de zerar length, criar o receive guard
   ou suspender. Enquanto `receiving` estiver ativo, recusar acquire, inclusive
   durante timeout/cancelamento/atribuição dos metadados do peer.
5. Manter acquire/release sem suspensão/chamadas PHP; release não falha.
   Preservar os guards existentes de receive e a limpeza em todos os caminhos.
   O caller mantém o objeto vivo, e release deve aceitar o token adquirido.
6. Instalar o novo header público e exportar o símbolo também em builds com
   visibilidade oculta/Windows. O Swoole não inclui headers nem linka psampler.

## Ponte opcional, ainda não implementada

Uma extensão pequena `psampler_swoole` poderá depender obrigatoriamente de
**ambos**, enquanto psampler e Swoole permanecem independentes entre si:

```text
psampler         swoole
    \             /
       psampler_swoole
```

Em seu MINIT, a ponte obtém e valida a tabela pública do Swoole, verifica a
ABI da psampler e registra um `psampler_binary_provider` para a classe exata
recebida do Swoole. Depende de `ZEND_MOD_REQUIRED("psampler")` e
`ZEND_MOD_REQUIRED("swoole")`, sem dependências reversas ou circulares.
Carregue as duas bibliotecas antes da ponte nos `.ini` para resolver símbolos;
a ordem relativa entre psampler e Swoole é indiferente. As dependências Zend
resolvem a ordem de MINIT e o shutdown inverso também em builds estáticos.
No MSHUTDOWN da ponte, remova o provider antes de descarregar seu código.

Acquire da ponte usa `swoole_buffer_read_view_v1` local, chama a função pública
e, no sucesso, mapeia `source`, `data`, `length`, `capacity` e `token` para a
view psampler, com segunda região vazia. Release repassa o objeto original e
o token à função pública. Não exige alocação por pacote ou string. A ponte
deve preservar a identidade retornada pelo produtor para a validação central
e liberar qualquer aquisição bem-sucedida mesmo se a metadata for inválida.
O ponteiro da tabela fica imutável no processo após MINIT, sem lookup de
símbolos ou gravação global por pacote.

Não foi criado adaptador que simule essa API ausente. Até o produtor e a ponte
serem implementados, `supportsNativeBuffer($swooleBuffer)` retorna falso e
`appendBuffer($swooleBuffer)` lança `ValueError` sem tentar conversão.

## Testes preparados e validação pendente

Todos os testes abaixo estão **escritos, não executados**:

| PHPT | Cobertura / dependência |
| --- | --- |
| `byte_buffer_native.phpt` | Vazio, NUL, 160/1024/2048 bytes, slices, argumentos nomeados e reutilização |
| `byte_buffer_native_ring.phpt` | Origem em duas regiões, destino com wraparound, limite exato, crescimento e FIFO repetido |
| `byte_buffer_native_errors.phpt` | Tipos/faixas, PHP_INT_MAX, autoappend, PcmBuffer recusado e ausência de alteração parcial |
| `byte_buffer_native_lifecycle.phpt` | Destruição com WeakReference, reutilização e cópia independente |
| `byte_buffer_native_no_swoole.phpt` | Carregamento sem Swoole, classe PHP impostora e rejeição sem chamar toString |
| `byte_buffer_native_swoole_unavailable.phpt` | Swoole com Buffer e sem provider público, incluindo o patch atual |
| `binary_provider_read.phpt` | Fixture C: leitura direta, regiões NULL vazias, checksum, dois spans e remoção do único dono durante leitura |
| `binary_provider_validation.phpt` | Fixture C: objetos não inicializados, metadata inconsistente/overflow/origem errada, falhas sem exceção, guard liberado e rejeição antes de copiar |
| `binary_provider_guards.phpt` | Fixture C: mutações/reentrada bloqueadas, leituras permitidas, exceções e destruição |
| `byte_buffer_native_swoole_future.phpt` | **Futuro**: UDP real, datagrama vazio, reutilização, capacidade exata e overflow |
| `binary_provider_swoole_guards_future.phpt` | **Futuro + fixture**: receive excluído durante leitura, leitura excluída durante receive e limpeza no cancelamento |

Os testes futuros usam SKIPIF explícito quando não há provider registrado;
não representam integração funcional com o patch v1.1.3. A fixture é uma
extensão separada em `tests/native_binary_provider/`; não integra o build de
produção e não depende de Swoole. Seu README descreve uso e limites.

A compilação local deverá validar macros Zend, assinaturas/arginfo, exports,
instalação dos headers, linkagem dos sources em build estático/dinâmico e
build da fixture com dependência/ordem corretas. Executar depois os PHPTs
novos e existentes, inclusive module_info e regressões de PCM/DSP. Verificar
ZTS com workers paralelos e objetos independentes, 32 bits e Windows quando
aplicáveis. ASan/UBSan devem exercitar wraparound, destruição e falhas;
bailout Zend e shutdown/unload precisam de validação em processos isolados.
Os testes PHPT não provam a ausência de strings por instrumentação: a revisão
do caminho C mostra que não há construção de payload string na integração;
uma medição futura poderá confirmar alocações e custo.

Nenhuma compilação, PHPT, script PHP, teste nativo, benchmark ou instalação de
dependência foi executado nesta tarefa. A versão da psampler não foi alterada.
