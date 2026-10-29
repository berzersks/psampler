#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#define PSAMPLER_BINARY_BUILD
#include "psampler_binary.h"
#include "binary_buffer.h"
#include "Zend/zend_exceptions.h"
#include <string.h>

/* Written only on the process startup/shutdown thread, read-only in requests. */
static HashTable binary_providers;
static zend_bool binary_registry_ready;

static void binary_provider_free(zval *entry)
{
    pefree(Z_PTR_P(entry), 1);
}

void psampler_binary_registry_init(void)
{
    zend_hash_init(&binary_providers, 4, NULL, binary_provider_free, 1);
    binary_registry_ready = 1;
}

void psampler_binary_registry_shutdown(void)
{
    if (binary_registry_ready) {
        binary_registry_ready = 0;
        zend_hash_destroy(&binary_providers);
    }
}

PSAMPLER_BINARY_API uint32_t psampler_binary_api_version(void)
{
    return PSAMPLER_BINARY_API_VERSION;
}

PSAMPLER_BINARY_API zend_bool psampler_binary_register_provider(
    const psampler_binary_provider *provider)
{
    psampler_binary_provider *copy;
    zend_string *name;

    if (!binary_registry_ready || !php_during_module_startup()
        || provider == NULL || provider->api_version != PSAMPLER_BINARY_API_VERSION
        || provider->struct_size != sizeof(*provider) || provider->source_ce == NULL
        || provider->source_ce->type != ZEND_INTERNAL_CLASS
        || provider->acquire == NULL || provider->release == NULL) {
        return 0;
    }
    name = provider->source_ce->name;
    if (zend_hash_exists(&binary_providers, name)) {
        return 0;
    }
    copy = pemalloc(sizeof(*copy), 1);
    *copy = *provider;
    if (zend_hash_str_add_ptr(&binary_providers, ZSTR_VAL(name), ZSTR_LEN(name), copy) == NULL) {
        pefree(copy, 1);
        return 0;
    }
    return 1;
}

PSAMPLER_BINARY_API zend_bool psampler_binary_unregister_provider(zend_class_entry *source_ce)
{
    psampler_binary_provider *entry;

    if (!binary_registry_ready || source_ce == NULL
        || (!php_during_module_startup() && !php_during_module_shutdown())) {
        return 0;
    }
    entry = zend_hash_find_ptr(&binary_providers, source_ce->name);
    if (entry == NULL || entry->source_ce != source_ce) {
        return 0;
    }
    return zend_hash_del(&binary_providers, source_ce->name) == SUCCESS;
}

static const psampler_binary_provider *binary_provider_for(zval *source)
{
    psampler_binary_provider *entry;

    if (source == NULL || !binary_registry_ready) {
        return NULL;
    }
    ZVAL_DEREF(source);
    if (Z_TYPE_P(source) != IS_OBJECT) {
        return NULL;
    }
    entry = zend_hash_find_ptr(&binary_providers, Z_OBJCE_P(source)->name);
    return entry != NULL && entry->source_ce == Z_OBJCE_P(source) ? entry : NULL;
}

PSAMPLER_BINARY_API zend_bool psampler_binary_source_supported(zval *source)
{
    return binary_provider_for(source) != NULL;
}

static zend_bool binary_view_valid(const psampler_binary_view *view, zend_object *source)
{
    size_t first = view->spans[0].length;
    size_t second = view->spans[1].length;
    size_t maximum = (size_t) ZEND_LONG_MAX;

    return view->source == source && view->capacity <= maximum
        && first <= view->capacity && second <= view->capacity - first
        && (first == 0 || view->spans[0].data != NULL)
        && (second == 0 || view->spans[1].data != NULL);
}

