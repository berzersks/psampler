#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#define PSAMPLER_NATIVE_BUILD
#include "psampler_native.h"
#include "pcm_buffer.h"
#include "pcm_core.h"
#include "psampler_resample.h"
#include "Zend/zend_exceptions.h"
#include <string.h>

#define PCM_BUFFER_MAX_SIZE ((size_t) ZEND_LONG_MAX < ZSTR_MAX_LEN \
    ? (size_t) ZEND_LONG_MAX : ZSTR_MAX_LEN)
#define PCM_BUFFER_INITIAL_CAPACITY ((size_t) 4096)

struct _psampler_pcm_buffer {
    unsigned char *data;
    size_t size;
    size_t capacity;
    uint32_t sample_rate;
    uint16_t channels;
    zend_bool invoking;
    zend_object std;
};

static zend_class_entry *pcm_buffer_ce;
static zend_object_handlers pcm_buffer_handlers;

/* Process-persistent state: writes ONLY on startup/shutdown thread; immutable
 * throughout requests. Hash lookup neither changes entries nor refcounts. */
static HashTable pcm_operations;
static zend_bool pcm_registry_ready;

typedef struct {
    psampler_pcm_operation_handler handler;
} pcm_operation;

static void pcm_operation_free(zval *entry)
{
    pefree(Z_PTR_P(entry), 1);
}

void psampler_pcm_registry_init(void)
{
    zend_hash_init(&pcm_operations, 8, NULL, pcm_operation_free, 1);
    pcm_registry_ready = 1;
}

void psampler_pcm_registry_shutdown(void)
{
    if (pcm_registry_ready) {
        pcm_registry_ready = 0;
        zend_hash_destroy(&pcm_operations);
    }
}

PSAMPLER_NATIVE_API uint32_t psampler_native_api_version(void)
{
    return PSAMPLER_NATIVE_API_VERSION;
}

PSAMPLER_NATIVE_API zend_bool psampler_register_pcm_operation(const char *name,
    psampler_pcm_operation_handler handler)
{
    pcm_operation *entry;
    size_t length;

    if (!pcm_registry_ready || !php_during_module_startup()
        || name == NULL || name[0] == '\0' || handler == NULL) {
        return 0;
    }
    length = strlen(name);
    if (zend_hash_str_exists(&pcm_operations, name, length)) {
        return 0;
    }
    entry = pemalloc(sizeof(*entry), 1);
    entry->handler = handler;
    if (zend_hash_str_add_ptr(&pcm_operations, name, length, entry) == NULL) {
        pefree(entry, 1);
        return 0;
    }
    return 1;
}

PSAMPLER_NATIVE_API zend_bool psampler_unregister_pcm_operation(const char *name)
{
    if (!pcm_registry_ready
        || (!php_during_module_startup() && !php_during_module_shutdown())
        || name == NULL || name[0] == '\0') {
        return 0;
    }
    return zend_hash_str_del(&pcm_operations, name, strlen(name)) == SUCCESS;
}

static inline psampler_pcm_buffer *pcm_from_object(zend_object *object)
{
    return (psampler_pcm_buffer *) ((char *) object
        - XtOffsetOf(psampler_pcm_buffer, std));
}

#define PCM_BUFFER_OBJ(zv) pcm_from_object(Z_OBJ_P(zv))

PSAMPLER_NATIVE_API psampler_pcm_buffer *psampler_pcm_from_zval(zval *value)
{
    psampler_pcm_buffer *pcm;
    if (value == NULL) {
        return NULL;
    }
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) != IS_OBJECT || Z_OBJCE_P(value) != pcm_buffer_ce) {
        return NULL;
    }
    pcm = PCM_BUFFER_OBJ(value);
    return pcm->sample_rate != 0 ? pcm : NULL;
}

PSAMPLER_NATIVE_API const unsigned char *psampler_pcm_data(const psampler_pcm_buffer *pcm)
{
    return pcm->data;
}

PSAMPLER_NATIVE_API size_t psampler_pcm_size(const psampler_pcm_buffer *pcm)
{
    return pcm->size;
}

PSAMPLER_NATIVE_API uint32_t psampler_pcm_sample_rate(const psampler_pcm_buffer *pcm)
{
    return pcm->sample_rate;
}

