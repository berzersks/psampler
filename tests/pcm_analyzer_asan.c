#include "../pcm_analyzer.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
int main(void) {
    pcm_analyzer *a=malloc(sizeof(*a));pcm_result *r=malloc(sizeof(*r));
    unsigned char *pcm = malloc(240002);
    if (!pcm||!a||!r) return 2;
    pcm_analyzer_init(a);
    unsigned int state=0x12345678;
    for (int run=0;run<100000;run++) {
        size_t n=(size_t)(run%100==0 ? run%751 : run%21)*320;
        for(size_t i=0;i<n;i++){state=state*1664525U+1013904223U;pcm[i]=(unsigned char)(state>>24);}
        int status=pcm_analyzer_run(a,pcm,n,r);
        if(status!=0 && status!=1) return 3;
        if(r->duration_ms!=(int)(n/16)) return 4;
        if(status==0){
            if(r->ring.frame_count>(int)PCM_RING_FRAMES||r->ring.pulse_count>r->ring.frame_count)return 5;
            if(r->ring.matched_pulse_count>r->ring.pulse_count)return 6;
        }
        if(run%1000==0){
            pcm_analyzer *other=malloc(sizeof(*other));if(!other)return 7;
            pcm_analyzer_init(other);
            if(pcm_analyzer_run(other,pcm,0,r)!=0||r->ring.pulse_count!=0)return 8;
            free(other);
        }
    }
    for(size_t n=0;n<=240002;n+=2){
        if(n>240000){if(pcm_analyzer_run(a,pcm,n,r)!=2)return 9;break;}
        if(n>320&&n<239998)continue;
        int status=pcm_analyzer_run(a,pcm,n,r);if(status!=0&&status!=1)return 10;
    }
    if(pcm_analyzer_run(a,pcm,1,r)!=2)return 11;
    free(pcm);free(r);free(a);
    puts("ASAN/UBSAN 100000 calls PASS");
    return 0;
}
