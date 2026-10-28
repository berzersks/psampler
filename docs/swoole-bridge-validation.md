# Validação da ponte Swoole incorporada

Implementação preparada para o próximo build. Nesta etapa não foram executados
PHPTs, cenários integrados, benchmark, link ou recompilação do PHP. As verificações
estáticas efetuadas estão no relatório final. A versão pública não mudou.

## Arquivos desta integração

Criados em produção: `swoole_bridge.c`, `swoole_bridge.h` e
`swoole_buffer_c_api.h` (header público copiado do patch do produtor).
Modificados: `psampler.c`, `config.m4`, `CMakeLists.txt`, `README.md`,
`docs/native-buffers.md` e `docs/swoole-buffer-c-api-v1.h`.

Criados para validação/medição:

- `docs/swoole-bridge-validation.md` e `bench/swoole-buffer-path.php`.
- `tests/byte_buffer_native_swoole_ring.phpt`.
- `tests/byte_buffer_native_swoole_no_buffer.phpt`.
- `tests/byte_buffer_native_swoole_oom.phpt`.
- `tests/binary_provider_swoole_lifecycle.phpt`.
- `tests/byte_buffer_native_swoole_abi_fixture.phpt`.
- `tests/byte_buffer_native_swoole_abi_rejected.phpt`.
- `tests/native_binary_provider/swoole_abi_fixture.inc`.
- `tests/run-swoole-abi-matrix.sh`.

Renomeados e atualizados: `tests/byte_buffer_native_swoole_future.phpt` para
`tests/byte_buffer_native_swoole_udp.phpt`, e
`tests/binary_provider_swoole_guards_future.phpt` para
`tests/binary_provider_swoole_guards.phpt`.

Modificados na fixture existente: `tests/native_binary_provider/config.m4`,
`tests/native_binary_provider/psampler_binary_test.c` e seu `README.md`.
O registry, ByteBuffer, header binário e os fontes de PCM/DSP não foram alterados.
Nenhum arquivo de outro repositório foi modificado.

Verificações estáticas concluídas nesta etapa:

- C: `cc -fsyntax-only`, com headers PHP locais, em sete casos: ponte
  desabilitada, habilitada/weak, link direto, modo compartilhado, psampler.c,
  fixture normal e fixture com ABI simulada. Sem objetos, executáveis ou relink.
- PHP: lint de 20 seções FILE/SKIPIF dos PHPTs Swoole e do benchmark.
- Shell: `bash -n` no runner da matriz ABI.
- Header Swoole idêntico ao do patch versionado; SHA-256:
  `7b2f89c8b728bf08a26e724ac9b966144c2e4993b165202c3f1c7e0710f167f3`.
- Revisão de layouts/assinaturas, config.m4, ordenação Zend de startup/shutdown
  e composição do link estático no Makefile local; `git diff --check`.

## Depois de compilar o novo PHP

Os comandos abaixo partem da raiz da psampler. Ajuste o caminho do executável
se o build não for copiado para `./php`. Não execute `buildspc.sh` como parte
desta revisão: ele altera a árvore do pcg729 e recompila o PHP.

```bash
PHP_BIN="$PWD/php"
PHP_SRC=/home/lotus/CLionProjects/pcg729/source/php-src
"$PHP_BIN" --ri psampler
"$PHP_BIN" -r '$b = new Swoole\Buffer(2048); var_dump(PHP_ZTS, ByteBuffer::supportsNativeBuffer($b));'
TEST_PHP_EXECUTABLE="$PHP_BIN" REPORT_EXIT_STATUS=1 \
  "$PHP_BIN" "$PHP_SRC/run-tests.php" -n tests
```

O estado deve ser `enabled (Swoole read ABI v1)` e o capability check deve ser
true. PHPTs com `psampler_binary_test` precisam da fixture normal compilada no
mesmo PHP ou carregada como DSO compatível. O runner marca como SKIP se ela não
estiver disponível; isso não conta como validação de guards/lifecycle/bailout.
Para um build compartilhado, adicione ao runner os argumentos
`-d extension=/caminho/swoole.so -d extension=/caminho/psampler.so`
e, nos testes que exigem fixture, `-d extension=/caminho/psampler_binary_test.so`.
Repita com a ordem dos dois módulos de produção invertida; mantenha a fixture
normal depois da psampler por causa de seus imports obrigatórios.

