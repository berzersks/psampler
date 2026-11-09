# Acoustic carrier attacks and unaligned ring pulses

The 2026-11-08 change includes the previously validated local off-grid/AM fix.
Public PHP APIs and signal debounce (3 frames, silence 6 frames) are unchanged.

## Root causes

The 500 ms Hann bank's 5 Hz grid loses energy between bins. Continuous-frequency
log-parabolic refinement uses the bounded current window to recover the peak,
retaining -48 dBFS level, 10 dB prominence and 1.5 dB purity requirements.

AM attacks have varying RMS, so the old amplitude-stability condition fails and
historical features may label the attack voice_like. Crossings locate a carrier;
a projection against all frame AC energy verifies >=80% concentration. Three
recent regular frames allow 4% carrier drift during onset, or two coherent frames
allow <1.5% drift, unless an earlier regular frame contradicts that carrier. These additional onset vetoes apply before established voice;
the previous 1.5% three-frame rule remains in effect during established speech.
This preserves approved postal speech transitions without extending voice debounce.

Previously ring.started required two adjacent positive 500 ms labels. A pulse
starting at 4737 ms straddles windows: partial windows lose Hann purity and may
be classified other_audio, so a valid ~1 s pulse gets only one positive label.
The intermediate other_audio label could interrupt the earlier ring sequence.

Streaming now measures continuous coherent carrier duration in 20 ms frames and
requires a positive long-window spectral verification. A candidate in progress
protects transitions from intermediate other_audio labels. Cadence uses observed
carrier start spacing, rather than 500 ms quantized starts. Memory remains bounded.

## Duration policy

rec2's first pulse cannot satisfy a genuine 1000 ms minimum. At a -42 dBFS
1 ms RMS threshold the normalized recording has activity at 2–823 ms,
4737–5744 ms and 9677–10686 ms. Continuous fully coherent 20 ms frames span
20–800, 4740–5720 and 9680–10660 ms. Streaming minimum is now explicitly
750 ms of coherent evidence (confirmed on the 20 ms timeline at >=760 ms),
with spectral validation. Partial boundary frames can shorten this evidence:
a nominal 760 ms pulse may be rejected at some phases; 800 ms synthetic pulses
pass the tested phases. No duration is rounded up to manufacture acceptance.
The old apparent acceptance of the short first pulse came from counting full
500 ms windows that extended beyond the actual tone. The test preserves that
pulse instead of padding its tone or trimming it to manufacture qualification.
PCMAnalyzer's legacy whole-buffer ring diagnostics remain on their existing
500 ms grid and two-label qualification; short streaming events therefore have
finer boundaries than those legacy diagnostics. No return fields changed.

## Recorded results

Comparison baseline is the existing local approved off-grid/AM implementation,
not the older published commit, which lacked that earlier fix.

| rec2 event | Before | After |
|---|---|---|
| ring.started (ms) | 1000, 10500 | 780, 5500, 10440 |
| ring.ended (ms) | 1500, 11000 | 820, 5740, 10680 |
| cadence | missing | 4720 ms initial observed period; next spacing 4940 ms |
| ring.interrupted (ms) | 5000, 14500 | 15500 (after true speech) |
| speech.started (ms) | 4800, 9740, 14240, 16540 | 14240 |

Postal retains six starts/ends, one cadence event (5000 ms), one interruption
(31500 ms), and exactly the same speech transitions at original alignment:
starts 31200, 44520, 44800, 46920 ms; no voice event at its 1400 Hz beep.
Ring starts now occur at 880 + 5000*n ms, ends at 1120 + 5000*n ms.
The approved first-speech latency stays 66 ms (reference onset 31134 ms).
rec2 first detection remains 14240 ms: 116 ms from the independently measured
1 ms/-42 dBFS onset 14124 ms. Onsets depend on measurement definition.

## Validation

Run acoustic_stream_suite.php, acoustic_offgrid_suite.php (75 cases),
rec2_regression.php and rec_postal_regression.php. See
../tests/acoustic-fixtures/recordings.md for external fixture paths and rec2 hash.
rec2 checks chunk invariance, 11 full-stream shifts and 480 isolated attack phases;
postal checks 8 full-stream trims and all 160 sample phases of its real beep.
acoustic_duration.phpt explicitly checks 700/740 ms rejection and >=800 ms
acceptance across six window alignments. No audio copies are committed.
Native acoustic_stream_asan.c checks 100000 streaming frames under ASan/UBSan;
pcm_analyzer_asan.c checks 100000 whole-buffer calls. Test sockets require local
UDP permission; LeakSanitizer requires execution outside ptrace restrictions.

Baseline native state and corrected native state are both 79176 bytes. In three
200-pass rec2 runs, baseline CPU user seconds were .85/.85/.87 and corrected
.86/.85/.88 (median +1.2%, within scheduling variation). Peak RSS ranged
2096–2148 KiB before and 2104–2244 KiB after; no stream-proportional allocation.
The earlier off-grid correction adds an 8000-byte bounded sample buffer versus
the published predecessor. These measurements compare the approved local base.

Limits: this is heuristic acoustic classification, not a speech recognition
model. Cadence reports measured onset intervals, not an invented exact 5000 ms
for rec2's initial shortened spacing. External real-audio tests skip in CI unless
fixtures are supplied. Long-window diagnostic output intentionally retains its
legacy quantization. Event times are confirmation times, never backdated.
