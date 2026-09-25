#include "pcm_analyzer.h"
#include <math.h>
#include <stdlib.h>
#include <string.h>
#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif
static const int frequencies[PCM_FREQ_COUNT] = {125,250,425,700,1000,1500,2200,3000};
static const int ring_background[10] = {250,300,350,500,600,700,850,1000,1200,1500};
static double rounded(double x, int decimals) { double scale = decimals == 3 ? 1000.0 : 10000.0; return round(x * scale) / scale; }
static double mean(const double *values, int count) { double sum = 0.0; for (int i=0;i<count;i++) sum += values[i]; return count ? sum/count : 0.0; }
static double stddev(const double *values, int count, double average) { double sum=0.0; for(int i=0;i<count;i++){ double d=values[i]-average; sum += d*d; } return count ? sqrt(sum/count) : 0.0; }
static int compare_double(const void *a,const void *b) { double x=*(const double*)a,y=*(const double*)b; return (x>y)-(x<y); }
#ifdef PCM_INSERTION_MEDIAN
static void insertion_sort(double *values,int count){
    for(int i=1;i<count;i++){
        double value=values[i];int j=i;
        while(j>0&&values[j-1]>value){values[j]=values[j-1];j--;}
        values[j]=value;
    }
}
#define PCM_SORT(values,count) insertion_sort((values),(count))
#else
#define PCM_SORT(values,count) qsort((values),(size_t)(count),sizeof(double),compare_double)
#endif
static int sample_at(const unsigned char *pcm, size_t at) { unsigned int u=(unsigned int)pcm[at*2]|((unsigned int)pcm[at*2+1]<<8); return u>=32768U ? (int)u-65536 : (int)u; }
const char *pcm_signal_name(pcm_signal signal) {
    static const char *names[]={"silence","narrowband_tone","noise","music_like","voice_like","other_audio"};
    return names[signal];
}
void pcm_analyzer_init(pcm_analyzer *a) {
    static const double sr=8000.0;
    a->sample_rate=8000; a->frame_duration_ms=20; a->frame_samples=160; a->frame_bytes=320;
    for(int i=0;i<PCM_FREQ_COUNT;i++) a->coefficients[i]=2.0*cos(2.0*M_PI*frequencies[i]/sr);
    for(int i=0;i<14;i++)a->ring_coefficients[i]=2.0*cos(2.0*M_PI*(395.0+5.0*i)/sr);
    for(int i=0;i<10;i++)a->ring_coefficients[i+14]=2.0*cos(2.0*M_PI*ring_background[i]/sr);
    for(int i=0;i<PCM_RING_SAMPLES;i++)a->ring_hann[i]=0.5*(1.0-cos(2.0*M_PI*i/(PCM_RING_SAMPLES-1)));
}
static double ring_level(const pcm_analyzer *a,int bin) {
    double x=a->ring_x[bin],y=a->ring_y[bin],c=a->ring_coefficients[bin];
    double power=x*x+y*y-c*x*y;
    if(power<=0.0)return -120.0;
    double amplitude=4.0*sqrt(power)/PCM_RING_SAMPLES;
    return amplitude>0.0 ? fmax(-120.0,fmin(0.0,20.0*log10(amplitude/32768.0))) : -120.0;
}
#if defined(PCM_RING_STAGED_EXPERIMENT) || defined(PCM_RING_FULL_TWO_PASS_EXPERIMENT) || defined(PCM_RING_ALL_TWO_PASS_EXPERIMENT)
/* Experimental second read; ringstage skips bins and loses diagnostics. */
static void ring_bank_pass(pcm_analyzer *a,int frame,int first,int count) {
    if(a->ring_squares==0.0)return; /* exact digital-zero fast path */
    const unsigned char *pcm=a->ring_pcm+(size_t)frame*PCM_RING_SAMPLES*2;
    int started=0;
    for(int j=0;j<PCM_RING_SAMPLES;j++) {
        int sample=sample_at(pcm,(size_t)j);
        if(!sample&&!started)continue;
        started=1;
        double input=sample*a->ring_hann[j];
        for(int i=first;i<first+count;i++) {
            double next=input+a->ring_coefficients[i]*a->ring_x[i]-a->ring_y[i];
            a->ring_y[i]=a->ring_x[i];a->ring_x[i]=next;
        }
    }
}
#endif
static void ring_finish_frame(pcm_analyzer *a,pcm_ring_result *r) {
    pcm_ring_frame *f=&r->frames[r->frame_count];
    double rms=sqrt(a->ring_squares/PCM_RING_SAMPLES);
    f->rms_dbfs=rms>0.0?fmax(-120.0,20.0*log10(rms/32768.0)):-120.0;
    double dc=a->ring_sum/PCM_RING_SAMPLES;
    double ac_rms=sqrt(fmax(0.0,a->ring_squares/PCM_RING_SAMPLES-dc*dc));
    double ac_dbfs=ac_rms>0.0?fmax(-120.0,20.0*log10(ac_rms/32768.0)):-120.0;
    f->ac_rms_dbfs=ac_dbfs;
    double best=-120.0,frequency=425.0,background[10];
#ifdef PCM_RING_STAGED_EXPERIMENT
    /* Cauchy-Schwarz: Hann bin amplitude <= 4/N sqrt(S2*W2).
       1499.626 safely exceeds analytic Hann W2=1499.625. */
    double bound=4.0*sqrt(a->ring_squares*1499.626)/PCM_RING_SAMPLES;
    if(bound>=32768.0*0.003981071705534973)
        ring_bank_pass(a,r->frame_count,0,14);
#endif
#ifdef PCM_RING_FULL_TWO_PASS_EXPERIMENT
    ring_bank_pass(a,r->frame_count,0,14);
#endif
#ifdef PCM_RING_ALL_TWO_PASS_EXPERIMENT
    ring_bank_pass(a,r->frame_count,0,24);
#endif
    for(int i=0;i<14;i++){double level=ring_level(a,i);if(level>best){best=level;frequency=395.0+5.0*i;}}
#ifdef PCM_RING_STAGED_EXPERIMENT
    if(best>=-48.0&&best-ac_dbfs>=1.5)
        ring_bank_pass(a,r->frame_count,14,10);
#endif
#ifdef PCM_RING_FULL_TWO_PASS_EXPERIMENT
    ring_bank_pass(a,r->frame_count,14,10);
#endif
    for(int i=0;i<10;i++)background[i]=ring_level(a,i+14);
    PCM_SORT(background,10);
    f->ring_frequency_hz=frequency;f->ring_level_dbfs=best;
    f->prominence_db=best-(background[4]+background[5])/2.0;
    f->tone_purity_db=best-ac_dbfs;
    f->state=best>=-48.0&&f->prominence_db>=10.0&&f->tone_purity_db>=1.5?1:ac_dbfs<=-50.0?0:2;
    if(f->state==1){
        int start=r->frame_count*500;
        if(r->pulse_count>0&&r->pulses[r->pulse_count-1].end_ms==start){
            pcm_ring_pulse *p=&r->pulses[r->pulse_count-1];p->end_ms=start+500;p->duration_ms=p->end_ms-p->start_ms;p->tone_frames++;
        }else if(r->pulse_count<PCM_RING_PULSES){
            pcm_ring_pulse *p=&r->pulses[r->pulse_count++];
            *p=(pcm_ring_pulse){start,start+500,500,1};
        }
    }
    r->frame_count++;a->ring_sample_index=0;a->ring_squares=0.0;a->ring_sum=0.0;
    memset(a->ring_x,0,sizeof(a->ring_x));memset(a->ring_y,0,sizeof(a->ring_y));
}
static void ring_sample(pcm_analyzer *a,pcm_ring_result *r,int sample){
    int at=a->ring_sample_index;
    if(r->frame_count>=PCM_RING_FRAMES)return;
#ifndef PCM_DISABLE_ZERO_SKIP
    /* Zero input with zero state leaves every resonator at zero. */
    if(sample==0&&a->ring_squares==0.0){
        if(++a->ring_sample_index==PCM_RING_SAMPLES)ring_finish_frame(a,r);
        return;
    }
#endif
    a->ring_squares+=(double)sample*sample;
    a->ring_sum+=sample;
#if !defined(PCM_RING_STAGED_EXPERIMENT) && !defined(PCM_RING_FULL_TWO_PASS_EXPERIMENT) && !defined(PCM_RING_ALL_TWO_PASS_EXPERIMENT)
    pcm_ring_number input=(pcm_ring_number)(sample*a->ring_hann[at]);
    for(int i=0;i<PCM_RING_BINS;i++){
        pcm_ring_number next=input+a->ring_coefficients[i]*a->ring_x[i]-a->ring_y[i];
        a->ring_y[i]=a->ring_x[i];a->ring_x[i]=next;
    }
#endif
    if(++a->ring_sample_index==PCM_RING_SAMPLES)ring_finish_frame(a,r);
}
static void ring_finish_result(pcm_ring_result *r){
    r->duration_ms=r->frame_count*500;r->disturbance_at_ms=-1;
    int kept=0;
    for(int i=0;i<r->pulse_count;i++)if(r->pulses[i].tone_frames>=2)r->pulses[kept++]=r->pulses[i];
    r->pulse_count=kept;r->has_pattern=kept>0;
    int lengths[PCM_RING_PULSES]={0},previous[PCM_RING_PULSES],best_end=-1,best_length=0;
    for(int i=0;i<kept;i++){
        lengths[i]=1;previous[i]=-1;
        for(int j=0;j<i;j++){
            int period=r->pulses[i].start_ms-r->pulses[j].start_ms;
            if(abs(period-5000)<=600&&lengths[j]+1>lengths[i]){lengths[i]=lengths[j]+1;previous[i]=j;}
        }
        if(lengths[i]>best_length){best_length=lengths[i];best_end=i;}
    }
    int reverse[PCM_RING_PULSES],count=0;
    while(best_end>=0){reverse[count++]=best_end;best_end=previous[best_end];}
    r->matched_pulse_count=count;r->has_valid_cadence=count>=2;
    for(int i=0;i<count;i++)r->matched_indexes[i]=reverse[count-i-1];
    for(int i=1;i<count;i++)r->periods_ms[i-1]=r->pulses[r->matched_indexes[i]].start_ms-r->pulses[r->matched_indexes[i-1]].start_ms;
    int disturbance_start=-1;
    for(int i=0;i<r->frame_count;i++){
        int middle=i*500+250,protected=0;
        for(int j=0;j<count;j++){
            pcm_ring_pulse *p=&r->pulses[r->matched_indexes[j]];
            if(middle>=p->start_ms-400&&middle<=p->end_ms+400){protected=1;break;}
        }
        if(r->frames[i].state==2&&!protected){if(disturbance_start<0)disturbance_start=i*500;continue;}
        if(disturbance_start>=0){
            int duration=i*500-disturbance_start;
            if(duration>=300){r->disturbance_at_ms=disturbance_start;r->disturbance_duration_ms=duration;break;}
            disturbance_start=-1;
        }
    }
    if(r->disturbance_at_ms<0&&disturbance_start>=0){
        int duration=r->duration_ms-disturbance_start;
        if(duration>=300){r->disturbance_at_ms=disturbance_start;r->disturbance_duration_ms=duration;}
    }
    r->ring_from_start_to_end=r->has_pattern&&r->disturbance_at_ms<0;
    double cycle=r->has_valid_cadence?fmin(1.0,0.70+fmax(0,count-2)*0.15):r->has_pattern?0.45:0.0;
    r->confidence=r->has_pattern?rounded(cycle*0.70+(r->disturbance_at_ms<0?0.30:0.0),4):0.0;
}
static pcm_frame scan_frame(pcm_analyzer *a,pcm_ring_result *r,const unsigned char *pcm, int n
#ifdef PCM_PREDECODE_SCRATCH
    ,const int16_t *decoded
#endif
) {
    pcm_frame out={0}; double squares=0,sum=0,absolutes=0,difference=0;
    double intervals[160]; int interval_count=0,last_cross=-1,previous=0;
    for(int i=0;i<n;i++) {
#ifdef PCM_PREDECODE_SCRATCH
        int sample=decoded[i];
#else
        int sample=sample_at(pcm,(size_t)i);
#endif
#ifndef PCM_RING_SEPARATE
        ring_sample(a,r,sample);
#endif
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
        for(int j=0;j<a->frame_samples;j++){
#ifdef PCM_PREDECODE_SCRATCH
            double s=a->decoded[(start_frame+frame)*a->frame_samples+j];
#else
            double s=sample_at(samples,(size_t)j);
#endif
            square+=s*s;
        }
        if(square/a->frame_samples<threshold)continue;
        for(int k=0;k<PCM_FREQ_COUNT;k++) {
            double x=0,y=0,c=a->coefficients[k];
            for(int j=0;j<a->frame_samples;j++) {
#ifdef PCM_PREDECODE_SCRATCH
                double s=a->decoded[(start_frame+frame)*a->frame_samples+j];
#else
                double s=sample_at(samples,(size_t)j);
#endif
                double next=s+c*x-y;y=x;x=next;
            }
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
    PCM_SORT(cvs,cv_count);
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
    memset(a->ring_x,0,sizeof(a->ring_x));memset(a->ring_y,0,sizeof(a->ring_y));
    a->ring_squares=0.0;a->ring_sum=0.0;a->ring_sample_index=0;
#if defined(PCM_RING_STAGED_EXPERIMENT) || defined(PCM_RING_FULL_TWO_PASS_EXPERIMENT) || defined(PCM_RING_ALL_TWO_PASS_EXPERIMENT)
    a->ring_pcm=pcm;
#endif
    r->signal_features.signal=PCM_SILENCE;r->signal_features.rms_mean_dbfs=-120.0;
    r->duration_ms=(int)(length/16);int frames=(int)(length/a->frame_bytes);
#ifdef PCM_PREDECODE_SCRATCH
    for(size_t i=0;i<length/2;i++)a->decoded[i]=(int16_t)sample_at(pcm,i);
#endif
    int start=-1,last=-1;
    for(int i=0;i<frames;i++){
        a->frames[i]=scan_frame(a,&r->ring,pcm+(size_t)i*a->frame_bytes,a->frame_samples
#ifdef PCM_PREDECODE_SCRATCH
            ,a->decoded+(size_t)i*a->frame_samples
#endif
        );
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
#ifdef PCM_RING_SEPARATE
    /* Benchmark variant: identical ring math, but a second PCM decode pass. */
    size_t ring_samples=(length/2/PCM_RING_SAMPLES)*PCM_RING_SAMPLES;
    for(size_t i=0;i<ring_samples;i++)ring_sample(a,&r->ring,sample_at(pcm,i));
#endif
    ring_finish_result(&r->ring);
    if(length<8000)r->ring.duration_ms=r->duration_ms;
    return 0;
}
