# Fixture isolada da ABI binária

Módulo exclusivamente de teste, fora da lista de sources da psampler. Depois
de compilar/instalar a psampler e os headers públicos, construa esta extensão
separadamente com `--enable-psampler-binary-test` e carregue-a após psampler.
A fixture também inclui um consumidor da ABI PCM v1 em `pcm_lifecycle.inc`.

Os includes públicos são `ext/psampler/psampler_binary.h` e
`ext/psampler/psampler_native.h`; a extensão exige dependência
Zend/build de psampler. Para bibliotecas dinâmicas, psampler deve estar
carregada antes da fixture para resolver os símbolos. Em builds estáticos,
inclua a fixture como extensão separada e respeite a dependência declarada.
Não é um módulo para instalar em produção. Configuração Windows não fornecida.

`PsamplerBinaryTestBuffer` guarda uma alocação nativa contígua copiada da string
de seed recebida pelo construtor. Reconstruir o objeto permite reutilizá-lo e
mudar o modo. Não contém uma segunda implementação de ring buffer. O modo
`split` apresenta duas regiões da mesma alocação apenas para testar a ABI.
`second-only` deixa a primeira região NULL/vazia e usa somente a segunda.
`stats()` retorna contadores por objeto `[acquired, released, reading]`.

Modos inválidos `null`, `capacity`, `second-overflow`, `overflow`, `capacity-overflow` e `source`
retornam descritores deliberadamente inconsistentes para verificar a validação
antes da leitura. `failure` falha sem exceção; `busy` simula receive pendente.
`exception-success` adquire o guard e retorna SUCCESS com exceção pendente.
`empty-null` retorna regiões vazias com ponteiros NULL.
**`huge` mente sobre o tamanho da alocação e só pode ser usado com um destino
já não vazio para testar a rejeição de overflow em appendBuffer; nunca deve
ser entregue a um leitor ou copiado para um destino vazio.**

`psampler_binary_test_read(source, hook=null, outcome="success")` examina
diretamente as regiões e retorna comprimento, soma módulo 65521 e número de
regiões não vazias. Não exporta ponteiros nem cria uma string de payload.
O hook existe só no módulo de teste e reentra PHP sincronamente para verificar
guards; não deve suspender a execução. Outcomes `failure`, `exception` e
`exception-success` exercitam a limpeza após falhas.

`psampler_binary_test_pin()` cria uma origem cujo único dono é um zval C,
adquire a view e destrói esse dono dentro do callback. A leitura subsequente
testa a referência mantida pelo dispatcher; a origem deve morrer no retorno.
Rodar esse caminho posteriormente com ASan/UBSan.

`psampler_binary_test_uninitialized(prototype)` aloca um objeto da classe do
protótipo registrado sem chamar o construtor, exclusivamente para testar esse
estado. Reflection proíbe esse bypass em classes internas finais com
create_object customizado; por isso essa cobertura exige a fixture C.

MINIT verifica versões/tamanhos inválidos, NULLs e registro duplicado;
também remove e registra novamente o produtor durante startup.
`psampler_binary_test_registry_locked()` verifica que registro/remoção são
recusados durante requests. MSHUTDOWN remove o produtor antes de seu código
ser descarregado.

Os PHPTs `binary_provider_{read,validation,guards}.phpt` exigem a fixture via
`--EXTENSIONS--`. O teste futuro de guards Swoole exige também a futura API
C pública e ponte registrada. Os PHPTs futuros declaram a ponte proposta
`psampler_swoole` em EXTENSIONS. Execute separadamente o cenário sem Swoole;
ele usa SKIPIF quando Swoole está carregado.

`psampler.test.lifecycle` é uma operação PCM registrada apenas por esta fixture.
Os modos `success`, `failure`, `exception`, `exception-success`, `bailout` e
`oom` exercitam retorno, resultado parcial e interrupções. `inspect` verifica
getters/identidade/registro; `hook`, `hook-failure` e `hook-bailout` permitem
reentrada PHP síncrona e criação de resultados cujo destrutor será observado.

`psampler_pcm_test_catch(pcm, operation, ...args)` chama o método interno real
em um frame de teste e intercepta o bailout em C. Retorna `[capturado,
resultado_null]`; libera os argumentos desse frame e restaura o executor.
Use somente com bailouts nativos diretos ou callbacks PHP que já retornaram:
não serve para recuperar execução após saltar frames PHP arbitrários.
`psampler_test_bailout()` serve exclusivamente aos testes fatais isolados.

`psampler_binary_test_catch(source)` intercepta bailout de acquire/leitor e
retorna `[capturado, refcount_preservado]`. O modo de produtor
`acquire-bailout` interrompe antes de adquirir o guard; não deve chamar release.
Os modos normais adquirem a view e provocam bailout no leitor, exigindo release
exatamente uma vez. A responsabilidade do produtor por desfazer uma aquisição
incompleta continua sendo a mesma; o dispatcher libera sua referência à origem.

Os PHPTs `pcm_buffer_native_lifecycle`, `pcm_buffer_invoke_{bailout,fatal,
cleanup_bailout,oom}`, `binary_provider_bailout` e `byte_buffer_native_oom`
verificam guards, ownership, destruição e integridade. Execute-os com o runner
PHPT e ambas as extensões carregadas. Os testes fatais/OOM executam em seus
próprios processos e verificam cleanup em shutdown; os testes com captura em C
exigem reutilização e destruição dos objetos, além da propagação do bailout.

Validação de lifecycle em 2026-10-28: PHP 8.5 NTS com módulos compartilhados
e PHP 8.5 ZTS por relink dos objetos existentes, preservando os executáveis
originais. Valgrind com `USE_ZEND_ALLOC=0` cobre buffers, referências e os
caminhos fatais. Isso não afirma cobertura de requests ZTS concorrentes nem
da ponte Swoole futura.
