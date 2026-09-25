#define _POSIX_C_SOURCE 200809L
#include "../pcm_analyzer.h"
#include <glob.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/resource.h>
#include <time.h>

typedef struct { unsigned char *data; size_t size; } input;
static double milliseconds(void) {
    struct timespec t;clock_gettime(CLOCK_MONOTONIC,&t);
    return t.tv_sec*1000.0+t.tv_nsec/1000000.0;
}
static double cpu_ms(void) {
    struct rusage r;getrusage(RUSAGE_SELF,&r);
    return (r.ru_utime.tv_sec+r.ru_stime.tv_sec)*1000.0+
        (r.ru_utime.tv_usec+r.ru_stime.tv_usec)/1000.0;
}
static int cmp(const void *a,const void *b) {
    double x=*(const double *)a,y=*(const double *)b;
    return (x>y)-(x<y);
}
static long rss_kb(void) {
    FILE *f=fopen("/proc/self/status","r");if(!f)return 0;
    char line[256];long value=0;
    while(fgets(line,sizeof(line),f))if(sscanf(line,"VmRSS: %ld",&value)==1)break;
    fclose(f);return value;
}
int main(int argc,char **argv) {
    int runs=argc>1?atoi(argv[1]):1000;if(runs<1000)runs=1000;
    pcm_analyzer *a=malloc(sizeof(*a));pcm_result *r=malloc(sizeof(*r));
    if(!a||!r)return 2;pcm_analyzer_init(a);
    unsigned long long checksum=0;
    int durations[]={1,3,5,10,15};
    for(int d=0;d<5;d++) {
        char pattern[256];snprintf(pattern,sizeof(pattern),"bench/ring-fixtures/%02ds_*.pcm",durations[d]);
        glob_t paths={0};if(glob(pattern,0,NULL,&paths)||paths.gl_pathc==0)return 3;
        input *inputs=calloc(paths.gl_pathc,sizeof(*inputs));
        for(size_t i=0;i<paths.gl_pathc;i++) {
            FILE *f=fopen(paths.gl_pathv[i],"rb");if(!f)return 4;
            fseek(f,0,SEEK_END);inputs[i].size=(size_t)ftell(f);rewind(f);
            inputs[i].data=malloc(inputs[i].size);if(!inputs[i].data)return 5;
            if(fread(inputs[i].data,1,inputs[i].size,f)!=inputs[i].size)return 6;
            fclose(f);
        }
        for(int i=0;i<25;i++){
            input *b=&inputs[i%paths.gl_pathc];if(pcm_analyzer_run(a,b->data,b->size,r))return 7;
            checksum+=r->active_audio_ms;
        }
        double *times=malloc((size_t)runs*sizeof(double));if(!times)return 8;
        long initial=rss_kb();double cpu_start=cpu_ms(),wall_start=milliseconds();
        for(int i=0;i<runs;i++){
            input *b=&inputs[i%paths.gl_pathc];double start=milliseconds();
            if(pcm_analyzer_run(a,b->data,b->size,r))return 9;
            times[i]=milliseconds()-start;checksum+=r->active_audio_ms;
#ifndef OLD_CORE
            checksum+=r->ring.pulse_count;
#endif
        }
        double wall=milliseconds()-wall_start,cpu=cpu_ms()-cpu_start;
        qsort(times,(size_t)runs,sizeof(double),cmp);
        struct rusage usage;getrusage(RUSAGE_SELF,&usage);
        printf("{\"duration_s\":%d,\"jobs\":%d,\"p50_ms\":%.6f,\"p95_ms\":%.6f,\"p99_ms\":%.6f,\"max_ms\":%.6f,\"cpu_ms_per_job\":%.6f,\"jobs_s\":%.2f,\"rss_initial_kb\":%ld,\"rss_peak_kb\":%ld,\"rss_final_kb\":%ld,\"checksum\":%llu}\n",
            durations[d],runs,times[(runs+1)/2-1],times[(runs*95+99)/100-1],
            times[(runs*99+99)/100-1],times[runs-1],cpu/runs,runs/(wall/1000),
            initial,usage.ru_maxrss,rss_kb(),checksum);
        fflush(stdout);free(times);
        for(size_t i=0;i<paths.gl_pathc;i++)free(inputs[i].data);
        free(inputs);globfree(&paths);
    }
    free(r);free(a);return 0;
}
