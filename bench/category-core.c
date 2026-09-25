#define _POSIX_C_SOURCE 200809L
#include "../pcm_analyzer.h"
#include <glob.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/resource.h>
#include <time.h>

static double now(void) { struct timespec t; clock_gettime(CLOCK_MONOTONIC,&t); return t.tv_sec*1000.0+t.tv_nsec/1e6; }
static double cpu(void) { struct rusage r; getrusage(RUSAGE_SELF,&r); return (r.ru_utime.tv_sec+r.ru_stime.tv_sec)*1000.0+(r.ru_utime.tv_usec+r.ru_stime.tv_usec)/1000.0; }
static int cmp(const void *a,const void *b) { double x=*(const double*)a,y=*(const double*)b; return (x>y)-(x<y); }
int main(int argc,char **argv) {
    const char *dir=argc>1?argv[1]:"bench/dsp-workloads";
    int runs=argc>2?atoi(argv[2]):1000;
    if(runs<100)return 2;
    char pattern[512];snprintf(pattern,sizeof(pattern),"%s/*.pcm",dir);
    glob_t paths={0};if(glob(pattern,0,NULL,&paths)||!paths.gl_pathc)return 3;
    pcm_analyzer *a=malloc(sizeof(*a));pcm_result *r=malloc(sizeof(*r));
    double *times=malloc((size_t)runs*sizeof(*times));
    if(!a||!r||!times)return 4;pcm_analyzer_init(a);
    unsigned long long sink=0;
    for(size_t k=0;k<paths.gl_pathc;k++) {
        FILE *f=fopen(paths.gl_pathv[k],"rb");if(!f)return 5;
        fseek(f,0,SEEK_END);size_t size=(size_t)ftell(f);rewind(f);
        unsigned char *data=malloc(size);if(!data)return 6;
        if(fread(data,1,size,f)!=size)return 7;fclose(f);
        for(int i=0;i<25;i++){if(pcm_analyzer_run(a,data,size,r))return 8;sink+=r->active_audio_ms+r->ring.pulse_count;}
        double cs=cpu(),ws=now();
        for(int i=0;i<runs;i++) {
            double t=now();if(pcm_analyzer_run(a,data,size,r))return 9;
            times[i]=now()-t;sink+=r->active_audio_ms+r->ring.pulse_count;
        }
        double elapsed=now()-ws,used=cpu()-cs;
        qsort(times,(size_t)runs,sizeof(*times),cmp);
        struct rusage usage;getrusage(RUSAGE_SELF,&usage);
        const char *base=strrchr(paths.gl_pathv[k],'/');base=base?base+1:paths.gl_pathv[k];
        printf("{\"category\":\"%.*s\",\"jobs\":%d,\"cpu_ms_per_job\":%.6f,\"p50_ms\":%.6f,\"p95_ms\":%.6f,\"p99_ms\":%.6f,\"jobs_s\":%.2f,\"rss_peak_kb\":%ld,\"sink\":%llu}\n",
            (int)(strlen(base)-4),base,runs,used/runs,times[(runs+1)/2-1],times[(runs*95+99)/100-1],times[(runs*99+99)/100-1],runs*1000.0/elapsed,usage.ru_maxrss,sink);
        fflush(stdout);free(data);
    }
    free(times);free(r);free(a);globfree(&paths);return 0;
}
