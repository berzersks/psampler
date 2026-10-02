#define _POSIX_C_SOURCE 200809L
#include "psampler_resample.h"
#include "pcm_core.h"
#include <errno.h>
#include <inttypes.h>
#include <math.h>
#include <openssl/evp.h>
#include <string.h>
#include <sys/resource.h>
#include <time.h>

void psampler_standalone_init(void);
void psampler_standalone_shutdown(void);
psampler_result psampler_standalone_reference(const unsigned char *, size_t,
    uint32_t, uint32_t, unsigned char **, size_t *);
#define SLOTS 8
#define PCM_BUFFER_MAX_SIZE ((size_t) PSAMPLER_LONG_MAX)
#define PCM_BUFFER_INITIAL_CAPACITY ((size_t) 4096)
typedef struct {
    unsigned char *data;
    size_t size, capacity;
    uint32_t sample_rate;
    uint16_t channels;
} psampler_pcm_buffer;
#include "pcm_storage.inc"

typedef struct {
    uint64_t calls, frames;
    uint32_t source, target, ptime;
    uint16_t channels, target_channels;
    size_t samples, frame_bytes;
    bool verify;
} config;
typedef struct {
    psampler_pcm_buffer pcm;
    uint64_t frames, bytes;
} call_state;
static void check(bool ok, const char *message)
{
    if (!ok) { fprintf(stderr, "ERROR: %s\n", message); exit(1); }
}
static uint64_t number(const char *s)
{
    check(*s >= '0' && *s <= '9', "expected a positive integer");
    char *end;
    errno = 0;
    unsigned long long n = strtoull(s, &end, 10);
    check(!errno && !*end && n > 0 && n <= INT64_MAX, "integer out of range");
    return n;
}
static config options(int argc, char **argv)
{
    config c = {.calls=50, .frames=1000, .source=44100, .target=8000,
        .ptime=20, .channels=2, .target_channels=1};
    bool explicit_frames = false;
    double duration = 0;
    for (int i=1; i<argc; i++) {
        const char *a = argv[i], *v = strchr(a, '=');
        if (!strcmp(a, "--help")) {
            puts("Usage: ./pcm_benchmark_c --runtime=throughput --calls=50 --frames=10000\n"
                 "  --ptime=20 --source-rate=48000 --source-channels=2\n"
                 "  --target-rate=8000 --target-channels=1 [--verify] [--duration=seconds]\n"
                 "Standalone supports throughput only; --frames overrides --duration.");
            exit(0);
        }
        if (!strcmp(a, "--verify")) { c.verify=true; continue; }
        check(v != NULL, "expected --option=value");
        size_t key = (size_t)(v-a); v++;
#define IS(s) (key == strlen(s) && !strncmp(a, s, key))
        if (IS("--runtime")) { check(!strcmp(v,"throughput"), "C standalone supports throughput only"); continue; }
        if (IS("--duration")) {
            char *end; errno=0; duration=strtod(v,&end);
            check(!errno && !*end && isfinite(duration) && duration>0, "invalid duration"); continue;
        }
        uint64_t n = number(v);
        if (IS("--calls")) c.calls=n;
        else if (IS("--frames")) { c.frames=n; explicit_frames=true; }
        else if (IS("--source-rate")) { check(n<=UINT32_MAX,"invalid rate"); c.source=n; }
        else if (IS("--target-rate")) { check(n<=UINT32_MAX,"invalid rate"); c.target=n; }
        else if (IS("--ptime")) { check(n<=INT32_MAX,"invalid ptime"); c.ptime=n; }
        else if (IS("--source-channels")) { check(n<=2,"invalid channels"); c.channels=n; }
        else if (IS("--target-channels")) { check(n<=2,"invalid channels"); c.target_channels=n; }
        else check(false,"unknown option");
#undef IS
    }
    if (duration && !explicit_frames) {
        double frames=ceil(duration*1000/c.ptime);
        check(frames>=1 && frames<(double)INT64_MAX,"duration overflow"); c.frames=(uint64_t)frames;
    }
    check(c.target_channels<=c.channels,"upmix not supported");
    uint64_t samples=(uint64_t)c.source*c.ptime;
    check(samples%1000==0 && samples>=1000,"ptime must describe integer source samples");
    samples/=1000;
    check(samples<=SIZE_MAX/(2*c.channels),"frame size overflow");
    c.samples=(size_t)samples; c.frame_bytes=c.samples*2*c.channels;
    check(c.calls<=INT64_MAX/c.frames && c.calls*c.frames<=INT64_MAX/c.frame_bytes,"input counter overflow");
    /* Fixture arithmetic has the same signed integer domain as PHP/Go. */
    check(samples <= (INT64_MAX/(997*4096)-17)/SLOTS,"fixture index overflow");
    check(c.calls<=SIZE_MAX/sizeof(call_state),"call storage overflow");
    return c;
}
static int tone(uint64_t index, unsigned hz, uint32_t rate)
{
    int phase=(int)(index*hz*4096/rate%4096);
    return phase<2048 ? phase-1024 : 3072-phase;
}
static unsigned char *fixture(const config *c, unsigned slot)
{
    unsigned char *b=psampler_alloc(c->frame_bytes);
    for (size_t j=0;j<c->samples;j++) {
        uint64_t i=slot*c->samples+j;
        int gain=6+(slot%3)*5+(j*3/c->samples)*3;
        int l=(3*tone(i,440,c->source)+tone(i,997,c->source))*gain/4;
        int r=(3*tone(i+17,659,c->source)-tone(i,123,c->source))*gain/4;
        if (slot==7 || (j>=c->samples/3 && j<c->samples/2)) l=r=0;
        psampler_pcm16_write(b+j*2*c->channels,l);
        if (c->channels==2) psampler_pcm16_write(b+j*4+2,r);
    }
    return b;
}
static void pipeline(psampler_pcm_buffer *p, const unsigned char *b, const config *c)
{
    p->size=0; p->sample_rate=c->source; p->channels=c->channels;
    check(pcm_reserve(p,c->frame_bytes),"reserve failed");
    memcpy(p->data,b,c->frame_bytes); p->size=c->frame_bytes;
    if (c->channels==2 && c->target_channels==1) {
        psampler_pcm16_stereo_to_mono(p->data,p->size,p->data);
        p->size/=2; p->channels=1;
    }
    check(pcm_storage_resample(p,c->target)==PSAMPLER_SUCCESS,"resample failed");
}
static EVP_MD_CTX *hash_start(void)
{
    EVP_MD_CTX *h=EVP_MD_CTX_new();
    check(h && EVP_DigestInit_ex(h,EVP_sha256(),NULL)==1,"SHA256 init failed"); return h;
}
static void hash_add(EVP_MD_CTX *h, const void *b, size_t n)
{
    check(EVP_DigestUpdate(h,b,n)==1,"SHA256 update failed");
}
static void hash_end(EVP_MD_CTX *h, char hex[65])
{
    unsigned char digest[EVP_MAX_MD_SIZE]; unsigned n;
    check(EVP_DigestFinal_ex(h,digest,&n)==1 && n==32,"SHA256 final failed");
    for (unsigned i=0;i<n;i++) sprintf(hex+2*i,"%02x",digest[i]);
    EVP_MD_CTX_free(h);
}
static void validate(const psampler_pcm_buffer *p, const unsigned char *b, const config *c)
{
    check(p->sample_rate==c->target && p->channels==c->target_channels,"metadata mismatch");
    check(p->size<=p->capacity && p->size%(2*c->target_channels)==0,"size/capacity/alignment mismatch");
    double out=(double)(p->size/(2*c->target_channels));
    check(fabs(out/c->target-(double)c->samples/c->source)<=64.0/c->source+2.0/c->target,"duration mismatch");
    if (c->source==c->target || (c->samples>32 && (double)(c->samples-32)*c->target/c->source>=1))
        check(p->size>0,"unexpected empty output");
    psampler_pcm_buffer reference={0}; pipeline(&reference,b,c);
    check(reference.size==p->size && (!p->size || !memcmp(reference.data,p->data,p->size)),"non-deterministic output");
    free(reference.data);
    if (c->verify && c->samples<=8192 && c->source!=c->target) {
        /* Independent generic sink, per channel, matching PHP --verify. */
        unsigned char *mono=psampler_alloc(c->samples*2);
        for (unsigned channel=0;channel<c->target_channels;channel++) {
            if (c->channels==2 && c->target_channels==1)
                psampler_pcm16_stereo_to_mono(b,c->frame_bytes,mono);
            else for (size_t j=0;j<c->samples;j++)
                memcpy(mono+j*2,b+(j*c->channels+channel)*2,2);
            unsigned char *oracle; size_t size;
            check(psampler_standalone_reference(mono,c->samples*2,c->source,c->target,&oracle,&size)==PSAMPLER_SUCCESS,"generic oracle failed");
            check(size*c->target_channels==p->size,"generic size mismatch");
            for (size_t j=0;j<size/2;j++)
                check(!memcmp(oracle+j*2,p->data+(j*c->target_channels+channel)*2,2),"generic/native mismatch");
            free(oracle);
        }
        free(mono);
    }
}
static void downmix_hash(unsigned char *bank[SLOTS], const config *c, char hex[65])
{
    const int edges[]={-32768,-32768,32767,32767,-32768,32767,-3,0,3,0,-32768,0,32767,0};
    unsigned char edge_bytes[28];
    for (size_t i=0;i<14;i++) psampler_pcm16_write(edge_bytes+2*i,edges[i]);
    EVP_MD_CTX *h=hash_start();
    for (int slot=-1;slot<(c->channels==2?SLOTS:0);slot++) {
        const unsigned char *b=slot<0?edge_bytes:bank[slot];
        size_t n=slot<0?sizeof(edge_bytes):c->frame_bytes;
        unsigned char *mono=psampler_alloc(n/2);
        psampler_pcm16_stereo_to_mono(b,n,mono);
        for (size_t j=0;j<n;j+=4) {
            /* Independent decoding/truncation reference outside measurement. */
            int l=b[j]+256*b[j+1], r=b[j+2]+256*b[j+3];
            if(l>=32768) l-=65536;
            if(r>=32768) r-=65536;
            check(psampler_pcm16_read(mono+j/2)==(l+r)/2,"downmix mismatch");
        }
        hash_add(h,mono,n/2); free(mono);
    }
    hash_end(h,hex);
}
static double wall(void)
{
    struct timespec t; check(!clock_gettime(CLOCK_MONOTONIC,&t),"clock_gettime failed");
    return t.tv_sec+t.tv_nsec/1e9;
}
static double seconds(struct timeval t) { return t.tv_sec+t.tv_usec/1e6; }
int main(int argc,char **argv)
{
    config c=options(argc,argv);
    psampler_standalone_init();
    unsigned char *bank[SLOTS]; char fixture_sha[65], downmix_sha[65], output_sha[3][65];
    EVP_MD_CTX *h=hash_start();
    for(unsigned i=0;i<SLOTS;i++) { bank[i]=fixture(&c,i); hash_add(h,bank[i],c.frame_bytes); }
    hash_end(h,fixture_sha);
    call_state *states=psampler_calloc(c.calls,sizeof(*states));
    for(uint64_t i=0;i<c.calls;i++)
        for(unsigned slot=0;slot<SLOTS;slot++) pipeline(&states[i].pcm,bank[slot],&c);
    struct rusage u0,u1;
    check(!getrusage(RUSAGE_SELF,&u0),"getrusage failed"); double start=wall();
    /* Same cooperative throughput order as PHP: each call runs to completion.
     * No hashing, validation, pacing, fixture allocation or I/O in this window. */
    for(uint64_t i=0;i<c.calls;i++) {
        call_state *s=&states[i];
        for(uint64_t frame=0;frame<c.frames;frame++) {
            pipeline(&s->pcm,bank[frame%SLOTS],&c);
            s->frames++; s->bytes+=s->pcm.size;
        }
    }
    double elapsed=wall()-start; check(!getrusage(RUSAGE_SELF,&u1),"getrusage failed");
    uint64_t frames=0,bytes=0;
    for(uint64_t i=0;i<c.calls;i++) {
        call_state *s=&states[i]; frames+=s->frames;
        check(UINT64_MAX-bytes>=s->bytes,"output counter overflow"); bytes+=s->bytes;
        check(s->frames==c.frames && s->pcm.sample_rate==c.target && s->pcm.channels==c.target_channels,"call mismatch");
    }
    uint64_t nh=c.calls<3?c.calls:3;
    for(uint64_t i=0;i<nh;i++) {
        validate(&states[i].pcm,bank[(c.frames-1)%SLOTS],&c);
        h=hash_start(); hash_add(h,states[i].pcm.data,states[i].pcm.size); hash_end(h,output_sha[i]);
    }
    downmix_hash(bank,&c,downmix_sha);
    psampler_pcm_buffer reference={0}; uint64_t cycle=0,tail=0;
    for(unsigned slot=0;slot<SLOTS;slot++) {
        pipeline(&reference,bank[slot],&c); cycle+=reference.size;
        if(slot<c.frames%SLOTS) tail+=reference.size;
        if(c.verify) {
            validate(&reference,bank[slot],&c);
            for(int repeat=0;repeat<3;repeat++) { pipeline(&reference,bank[slot],&c); validate(&reference,bank[slot],&c); }
        }
    }
    check(frames==c.calls*c.frames && bytes==c.calls*((c.frames/SLOTS)*cycle+tail),"counter mismatch");
    double user=seconds(u1.ru_utime)-seconds(u0.ru_utime),system=seconds(u1.ru_stime)-seconds(u0.ru_stime);
    double cpu=user+system,audio=(double)frames*c.samples/c.source;
    puts("language: C\nimplementation: psampler native C / cached sinc-Kaiser FIR\nruntime_mode: throughput");
    printf("calls: %"PRIu64"\nframes_per_call: %"PRIu64"\ntotal_frames: %"PRIu64"\nptime_ms: %u\n",c.calls,c.frames,frames,c.ptime);
    printf("source_rate: %u\nsource_channels: %u\nsource_frame_bytes: %zu\ntarget_rate: %u\ntarget_channels: %u\n",c.source,c.channels,c.frame_bytes,c.target,c.target_channels);
    printf("elapsed_seconds: %.9f\nframes_processed: %"PRIu64"\nframes_per_second: %.6f\ninput_bytes: %"PRIu64"\noutput_bytes: %"PRIu64"\n",elapsed,frames,frames/elapsed,frames*c.frame_bytes,bytes);
    printf("audio_seconds_processed: %.6f\naudio_seconds_per_wall_second: %.6f\ncpu_user_seconds: %.6f\ncpu_system_seconds: %.6f\ncpu_total_seconds: %.6f\naverage_cpu_percent: %.6f\n",audio,audio/elapsed,user,system,cpu,100*cpu/elapsed);
    printf("deadline_misses: n/a\nmax_delay_ms: n/a\naverage_delay_ms: n/a\nfixture_sha256: %s\ndownmix_sha256: %s\noutput_sha256: ",fixture_sha,downmix_sha);
    for(uint64_t i=0;i<nh;i++) printf("%s%s",i?",":"",output_sha[i]);
    printf("\nverify: %s\nvalidation: ok\n",c.verify?"true":"false");
    free(reference.data);
    for(uint64_t i=0;i<c.calls;i++) free(states[i].pcm.data);
    free(states);
    for(unsigned i=0;i<SLOTS;i++) free(bank[i]);
    psampler_standalone_shutdown();
    return 0;
}
