PHP_ARG_ENABLE(psampler_binary_test, whether to enable the isolated binary ABI fixture,
[  --enable-psampler-binary-test  Build test-only binary provider], no)

if test "$PHP_PSAMPLER_BINARY_TEST" != "no"; then
  PHP_NEW_EXTENSION(psampler_binary_test, psampler_binary_test.c, $ext_shared)
  PHP_ADD_EXTENSION_DEP(psampler_binary_test, psampler)
fi
