#ifndef PCM_ANALYZER_H
#define PCM_ANALYZER_H
#include <stddef.h>
#include <stdint.h>
#define PCM_MAX_FRAMES 750
#define PCM_MAX_SEGMENTS 64
#define PCM_FREQ_COUNT 8
#define PCM_RING_BINS 24
#define PCM_RING_SAMPLES 4000
#define PCM_RING_FRAMES 30
#define PCM_RING_PULSES 30
typedef struct { int start_ms, end_ms, duration_ms, tone_frames; } pcm_ring_pulse;
typedef struct {
    int state; /* 0 silence, 1 ring, 2 other */
    double rms_dbfs, ac_rms_dbfs, ring_frequency_hz, ring_level_dbfs, prominence_db, tone_purity_db;
} pcm_ring_frame;
typedef struct {
    int duration_ms, frame_count, pulse_count, matched_pulse_count;
    int has_pattern, has_valid_cadence, ring_from_start_to_end;
    int disturbance_at_ms, disturbance_duration_ms;
    double confidence;
    pcm_ring_pulse pulses[PCM_RING_PULSES];
    int matched_indexes[PCM_RING_PULSES], periods_ms[PCM_RING_PULSES];
    pcm_ring_frame frames[PCM_RING_FRAMES];
} pcm_ring_result;

typedef enum { PCM_SILENCE, PCM_TONE, PCM_NOISE, PCM_MUSIC, PCM_VOICE, PCM_OTHER } pcm_signal;
typedef struct {
    double rms_dbfs, crossing_cv, difference;
    int crossings;
    unsigned char active;
} pcm_frame;
typedef struct {
    pcm_signal signal;
    int active_frames, first_active_ms;
    double rms_mean_dbfs, rms_std_db, crossing_mean, crossing_std;
    double crossing_interval_cv, normalized_difference;
    double dominant_tone_strength, spectral_variability, spectral_entropy;
} pcm_features;
typedef struct {
    int started_at_ms, ended_at_ms, duration_ms;
    int vad_voice_ms, vad_longest_ms;
    pcm_signal signal;
    pcm_features features;
} pcm_segment;
typedef struct {
    int duration_ms, active_audio_ms, silence_ms, voice_ms, longest_segment_ms;
    int segment_count, first_voice_segment_ms, pause_count;
    int first_voice_ms, last_voice_ms, tone_ms, noise_ms, music_ms;
    double mean_pause_ms, voice_ratio, silence_ratio;
    pcm_signal signal;
    pcm_features signal_features;
    pcm_segment segments[PCM_MAX_SEGMENTS];
    int all_segment_count;
    pcm_ring_result ring;
} pcm_result;
typedef struct {
    int sample_rate, frame_duration_ms, frame_samples, frame_bytes;
    double coefficients[PCM_FREQ_COUNT];
    pcm_frame frames[PCM_MAX_FRAMES];
    double ring_coefficients[PCM_RING_BINS], ring_hann[PCM_RING_SAMPLES];
    double ring_x[PCM_RING_BINS], ring_y[PCM_RING_BINS];
    double ring_squares;
    double ring_sum;
    int ring_sample_index;
#ifdef PCM_PREDECODE_SCRATCH
    int16_t decoded[120000];
#endif
} pcm_analyzer;
void pcm_analyzer_init(pcm_analyzer *analyzer);
/* 0: OK, 1: too many segments. Input length is validated by binding. */
int pcm_analyzer_run(pcm_analyzer *analyzer, const unsigned char *pcm, size_t length, pcm_result *result);
const char *pcm_signal_name(pcm_signal signal);
#endif
