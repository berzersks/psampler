#include "pcm_analyzer.h"
#include <math.h>
#include <stdlib.h>
#include <string.h>
#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif
static const int frequencies[PCM_FREQ_COUNT] = {125,250,425,700,1000,1500,2200,3000};
static double rounded(double x, int decimals) { double scale = decimals == 3 ? 1000.0 : 10000.0; return round(x * scale) / scale; }
static double mean(const double *values, int count) { double sum = 0.0; for (int i=0;i<count;i++) sum += values[i]; return count ? sum/count : 0.0; }
static double stddev(const double *values, int count, double average) { double sum=0.0; for(int i=0;i<count;i++){ double d=values[i]-average; sum += d*d; } return count ? sqrt(sum/count) : 0.0; }
static int compare_double(const void *a,const void *b) { double x=*(const double*)a,y=*(const double*)b; return (x>y)-(x<y); }
static int sample_at(const unsigned char *pcm, size_t at) { unsigned int u=(unsigned int)pcm[at*2]|((unsigned int)pcm[at*2+1]<<8); return u>=32768U ? (int)u-65536 : (int)u; }
const char *pcm_signal_name(pcm_signal signal) {
    static const char *names[]={"silence","narrowband_tone","noise","music_like","voice_like","other_audio"};
    return names[signal];
}
void pcm_analyzer_init(pcm_analyzer *a) {
    static const double sr=8000.0;
    a->sample_rate=8000; a->frame_duration_ms=20; a->frame_samples=160; a->frame_bytes=320;
    for(int i=0;i<PCM_FREQ_COUNT;i++) a->coefficients[i]=2.0*cos(2.0*M_PI*frequencies[i]/sr);
}
static pcm_frame scan_frame(const unsigned char *pcm, int n) {
    pcm_frame out={0}; double squares=0,sum=0,absolutes=0,difference=0;
    double intervals[160]; int interval_count=0,last_cross=-1,previous=0;
    for(int i=0;i<n;i++) {
        int sample=sample_at(pcm,(size_t)i);
        squares += (double)sample*sample; sum+=sample; absolutes+=abs(sample);
        if(i>0) {
            difference+=abs(sample-previous);
            if(previous<=0 && sample>0) {
                if(last_cross>=0) intervals[interval_count++]=i-last_cross;
                last_cross=i; out.crossings++;
            }
        }
        previous=sample;
    }
    double dc=sum/n, rms=sqrt(fmax(0.0,squares/n-dc*dc));
    out.rms_dbfs=rms>0 ? fmax(-120.0,20.0*log10(rms/32768.0)) : -120.0;
    double interval_mean=mean(intervals,interval_count);
    out.crossing_cv=interval_mean>0 ? stddev(intervals,interval_count,interval_mean)/interval_mean : -1.0;
    out.difference=absolutes>0 ? difference/absolutes : 0.0;
    out.active=out.rms_dbfs>=-42.0;
    return out;
}
static void spectral(const pcm_analyzer *a,const unsigned char *pcm,int start_frame,int frames,pcm_features *out) {
    int step=(frames+19)/20; if(step<5)step=5;
    double prev[PCM_FREQ_COUNT]={0},dominance[PCM_MAX_FRAMES/5+1],entropy[PCM_MAX_FRAMES/5+1],variation[PCM_MAX_FRAMES/5+1];
    int count=0,variations=0,have_prev=0;
    const double threshold=32768.0*32768.0*pow(10.0,-42.0/10.0);
    for(int frame=0;frame<frames;frame+=step) {
        const unsigned char *samples=pcm+(size_t)(start_frame+frame)*a->frame_bytes;
        double square=0,power[PCM_FREQ_COUNT],total=0;
        for(int j=0;j<a->frame_samples;j++){ double s=sample_at(samples,(size_t)j);square+=s*s; }
        if(square/a->frame_samples<threshold)continue;
        for(int k=0;k<PCM_FREQ_COUNT;k++) {
            double x=0,y=0,c=a->coefficients[k];
            for(int j=0;j<a->frame_samples;j++) { double next=sample_at(samples,(size_t)j)+c*x-y;y=x;x=next; }
            power[k]=fmax(0.0,x*x+y*y-c*x*y); total+=power[k];
        }
        if(total<=0)continue;
        double top=0,ent=0,dist=0;
        for(int k=0;k<PCM_FREQ_COUNT;k++){
            double value=power[k]/total;
            if(value>top)top=value;
            if(value>0)ent-=value*log(value);
            if(have_prev)dist+=fabs(value-prev[k]);
            prev[k]=value;
        }
        dominance[count]=top; entropy[count]=ent/log((double)PCM_FREQ_COUNT);count++;
        if(have_prev)variation[variations++]=dist/2.0;
        have_prev=1;
    }
    out->dominant_tone_strength=rounded(mean(dominance,count),4);
    out->spectral_variability=rounded(mean(variation,variations),4);
    out->spectral_entropy=rounded(mean(entropy,count),4);
}
static void classify(const pcm_analyzer *a,const unsigned char *pcm,int start,int end,pcm_features *out) {
    memset(out,0,sizeof(*out));out->signal=PCM_SILENCE;out->rms_mean_dbfs=-120.0;
    double rms[PCM_MAX_FRAMES],crossings[PCM_MAX_FRAMES],cvs[PCM_MAX_FRAMES],differences[PCM_MAX_FRAMES];
    int count=0,cv_count=0,stable=0;
    for(int i=start;i<end;i++){
        pcm_frame f=a->frames[i];if(!f.active)continue;
        if(count==0)out->first_active_ms=(i-start)*20;
        rms[count]=f.rms_dbfs;crossings[count]=f.crossings;differences[count]=f.difference;count++;
        if(f.crossing_cv>=0){ cvs[cv_count++]=f.crossing_cv;if(f.crossing_cv<0.12)stable++; }
    }
    if(count==0)return;
    out->active_frames=count;
    double rm=mean(rms,count),cm=mean(crossings,count),dm=mean(differences,count);
    double rs=stddev(rms,count,rm),cs=stddev(crossings,count,cm);
    qsort(cvs,(size_t)cv_count,sizeof(double),compare_double);
    double cv=cv_count ? (cv_count%2 ? cvs[cv_count/2] : (cvs[cv_count/2-1]+cvs[cv_count/2])/2.0) : 0.0;
    spectral(a,pcm,start,end-start,out);
    int tone=((double)stable/count>=0.65 && rs<1.8)||(cm<3.0&&rs<0.8&&cs<1.2&&dm<0.85);
    int noise=!tone&&cm>=34.0&&dm>=0.90;
    int music=!tone&&!noise&&count>=40&&((out->spectral_variability<0.10&&rs>=1.4)||
        (out->spectral_variability<0.20&&cs<1.5&&out->dominant_tone_strength>=0.70&&out->spectral_entropy>=0.35)||
        (rs<1.4&&cs>=2.0&&out->spectral_entropy>=0.4));
    int voice=!tone&&!noise&&!music&&cm>=1.5&&cm<=33.0&&dm>=0.08&&rs>=1.4;
    out->signal=tone?PCM_TONE:noise?PCM_NOISE:music?PCM_MUSIC:voice?PCM_VOICE:PCM_OTHER;
    out->rms_mean_dbfs=rounded(rm,3);out->rms_std_db=rounded(rs,3);
    out->crossing_mean=rounded(cm,3);out->crossing_std=rounded(cs,3);
    out->crossing_interval_cv=rounded(cv,3);out->normalized_difference=rounded(dm,3);
}
static void append_region(pcm_analyzer *a,const unsigned char *pcm,pcm_result *r,int start,int end) {
    pcm_segment *s=&r->segments[r->all_segment_count++];memset(s,0,sizeof(*s));
    s->started_at_ms=start*20;s->ended_at_ms=end*20;s->duration_ms=(end-start)*20;
    classify(a,pcm,start,end,&s->features);s->signal=s->features.signal;
    if(r->all_segment_count==1)r->signal_features=s->features;
    r->active_audio_ms+=s->duration_ms;
    if(s->signal==PCM_TONE)r->tone_ms+=s->duration_ms;
    if(s->signal==PCM_NOISE)r->noise_ms+=s->duration_ms;
    if(s->signal==PCM_MUSIC)r->music_ms+=s->duration_ms;
    if(s->signal==PCM_VOICE) {
        int current=0,gap=0,first=-1;
        for(int i=start;i<end;i++){
            if(a->frames[i].active){if(first<0)first=i*20;s->vad_voice_ms+=20;current+=20;gap=0;}
            else if(current>0){gap+=20;if(gap>120){if(current>s->vad_longest_ms)s->vad_longest_ms=current;current=0;gap=0;}}
        }
        if(current>s->vad_longest_ms)s->vad_longest_ms=current;
        r->voice_ms+=s->vad_voice_ms;
        if(s->vad_longest_ms>r->longest_segment_ms)r->longest_segment_ms=s->vad_longest_ms;
        if(s->vad_voice_ms>0){
            if(r->segment_count==0){r->first_voice_ms=first;r->first_voice_segment_ms=s->vad_voice_ms;}
            else {int pause=s->started_at_ms-r->last_voice_ms;if(pause>0)r->mean_pause_ms+=pause;r->pause_count++;}
            r->last_voice_ms=s->ended_at_ms;r->signal_features=s->features;r->segment_count++;
        }
    }
}
int pcm_analyzer_run(pcm_analyzer *a,const unsigned char *pcm,size_t length,pcm_result *r){
    if (length > 240000 || (length & 1)) return 2;
    memset(r,0,sizeof(*r));r->first_voice_ms=-1;r->last_voice_ms=-1;r->signal=PCM_SILENCE;
    r->signal_features.signal=PCM_SILENCE;r->signal_features.rms_mean_dbfs=-120.0;
    r->duration_ms=(int)(length/16);int frames=(int)(length/a->frame_bytes);
    int start=-1,last=-1;
    for(int i=0;i<frames;i++){
        a->frames[i]=scan_frame(pcm+(size_t)i*a->frame_bytes,a->frame_samples);
        if(!a->frames[i].active)continue;
        if(last>=0&&i-last>10){if(r->all_segment_count>=PCM_MAX_SEGMENTS)return 1;append_region(a,pcm,r,start,last+1);start=-1;}
        if(start<0)start=i;
        last=i;
    }
    if(start>=0){if(r->all_segment_count>=PCM_MAX_SEGMENTS)return 1;append_region(a,pcm,r,start,last+1);}
    r->silence_ms=r->duration_ms-r->active_audio_ms;if(r->silence_ms<0)r->silence_ms=0;
    r->voice_ratio=r->duration_ms?rounded((double)r->voice_ms/r->duration_ms,4):0;
    r->silence_ratio=r->duration_ms?rounded((double)r->silence_ms/r->duration_ms,4):1;
    if(r->pause_count)r->mean_pause_ms=round(r->mean_pause_ms/r->pause_count*100.0)/100.0;
    if(r->voice_ms)r->signal=PCM_VOICE;
    else if(r->music_ms>=r->tone_ms&&r->music_ms>=r->noise_ms&&r->music_ms)r->signal=PCM_MUSIC;
    else if(r->tone_ms>=r->noise_ms&&r->tone_ms)r->signal=PCM_TONE;
    else if(r->noise_ms)r->signal=PCM_NOISE;
    else if(r->active_audio_ms)r->signal=PCM_OTHER;
    return 0;
}
