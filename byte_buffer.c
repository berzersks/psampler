#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "byte_buffer.h"

#include <string.h>

#define BYTE_BUFFER_DEFAULT_CAPACITY ((size_t) 4096)
#define BYTE_BUFFER_MAX_CAPACITY ((size_t) ZEND_LONG_MAX)

typedef struct _byte_buffer_object {
    unsigned char *data;
    size_t capacity;
    size_t read_pos;
    size_t write_pos;
    size_t length;
    zend_object std;
} byte_buffer_object;

static zend_class_entry *byte_buffer_ce;
static zend_object_handlers byte_buffer_handlers;

static inline byte_buffer_object *byte_buffer_from_object(zend_object *object)
{
    return (byte_buffer_object *) ((char *) object
        - XtOffsetOf(byte_buffer_object, std));
}

#define BYTE_BUFFER_OBJ(zv) byte_buffer_from_object(Z_OBJ_P((zv)))

static zend_object *byte_buffer_create(zend_class_entry *ce)
{
    byte_buffer_object *buffer = zend_object_alloc(sizeof(byte_buffer_object), ce);

    zend_object_std_init(&buffer->std, ce);
    object_properties_init(&buffer->std, ce);
    buffer->std.handlers = &byte_buffer_handlers;

    return &buffer->std;
}

static void byte_buffer_free(zend_object *object)
{
    byte_buffer_object *buffer = byte_buffer_from_object(object);

    if (buffer->data != NULL) {
        efree(buffer->data);
        buffer->data = NULL;
    }

    buffer->capacity = 0;
    buffer->read_pos = 0;
    buffer->write_pos = 0;
    buffer->length = 0;

    zend_object_std_dtor(&buffer->std);
}

/*
 * Copies the readable bytes into a new allocation before releasing the old
 * one. If the Zend allocator aborts the request, the existing buffer remains
 * valid and will still be released by the object destructor.
 */
static zend_bool byte_buffer_reserve(
    byte_buffer_object *buffer,
    size_t additional
)
{
    size_t required;
    size_t new_capacity;
    unsigned char *new_data;

    if (additional == 0) {
        return 1;
    }

    if (UNEXPECTED(buffer->length > BYTE_BUFFER_MAX_CAPACITY
        || additional > BYTE_BUFFER_MAX_CAPACITY - buffer->length)) {
        zend_value_error("ByteBuffer size exceeds the maximum supported size");
        return 0;
    }

    required = buffer->length + additional;
    if (required <= buffer->capacity) {
        return 1;
    }

    new_capacity = buffer->capacity;
    if (new_capacity == 0) {
        new_capacity = BYTE_BUFFER_DEFAULT_CAPACITY;
    }

    while (new_capacity < required) {
        if (new_capacity > BYTE_BUFFER_MAX_CAPACITY / 2) {
            new_capacity = required;
            break;
        }
        new_capacity *= 2;
    }

    new_data = emalloc(new_capacity);

    if (buffer->length != 0) {
        size_t first_part = buffer->capacity - buffer->read_pos;

        if (first_part > buffer->length) {
            first_part = buffer->length;
        }

        memcpy(new_data, buffer->data + buffer->read_pos, first_part);
        if (first_part < buffer->length) {
            memcpy(
                new_data + first_part,
                buffer->data,
                buffer->length - first_part
            );
        }
    }

    if (buffer->data != NULL) {
        efree(buffer->data);
    }

    buffer->data = new_data;
    buffer->capacity = new_capacity;
    buffer->read_pos = 0;
    buffer->write_pos = buffer->length;

    return 1;
}

static zend_bool byte_buffer_validate_read(
    const byte_buffer_object *buffer,
    zend_long requested
)
{
    if (UNEXPECTED(requested < 0)) {
        zend_argument_value_error(1, "must be greater than or equal to 0");
        return 0;
    }

    if (UNEXPECTED((size_t) requested > buffer->length)) {
        zend_argument_value_error(
            1,
            "must be less than or equal to the number of buffered bytes ("
                ZEND_LONG_FMT ")",
            (zend_long) buffer->length
        );
        return 0;
    }

    return 1;
}

