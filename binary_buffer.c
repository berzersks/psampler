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
