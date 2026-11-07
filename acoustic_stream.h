#ifndef ACOUSTIC_STREAM_H
#define ACOUSTIC_STREAM_H
#include "pcm_analyzer.h"
#define ACOUSTIC_HISTORY 50
#define ACOUSTIC_EVENTS 8
/* Native bounded state; no Zend dependency. DSP implementation shares pcm_analyzer.c. */
typedef enum { AS_SIGNAL, AS_RING_START, AS_RING_END, AS_CADENCE, AS_INTERRUPTED,
    AS_SPEECH_START, AS_SPEECH_END } acoustic_event_type;
typedef struct {
    acoustic_event_type type;
    uint64_t at_ms, detected_ms;
    pcm_signal signal;
    int period_ms;
    const char *reason;
} acoustic_event;
typedef struct {
    pcm_analyzer dsp;
    pcm_ring_result ring_window;
    unsigned char pending[320];
    size_t pending_bytes;
    uint64_t frames, pulse_start, previous_pulse, pulse_end;
    int ring_active, ring_seen, cadence, interrupted, other_windows;
    int candidate_frames, speech, history_count;
    pcm_signal signal, candidate;
    pcm_frame history[ACOUSTIC_HISTORY];
    double spectra[ACOUSTIC_HISTORY][PCM_FREQ_COUNT];
    unsigned char spectral_valid[ACOUSTIC_HISTORY];
    pcm_ring_frame last_ring;
    acoustic_event events[ACOUSTIC_EVENTS];
    int event_count;
} acoustic_stream;
void acoustic_stream_init(acoustic_stream *s);
/* Exactly one complete 20 ms frame. At most ACOUSTIC_EVENTS native events. */
void acoustic_stream_frame(acoustic_stream *s,const unsigned char *pcm);
const char *acoustic_event_name(acoustic_event_type type);
#endif