static zend_string *byte_buffer_copy_bytes(
    const byte_buffer_object *buffer,
    size_t bytes
)
{
    zend_string *result = zend_string_alloc(bytes, 0);
    size_t first_part = buffer->capacity - buffer->read_pos;

    if (first_part > bytes) {
        first_part = bytes;
    }

    memcpy(ZSTR_VAL(result), buffer->data + buffer->read_pos, first_part);
    if (first_part < bytes) {
        memcpy(
            ZSTR_VAL(result) + first_part,
            buffer->data,
            bytes - first_part
        );
    }

    ZSTR_VAL(result)[bytes] = '\0';
    return result;
}

static void byte_buffer_advance(byte_buffer_object *buffer, size_t bytes)
{
    size_t until_end;

    if (bytes == buffer->length) {
        buffer->read_pos = 0;
        buffer->write_pos = 0;
        buffer->length = 0;
        return;
    }

    until_end = buffer->capacity - buffer->read_pos;
    if (bytes >= until_end) {
        buffer->read_pos = bytes - until_end;
    } else {
        buffer->read_pos += bytes;
    }
    buffer->length -= bytes;
}

PHP_METHOD(ByteBuffer, __construct)
{
    zend_long initial_capacity = (zend_long) BYTE_BUFFER_DEFAULT_CAPACITY;
    byte_buffer_object *buffer;
    unsigned char *new_data;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(initial_capacity)
    ZEND_PARSE_PARAMETERS_END();

    if (UNEXPECTED(initial_capacity <= 0)) {
        zend_argument_value_error(1, "must be greater than 0");
        RETURN_THROWS();
    }

    /* Allocate first so a repeated constructor call cannot lose old data on OOM. */
    new_data = emalloc((size_t) initial_capacity);
    buffer = BYTE_BUFFER_OBJ(getThis());

    if (buffer->data != NULL) {
        efree(buffer->data);
    }

    buffer->data = new_data;
    buffer->capacity = (size_t) initial_capacity;
    buffer->read_pos = 0;
    buffer->write_pos = 0;
    buffer->length = 0;
}

PHP_METHOD(ByteBuffer, append)
{
    zend_string *data;
    byte_buffer_object *buffer;
    size_t data_length;
    size_t first_part;
    size_t second_part;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(data)
    ZEND_PARSE_PARAMETERS_END();

    data_length = ZSTR_LEN(data);
    if (data_length == 0) {
        RETURN_NULL();
    }

    buffer = BYTE_BUFFER_OBJ(getThis());
    if (!byte_buffer_reserve(buffer, data_length)) {
        RETURN_THROWS();
    }

    first_part = buffer->capacity - buffer->write_pos;
    if (first_part > data_length) {
        first_part = data_length;
    }

    memcpy(buffer->data + buffer->write_pos, ZSTR_VAL(data), first_part);

    second_part = data_length - first_part;
    if (second_part != 0) {
        memcpy(buffer->data, ZSTR_VAL(data) + first_part, second_part);
        buffer->write_pos = second_part;
    } else {
        buffer->write_pos += first_part;
        if (buffer->write_pos == buffer->capacity) {
            buffer->write_pos = 0;
        }
    }

    buffer->length += data_length;
    RETURN_NULL();
}

PHP_METHOD(ByteBuffer, length)
{
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_LONG((zend_long) BYTE_BUFFER_OBJ(getThis())->length);
}

PHP_METHOD(ByteBuffer, has)
{
    zend_long bytes;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(bytes)
    ZEND_PARSE_PARAMETERS_END();

    if (UNEXPECTED(bytes < 0)) {
        zend_argument_value_error(1, "must be greater than or equal to 0");
        RETURN_THROWS();
    }

    RETURN_BOOL((size_t) bytes <= BYTE_BUFFER_OBJ(getThis())->length);
}

