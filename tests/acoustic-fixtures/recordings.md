# External recordings

Keep recordings in their original archive; do not commit duplicate private audio.

* rec2.wav: `/home/lotus/projetos/libspech/rec2.wav`, PCM16 mono 48000 Hz.
  SHA-256 `406ea08ef425e7e1ce7b11bb7c950f9e1a62b843e542d4a3bf14875619426f40`.
* rec_postal.wav: `/home/lotus/projetos/libspech/rec_postal.wav`, PCM16 mono 8000 Hz.

Normalize a **copy** of rec2 with `ffmpeg -v error -i /path/rec2.wav -ar 8000 -ac 1 /tmp/rec2-8k.wav`.
Run `php tests/rec2_regression.php /tmp/rec2-8k.wav` and
`php tests/rec_postal_regression.php /path/rec_postal.wav`.
Set `PSAMPLER_REC2_8K=/tmp/rec2-8k.wav` to enable rec2.phpt.
This fixture is optional in portable CI, mandatory for acoustic changes where available.
The test prepends silence to preserve the short initial pulse, checks chunk equality,
and shifts isolated recorded attacks through every sample phase of a 20 ms frame.
