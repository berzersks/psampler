#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_psampler.h"
#include <math.h>
#include <zend_smart_str.h>
#include <string.h>

#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif

#define FILTER_LENGTH 64
#define KAISER_BETA 8.6
#define MAX_BUFFER_SIZE 8192

static zend_class_entry *psampler_ce;
static zend_class_entry *lpcm_ce;

typedef struct _psampler_context {
    double ratio;
    double src_rate;
    double dst_rate;
    double last_dc;
    
    int16_t *input_buffer;
    size_t buffer_size;
    size_t buffer_used;
    
    double frac_pos;
    
    double *filter_bank;
    int filter_length;
    int phases;
    
    struct _psampler_context *next;
} psampler_context;

typedef struct {
    psampler_context *contexts;
    psampler_context *current_context;
    
    int pending_samples;
    int min_output_samples;
    
    zend_object std;
} psampler_object;

#define PSAMPLER_OBJ(zv) ((psampler_object *)((char *)(Z_OBJ_P(zv)) - XtOffsetOf(psampler_object, std)))

typedef struct {
    int channels;      // 1 = mono, 2 = stereo
    int bit_depth;     // 8, 16, 24, 32
    int is_big_endian; // 0 = little-endian, 1 = big-endian
    zend_object std;
} lpcm_object;

#define LPCM_OBJ(zv) ((lpcm_object *)((char *)(Z_OBJ_P(zv)) - XtOffsetOf(lpcm_object, std)))

// Função Bessel I0 modificada para janela Kaiser
static double bessel_i0(double x)
{
    double sum = 1.0;
    double term = 1.0;
    double x_half = x / 2.0;
    
    for (int i = 1; i < 50; i++) {
        term *= (x_half / i);
        sum += term * term;
    }
    return sum;
}

// Gera janela Kaiser
static double kaiser_window(int n, int N, double beta)
{
    double alpha = (N - 1) / 2.0;
    double arg = beta * sqrt(1.0 - pow((n - alpha) / alpha, 2.0));
    return bessel_i0(arg) / bessel_i0(beta);
}

// Função sinc
static double sinc(double x)
{
    if (fabs(x) < 1e-8) return 1.0;
    return sin(M_PI * x) / (M_PI * x);
}

// Gera banco de filtros polyphase de alta qualidade
static void generate_filter_bank(psampler_context *ctx)
{
    int filter_len = FILTER_LENGTH;
    int phases = 256; // Número de fases para interpolação suave
    
    ctx->filter_length = filter_len;
    ctx->phases = phases;
    ctx->filter_bank = (double *)emalloc(filter_len * phases * sizeof(double));
    
    double cutoff = (ctx->ratio < 1.0) ? ctx->ratio : 1.0;
    cutoff *= 0.95; // Margem de segurança para anti-aliasing
    
    // Gera filtro sinc com janela Kaiser para cada fase
    for (int phase = 0; phase < phases; phase++) {
        double phase_offset = (double)phase / phases;
        double sum = 0.0;
        
        for (int i = 0; i < filter_len; i++) {
            double t = i - (filter_len - 1) / 2.0 + phase_offset;
            double h = sinc(2.0 * cutoff * t) * 2.0 * cutoff;
            h *= kaiser_window(i, filter_len, KAISER_BETA);
            ctx->filter_bank[phase * filter_len + i] = h;
            sum += h;
        }
        
        // Normaliza para manter ganho unitário
        if (sum > 0.0) {
            for (int i = 0; i < filter_len; i++) {
                ctx->filter_bank[phase * filter_len + i] /= sum;
            }
        }
    }
}

static psampler_context *create_context(double src_rate, double dst_rate)
{
    psampler_context *ctx = (psampler_context *)emalloc(sizeof(psampler_context));
    ctx->src_rate = src_rate;
    ctx->dst_rate = dst_rate;
    ctx->ratio = dst_rate / src_rate;
    ctx->last_dc = 0.0;
    ctx->frac_pos = 0.0;
    
    ctx->buffer_size = MAX_BUFFER_SIZE;
    ctx->buffer_used = 0;
    ctx->input_buffer = (int16_t *)emalloc(ctx->buffer_size * sizeof(int16_t));
    memset(ctx->input_buffer, 0, ctx->buffer_size * sizeof(int16_t));
    
    ctx->filter_bank = NULL;
    ctx->next = NULL;
    
    generate_filter_bank(ctx);
    
    return ctx;
}

