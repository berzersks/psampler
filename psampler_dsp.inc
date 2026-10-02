/* Single DSP implementation, included by PHP and standalone adapters.
 * Keep arithmetic, cache, allocation lifecycle and native output identical. */
#include "psampler_platform.h"
#include "pcm_core.h"
#include <math.h>
#include <string.h>

#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif

#define FILTER_LENGTH 64
#define FILTER_PHASES 256
#define KAISER_BETA 8.6
#define MAX_BUFFER_SIZE 8192
#define FILTER_CACHE_LIMIT 16

typedef struct _psampler_filter_bank {
    double src_rate;
    double dst_rate;
    double coefficients[FILTER_LENGTH * FILTER_PHASES];
    unsigned int references;
    bool cached;
    uint64_t last_used;
    struct _psampler_filter_bank *next;
} psampler_filter_bank;

typedef struct _psampler_context {
    double ratio;
    double src_rate;
    double dst_rate;
    double last_dc;
    
    int16_t input_buffer[MAX_BUFFER_SIZE];
    size_t buffer_size;
    size_t buffer_used;
    
    double frac_pos;
    
    psampler_filter_bank *filter_bank;
    int filter_length;
    int phases;
    
    struct _psampler_context *next;
} psampler_context;

static psampler_filter_bank *filter_cache;
static size_t filter_cache_size;
static uint64_t filter_cache_clock;
#ifdef PSAMPLER_DSP_THREADS
static psampler_mutex filter_cache_mutex;
#endif

static void filter_cache_lock(void)
{
#ifdef PSAMPLER_DSP_THREADS
    psampler_mutex_lock(filter_cache_mutex);
#endif
}

static void filter_cache_unlock(void)
{
#ifdef PSAMPLER_DSP_THREADS
    psampler_mutex_unlock(filter_cache_mutex);
#endif
}


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

// Função sinc
static double sinc(double x)
{
    if (fabs(x) < 1e-8) return 1.0;
    return sin(M_PI * x) / (M_PI * x);
}

/* Gera somente os coeficientes imutáveis. A janela Kaiser também é invariável
 * entre as fases, portanto é calculada uma vez por tap, não 256 vezes. */
static void generate_filter_bank(psampler_filter_bank *bank)
{
    double window[FILTER_LENGTH];
    double alpha = (FILTER_LENGTH - 1) / 2.0;
    double beta_i0 = bessel_i0(KAISER_BETA);
    double ratio = bank->dst_rate / bank->src_rate;
    double cutoff = ratio < 1.0 ? ratio : 1.0;
    cutoff *= 0.95; // Margem de segurança para anti-aliasing

    for (int i = 0; i < FILTER_LENGTH; i++) {
        double position = (i - alpha) / alpha;
        double arg = KAISER_BETA * sqrt(1.0 - position * position);
        window[i] = bessel_i0(arg) / beta_i0;
    }

    // Gera filtro sinc com janela Kaiser para cada fase
    for (int phase = 0; phase < FILTER_PHASES; phase++) {
        double phase_offset = (double) phase / FILTER_PHASES;
        double sum = 0.0;

        for (int i = 0; i < FILTER_LENGTH; i++) {
            double t = i - (FILTER_LENGTH - 1) / 2.0 + phase_offset;
            double h = sinc(2.0 * cutoff * t) * 2.0 * cutoff;
            h *= window[i];
            bank->coefficients[phase * FILTER_LENGTH + i] = h;
            sum += h;
        }

        // Normaliza para manter ganho unitário
        if (sum > 0.0) {
            for (int i = 0; i < FILTER_LENGTH; i++) {
                bank->coefficients[phase * FILTER_LENGTH + i] /= sum;
            }
        }
    }
}

static psampler_filter_bank *create_filter_bank(double src_rate, double dst_rate)
{
    psampler_filter_bank *bank = psampler_palloc(sizeof(*bank), 1);
    bank->src_rate = src_rate;
    bank->dst_rate = dst_rate;
    bank->references = 1;
    bank->cached = 0;
    bank->last_used = 0;
    bank->next = NULL;
    generate_filter_bank(bank);
    return bank;
}

/* The cache is process-persistent and bounded. Coefficients are immutable after
 * publication. A reference prevents eviction while a streaming Resampler owns
 * the bank; transient PcmBuffer contexts normally release it immediately. */
