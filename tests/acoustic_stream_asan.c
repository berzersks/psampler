#include "../acoustic_stream.h"
#include <stdlib.h>
#include <stdio.h>
#include <math.h>
int main(void) {
    acoustic_stream *s=malloc(sizeof(*s));
    if(!s)return 1;
    acoustic_stream_init(s);
    unsigned char pcm[320];unsigned int seed=42;
    for(int frame=0;frame<100000;frame++) {
        for(int i=0;i<160;i++) {
            seed=seed*1664525U+1013904223U;
            int v=frame%300<100?(int)(12000*sin(2*3.141592653589793*426.35*(frame*160+i)/8000.0)):
                frame%300<200?0:(int)(seed>>16)-32768;
            pcm[2*i]=(unsigned char)v;pcm[2*i+1]=(unsigned char)(v>>8);
        }
        acoustic_stream_frame(s,pcm);
        if(s->event_count>ACOUSTIC_EVENTS || s->history_count>ACOUSTIC_HISTORY || s->dsp.ring_sample_index>=PCM_RING_SAMPLES)return 2;
        for(int i=0;i<s->event_count;i++)if(s->events[i].at_ms!=s->frames*20)return 3;
        if(frame%10000==9999)acoustic_stream_init(s);
    }
    free(s);puts("ASAN/UBSAN 100000 streaming frames PASS");return 0;
}
