/* Development diagnostic: compile with psampler_standalone.c and ASan/UBSan. */
#include "../psampler_resample.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

void psampler_standalone_init(void);
void psampler_standalone_shutdown(void);

int main(void)
{
    static const uint32_t rates[][2] = {
        {48000,8000}, {44100,8000}, {8000,48000}, {48000,44100}
    };
    unsigned char input[3840];
    psampler_standalone_init();
    for (int round = 0; round < 200; round++) {
        psampler_pcm_stream *streams[4];
        unsigned char *output[4] = {NULL, NULL, NULL, NULL};
        size_t capacity[4] = {0}, total[4] = {0};
        for (int j = 0; j < 4; j++) {
            streams[j] = psampler_pcm_stream_create(rates[j][0], rates[j][1], 1);
            if (streams[j] == NULL) return 1;
        }
        for (int frame = 0; frame < 50; frame++) {
            for (int j = 0; j < 4; j++) {
                size_t bytes = rates[j][0] / 50 * 2;
                size_t size = 0;
                for (size_t k = 0; k < bytes; k++) input[k] = (unsigned char) (k + frame + round);
                if (psampler_pcm_stream_process(streams[j], input, bytes, false,
                    &output[j], &size, &capacity[j]) != PSAMPLER_SUCCESS) return 2;
                if (size > capacity[j]) return 3;
                total[j] += size / 2;
            }
        }
        for (int j = 0; j < 4; j++) {
            size_t size = 0;
            if (psampler_pcm_stream_process(streams[j], NULL, 0, true,
                &output[j], &size, &capacity[j]) != PSAMPLER_SUCCESS) return 4;
            total[j] += size / 2;
            if (total[j] != rates[j][1]) {
                fprintf(stderr, "round %d stream %d: %zu != %u\n",
                    round, j, total[j], rates[j][1]);
                return 5;
            }
            psampler_pcm_stream_destroy(streams[j]);
            free(output[j]);
        }
    }
    psampler_standalone_shutdown();
    puts("ASan/UBSan PCM stream stress: OK");
    return 0;
}
