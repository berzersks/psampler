# Fixture isolada da ABI binária

Código de teste **não compilado nem executado**. Não integra a lista de sources
da psampler. Depois de compilar/instalar a psampler e o header público,
o mantenedor pode construir esta extensão separadamente com a opção
`--enable-psampler-binary-test` e carregá-la após psampler. Nenhuma ferramenta
de build ou teste foi executada na preparação desta fixture.

O include é `ext/psampler/psampler_binary.h`; a extensão exige dependência
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

Ainda são necessários cenários externos em processos isolados para bailout
Zend (inclusive falha de alocação), shutdown e requests ZTS concorrentes;
esta fixture não afirma cobrir esses casos em execução. Os contadores são
por objeto, sem globals mutáveis por pacote.