static void free_context(psampler_context *ctx)
{
    if (ctx->input_buffer) {
        efree(ctx->input_buffer);
    }
    if (ctx->filter_bank) {
        efree(ctx->filter_bank);
    }
    efree(ctx);
}

// Destrutor para liberar memória
static void psampler_free(zend_object *object)
{
    psampler_object *obj = (psampler_object *)((char *)object - XtOffsetOf(psampler_object, std));
    
    psampler_context *ctx = obj->contexts;
    while (ctx) {
        psampler_context *next = ctx->next;
        free_context(ctx);
        ctx = next;
    }
    
    zend_object_std_dtor(&obj->std);
}

static zend_object_handlers psampler_handlers;

static zend_object *psampler_create(zend_class_entry *ce)
{
    psampler_object *obj = zend_object_alloc(sizeof(psampler_object), ce);
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = &psampler_handlers;
    
    // Inicializa ponteiros
    obj->contexts = NULL;
    obj->current_context = NULL;
    
    return &obj->std;
}

// Handlers para LPCM
static void lpcm_free(zend_object *object)
{
    lpcm_object *obj = (lpcm_object *)((char *)object - XtOffsetOf(lpcm_object, std));
    zend_object_std_dtor(&obj->std);
}

static zend_object_handlers lpcm_handlers;

static zend_object *lpcm_create(zend_class_entry *ce)
{
    lpcm_object *obj = zend_object_alloc(sizeof(lpcm_object), ce);
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = &lpcm_handlers;
    
    // Valores padrão
    obj->channels = 1;
    obj->bit_depth = 16;
    obj->is_big_endian = 0;
    
    return &obj->std;
}

PHP_METHOD(Resampler, __construct)
{
    zend_long src = 0, dst = 0;
    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(src)
        Z_PARAM_LONG(dst)
    ZEND_PARSE_PARAMETERS_END();

    psampler_object *obj = PSAMPLER_OBJ(getThis());
    obj->pending_samples = 0;
    obj->min_output_samples = 512; // Mínimo de amostras para pacote válido
    
    // Se as taxas forem fornecidas, cria o primeiro contexto
    if (src > 0 && dst > 0) {
        psampler_context *ctx = create_context((double)src, (double)dst);
        obj->contexts = ctx;
        obj->current_context = ctx;
    }
}

PHP_METHOD(Resampler, reset)
{
    psampler_object *obj = PSAMPLER_OBJ(getThis());
    
    // O usuário solicitou que reset() limpe os contextos/estados
    psampler_context *ctx = obj->contexts;
    while (ctx) {
        psampler_context *next = ctx->next;
        free_context(ctx);
        ctx = next;
    }
    
    obj->contexts = NULL;
    obj->current_context = NULL;
    obj->pending_samples = 0;
    
    RETURN_TRUE;
}

PHP_METHOD(Resampler, returnEmpty)
{
    psampler_object *obj = PSAMPLER_OBJ(getThis());
    
    // Verifica se há amostras pendentes suficientes para um pacote válido
    if (obj->pending_samples >= obj->min_output_samples) {
        obj->pending_samples = 0;
        RETURN_FALSE; // Retorna false indicando que há pacote válido disponível
    }
    
    // Retorna string vazia (pacote vazio) enquanto não há amostras suficientes
    RETURN_EMPTY_STRING();
}

