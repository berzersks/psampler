PHP_ARG_ENABLE(psampler, whether to enable psampler support,
[  --enable-psampler           Enable psampler support])

PHP_ARG_ENABLE([psampler-swoole-bridge], [whether to enable the optional Swoole Buffer bridge],
  [AS_HELP_STRING([--disable-psampler-swoole-bridge], [Disable native Swoole Buffer integration])], [yes], [no])
PHP_ARG_ENABLE([psampler-swoole-static], [whether to require direct Swoole read ABI linkage],
  [AS_HELP_STRING([--enable-psampler-swoole-static], [Require Swoole read ABI symbols at link time (normally unnecessary on ELF)])], [no], [no])

if test "$PHP_PSAMPLER" != "no"; then
  if test "$PHP_PSAMPLER_SWOOLE_BRIDGE" != "no"; then
    AC_DEFINE([PSAMPLER_SWOOLE_BRIDGE], [1], [Enable optional native Swoole Buffer bridge])
    if test "$PHP_PSAMPLER_SWOOLE_STATIC" = "yes"; then
      AC_DEFINE([PSAMPLER_SWOOLE_STATIC], [1], [Require directly linked Swoole read ABI symbols])
    fi
  elif test "$PHP_PSAMPLER_SWOOLE_STATIC" = "yes"; then
    AC_MSG_ERROR([--enable-psampler-swoole-static requires the Swoole bridge])
  fi
  dnl PHP_NEW_EXTENSION defines COMPILE_DL_PSAMPLER only for shared builds.
  dnl Runtime ZEND_MOD_OPTIONAL orders MINIT without a mandatory link dependency.
  dnl PHP_ADD_EXTENSION_DEP would reject static psampler + shared Swoole.
  if test "$ext_shared" = "yes"; then
    PHP_NEW_EXTENSION(psampler, psampler.c byte_buffer.c binary_buffer.c swoole_bridge.c pcm_core.c pcm_buffer.c pcm_analyzer.c, $ext_shared, , [-fno-math-errno])
  else
    PHP_NEW_EXTENSION(psampler, psampler.c byte_buffer.c binary_buffer.c swoole_bridge.c pcm_core.c pcm_buffer.c, $ext_shared, , [-fno-math-errno])
    PHP_ADD_SOURCES([$ext_dir], [pcm_analyzer.c], [-O3 -fno-fast-math])
  fi
  PHP_INSTALL_HEADERS([ext/psampler], [psampler_native.h psampler_binary.h])
fi
