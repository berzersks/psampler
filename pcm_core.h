#ifndef PSAMPLER_PCM_CORE_H
#define PSAMPLER_PCM_CORE_H

#include <stddef.h>
#include <stdint.h>

/* Byte access: no alignment or host-endianness assumptions. */
static inline int32_t psampler_pcm16_read(const unsigned char *src)
{
    uint32_t value = (uint32_t) src[0] | ((uint32_t) src[1] << 8);
    return value >= 32768 ? (int32_t) value - 65536 : (int32_t) value;
}

static inline void psampler_pcm16_write(unsigned char *dst, int32_t sample)
{
    uint16_t value = (uint16_t) sample;
    dst[0] = (unsigned char) value;
    dst[1] = (unsigned char) (value >> 8);
}

/* Callers validate lengths/capacity: stereo src_len % 4 == 0 with
 * src_len / 2 writable bytes; mono src_len % 2 == 0 with src_len * 2
 * writable bytes and no multiplication overflow. Zero length permits NULL.
 * Exact src == dst is supported; other overlap is forbidden. Stereo compacts
 * forwards; mono expands backwards. */
void psampler_pcm16_stereo_to_mono(const unsigned char *src, size_t src_len,
    unsigned char *dst);
void psampler_pcm16_mono_to_stereo(const unsigned char *src, size_t src_len,
    unsigned char *dst);

#endif