PHP_METHOD(Resampler, sample)
{
    zend_string *input;
    zend_long src = 0, dst = 0;
    
    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STR(input)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(src)
        Z_PARAM_LONG(dst)
    ZEND_PARSE_PARAMETERS_END();

    psampler_object *obj = PSAMPLER_OBJ(getThis());
    psampler_context *ctx = obj->current_context;
    
    // Se novas taxas forem fornecidas, busca ou cria o contexto correspondente
    if (src > 0 && dst > 0) {
        // Se as taxas fornecidas são as do contexto atual, não faz nada
        if (ctx && (zend_long)ctx->src_rate == src && (zend_long)ctx->dst_rate == dst) {
            // Já estamos no contexto correto
        } else {
            // Procura na lista de contextos
            psampler_context *curr = obj->contexts;
            psampler_context *prev = NULL;
            while (curr) {
                if ((zend_long)curr->src_rate == src && (zend_long)curr->dst_rate == dst) {
                    break;
                }
                prev = curr;
                curr = curr->next;
            }
            
            if (curr) {
                // Contexto encontrado
                obj->current_context = curr;
                ctx = curr;
            } else {
                // Cria novo contexto e adiciona à lista
                ctx = create_context((double)src, (double)dst);
                if (prev) {
                    prev->next = ctx;
                } else {
                    obj->contexts = ctx;
                }
                obj->current_context = ctx;
            }
        }
    }
    
    // Verifica se temos um contexto válido
    if (!ctx) {
        php_error_docref(NULL, E_WARNING, "Resampler not initialized with valid sample rates.");
        RETURN_EMPTY_STRING();
    }

    const int16_t *new_samples = (const int16_t *)ZSTR_VAL(input);
    size_t new_count = ZSTR_LEN(input) / 2;
    
    if (new_count == 0) {
        RETURN_EMPTY_STRING();
    }
    
    // Adiciona novas amostras ao buffer interno do contexto
    size_t space_available = ctx->buffer_size - ctx->buffer_used;
    size_t to_copy = (new_count < space_available) ? new_count : space_available;
    
    if (to_copy > 0) {
        memcpy(ctx->input_buffer + ctx->buffer_used, new_samples, to_copy * sizeof(int16_t));
        ctx->buffer_used += to_copy;
    }
    
    // Calcula quantas amostras de saída podemos gerar
    int filter_half = ctx->filter_length / 2;
    double step = 1.0 / ctx->ratio;
    size_t max_out_samples = 0;
    
    if (ctx->buffer_used > filter_half) {
        max_out_samples = (size_t)((ctx->buffer_used - filter_half) * ctx->ratio);
    }
    
    if (max_out_samples == 0) {
        RETURN_EMPTY_STRING();
    }
    
    smart_str out = {0};
    smart_str_alloc(&out, max_out_samples * 2 + 16, 0);
    
    size_t out_count = 0;
    
    // Processa com filtro polyphase de alta qualidade
    while (out_count < max_out_samples) {
        size_t base_idx = (size_t)ctx->frac_pos;
        
        // Verifica se temos amostras suficientes no buffer
        if (base_idx + filter_half >= ctx->buffer_used) {
            break;
        }
        
        // Calcula índice da fase do filtro
        double frac = ctx->frac_pos - base_idx;
        int phase_idx = (int)(frac * ctx->phases);
        if (phase_idx >= ctx->phases) phase_idx = ctx->phases - 1;
        
        // Aplica filtro polyphase
        double sample = 0.0;
        double *filter = &ctx->filter_bank[phase_idx * ctx->filter_length];
        
        for (int i = 0; i < ctx->filter_length; i++) {
            int src_idx = (int)base_idx - filter_half + i;
            if (src_idx >= 0 && src_idx < (int)ctx->buffer_used) {
                sample += ctx->input_buffer[src_idx] * filter[i];
            }
        }
        
        // Remoção de DC offset aprimorada (filtro passa-alta de 1 polo)
        ctx->last_dc = 0.9995 * ctx->last_dc + 0.0005 * sample;
        sample -= ctx->last_dc;
        
        // Clipping suave (soft clipping) para evitar distorção
        if (sample > 32767.0) sample = 32767.0;
        else if (sample < -32768.0) sample = -32768.0;
        
        int16_t out_sample = (int16_t)lrint(sample);
        smart_str_appendl(&out, (char *)&out_sample, 2);
        
        ctx->frac_pos += step;
        out_count++;
    }
    
    // Atualiza pending_samples para controle de returnEmpty()
    obj->pending_samples = (int)out_count;
    
    // Remove amostras processadas do buffer
    size_t consumed = (size_t)ctx->frac_pos;
    if (consumed > 0 && consumed < ctx->buffer_used) {
        ctx->frac_pos -= consumed;
        memmove(ctx->input_buffer, ctx->input_buffer + consumed, 
                (ctx->buffer_used - consumed) * sizeof(int16_t));
        ctx->buffer_used -= consumed;
    } else if (consumed >= ctx->buffer_used) {
        ctx->buffer_used = 0;
        ctx->frac_pos = 0.0;
    }
    
    smart_str_0(&out);
    
    if (out_count == 0) {
        smart_str_free(&out);
        RETURN_EMPTY_STRING();
    }
    
    RETURN_STR(out.s);
}