static psampler_filter_bank *acquire_filter_bank(double src_rate, double dst_rate)
{
    psampler_filter_bank *bank;
    psampler_filter_bank *created;

    filter_cache_lock();
    for (bank = filter_cache; bank != NULL; bank = bank->next) {
        if (bank->src_rate == src_rate && bank->dst_rate == dst_rate) {
            bank->references++;
            bank->last_used = ++filter_cache_clock;
            filter_cache_unlock();
            return bank;
        }
    }
    filter_cache_unlock();

    /* Do the expensive trigonometry outside the cache lock. A concurrent
     * creator is detected below and this private duplicate is discarded. */
    created = create_filter_bank(src_rate, dst_rate);

    filter_cache_lock();
    for (bank = filter_cache; bank != NULL; bank = bank->next) {
        if (bank->src_rate == src_rate && bank->dst_rate == dst_rate) {
            bank->references++;
            bank->last_used = ++filter_cache_clock;
            filter_cache_unlock();
            psampler_pfree(created, 1);
            return bank;
        }
    }

    if (filter_cache_size >= FILTER_CACHE_LIMIT) {
        psampler_filter_bank **cursor = &filter_cache;
        psampler_filter_bank **victim_link = NULL;
        psampler_filter_bank *victim = NULL;
        while (*cursor != NULL) {
            if ((*cursor)->references == 0
                && (victim == NULL || (*cursor)->last_used < victim->last_used)) {
                victim = *cursor;
                victim_link = cursor;
            }
            cursor = &(*cursor)->next;
        }
        if (victim != NULL) {
            *victim_link = victim->next;
            filter_cache_size--;
            psampler_pfree(victim, 1);
        }
    }

    if (filter_cache_size < FILTER_CACHE_LIMIT) {
        created->cached = 1;
        created->last_used = ++filter_cache_clock;
        created->next = filter_cache;
        filter_cache = created;
        filter_cache_size++;
    }
    filter_cache_unlock();
    return created;
}

static void release_filter_bank(psampler_filter_bank *bank)
{
    if (!bank->cached) {
        psampler_pfree(bank, 1);
        return;
    }
    filter_cache_lock();
    PSAMPLER_ASSERT(bank->references > 0);
    bank->references--;
    filter_cache_unlock();
}

static void filter_cache_init(void)
{
    filter_cache = NULL;
    filter_cache_size = 0;
    filter_cache_clock = 0;
#ifdef PSAMPLER_DSP_THREADS
    filter_cache_mutex = psampler_mutex_alloc();
#endif
}

static void filter_cache_shutdown(void)
{
    psampler_filter_bank *bank = filter_cache;
    while (bank != NULL) {
        psampler_filter_bank *next = bank->next;
        psampler_pfree(bank, 1);
        bank = next;
    }
    filter_cache = NULL;
    filter_cache_size = 0;
#ifdef PSAMPLER_DSP_THREADS
    psampler_mutex_free(filter_cache_mutex);
    filter_cache_mutex = NULL;
#endif
}

static psampler_context *create_context(double src_rate, double dst_rate)
{
    psampler_context *ctx = (psampler_context *)psampler_alloc(sizeof(psampler_context));
    ctx->src_rate = src_rate;
    ctx->dst_rate = dst_rate;
    ctx->ratio = dst_rate / src_rate;
    ctx->last_dc = 0.0;
    ctx->frac_pos = 0.0;
    
    ctx->buffer_size = MAX_BUFFER_SIZE;
    ctx->buffer_used = 0;
    memset(ctx->input_buffer, 0, ctx->buffer_size * sizeof(int16_t));
    ctx->filter_length = FILTER_LENGTH;
    ctx->phases = FILTER_PHASES;
    ctx->filter_bank = acquire_filter_bank(src_rate, dst_rate);
    ctx->next = NULL;

    return ctx;
}

static void free_context(psampler_context *ctx)
{
    release_filter_bank(ctx->filter_bank);
    psampler_free_mem(ctx);
}


/* Shared streaming DSP. Generic callers emit through a sink; PcmBuffer writes
 * into a preallocated native destination. Input stride selects a channel from
 * interleaved PCM16LE. Admits at most the available context space, as the
 * legacy adapter did. */
typedef psampler_result (*resample_sink)(void *state, int16_t sample);

typedef struct {
    unsigned char *data;
    size_t capacity;
    size_t frames[2];
    uint16_t channels;
    uint16_t channel;
} resample_native_output;

