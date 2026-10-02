/* Stress concurrent filter-bank acquisition, eviction, release and shutdown. */
#include "../psampler_resample.h"
#include <pthread.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

void psampler_standalone_init(void);
void psampler_standalone_shutdown(void);

static void *worker(void *argument)
{
    uintptr_t worker_id = (uintptr_t) argument;
    unsigned char input[882 * 2] = {0};
    for (int round = 0; round < 80; round++) {
        uint32_t dst = 6000 + ((round * 7 + worker_id * 11) % 40) * 137;
        psampler_pcm_stream *stream = psampler_pcm_stream_create(44100, dst, 1);
        unsigned char *output = NULL;
        size_t size = 0, capacity = 0;
        if (stream == NULL) return (void *) 1;
        for (int frame = 0; frame < 5; frame++) {
            if (psampler_pcm_stream_process(stream, input, sizeof(input), false,
                &output, &size, &capacity) != PSAMPLER_SUCCESS) return (void *) 2;
            if (size > capacity) return (void *) 3;
        }
        if (psampler_pcm_stream_process(stream, NULL, 0, true,
            &output, &size, &capacity) != PSAMPLER_SUCCESS) return (void *) 4;
        psampler_pcm_stream_destroy(stream);
        free(output);
    }
    return NULL;
}

int main(void)
{
    pthread_t threads[8];
    psampler_standalone_init();
    for (uintptr_t i = 0; i < 8; i++) {
        if (pthread_create(&threads[i], NULL, worker, (void *) i) != 0) return 1;
    }
    for (int i = 0; i < 8; i++) {
        void *result = NULL;
        if (pthread_join(threads[i], &result) != 0 || result != NULL) return 2;
    }
    psampler_standalone_shutdown();
    puts("concurrent FIR cache stress: OK");
    return 0;
}
