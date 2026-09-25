#!/usr/bin/env python3
"""Deterministic PCM for DSP thresholds and independent 15 s workloads."""
import math
import random
import struct
import json
from pathlib import Path

ROOT = Path(__file__).parent
RATE = 8000


def save(folder, name, seconds, tone=0.0, frequency=425.0, noise=0.0,
         other=None, pulses=None, seed=4169):
    folder.mkdir(exist_ok=True)
    rng = random.Random(seed)
    data = bytearray(round(seconds * RATE) * 2)
    for i in range(len(data) // 2):
        t = i / RATE
        on = pulses is None or any(start <= t < end for start, end in pulses)
        value = tone * math.sin(2 * math.pi * frequency * t) if on else 0.0
        if other == "voice":
            value += 4200 * (0.65 + 0.35 * math.sin(2 * math.pi * 3 * t)) * (
                math.sin(2 * math.pi * 180 * t) + 0.4 * math.sin(2 * math.pi * 390 * t))
        elif other == "music":
            value += 3000 * (math.sin(2 * math.pi * 440 * t) +
                             math.sin(2 * math.pi * 660 * t))
        elif other == "noise":
            value += rng.uniform(-6000, 6000)
        value += rng.uniform(-noise, noise)
        struct.pack_into("<h", data, i * 2, max(-32768, min(32767, round(value))))
    (folder / f"{name}.pcm").write_bytes(data)


work = ROOT / "dsp-workloads"
save(work, "silence", 15)
save(work, "ring_clean", 15, tone=12000)
for kind in ("voice", "noise", "music"):
    save(work, kind, 15, other=kind)
    save(work, f"ring_plus_{kind}", 15, tone=12000, other=kind)

edge = ROOT / "dsp-boundaries"
for db in (-48.2, -48.1, -48.0, -47.9, -47.8):
    save(edge, f"level_{db:+.1f}", 1, tone=32768 * 10 ** (db / 20))
for hz in (390, 394, 395, 396, 400, 420, 425, 430, 459, 460, 461, 465):
    save(edge, f"frequency_{hz}", 1, tone=12000, frequency=hz)
for gap in (4000, 4500, 5000, 5500, 6000):
    save(edge, f"cadence_{gap}", 12, tone=12000,
         pulses=((0, 1), (gap / 1000, gap / 1000 + 1)))
for noise in (200, 400, 600, 800, 1000, 1500, 2000, 3000, 4000, 6000):
    save(edge, f"mixture_{noise}", 1, tone=1000, noise=noise)


def metrics(tone, noise, seed):
    rng = random.Random(seed)
    samples = [max(-32768, min(32767, round(
        tone * math.sin(2 * math.pi * 425 * i / RATE) +
        rng.uniform(-noise, noise)))) for i in range(4000)]
    weights = [0.5 * (1 - math.cos(2 * math.pi * i / 3999)) for i in range(4000)]
    frequencies = list(range(395, 461, 5)) + [250, 300, 350, 500, 600,
                                               700, 850, 1000, 1200, 1500]
    levels = []
    for frequency in frequencies:
        coefficient = 2 * math.cos(2 * math.pi * frequency / RATE)
        x = y = 0.0
        for sample, weight in zip(samples, weights):
            x, y = sample * weight + coefficient * x - y, x
        power = x*x + y*y - coefficient*x*y
        amplitude = 4 * math.sqrt(max(0, power)) / 4000
        levels.append(max(-120, min(0, 20*math.log10(amplitude/32768)))
                      if amplitude > 0 else -120)
    best = max(levels[:14])
    background = sorted(levels[14:])
    ac = sum(s*s for s in samples)/4000 - (sum(samples)/4000)**2
    ac_db = max(-120, 20*math.log10(math.sqrt(ac)/32768)) if ac > 0 else -120
    return best-(background[4]+background[5])/2, best-ac_db


targets = {"prominence": (9.8, 9.9, 10.0, 10.1, 10.2),
           "purity": (1.3, 1.4, 1.5, 1.6, 1.7)}
observed = {}
for kind, values in targets.items():
    tone, seed, bounds = (100, 1, (100, 6000)) if kind == "prominence" else (1000, 4169, (500, 1100))
    for target in values:
        lo, hi = bounds
        for _ in range(15):
            middle = (lo+hi)/2
            value = metrics(tone, middle, seed)[0 if kind == "prominence" else 1]
            if value > target:
                lo = middle
            else:
                hi = middle
        noise = (lo+hi)/2
        name = f"{kind}_{target:.1f}"
        save(edge, name, 1, tone=tone, noise=noise, seed=seed)
        measured = metrics(tone, noise, seed)
        observed[name] = {"tone": tone, "noise": noise, "seed": seed,
                          "prominence_db": measured[0], "purity_db": measured[1]}
(edge / "targets.json").write_text(json.dumps(observed, indent=2) + "\n")
