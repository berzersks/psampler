--TEST--
psampler module info lists every registered class, method and function
--SKIPIF--
<?php
if (!extension_loaded('psampler')) {
    die('skip psampler extension not loaded');
}
?>
--FILE--
<?php
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
$extension = new ReflectionExtension('psampler');

foreach ($extension->getClasses() as $class) {
    $pattern = '/^' . preg_quote($class->getName(), '/') . ' => (.+)$/m';
    if (!preg_match($pattern, $info, $match)) {
        throw new RuntimeException("Missing class in module info: {$class->getName()}");
    }

    foreach ($class->getMethods() as $method) {
        $methodPattern = '/(?:^|, )' . preg_quote($method->getName(), '/') . '\\(/';
        if (!preg_match($methodPattern, $match[1])) {
            throw new RuntimeException(
                "Missing method in module info: {$class->getName()}::{$method->getName()}"
            );
        }
    }
}

foreach ($extension->getFunctions() as $function) {
    $name = $function->getName();
    if (!preg_match('/^' . preg_quote($name, '/') . ' => ' . preg_quote($name, '/') . '\\(/m', $info)) {
        throw new RuntimeException("Missing function in module info: {$name}");
    }
}

echo "complete public API listed\n";
?>
--EXPECT--
complete public API listed
