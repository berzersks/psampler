#!/usr/bin/env python3
"""Generate a laboratory TTS utterance through installed eSpeak NG, without builds.
Optional alternative: supply a consented laboratory WAV as argv[1]. Output is 8k mono PCM16.
This is articulated speech synthesis, not a sinusoidal 'voice' proxy.
"""
import ctypes as c
import ctypes.util
import struct
import sys
import wave
from pathlib import Path

if len(sys.argv) > 1:
    with wave.open(sys.argv[1]) as w:
        if w.getnchannels() != 1 or w.getsampwidth() != 2:
            raise SystemExit('Expected uncompressed mono PCM16 WAV')
        rate = w.getframerate()
        values = list(struct.unpack('<%dh' % w.getnframes(), w.readframes(w.getnframes())))
else:
    lib = c.CDLL(ctypes.util.find_library('espeak-ng') or 'libespeak-ng.so.1')
    rate = lib.espeak_Initialize(2, 0, None, 0)  # synchronous synthesis
    if rate <= 0:
        raise SystemExit('eSpeak initialization failed')
    values = []
    @c.CFUNCTYPE(c.c_int, c.POINTER(c.c_short), c.c_int, c.c_void_p)
    def collect(samples, count, events):
        if samples:
            values.extend(samples[:count])
        return 0
    lib.espeak_SetSynthCallback(collect)
    if lib.espeak_SetVoiceByName(b'pt-br'):
        raise SystemExit('Portuguese voice unavailable')
    text = 'Olá. Esta é uma mensagem de laboratório para testar atividade de voz. Por favor, aguarde um momento.'.encode()
    if lib.espeak_Synth(text, len(text)+1, 0, 1, 0, 1, None, None):
        raise SystemExit('Synthesis failed')
    lib.espeak_Terminate()
# Offline linear interpolation is sufficient for this test fixture; no extension DSP involved.
out = bytearray()
for i in range(int(len(values)*8000/rate)):
    pos = i*rate/8000
    j = int(pos)
    x = round(values[j]*(1-(pos-j))+values[min(j+1,len(values)-1)]*(pos-j))
    out += struct.pack('<h', x)
path = Path(__file__).parent/'acoustic-fixtures'/'speech.pcm'
path.write_bytes(out)
print(f'{path}: {len(out)//16} ms, PCM16LE mono 8000 Hz')
