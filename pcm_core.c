#include "pcm_core.h"

void psampler_pcm16_stereo_to_mono(const unsigned char *src, size_t src_len,
    unsigned char *dst)
{
    for (size_t i = 0, j = 0; i < src_len; i += 4, j += 2) {
        int32_t sum = psampler_pcm16_read(src + i)
            + psampler_pcm16_read(src + i + 2);
        psampler_pcm16_write(dst + j, sum / 2);
    }
}

void psampler_pcm16_mono_to_stereo(const unsigned char *src, size_t src_len,
    unsigned char *dst)
{
    for (size_t i = src_len; i != 0; i -= 2) {
        unsigned char lo = src[i - 2], hi = src[i - 1];
        size_t j = (i - 2) * 2;
        dst[j] = lo;
        dst[j + 1] = hi;
        dst[j + 2] = lo;
        dst[j + 3] = hi;
    }
}