PHP_METHOD(Resampler, process)
{
    // Alias para manter compatibilidade, chama sample internamente sem trocar taxas
    zend_string *input;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(input)
    ZEND_PARSE_PARAMETERS_END();

    psampler_object *obj = PSAMPLER_OBJ(getThis());
    psampler_context *ctx = obj->current_context;
    
    if (!ctx) {
        php_error_docref(NULL, E_WARNING, "Resampler not initialized with valid sample rates.");
        RETURN_EMPTY_STRING();
    }

    // Chama sample internamente com as taxas do contexto atual
    zval method_name, params[3];
    ZVAL_STRING(&method_name, "sample");
    ZVAL_STR(&params[0], input);
    ZVAL_LONG(&params[1], (zend_long)ctx->src_rate);
    ZVAL_LONG(&params[2], (zend_long)ctx->dst_rate);

    call_user_function(NULL, getThis(), &method_name, return_value, 3, params);
    
    zval_ptr_dtor(&method_name);
}

// ============================================================================
// Métodos da classe LPCM
// ============================================================================

PHP_METHOD(LPCM, __construct)
{
    zend_long channels, bit_depth;
    zend_bool is_big_endian = 0;
    
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_LONG(channels)
        Z_PARAM_LONG(bit_depth)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_big_endian)
    ZEND_PARSE_PARAMETERS_END();
    
    lpcm_object *obj = LPCM_OBJ(getThis());
    
    // Valida channels
    if (channels != 1 && channels != 2) {
        zend_throw_exception(NULL, "Channels must be 1 (mono) or 2 (stereo)", 0);
        RETURN_THROWS();
    }
    
    // Valida bit_depth
    if (bit_depth != 8 && bit_depth != 16 && bit_depth != 24 && bit_depth != 32) {
        zend_throw_exception(NULL, "Bit depth must be 8, 16, 24, or 32", 0);
        RETURN_THROWS();
    }
    
    obj->channels = (int)channels;
    obj->bit_depth = (int)bit_depth;
    obj->is_big_endian = is_big_endian ? 1 : 0;
}

PHP_METHOD(LPCM, encodeMono)
{
    zval *samples_array;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(samples_array)
    ZEND_PARSE_PARAMETERS_END();
    
    lpcm_object *obj = LPCM_OBJ(getThis());
    
    if (obj->channels != 1) {
        zend_throw_exception(NULL, "encodeMono requires channels=1", 0);
        RETURN_THROWS();
    }
    
    HashTable *ht = Z_ARRVAL_P(samples_array);
    size_t num_samples = zend_hash_num_elements(ht);
    
    if (num_samples == 0) {
        RETURN_EMPTY_STRING();
    }
    
    smart_str out = {0};
    int bytes_per_sample = obj->bit_depth / 8;
    
    zval *entry;
    ZEND_HASH_FOREACH_VAL(ht, entry) {
        int32_t sample = (int32_t)zval_get_long(entry);
        
        // Clipping baseado no bit depth (usa int64_t para evitar overflow em 32-bit)
        int64_t max_val = ((int64_t)1 << (obj->bit_depth - 1)) - 1;
        int64_t min_val = -((int64_t)1 << (obj->bit_depth - 1));
        if (sample > max_val) sample = (int32_t)max_val;
        if (sample < min_val) sample = (int32_t)min_val;
        
        // Escreve bytes conforme endianness
        unsigned char bytes[4];
        for (int i = 0; i < bytes_per_sample; i++) {
            if (obj->is_big_endian) {
                bytes[i] = (sample >> ((bytes_per_sample - 1 - i) * 8)) & 0xFF;
            } else {
                bytes[i] = (sample >> (i * 8)) & 0xFF;
            }
        }
        smart_str_appendl(&out, (char *)bytes, bytes_per_sample);
    } ZEND_HASH_FOREACH_END();
    
    smart_str_0(&out);
    RETURN_STR(out.s);
}

