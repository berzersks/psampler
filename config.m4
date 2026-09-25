PHP_ARG_ENABLE(psampler, whether to enable psampler support,
[  --enable-psampler           Enable psampler support])

if test "$PHP_PSAMPLER" != "no"; then
  AC_DEFINE(COMPILE_DL_PSAMPLER, 1, [Whether to build psampler as dynamic module])
  if test "$ext_shared" = "yes"; then
    PHP_NEW_EXTENSION(psampler, psampler.c byte_buffer.c pcm_analyzer.c, $ext_shared)
  else
    PHP_NEW_EXTENSION(psampler, psampler.c byte_buffer.c, $ext_shared)
    PHP_ADD_SOURCES([$ext_dir], [pcm_analyzer.c], [-O3 -fno-fast-math])
  fi
fi
