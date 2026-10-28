PHP_ARG_ENABLE(psampler_binary_test, whether to enable the isolated binary ABI fixture,
[  --enable-psampler-binary-test  Build test-only binary provider], no)

PHP_ARG_ENABLE([psampler-binary-test-swoole-abi], [whether to emulate the Swoole ABI in the existing fixture],
  [AS_HELP_STRING([--enable-psampler-binary-test-swoole-abi], [TEST ONLY: fixture acts as Swoole for startup ABI rejection tests])], [no], [no])

if test "$PHP_PSAMPLER_BINARY_TEST" != "no"; then
  PHP_NEW_EXTENSION(psampler_binary_test, psampler_binary_test.c, $ext_shared)
  if test "$PHP_PSAMPLER_BINARY_TEST_SWOOLE_ABI" = "yes"; then
    AC_DEFINE([PSAMPLER_BINARY_TEST_SWOOLE_ABI], [1], [Test fixture emulates Swoole at startup])
  else
    PHP_ADD_EXTENSION_DEP(psampler_binary_test, psampler)
  fi
fi