Inclua os PHPTs anteriores de buffers, registry e PCM/DSP na regressão completa.
Execute os testes OOM/fatais com o runner, que os isola em processos. Para
Valgrind/ASan/UBSan, use builds e limites de memória adequados: uma mudança do
allocator pode invalidar a condição de OOM dos PHPTs. Não use uma captura de
bailout de fixture para tentar continuar depois de saltar frames PHP arbitrários.

No binário totalmente estático, confira a tabela normal de símbolos (um
executável stripado pode não tê-la):

```bash
nm "$PHP_BIN" | rg 'swoole_buffer_(get_read_api|read_api_version)|psampler_module_entry'
readelf -Ws "$PHP_BIN" | rg 'swoole_buffer_(get_read_api|read_api_version)'
```

A presença na dynsym não é requisito quando ambos estão ligados estaticamente;
os testes de capability/UDP confirmam a resolução usada pelo código novo.
Para psampler DSO sobre Swoole estático, confira também `nm -D` no executável;
os símbolos precisam estar visíveis ao loader.

## Matriz adicional de compatibilidade

| Cenário | Verificação |
| --- | --- |
| PHP estático ELF ZTS com produtor atual | Capability true; UDP, ring, guards, bailout/OOM e regressões |
| PHP só com psampler | byte_buffer_native_no_swoole; `-n` não remove módulos compilados estaticamente, requer outro binário |
| Swoole upstream sem Buffer | byte_buffer_native_swoole_no_buffer |
| Swoole antigo com Buffer e sem ABI | byte_buffer_native_swoole_unavailable; status de símbolos ausentes |
| Bridge desabilitada | Mesmo cenário indisponível, ByteBuffer interno continua funcionando |
| Módulos compartilhados | Ambas as ordens INI e shutdown; conferir exports |
| PHP estático com Swoole DSO | Opcional Zend deve ordenar MINIT e fallback deve resolver pelo handle |
| ZTS concorrente | Requests/threads com objetos próprios, sem compartilhamento de objetos; validar no SAPI/thread harness real |

Nenhuma execução concorrente por processos deve ser apresentada como prova
de threads ZTS. Windows, 32 bits e toolchains não ELF permanecem pendentes.

Para recusa de ABI sem alterar o repositório produtor, use a variante da fixture
**já existente**, em um PHP sem Swoole real. O novo switch de configuração é
`--enable-psampler-binary-test-swoole-abi`, junto de
`--enable-psampler-binary-test`. Construa em uma cópia da fixture dentro deste
repositório, preservando o include relativo do header, para manter a fixture
normal disponível. A variante chama seu módulo `swoole`, tem a classe interna
`Swoole\Buffer` de teste e não implementa sockets. Não a distribua nem a carregue
junto do Swoole real. O único module_entry da fixture é reutilizado.

```bash
bash tests/run-swoole-abi-matrix.sh \
  /caminho/php-sem-swoole /caminho/run-tests.php \
  /caminho/psampler.so /caminho/fixture-alternativa/psampler_binary_test.so
```

A matriz varia a versão do símbolo, versão/tamanho da tabela, tabela NULL,
classe NULL/estrangeira e callbacks NULL. O controle `valid` deve registrar o
produtor e balancear acquire/release; cada outro modo deve manter o source sem
nenhum acquire e o ByteBuffer utilizável. O controle positivo evita que uma
falha de resolução faça todos os testes negativos passarem indevidamente.
A fixture simulada não substitui os testes com objetos reais do Swoole.

## Benchmark dos caminhos completos

O arquivo [swoole-buffer-path.php](../bench/swoole-buffer-path.php) compara:

```php
$packet = $receiver->recvfrom($peer, $timeout);
$destination->append($packet);
// versus
$bytes = $receiver->recvfromInto($source, $peer, $timeout);
if ($bytes !== false) $destination->appendBuffer($source);
```

