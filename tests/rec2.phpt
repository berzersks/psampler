--TEST--
Real rec2 ring alignment, modulated attacks and true speech (external fixture)
--EXTENSIONS--
psampler
--SKIPIF--
<?php if (!getenv('PSAMPLER_REC2_8K')) die('skip set PSAMPLER_REC2_8K; see acoustic-fixtures/recordings.md'); ?>
--FILE--
<?php require __DIR__.'/rec2_regression.php'; ?>
--EXPECT--
AcousticStreamAnalyzer suite OK
rec2 regression OK: 3 rings, cadence, real speech, 480 attack phases