PHP_METHOD(ByteBuffer, peek)
{
    zend_long bytes;
    byte_buffer_object *buffer;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(bytes)
    ZEND_PARSE_PARAMETERS_END();

    buffer = BYTE_BUFFER_OBJ(getThis());
    if (!byte_buffer_validate_read(buffer, bytes)) {
        RETURN_THROWS();
    }

    if (bytes == 0) {
        RETURN_EMPTY_STRING();
    }

    RETURN_STR(byte_buffer_copy_bytes(buffer, (size_t) bytes));
}

PHP_METHOD(ByteBuffer, pop)
{
    zend_long bytes;
    byte_buffer_object *buffer;
    zend_string *result;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(bytes)
    ZEND_PARSE_PARAMETERS_END();

    buffer = BYTE_BUFFER_OBJ(getThis());
    if (!byte_buffer_validate_read(buffer, bytes)) {
        RETURN_THROWS();
    }

    if (bytes == 0) {
        RETURN_EMPTY_STRING();
    }

    /* Copy before advancing so allocation failure cannot consume bytes. */
    result = byte_buffer_copy_bytes(buffer, (size_t) bytes);
    byte_buffer_advance(buffer, (size_t) bytes);

    RETURN_STR(result);
}

PHP_METHOD(ByteBuffer, discard)
{
    zend_long bytes;
    byte_buffer_object *buffer;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(bytes)
    ZEND_PARSE_PARAMETERS_END();

    buffer = BYTE_BUFFER_OBJ(getThis());
    if (!byte_buffer_validate_read(buffer, bytes)) {
        RETURN_THROWS();
    }

    if (bytes != 0) {
        byte_buffer_advance(buffer, (size_t) bytes);
    }

    RETURN_NULL();
}

PHP_METHOD(ByteBuffer, clear)
{
    byte_buffer_object *buffer;

    ZEND_PARSE_PARAMETERS_NONE();

    buffer = BYTE_BUFFER_OBJ(getThis());
    buffer->read_pos = 0;
    buffer->write_pos = 0;
    buffer->length = 0;

    RETURN_NULL();
}

PHP_METHOD(ByteBuffer, capacity)
{
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_LONG((zend_long) BYTE_BUFFER_OBJ(getThis())->capacity);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_byte_buffer_construct, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, initialCapacity, IS_LONG, 0, "4096")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_byte_buffer_append,
    0,
    1,
    IS_VOID,
    0
)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_byte_buffer_length,
    0,
    0,
    IS_LONG,
    0
)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_byte_buffer_has,
    0,
    1,
    _IS_BOOL,
    0
)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_byte_buffer_read,
    0,
    1,
    IS_STRING,
    0
)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_byte_buffer_discard,
    0,
    1,
    IS_VOID,
    0
)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_byte_buffer_clear,
    0,
    0,
    IS_VOID,
    0
)
ZEND_END_ARG_INFO()

static const zend_function_entry byte_buffer_methods[] = {
    PHP_ME(ByteBuffer, __construct, arginfo_byte_buffer_construct, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, append, arginfo_byte_buffer_append, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, length, arginfo_byte_buffer_length, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, has, arginfo_byte_buffer_has, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, pop, arginfo_byte_buffer_read, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, peek, arginfo_byte_buffer_read, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, discard, arginfo_byte_buffer_discard, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, clear, arginfo_byte_buffer_clear, ZEND_ACC_PUBLIC)
    PHP_ME(ByteBuffer, capacity, arginfo_byte_buffer_length, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

void psampler_register_byte_buffer_class(void)
{
    zend_class_entry ce;

    memcpy(
        &byte_buffer_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    byte_buffer_handlers.offset = XtOffsetOf(byte_buffer_object, std);
    byte_buffer_handlers.free_obj = byte_buffer_free;
    byte_buffer_handlers.clone_obj = NULL;

    INIT_CLASS_ENTRY(ce, "ByteBuffer", byte_buffer_methods);
    byte_buffer_ce = zend_register_internal_class(&ce);
    byte_buffer_ce->create_object = byte_buffer_create;
    byte_buffer_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NOT_SERIALIZABLE;
}
