#include "../pcm_analyzer.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
int main(void) {
    pcm_analyzer a; pcm_result r;
    unsigned char *pcm = malloc(240000);
    if (!pcm) return 2;
    pcm_analyzer_init(&a);
    unsigned int state=0x12345678;
    for (int run=0;run<100000;run++) {
        size_t n=(size_t)(run%100==0 ? run%751 : run%21)*320;
        for(size_t i=0;i<n;i++){state=state*1664525U+1013904223U;pcm[i]=(unsigned char)(state>>24);}
        int status=pcm_analyzer_run(&a,pcm,n,&r);
        if(status!=0 && status!=1) return 3;
        if(r.duration_ms!=(int)(n/16)) return 4;
    }
    free(pcm);
    puts("ASAN/UBSAN 100000 calls PASS");
    return 0;
}