PHP_METHOD(LPCM, decodeMono)
{
    char *pcm_data;
    size_t pcm_len;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(pcm_data, pcm_len)
    ZEND_PARSE_PARAMETERS_END();
    
    lpcm_object *obj = LPCM_OBJ(getThis());
    
    if (obj->channels != 1) {
        zend_throw_exception(NULL, "decodeMono requires channels=1", 0);
        RETURN_THROWS();
    }
    
    int bytes_per_sample = obj->bit_depth / 8;
    size_t num_samples = pcm_len / bytes_per_sample;
    
    array_init(return_value);
    
    for (size_t i = 0; i < num_samples; i++) {
        unsigned char *bytes = (unsigned char *)&pcm_data[i * bytes_per_sample];
        int32_t sample = 0;
        
        // Lê bytes conforme endianness
        for (int j = 0; j < bytes_per_sample; j++) {
            if (obj->is_big_endian) {
                sample |= ((int32_t)bytes[j]) << ((bytes_per_sample - 1 - j) * 8);
            } else {
                sample |= ((int32_t)bytes[j]) << (j * 8);
            }
        }
        
        // Extensão de sinal para valores negativos (usa int64_t para evitar overflow em 32-bit)
        if (sample & ((int64_t)1 << (obj->bit_depth - 1))) {
            sample |= ~(((int64_t)1 << obj->bit_depth) - 1);
        }
        
        add_next_index_long(return_value, sample);
    }
}

PHP_METHOD(LPCM, encodeStereo)
{
    zval *left_array, *right_array;
    
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ARRAY(left_array)
        Z_PARAM_ARRAY(right_array)
    ZEND_PARSE_PARAMETERS_END();
    
    lpcm_object *obj = LPCM_OBJ(getThis());
    
    if (obj->channels != 2) {
        zend_throw_exception(NULL, "encodeStereo requires channels=2", 0);
        RETURN_THROWS();
    }
    
    HashTable *left_ht = Z_ARRVAL_P(left_array);
    HashTable *right_ht = Z_ARRVAL_P(right_array);
    size_t left_count = zend_hash_num_elements(left_ht);
    size_t right_count = zend_hash_num_elements(right_ht);
    
    if (left_count != right_count) {
        zend_throw_exception(NULL, "Left and right arrays must have same length", 0);
        RETURN_THROWS();
    }
    
    if (left_count == 0) {
        RETURN_EMPTY_STRING();
    }
    
    smart_str out = {0};
    int bytes_per_sample = obj->bit_depth / 8;
    
    zval *left_entry, *right_entry;
    HashPosition left_pos, right_pos;
    
    zend_hash_internal_pointer_reset_ex(left_ht, &left_pos);
    zend_hash_internal_pointer_reset_ex(right_ht, &right_pos);
    
    for (size_t i = 0; i < left_count; i++) {
        left_entry = zend_hash_get_current_data_ex(left_ht, &left_pos);
        right_entry = zend_hash_get_current_data_ex(right_ht, &right_pos);
        
        int32_t samples[2] = {
            (int32_t)zval_get_long(left_entry),
            (int32_t)zval_get_long(right_entry)
        };
        
        for (int ch = 0; ch < 2; ch++) {
            int32_t sample = samples[ch];
            
            // Clipping (usa int64_t para evitar overflow em 32-bit)
            int64_t max_val = ((int64_t)1 << (obj->bit_depth - 1)) - 1;
            int64_t min_val = -((int64_t)1 << (obj->bit_depth - 1));
            if (sample > max_val) sample = (int32_t)max_val;
            if (sample < min_val) sample = (int32_t)min_val;
            
            // Escreve bytes
            unsigned char bytes[4];
            for (int j = 0; j < bytes_per_sample; j++) {
                if (obj->is_big_endian) {
                    bytes[j] = (sample >> ((bytes_per_sample - 1 - j) * 8)) & 0xFF;
                } else {
                    bytes[j] = (sample >> (j * 8)) & 0xFF;
                }
            }
            smart_str_appendl(&out, (char *)bytes, bytes_per_sample);
        }
        
        zend_hash_move_forward_ex(left_ht, &left_pos);
        zend_hash_move_forward_ex(right_ht, &right_pos);
    }
    
    smart_str_0(&out);
    RETURN_STR(out.s);
}