PSAMPLER_NATIVE_API uint16_t psampler_pcm_channels(const psampler_pcm_buffer *pcm)
{
    return pcm->channels;
}

static zend_object *pcm_buffer_create(zend_class_entry *ce)
{
    psampler_pcm_buffer *pcm = zend_object_alloc(sizeof(*pcm), ce);
    zend_object_std_init(&pcm->std, ce);
    object_properties_init(&pcm->std, ce);
    pcm->std.handlers = &pcm_buffer_handlers;
    return &pcm->std;
}

static void pcm_buffer_free(zend_object *object)
{
    psampler_pcm_buffer *pcm = pcm_from_object(object);
    if (pcm->data != NULL) {
        efree(pcm->data);
    }
    pcm->data = NULL;
    pcm->size = pcm->capacity = 0;
    zend_object_std_dtor(&pcm->std);
}

static zend_bool pcm_ready(const psampler_pcm_buffer *pcm, zend_bool mutating)
{
    if (UNEXPECTED(pcm->sample_rate == 0)) {
        zend_throw_error(NULL, "PcmBuffer constructor has not been called");
        return 0;
    }
    if (UNEXPECTED(mutating && pcm->invoking)) {
        zend_throw_error(NULL, "PcmBuffer cannot be modified or invoked recursively during invoke()");
        return 0;
    }
    return 1;
}

static zend_bool pcm_valid_rate(zend_long rate, uint32_t argument)
{
    if (UNEXPECTED(rate <= 0 || (uint64_t) rate > UINT32_MAX)) {
        zend_argument_value_error(argument, "must be between 1 and 4294967295 (and fit a PHP integer)");
        return 0;
    }
    return 1;
}

/* Object size/capacity always fit PHP int and zend_string. Allocation precedes
 * replacement; OOM cannot leave an object referring to released storage. */
static zend_bool pcm_reserve(psampler_pcm_buffer *pcm, size_t required)
{
    size_t capacity;
    unsigned char *data;
    if (UNEXPECTED(required > PCM_BUFFER_MAX_SIZE)) {
        zend_value_error("PcmBuffer exceeds the maximum supported size");
        return 0;
    }
    if (required <= pcm->capacity) {
        return 1;
    }
    capacity = pcm->capacity != 0 ? pcm->capacity : PCM_BUFFER_INITIAL_CAPACITY;
    while (capacity < required) {
        if (capacity > PCM_BUFFER_MAX_SIZE / 2) {
            capacity = required;
            break;
        }
        capacity *= 2;
    }
    data = emalloc(capacity);
    if (pcm->size != 0) {
        memcpy(data, pcm->data, pcm->size);
    }
    if (pcm->data != NULL) {
        efree(pcm->data);
    }
    pcm->data = data;
    pcm->capacity = capacity;
    return 1;
}

PHP_METHOD(PcmBuffer, __construct)
{
    zend_long rate, channels;
    psampler_pcm_buffer *pcm;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(rate)
        Z_PARAM_LONG(channels)
    ZEND_PARSE_PARAMETERS_END();
    if (!pcm_valid_rate(rate, 1)) {
        RETURN_THROWS();
    }
    if (channels != 1 && channels != 2) {
        zend_argument_value_error(2, "must be 1 (mono) or 2 (stereo)");
        RETURN_THROWS();
    }
    pcm = PCM_BUFFER_OBJ(getThis());
    if (pcm->invoking) {
        zend_throw_error(NULL, "PcmBuffer cannot be modified during invoke()");
        RETURN_THROWS();
    }
    /* A repeated constructor call resets size/metadata, retaining capacity. */
    pcm->size = 0;
    pcm->sample_rate = (uint32_t) rate;
    pcm->channels = (uint16_t) channels;
}

PHP_METHOD(PcmBuffer, append)
{
    zend_string *input;
    psampler_pcm_buffer *pcm;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(input)
    ZEND_PARSE_PARAMETERS_END();
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 1)) {
        RETURN_THROWS();
    }
    length = ZSTR_LEN(input);
    if (length % (2 * pcm->channels) != 0) {
        zend_argument_value_error(1, "must contain complete PCM16LE frames for the current channels");
        RETURN_THROWS();
    }
    if (length > PCM_BUFFER_MAX_SIZE - pcm->size) {
        zend_value_error("PcmBuffer exceeds the maximum supported size");
        RETURN_THROWS();
    }
    if (length != 0) {
        if (!pcm_reserve(pcm, pcm->size + length)) {
            RETURN_THROWS();
        }
        memcpy(pcm->data + pcm->size, ZSTR_VAL(input), length);
        pcm->size += length;
    }
    RETURN_NULL();
}

