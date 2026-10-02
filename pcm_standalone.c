/* Compiles the exact DSP also included by psampler.c, without PHP headers. */
#include "psampler_resample.h"
#include "psampler_dsp.inc"
void psampler_standalone_init(void) { filter_cache_init(); }
void psampler_standalone_shutdown(void) { filter_cache_shutdown(); }

/* Validation oracle using the generic sink, like the public PHP Resampler.
 * Keeping both real call paths in this translation unit also prevents the
 * standalone compiler from specializing away the generic/native branch. */
typedef struct { unsigned char *data; size_t size; } reference_output;
static psampler_result reference_sink(void *state, int16_t sample)
{
    reference_output *out = state;
    psampler_pcm16_write(out->data + out->size, sample);
    out->size += 2;
    return PSAMPLER_SUCCESS;
}
psampler_result psampler_standalone_reference(const unsigned char *input,
    size_t bytes, uint32_t src, uint32_t dst, unsigned char **output, size_t *size)
{
    psampler_context *ctx = create_context(src, dst);
    size_t bound, count;
    if (resample_output_bound(ctx, bytes / 2, &bound) == PSAMPLER_FAILURE) {
        free_context(ctx);
        return PSAMPLER_FAILURE;
    }
    reference_output out = {psampler_alloc(bound * 2 + 16), 0};
    psampler_result result = resample_pcm16_block(ctx, input, bytes / 2, 2,
        reference_sink, &out, NULL, &count);
    free_context(ctx);
    *output = out.data;
    *size = out.size;
    return result;
}