static psampler_result resample_output_bound(const psampler_context *ctx,
    size_t frames, size_t *max_out_samples)
{
    size_t space = ctx->buffer_size - ctx->buffer_used;
    size_t used = ctx->buffer_used + (frames < space ? frames : space);
    *max_out_samples = 0;
    if (used > (size_t) (ctx->filter_length / 2)) {
        double estimate = (used - ctx->filter_length / 2) * ctx->ratio;
        size_t limit = PSAMPLER_STRING_MAX / 2;
        if (limit > (size_t) PSAMPLER_LONG_MAX / 2) {
            limit = (size_t) PSAMPLER_LONG_MAX / 2;
        }
        /* Reserve headroom for the legacy smart_str allocation's +16. */
        if (!isfinite(estimate) || estimate >= (double) (limit - 8)) {
            psampler_value_error("Resampler output exceeds the maximum supported size");
            return PSAMPLER_FAILURE;
        }
        *max_out_samples = (size_t) estimate;
    }
    return PSAMPLER_SUCCESS;
}

static psampler_result resample_native_reserve(resample_native_output *out,
    size_t max_out_samples)
{
    size_t stride = 2 * out->channels;
    size_t frame = out->frames[out->channel];
    size_t limit = (size_t) PSAMPLER_LONG_MAX < PSAMPLER_STRING_MAX
        ? (size_t) PSAMPLER_LONG_MAX : PSAMPLER_STRING_MAX;
    if (max_out_samples > limit / stride
        || frame > limit / stride - max_out_samples) {
        psampler_value_error("PcmBuffer resampled output exceeds the maximum supported size");
        return PSAMPLER_FAILURE;
    }
    size_t required = (frame + max_out_samples) * stride;
    if (required > out->capacity) {
        size_t capacity = out->capacity != 0 ? out->capacity : 4096;
        while (capacity < required) {
            if (capacity > limit / 2) {
                capacity = required;
                break;
            }
            capacity *= 2;
        }
        /* Zero unwritten stereo channel bytes, including during growth. */
        unsigned char *data = psampler_calloc(capacity, 1);
        if (out->data != NULL) {
            memcpy(data, out->data, out->capacity);
            psampler_free_mem(out->data);
        }
        out->data = data;
        out->capacity = capacity;
    }
    return PSAMPLER_SUCCESS;
}

static psampler_result resample_pcm16_block(psampler_context *ctx,
    const unsigned char *input, size_t frames, size_t stride,
    resample_sink sink, void *sink_state, resample_native_output *native_out,
    size_t *out_count)
{
    size_t space_available = ctx->buffer_size - ctx->buffer_used;
    size_t to_copy = frames < space_available ? frames : space_available;
    int filter_half = ctx->filter_length / 2;
    double step = 1.0 / ctx->ratio;
    size_t max_out_samples;
    unsigned char *native_dst = NULL;
    size_t native_stride = 0;
    size_t native_frame = 0;
    *out_count = 0;

    /* Preflight before modifying streaming state. */
    if (resample_output_bound(ctx, frames, &max_out_samples) == PSAMPLER_FAILURE) {
        return PSAMPLER_FAILURE;
    }
    if (native_out != NULL) {
        if (resample_native_reserve(native_out, max_out_samples) == PSAMPLER_FAILURE) {
            return PSAMPLER_FAILURE;
        }
        native_stride = 2 * native_out->channels;
        native_frame = native_out->frames[native_out->channel];
        if (max_out_samples != 0) {
            native_dst = native_out->data + native_frame * native_stride
                + 2 * native_out->channel;
        }
    }
    for (size_t i = 0; i < to_copy; i++) {
        ctx->input_buffer[ctx->buffer_used + i] =
            (int16_t) psampler_pcm16_read(input + i * stride);
    }
    ctx->buffer_used += to_copy;
    if (max_out_samples == 0) {
        return PSAMPLER_SUCCESS;
    }

    while (*out_count < max_out_samples) {
        /* Compare as double before conversion: extreme downsampling steps
         * can advance frac_pos past SIZE_MAX on 32-bit hosts. */
        if (ctx->frac_pos >= (double) (ctx->buffer_used - filter_half)) {
            break;
        }
        size_t base_idx = (size_t) ctx->frac_pos;
        double frac = ctx->frac_pos - base_idx;
        int phase_idx = (int) (frac * ctx->phases);
        if (phase_idx >= ctx->phases) phase_idx = ctx->phases - 1;
        double sample = 0.0;
        const double *filter = &ctx->filter_bank->coefficients[phase_idx * ctx->filter_length];
        int src_start = (int) base_idx - filter_half;
        int first_tap = src_start < 0 ? -src_start : 0;
        int last_tap = ctx->filter_length;
        if (src_start + last_tap > (int) ctx->buffer_used) {
            last_tap = (int) ctx->buffer_used - src_start;
        }
        /* Keep the sum order unchanged while amortizing the loop branch. */
        int i = first_tap;
        for (; i + 3 < last_tap; i += 4) {
            sample += ctx->input_buffer[src_start + i] * filter[i];
            sample += ctx->input_buffer[src_start + i + 1] * filter[i + 1];
            sample += ctx->input_buffer[src_start + i + 2] * filter[i + 2];
            sample += ctx->input_buffer[src_start + i + 3] * filter[i + 3];
        }
        for (; i < last_tap; i++) {
            sample += ctx->input_buffer[src_start + i] * filter[i];
        }
        ctx->last_dc = 0.9995 * ctx->last_dc + 0.0005 * sample;
        sample -= ctx->last_dc;
        if (sample > 32767.0) sample = 32767.0;
        else if (sample < -32768.0) sample = -32768.0;
        int16_t out_sample = (int16_t) lrint(sample);
        if (native_out != NULL) {
            psampler_pcm16_write(native_dst, out_sample);
            native_dst += native_stride;
            native_frame++;
        } else if (sink(sink_state, out_sample) == PSAMPLER_FAILURE) {
            return PSAMPLER_FAILURE;
        }
        ctx->frac_pos += step;
        (*out_count)++;
    }
    if (native_out != NULL) {
        native_out->frames[native_out->channel] = native_frame;
    }

    if (ctx->frac_pos >= (double) ctx->buffer_used) {
        ctx->buffer_used = 0;
        ctx->frac_pos = 0.0;
    } else {
        size_t consumed = (size_t) ctx->frac_pos;
        if (consumed > 0) {
            ctx->frac_pos -= consumed;
            memmove(ctx->input_buffer, ctx->input_buffer + consumed,
                (ctx->buffer_used - consumed) * sizeof(int16_t));
            ctx->buffer_used -= consumed;
        }
    }
    return PSAMPLER_SUCCESS;
}


