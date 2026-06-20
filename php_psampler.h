/*
 * ==========================================================================
 * psampler - Extensao PHP para reamostragem (resampling) e manipulacao de
 *            audio PCM linear (LPCM)
 * ==========================================================================
 *
 * VISAO GERAL
 * -----------
 * Esta extensao expoe ao userland PHP duas classes e uma funcao global,
 * todas voltadas para processamento de audio PCM de 16 bits (signed,
 * little-endian no fluxo intercalado de saida):
 *
 *   1) class Resampler  -> reamostragem de alta qualidade (filtro polyphase
 *                          sinc + janela Kaiser, remocao de DC offset e soft
 *                          clipping), com suporte a streaming continuo via
 *                          buffer interno e multiplos contextos de taxa.
 *
 *   2) class LPCM       -> codificacao/decodificacao de PCM linear bruto
 *                          (mono e estereo) com largura de bits (8/16/24/32),
 *                          numero de canais e endianness configuraveis.
 *
 *   3) function interleavePcmStereo(string $leftPcm, string $rightPcm): string|false
 *                       -> intercala dois canais PCM 16-bit (L e R) em um
 *                          unico stream estereo intercalado.
 *
 * --------------------------------------------------------------------------
 * API: class Resampler
 * --------------------------------------------------------------------------
 *   __construct(?int $srcRate = null, ?int $dstRate = null)
 *       Cria o resampler. As taxas podem ser informadas aqui ou depois em
 *       sample(). Internamente mantem uma lista encadeada de contextos, um
 *       por par (srcRate, dstRate), permitindo trocar de taxa sem perder o
 *       estado dos demais.
 *
 *   reset(): void
 *       Reseta o estado interno (buffers, posicao fracionaria, DC offset)
 *       sem destruir os contextos.
 *
 *   sample(string $pcm, ?int $srcRate = null, ?int $dstRate = null): string
 *       Reamostra o bloco PCM informado. Se as taxas forem passadas, troca/
 *       cria o contexto correspondente. Trabalha em streaming: acumula
 *       amostras em buffer interno, aplica o filtro polyphase e devolve
 *       somente as amostras prontas; o restante fica retido para a proxima
 *       chamada. Retorna string PCM 16-bit (pode ser vazia se ainda nao ha
 *       amostras suficientes).
 *
 *   process(string $pcm): string
 *       Alias de conveniencia que chama sample() usando as taxas do contexto
 *       atual (sem troca de taxa).
 *
 *   returnEmpty(): string|false
 *       Indica/consulta o estado de saida pendente do ultimo processamento.
 *
 * --------------------------------------------------------------------------
 * API: class LPCM
 * --------------------------------------------------------------------------
 *   __construct(int $channels, int $bitDepth, bool $isBigEndian = false)
 *       channels: 1 (mono) ou 2 (stereo); bitDepth: 8, 16, 24 ou 32;
 *       isBigEndian: ordem de bytes do PCM.
 *
 *   encodeMono(array $samples): string
 *       Converte um array de amostras inteiras em PCM bruto mono.
 *
 *   decodeMono(string $pcmData): array
 *       Converte PCM bruto mono em array de amostras inteiras (com extensao
 *       de sinal de acordo com bitDepth).
 *
 *   encodeStereo(array $leftSamples, array $rightSamples): string
 *       Intercala dois arrays de amostras (L/R) em PCM bruto estereo.
 *
 *   decodeStereo(string $pcmData): array
 *       Decodifica PCM estereo em [arrayEsquerdo, arrayDireito].
 *
 * --------------------------------------------------------------------------
 * API: funcao global interleavePcmStereo
 * --------------------------------------------------------------------------
 *   interleavePcmStereo(string $leftPcm, string $rightPcm): string|false
 *       Intercala dois canais PCM 16-bit (L e R) em um stream estereo.
 *       Regras:
 *         1) left/right devem ter tamanho multiplo de 2 bytes (16-bit);
 *            caso contrario retorna false (E_WARNING).
 *         2) usa o maior numero de samples entre L e R; o lado que acabar
 *            antes e preenchido com 0 (silencio).
 *         3) saida = max_samples * 4 bytes (2 bytes L + 2 bytes R).
 *         4) sample_rate NAO e responsabilidade desta funcao (deve ser
 *            tratado por quem chama).
 *         5) string vazia e tratada como silencio.
 *
 * --------------------------------------------------------------------------
 * DETALHES INTERNOS DE DSP
 * --------------------------------------------------------------------------
 *   - Filtro: sinc janelado por Kaiser (FILTER_LENGTH=64, KAISER_BETA=8.6),
 *     dividido em 256 fases polyphase para interpolacao suave.
 *   - Buffer de streaming interno limitado a MAX_BUFFER_SIZE (8192) amostras.
 *   - Pos-processamento: remocao de DC offset (passa-alta de 1 polo) e soft
 *     clipping para evitar distorcao em [-32768, 32767].
 *   - Gerenciamento de memoria via emalloc/efree (pool do Zend) para os
 *     contextos/buffers e zend_string para as saidas (posse transferida ao
 *     engine, sem vazamentos).
 *
 * --------------------------------------------------------------------------
 * BUILD
 * --------------------------------------------------------------------------
 *   Como extensao dinamica:  phpize && ./configure && make
 *   Como extensao estatica:  ver buildspc.sh (linka no binario PHP, util
 *                            quando o PHP local nao suporta dynamic loading).
 * ==========================================================================
 */

#ifndef PHP_PSAMPLER_H
#define PHP_PSAMPLER_H

extern zend_module_entry psampler_module_entry;
#define phpext_psampler_ptr &psampler_module_entry

/*
 * Versionamento (SemVer: MAJOR.MINOR.PATCH)
 *   0.1.0 - Versao inicial: classes Resampler e LPCM.
 *   0.2.0 - Adicionada a funcao global interleavePcmStereo() e registro da
 *           tabela de funcoes do modulo.
 */
#define PHP_PSAMPLER_VERSION "0.2.0"

#ifdef PHP_WIN32
#   define PHP_PSAMPLER_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#   define PHP_PSAMPLER_API __attribute__ ((visibility("default")))
#else
#   define PHP_PSAMPLER_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

#endif /* PHP_PSAMPLER_H */
