#ifndef PSAMPLER_RESAMPLE_H
#define PSAMPLER_RESAMPLE_H

#include "psampler_platform.h"
#include <stdint.h>

/* Internal only: complete-buffer adapter using the legacy streaming DSP.
 * Fresh state per call/channel, no tail flushing or padding. On SUCCESS the
 * caller owns *output (psampler_free_mem, unless NULL). On FAILURE outputs remain empty.
 * Out-parameters must be non-NULL; input must describe complete frames. */
psampler_result psampler_resample_pcm16(const unsigned char *input, size_t input_size,
    uint32_t src_rate, uint32_t dst_rate, uint16_t channels,
    unsigned char **output, size_t *output_size, size_t *output_capacity);

#endif