psampler_result psampler_resample_pcm16(const unsigned char *input, size_t input_size,
    uint32_t src_rate, uint32_t dst_rate, uint16_t channels,
    unsigned char **output, size_t *output_size, size_t *output_capacity)
{
    psampler_context *ctx;
    resample_native_output out = {0};
    size_t stride = 2 * channels;
    *output = NULL;
    *output_size = *output_capacity = 0;
    if (src_rate == 0 || dst_rate == 0 || (channels != 1 && channels != 2)
        || input_size % stride != 0 || (input_size != 0 && input == NULL)) {
        psampler_value_error("Invalid native PCM16LE resampling input");
        return PSAMPLER_FAILURE;
    }
    out.channels = channels;
    for (out.channel = 0; out.channel < channels; out.channel++) {
        ctx = create_context((double) src_rate, (double) dst_rate);
        size_t offset = 0, frames = input_size / stride;
        while (offset < frames) {
            size_t available = ctx->buffer_size - ctx->buffer_used;
            size_t count = frames - offset;
            size_t produced;
            if (count > available) count = available;
            if (resample_pcm16_block(ctx,
                input + offset * stride + 2 * out.channel,
                count, stride, NULL, NULL, &out, &produced) == PSAMPLER_FAILURE) {
                free_context(ctx);
                if (out.data != NULL) psampler_free_mem(out.data);
                return PSAMPLER_FAILURE;
            }
            offset += count;
            /* The legacy finite streaming buffer cannot advance when the rate
             * ratio is too small to produce a sample even with a full buffer. */
            if (count == 0 && produced == 0) {
                free_context(ctx);
                if (out.data != NULL) psampler_free_mem(out.data);
                psampler_value_error("Sample rate ratio cannot advance the Resampler streaming buffer");
                return PSAMPLER_FAILURE;
            }
        }
        /* Keep legacy boundary behavior: no padding/flush of the retained tail. */
        free_context(ctx);
    }
    if (channels == 2 && out.frames[0] != out.frames[1]) {
        if (out.data != NULL) psampler_free_mem(out.data);
        psampler_throw_error(NULL, "Resampler channel output lengths differ");
        return PSAMPLER_FAILURE;
    }
    *output = out.data;
    *output_size = out.frames[0] * stride;
    *output_capacity = out.capacity;
    return PSAMPLER_SUCCESS;
}