PHP_METHOD(PcmBuffer, reset)
{
    zend_long rate, channels;
    psampler_pcm_buffer *pcm;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(rate)
        Z_PARAM_LONG(channels)
    ZEND_PARSE_PARAMETERS_END();
    if (!pcm_valid_rate(rate, 1)) {
        RETURN_THROWS();
    }
    if (channels != 1 && channels != 2) {
        zend_argument_value_error(2, "must be 1 (mono) or 2 (stereo)");
        RETURN_THROWS();
    }
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 1)) {
        RETURN_THROWS();
    }
    /* No allocation, release, or PCM copy: storage remains owned by this object. */
    pcm->size = 0;
    pcm->sample_rate = (uint32_t) rate;
    pcm->channels = (uint16_t) channels;
    RETURN_NULL();
}

#define PCM_INT_METHOD(method, field) \
PHP_METHOD(PcmBuffer, method) \
{ \
    psampler_pcm_buffer *pcm; \
    ZEND_PARSE_PARAMETERS_NONE(); \
    pcm = PCM_BUFFER_OBJ(getThis()); \
    if (!pcm_ready(pcm, 0)) { RETURN_THROWS(); } \
    RETURN_LONG((zend_long) pcm->field); \
}

PCM_INT_METHOD(size, size)
PCM_INT_METHOD(capacity, capacity)
PCM_INT_METHOD(sampleRate, sample_rate)
PCM_INT_METHOD(channels, channels)

PHP_METHOD(PcmBuffer, clear)
{
    psampler_pcm_buffer *pcm;
    ZEND_PARSE_PARAMETERS_NONE();
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 1)) {
        RETURN_THROWS();
    }
    pcm->size = 0;
    RETURN_NULL();
}

PHP_METHOD(PcmBuffer, toString)
{
    psampler_pcm_buffer *pcm;
    ZEND_PARSE_PARAMETERS_NONE();
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 0)) {
        RETURN_THROWS();
    }
    if (pcm->size == 0) {
        RETURN_EMPTY_STRING();
    }
    RETURN_STRINGL((const char *) pcm->data, pcm->size);
}

PHP_METHOD(PcmBuffer, toMono)
{
    psampler_pcm_buffer *pcm;
    ZEND_PARSE_PARAMETERS_NONE();
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 1)) {
        RETURN_THROWS();
    }
    if (pcm->channels == 2) {
        psampler_pcm16_stereo_to_mono(pcm->data, pcm->size, pcm->data);
        pcm->size /= 2;
        pcm->channels = 1;
    }
    RETURN_OBJ_COPY(&pcm->std);
}

PHP_METHOD(PcmBuffer, toStereo)
{
    psampler_pcm_buffer *pcm;
    ZEND_PARSE_PARAMETERS_NONE();
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 1)) {
        RETURN_THROWS();
    }
    if (pcm->channels == 1) {
        if (pcm->size > PCM_BUFFER_MAX_SIZE / 2) {
            zend_value_error("PcmBuffer is too large to convert to stereo");
            RETURN_THROWS();
        }
        if (!pcm_reserve(pcm, pcm->size * 2)) {
            RETURN_THROWS();
        }
        psampler_pcm16_mono_to_stereo(pcm->data, pcm->size, pcm->data);
        pcm->size *= 2;
        pcm->channels = 2;
    }
    RETURN_OBJ_COPY(&pcm->std);
}

PHP_METHOD(PcmBuffer, resample)
{
    zend_long rate;
    psampler_pcm_buffer *pcm;
    unsigned char *output;
    size_t size, capacity;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(rate)
    ZEND_PARSE_PARAMETERS_END();
    if (!pcm_valid_rate(rate, 1)) {
        RETURN_THROWS();
    }
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 1)) {
        RETURN_THROWS();
    }
    if (pcm->sample_rate != (uint32_t) rate) {
        if (pcm->size != 0) {
            if (psampler_resample_pcm16(pcm->data, pcm->size, pcm->sample_rate,
                (uint32_t) rate, pcm->channels, &output, &size, &capacity) == FAILURE) {
                RETURN_THROWS();
            }
            if (pcm->data != NULL) {
                efree(pcm->data);
            }
            pcm->data = output;
            pcm->size = size;
            pcm->capacity = capacity;
        }
        pcm->sample_rate = (uint32_t) rate;
    }
    RETURN_OBJ_COPY(&pcm->std);
}

