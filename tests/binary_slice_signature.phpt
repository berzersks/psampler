--TEST--
Binary slice exposes the documented PHP signature in static and shared builds
--EXTENSIONS--
psampler
--FILE--
<?php
$r = new ReflectionFunction('psampler_binary_slice');
var_dump((string) $r->getReturnType(), $r->getNumberOfRequiredParameters());
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->getType(), ':', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'required', "\n";
}
?>
--EXPECT--
string(6) "string"
int(1)
source:object:required
offset:int:0
length:?int:NULL
