--TEST--
ByteBuffer supports binary data, wraparound, growth, and bounds checks
--EXTENSIONS--
psampler
--FILE--
<?php
$buffer = new ByteBuffer(4);

var_dump($buffer->capacity());
var_dump($buffer->length());
var_dump($buffer->has(0));

$buffer->append("ab\0d");
var_dump($buffer->peek(4) === "ab\0d");
var_dump($buffer->pop(3) === "ab\0");

// Wrap around in the original four-byte allocation.
$buffer->append("ef");
var_dump($buffer->peek(3) === "def");
var_dump($buffer->capacity());

// Force growth while the readable data spans the end and start of the ring.
$buffer->append("ghijk");
var_dump($buffer->capacity());
var_dump($buffer->pop(8) === "defghijk");
var_dump($buffer->length());

$buffer->append("123456");
$buffer->discard(2);
var_dump($buffer->peek(4));
$capacity = $buffer->capacity();
$buffer->clear();
var_dump($buffer->length());
var_dump($buffer->capacity() === $capacity);

foreach ([-1, 1] as $bytes) {
    try {
        $buffer->pop($bytes);
    } catch (ValueError $error) {
        echo get_class($error), "\n";
    }
}

try {
    new ByteBuffer(0);
} catch (ValueError $error) {
    echo get_class($error), "\n";
}

try {
    clone $buffer;
} catch (Error $error) {
    echo get_class($error), "\n";
}

try {
    serialize($buffer);
} catch (Exception $error) {
    echo get_class($error), "\n";
}
?>
--EXPECT--
int(4)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(4)
int(8)
bool(true)
int(0)
string(4) "3456"
int(0)
bool(true)
ValueError
ValueError
ValueError
Error
Exception
