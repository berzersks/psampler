PHP_ARG_ENABLE(psampler, whether to enable psampler support,
[  --enable-psampler           Enable psampler support])

if test "$PHP_PSAMPLER" != "no"; then
  AC_DEFINE(COMPILE_DL_PSAMPLER, 1, [Whether to build psampler as dynamic module])
  PHP_NEW_EXTENSION(psampler, psampler.c, $ext_shared)
fi