São datagramas sintéticos de 172 bytes: cabeçalho de 12 bytes e payload de
160 bytes, incluindo NUL. Isso apenas fornece dados de entrada; não adiciona
parser, codec nem DSP à extensão. Ambos os modos usam o mesmo emissor externo,
protocolo de batches, timeout, número de pacotes, destino e sequência esperada.
O emissor é outro processo PHP, com streams UDP da biblioteca padrão; seu custo
de gerar strings não entra no CPU do receptor. O relatório separa seu CPU,
envios e erros, incluindo warmup.

Um pipe libera cada batch, de 32 pacotes por padrão, limitando a pressão na fila
UDP. O tempo medido inclui o sinal de envio, espera/socket e todos os appends,
inclusive criação/destruição da string tradicional e validações do retorno.
As mesmas chamadas hrtime/getrusage cercam cada batch; não subtraímos overhead
de medição. Após fechar o intervalo, a verificação compara o ring com a sequência
esperada e o drena, criando strings apenas fora da medição do caminho nativo.
O destino é pré-alocado igualmente nos dois modos; este benchmark não mede
crescimento. O native reutiliza um Swoole Buffer de 2048 bytes.

É uma carga controlada em loopback com backpressure por batches, não capacidade
máxima de rede ou tráfego em tempo real. Repita com outros tamanhos de batch
mantendo o mesmo valor nos dois caminhos. Batches maiores podem perder pacotes.
Só compare desempenho de amostras com perdas, erros de envio/recepção e falhas
de integridade **todos zerados**; uma amostra com erros continua relatando suas
contagens e não deve ser tratada como transferência bem-sucedida.

```bash
PHP_BIN="$PWD/php"
for repetition in 1 2 3 4 5; do
  if [ $((repetition % 2)) -eq 1 ]; then
    modes='traditional native'
  else
    modes='native traditional'
  fi
  for mode in $modes; do
    "$PHP_BIN" bench/swoole-buffer-path.php --mode="$mode" \
      --packets=1000000 --warmup=10000 --batch=32 --timeout=0.5
  done
done
```

Use processos novos para cada amostra, um host sem outras cargas e as mesmas
opções PHP/INI, afinidade e frequência de CPU. Se fixar afinidade do receptor,
lembre que o emissor herda a máscara: reserve dois cores para não transformar
o teste em disputa de um único core. Registre a topologia e o comando completo.

Cada linha JSON informa:

- `valid` indica se as contagens, integridade e erros permitem comparar a amostra.
- Pacotes recebidos por segundo e nanossegundos médios por pacote recebido.
- CPU user+system do receptor em segundos, e segundos por milhão de pacotes.
- RSS atual e pico RSS do processo no Linux (`/proc/self/status`).
- Uso/pico de memória Zend, com e sem reserva do allocator.
- Perdas por quantidade, erros de receive, batches com integridade divergente
  e contagens de envio/erro do emissor.
- Versões PHP/Swoole/psampler, ZTS, sistema operacional e parâmetros da carga.

RSS e picos Zend são do processo receptor inteiro e incluem inicialização,
infraestrutura de proc_open e strings de verificação; o RSS do processo emissor
é informado separadamente. Não são bytes por payload nem
alocações do trecho cronometrado. `allocations` fica NULL: as APIs PHP não
contam alocações de Zend/nativas. Para investigar, um run separado com
`USE_ZEND_ALLOC=0 valgrind --tool=memcheck --trace-children=no --log-file=...`
pode produzir totais de heap do receptor. Esses totais incluem verificação e
setup, mudam o allocator e não servem como medição isolada das alocações de
transferência. Não misture seus tempos com os runs normais. Instrumentação de
alocações estritamente dentro dos intervalos é uma pendência real.

Não há números medidos nesta etapa. Preencha os resultados após compilar,
reportando mediana e dispersão por modo e todos os erros. A integração reduz
a materialização de payload em string no código C, mas isso não demonstra
ganho de throughput ou CPU; nenhuma vantagem é presumida.
