PHP_ARG_ENABLE(psampler, whether to enable psampler support,
[  --enable-psampler           Enable psampler support])

if test "$PHP_PSAMPLER" != "no"; then
  PHP_NEW_EXTENSION(psampler, psampler.c, $ext_shared)
fi