PHP_METHOD(LPCM, decodeStereo)
{
    char *pcm_data;
    size_t pcm_len;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(pcm_data, pcm_len)
    ZEND_PARSE_PARAMETERS_END();
    
    lpcm_object *obj = LPCM_OBJ(getThis());
    
    if (obj->channels != 2) {
        zend_throw_exception(NULL, "decodeStereo requires channels=2", 0);
        RETURN_THROWS();
    }
    
    int bytes_per_sample = obj->bit_depth / 8;
    size_t num_frames = pcm_len / (bytes_per_sample * 2);
    
    zval left_array, right_array;
    array_init(&left_array);
    array_init(&right_array);
    
    for (size_t i = 0; i < num_frames; i++) {
        for (int ch = 0; ch < 2; ch++) {
            unsigned char *bytes = (unsigned char *)&pcm_data[(i * 2 + ch) * bytes_per_sample];
            int32_t sample = 0;
            
            // Lê bytes
            for (int j = 0; j < bytes_per_sample; j++) {
                if (obj->is_big_endian) {
                    sample |= ((int32_t)bytes[j]) << ((bytes_per_sample - 1 - j) * 8);
                } else {
                    sample |= ((int32_t)bytes[j]) << (j * 8);
                }
            }
            
            // Extensão de sinal (usa int64_t para evitar overflow em 32-bit)
            if (sample & ((int64_t)1 << (obj->bit_depth - 1))) {
                sample |= ~(((int64_t)1 << obj->bit_depth) - 1);
            }
            
            if (ch == 0) {
                add_next_index_long(&left_array, sample);
            } else {
                add_next_index_long(&right_array, sample);
            }
        }
    }
    
    array_init(return_value);
    add_next_index_zval(return_value, &left_array);
    add_next_index_zval(return_value, &right_array);
}