PSAMPLER_BINARY_API zend_result psampler_binary_with_view(zval *source,
    psampler_binary_read_handler handler, void *context)
{
    const psampler_binary_provider *provider;
    psampler_binary_view view = {0};
    zend_object *object;
    zend_result result;

    if (EG(exception)) {
        return FAILURE;
    }
    if (handler == NULL) {
        zend_value_error("Native binary read handler must not be NULL");
        return FAILURE;
    }
    provider = binary_provider_for(source);
    if (provider == NULL) {
        zend_value_error("Object has no registered native binary buffer provider");
        return FAILURE;
    }
    ZVAL_DEREF(source);
    object = Z_OBJ_P(source);
    GC_ADDREF(object);
    /* Acquisition may bail out (for example while reporting an error). No
     * successful view exists yet, but our source reference still needs undoing. */
    zend_try {
        result = provider->acquire(object, &view);
    } zend_catch {
        OBJ_RELEASE(object);
        zend_bailout();
    } zend_end_try();
    if (result != SUCCESS) {
        OBJ_RELEASE(object);
        if (!EG(exception)) {
            zend_throw_error(NULL, "Native binary provider acquisition failed without an exception");
        }
        return FAILURE;
    }
    if (EG(exception) || !binary_view_valid(&view, object)) {
        provider->release(object, &view);
        OBJ_RELEASE(object);
        if (!EG(exception)) {
            zend_value_error("Native binary provider returned an invalid view");
        }
        return FAILURE;
    }

    /* The descriptor/object/provider are established before setjmp and never
     * modified afterwards. Only result is modified, and is not read on bailout. */
    zend_try {
        result = handler(&view, context);
    } zend_catch {
        provider->release(object, &view);
        OBJ_RELEASE(object);
        zend_bailout();
    } zend_end_try();

    provider->release(object, &view);
    OBJ_RELEASE(object);
    if (result != SUCCESS || EG(exception)) {
        if (!EG(exception)) {
            zend_throw_error(NULL, "Native binary reader failed without an exception");
        }
        return FAILURE;
    }
    return SUCCESS;
}

typedef struct {
    size_t offset;
    size_t length;
    zend_bool all_remaining;
    zval *result;
} binary_slice_context;

static zend_result binary_slice_view(const psampler_binary_view *view, void *opaque)
{
    binary_slice_context *context = opaque;
    /* with_view has validated both spans against capacity <= ZEND_LONG_MAX. */
    size_t total = view->spans[0].length + view->spans[1].length;
    size_t offset = context->offset, length, remaining;
    unsigned int i;
    char *destination;
    zend_string *result;

    if (offset > total) {
        zend_argument_value_error(2, "must not exceed the native source length");
        return FAILURE;
    }
    length = context->all_remaining ? total - offset : context->length;
    if (length > total - offset) {
        zend_argument_value_error(3, "must not exceed the bytes remaining after offset");
        return FAILURE;
    }
    if (length > ZSTR_MAX_LEN) {
        zend_argument_value_error(3, "exceeds the maximum PHP string length");
        return FAILURE;
    }
    if (length == 0) {
        ZVAL_EMPTY_STRING(context->result);
        return SUCCESS;
    }

    /* Allocation is inside with_view's bailout guard. After allocation, only
     * infallible copies remain; publish ownership directly to the PHP return. */
    result = zend_string_alloc(length, 0);
    ZVAL_STR(context->result, result);
    destination = ZSTR_VAL(result);
    remaining = length;
    for (i = 0; i < 2 && remaining != 0; ++i) {
        size_t available = view->spans[i].length;
        size_t count;
        if (offset >= available) {
            offset -= available;
            continue;
        }
        count = available - offset;
        if (count > remaining) count = remaining;
        memcpy(destination, view->spans[i].data + offset, count);
        destination += count;
        remaining -= count;
        offset = 0;
    }
    ZSTR_VAL(result)[length] = '\0';
    return SUCCESS;
}

PHP_FUNCTION(psampler_binary_slice)
{
    zval *source;
    zend_long offset = 0, length = 0;
    zend_bool length_is_null = 1;
    binary_slice_context context;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_OBJECT(source)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(offset)
        Z_PARAM_LONG_OR_NULL(length, length_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (offset < 0) {
        zend_argument_value_error(2, "must be greater than or equal to 0");
        RETURN_THROWS();
    }
    if (!length_is_null && length < 0) {
        zend_argument_value_error(3, "must be greater than or equal to 0 or null");
        RETURN_THROWS();
    }
    context.offset = (size_t) offset;
    context.length = (size_t) length;
    context.all_remaining = length_is_null;
    context.result = return_value;
    if (psampler_binary_with_view(source, binary_slice_view, &context) != SUCCESS) {
        /* Keep the PHP return empty if the existing reader reports failure. */
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
        RETURN_THROWS();
    }
}