PHP_METHOD(PcmBuffer, canInvoke)
{
    zend_string *operation;
    psampler_pcm_buffer *pcm;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(operation)
    ZEND_PARSE_PARAMETERS_END();
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 0)) {
        RETURN_THROWS();
    }
    RETURN_BOOL(pcm_registry_ready && zend_hash_exists(&pcm_operations, operation));
}

PHP_METHOD(PcmBuffer, invoke)
{
    zend_string *operation;
    zval *args;
    uint32_t argc;
    psampler_pcm_buffer *pcm;
    pcm_operation *entry;
    zend_result result;
    ZEND_PARSE_PARAMETERS_START(1, -1)
        Z_PARAM_STR(operation)
        Z_PARAM_VARIADIC('*', args, argc)
    ZEND_PARSE_PARAMETERS_END();
    pcm = PCM_BUFFER_OBJ(getThis());
    if (!pcm_ready(pcm, 1)) {
        RETURN_THROWS();
    }
    entry = pcm_registry_ready ? zend_hash_find_ptr(&pcm_operations, operation) : NULL;
    if (entry == NULL) {
        zend_argument_value_error(1, "is not a registered PCM operation: %s", ZSTR_VAL(operation));
        RETURN_THROWS();
    }
    pcm->invoking = 1;
    ZVAL_NULL(return_value);
    result = entry->handler(pcm, argc, args, return_value);
    pcm->invoking = 0;
    if (result == FAILURE || EG(exception)) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        if (!EG(exception)) {
            zend_throw_error(NULL, "PCM operation failed without an exception");
        }
        RETURN_THROWS();
    }
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_pcm_buffer_construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, sampleRate, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, channels, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_buffer_append, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, pcm, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_buffer_reset, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, sampleRate, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, channels, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_buffer_int, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_buffer_clear, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_buffer_string, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_pcm_buffer_transform, 0, 0, PcmBuffer, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_pcm_buffer_resample, 0, 1, PcmBuffer, 0)
    ZEND_ARG_TYPE_INFO(0, sampleRate, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_buffer_can_invoke, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, operation, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_buffer_invoke, 0, 1, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, operation, IS_STRING, 0)
    ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry pcm_buffer_methods[] = {
    PHP_ME(PcmBuffer, __construct, arginfo_pcm_buffer_construct, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, append, arginfo_pcm_buffer_append, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, size, arginfo_pcm_buffer_int, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, capacity, arginfo_pcm_buffer_int, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, sampleRate, arginfo_pcm_buffer_int, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, channels, arginfo_pcm_buffer_int, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, clear, arginfo_pcm_buffer_clear, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, reset, arginfo_pcm_buffer_reset, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, toString, arginfo_pcm_buffer_string, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, toMono, arginfo_pcm_buffer_transform, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, toStereo, arginfo_pcm_buffer_transform, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, resample, arginfo_pcm_buffer_resample, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, canInvoke, arginfo_pcm_buffer_can_invoke, ZEND_ACC_PUBLIC)
    PHP_ME(PcmBuffer, invoke, arginfo_pcm_buffer_invoke, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

void psampler_register_pcm_buffer_class(void)
{
    zend_class_entry ce;
    memcpy(&pcm_buffer_handlers, &std_object_handlers, sizeof(pcm_buffer_handlers));
    pcm_buffer_handlers.offset = XtOffsetOf(psampler_pcm_buffer, std);
    pcm_buffer_handlers.free_obj = pcm_buffer_free;
    pcm_buffer_handlers.clone_obj = NULL;
    INIT_CLASS_ENTRY(ce, "PcmBuffer", pcm_buffer_methods);
    pcm_buffer_ce = zend_register_internal_class(&ce);
    pcm_buffer_ce->create_object = pcm_buffer_create;
    pcm_buffer_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NOT_SERIALIZABLE
        | ZEND_ACC_NO_DYNAMIC_PROPERTIES;
}
