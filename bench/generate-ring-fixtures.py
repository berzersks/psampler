#!/usr/bin/env python3
"""Deterministic 8 kHz PCM16 ring and non-ring evidence fixtures."""
import math
import random
import struct
from pathlib import Path

OUT = Path(__file__).with_name("ring-fixtures")
OUT.mkdir(exist_ok=True)
RATE = 8000


def write(name, seconds, pulses=(), other=(), frequency=425, amplitude=12000,
          dc=0, noise=0, clip=False):
    rng = random.Random(42)
    data = bytearray(seconds * RATE * 2)
    for i in range(seconds * RATE):
        t = i / RATE
        ring = any(start <= t < end for start, end in pulses)
        kind = next((kind for start, end, kind in other if start <= t < end), None)
        sample = amplitude * math.sin(2 * math.pi * frequency * t) if ring else 0.0
        if kind == "voice":
            sample += 6500 * (0.65 + 0.35 * math.sin(2 * math.pi * 3 * t)) * (
                math.sin(2 * math.pi * 180 * t) + 0.4 * math.sin(2 * math.pi * 390 * t))
        elif kind == "noise":
            sample += rng.uniform(-9500, 9500)
        elif kind == "music":
            sample += 4200 * (math.sin(2 * math.pi * 440 * t) + math.sin(2 * math.pi * 660 * t))
        elif kind == "tone":
            sample += 9000 * math.sin(2 * math.pi * 950 * t)
        sample += dc + rng.uniform(-noise, noise)
        value = max(-32768, min(32767, round(sample)))
        if clip and ring:
            value = max(-8000, min(8000, value))
        struct.pack_into("<h", data, i * 2, value)
    (OUT / f"{name}.pcm").write_bytes(data)


for seconds in (1, 3, 5, 10, 15):
    tag = f"{seconds:02d}s"
    write(f"{tag}_silence", seconds)
    write(f"{tag}_425_continuous", seconds, [(0, seconds)])
    write(f"{tag}_cadence", seconds,
          [(start, min(start + 1, seconds)) for start in range(0, seconds, 5)])
    write(f"{tag}_voice_only", seconds, other=[(0, seconds, "voice")])
    write(f"{tag}_noise_only", seconds, other=[(0, seconds, "noise")])
    write(f"{tag}_music_only", seconds, other=[(0, seconds, "music")])

write("pulse_short", 3, [(0, .5)])
write("pulse_long", 5, [(0, 3)])
write("two_pulses", 10, [(0, 1), (5, 6)])
write("three_pulses", 15, [(0, 1), (5, 6), (10, 11)])
write("jitter", 15, [(0, 1), (5.5, 6.5), (10, 11)])
write("outside_tolerance", 15, [(0, 1), (6, 7), (12, 13)])
write("short_gap", 5, [(0, 1), (1.5, 2.5)])
write("long_gap", 15, [(0, 1), (8, 9)])
write("late_start", 5, [(2, 3)])
write("early_end", 5, [(0, 1)])
write("ring_voice", 5, [(0, 1)], [(2, 4, "voice")])
write("ring_noise", 5, [(0, 1)], [(2, 4, "noise")])
write("ring_music", 5, [(0, 1)], [(2, 4, "music")])
write("ring_other_tone", 5, [(0, 1)], [(2, 4, "tone")])
write("short_disturbance", 5, [(0, 1)], [(2, 2.3, "voice")])
write("long_disturbance", 5, [(0, 1)], [(2, 4, "voice")])
write("partial_ring", 3, [(0, .75)])
write("interrupted_by_voice", 5, [(0, 1)], [(.5, 3, "voice")])
write("low_amplitude", 5, [(0, 1)], amplitude=180)
write("high_amplitude", 5, [(0, 1)], amplitude=30000)
write("dc_offset", 5, [(0, 1)], dc=4000)
write("clipping", 5, [(0, 1)], clip=True)
write("noisy_ring", 5, [(0, 1)], noise=1400)
for frequency in (395, 450, 460):
    write(f"shifted_{frequency}", 5, [(0, 1)], frequency=frequency)
