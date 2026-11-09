--TEST--
Shared acoustic DSP recognizes off-grid rings and rejects AM bips across alignments
--EXTENSIONS--
psampler
--FILE--
<?php require __DIR__ . '/acoustic_offgrid_suite.php'; ?>
--EXPECT--
AcousticStreamAnalyzer suite OK
Off-grid and AM regression OK (75 cases)