// ==========================================================================
// Funcao global: interleavePcmStereo
// Intercala dois canais PCM 16-bit (L e R) em um stream estereo intercalado.
// Regras:
//   1) left/right devem ter tamanho multiplo de 2 (samples de 16 bits),
//      caso contrario retorna false (PCM quebrado nao merece carinho).
//   2) usa o maior numero de samples entre L e R; lado que acabar -> 0.
//   3) saida = max_samples * 4 bytes (2 bytes L + 2 bytes R).
//   4) sample_rate nao e responsabilidade desta funcao.
//   5) string vazia e tratada como silencio.
// ==========================================================================
PHP_FUNCTION(interleavePcmStereo)
{
    zend_string *left;
    zend_string *right;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(left)
        Z_PARAM_STR(right)
    ZEND_PARSE_PARAMETERS_END();

    size_t left_len = ZSTR_LEN(left);
    size_t right_len = ZSTR_LEN(right);

    // Regra 1: tamanho deve ser multiplo de 2 (sample de 16 bits)
    if ((left_len % 2) != 0 || (right_len % 2) != 0) {
        php_error_docref(NULL, E_WARNING,
            "interleavePcmStereo: PCM length must be a multiple of 2 bytes (16-bit samples).");
        RETURN_FALSE;
    }

    size_t left_samples = left_len / 2;
    size_t right_samples = right_len / 2;

    // Regra 2: usa o maior numero de samples entre os dois canais
    size_t max_samples = (left_samples > right_samples) ? left_samples : right_samples;

    // Regra 5: ambos vazios -> silencio total -> string vazia
    if (max_samples == 0) {
        RETURN_EMPTY_STRING();
    }

    // Regra 3: saida = max_samples * 4 bytes
    size_t out_len = max_samples * 4;
    zend_string *out = zend_string_alloc(out_len, 0);
    char *dst = ZSTR_VAL(out);

    const char *lsrc = ZSTR_VAL(left);
    const char *rsrc = ZSTR_VAL(right);

    for (size_t i = 0; i < max_samples; i++) {
        // Canal esquerdo (escreve 0 se o lado ja acabou)
        if (i < left_samples) {
            dst[i * 4]     = lsrc[i * 2];
            dst[i * 4 + 1] = lsrc[i * 2 + 1];
        } else {
            dst[i * 4]     = 0;
            dst[i * 4 + 1] = 0;
        }
        // Canal direito (escreve 0 se o lado ja acabou)
        if (i < right_samples) {
            dst[i * 4 + 2] = rsrc[i * 2];
            dst[i * 4 + 3] = rsrc[i * 2 + 1];
        } else {
            dst[i * 4 + 2] = 0;
            dst[i * 4 + 3] = 0;
        }
    }

    // zend_string_alloc reserva out_len + 1 para o terminador
    ZSTR_VAL(out)[out_len] = '\0';

    // RETURN_STR transfere a posse de 'out' para o engine (sem vazamento)
    RETURN_STR(out);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_void, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_construct, 0, 0, 0)
    ZEND_ARG_TYPE_INFO(0, srcRate, IS_LONG, 1)
    ZEND_ARG_TYPE_INFO(0, dstRate, IS_LONG, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_sample, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, pcm, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, srcRate, IS_LONG, 1)
    ZEND_ARG_TYPE_INFO(0, dstRate, IS_LONG, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_process, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, pcm, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_returnEmpty, 0, 0, MAY_BE_STRING|MAY_BE_FALSE)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_interleavePcmStereo, 0, 2, MAY_BE_STRING|MAY_BE_FALSE)
    ZEND_ARG_TYPE_INFO(0, leftPcm, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, rightPcm, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry psampler_functions[] = {
    PHP_FE(interleavePcmStereo, arginfo_interleavePcmStereo)
    PHP_FE_END
};

static const zend_function_entry psampler_methods[] = {
    PHP_ME(Resampler, __construct, arginfo_construct, ZEND_ACC_PUBLIC)
    PHP_ME(Resampler, reset, arginfo_void, ZEND_ACC_PUBLIC)
    PHP_ME(Resampler, sample, arginfo_sample, ZEND_ACC_PUBLIC)
    PHP_ME(Resampler, process, arginfo_process, ZEND_ACC_PUBLIC)
    PHP_ME(Resampler, returnEmpty, arginfo_returnEmpty, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

// ArgInfo para classe LPCM
ZEND_BEGIN_ARG_INFO_EX(arginfo_lpcm_construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, channels, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, bitDepth, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, isBigEndian, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_lpcm_encodeMono, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, samples, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_lpcm_decodeMono, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, pcmData, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_lpcm_encodeStereo, 0, 2, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, leftSamples, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, rightSamples, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_lpcm_decodeStereo, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, pcmData, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry lpcm_methods[] = {
    PHP_ME(LPCM, __construct, arginfo_lpcm_construct, ZEND_ACC_PUBLIC)
    PHP_ME(LPCM, encodeMono, arginfo_lpcm_encodeMono, ZEND_ACC_PUBLIC)
    PHP_ME(LPCM, decodeMono, arginfo_lpcm_decodeMono, ZEND_ACC_PUBLIC)
    PHP_ME(LPCM, encodeStereo, arginfo_lpcm_encodeStereo, ZEND_ACC_PUBLIC)
    PHP_ME(LPCM, decodeStereo, arginfo_lpcm_decodeStereo, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(psampler)
{
    zend_class_entry ce;
    
    // Inicializa handlers personalizados para Resampler
    memcpy(&psampler_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    psampler_handlers.free_obj = psampler_free;
    psampler_handlers.offset = XtOffsetOf(psampler_object, std);
    
    INIT_CLASS_ENTRY(ce, "Resampler", psampler_methods);
    psampler_ce = zend_register_internal_class(&ce);
    psampler_ce->create_object = psampler_create;
    
    // Inicializa handlers personalizados para LPCM
    memcpy(&lpcm_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    lpcm_handlers.free_obj = lpcm_free;
    lpcm_handlers.offset = XtOffsetOf(lpcm_object, std);
    
    INIT_CLASS_ENTRY(ce, "LPCM", lpcm_methods);
    lpcm_ce = zend_register_internal_class(&ce);
    lpcm_ce->create_object = lpcm_create;
    
    return SUCCESS;
}

// ==========================================================================
// PHP_MINFO_FUNCTION: informacoes exibidas em phpinfo() e `php --ri psampler`
// ==========================================================================
PHP_MINFO_FUNCTION(psampler)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "psampler support", "enabled");
    php_info_print_table_row(2, "Version", PHP_PSAMPLER_VERSION);
    php_info_print_table_row(2, "Description",
        "Reamostragem (resampling) e manipulacao de audio PCM linear (LPCM) 16-bit");
    php_info_print_table_row(2, "Author", "psampler");
    php_info_print_table_end();

    // Classes expostas ao userland
    php_info_print_table_start();
    php_info_print_table_header(2, "Classe", "Metodos / API");
    php_info_print_table_row(2, "Resampler",
        "__construct(?int srcRate, ?int dstRate), reset(): void, "
        "sample(string pcm, ?int srcRate, ?int dstRate): string, "
        "process(string pcm): string, returnEmpty(): string|false");
    php_info_print_table_row(2, "LPCM",
        "__construct(int channels, int bitDepth, bool isBigEndian=false), "
        "encodeMono(array): string, decodeMono(string): array, "
        "encodeStereo(array, array): string, decodeStereo(string): array");
    php_info_print_table_end();

    // Funcoes globais expostas ao userland
    php_info_print_table_start();
    php_info_print_table_header(2, "Funcao global", "Assinatura");
    php_info_print_table_row(2, "interleavePcmStereo",
        "interleavePcmStereo(string leftPcm, string rightPcm): string|false "
        "- intercala dois canais PCM 16-bit (L/R) em stream estereo");
    php_info_print_table_end();

    // Detalhes internos de DSP / configuracao do filtro
    php_info_print_table_start();
    php_info_print_table_header(2, "Parametro de DSP", "Valor");
    php_info_print_table_row(2, "Filtro", "sinc janelado por Kaiser (polyphase)");
    php_info_print_table_row(2, "FILTER_LENGTH", "64");
    php_info_print_table_row(2, "KAISER_BETA", "8.6");
    php_info_print_table_row(2, "Fases polyphase", "256");
    php_info_print_table_row(2, "MAX_BUFFER_SIZE", "8192 amostras");
    php_info_print_table_row(2, "Pos-processamento",
        "remocao de DC offset (passa-alta 1 polo) + soft clipping [-32768, 32767]");
    php_info_print_table_end();
}

zend_module_entry psampler_module_entry = {
    STANDARD_MODULE_HEADER,
    "psampler",
    psampler_functions,
    PHP_MINIT(psampler),
    NULL,
    NULL,
    NULL,
    PHP_MINFO(psampler),
    PHP_PSAMPLER_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_PSAMPLER
ZEND_GET_MODULE(psampler)
#endif
