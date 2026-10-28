#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#define PSAMPLER_BINARY_BUILD
/* We resolve functions ourselves; never require a Windows import library. */
#define SWOOLE_BUFFER_C_API_STATIC
#include "psampler_binary.h"
#include "swoole_buffer_c_api.h"
#include "swoole_bridge.h"
#include "Zend/zend_modules.h"

/* Process startup/shutdown writes only. No request objects or payload here. */
static const swoole_buffer_read_api_v1 *swoole_api;
static const char *bridge_status = "unavailable";

#ifdef PSAMPLER_SWOOLE_BRIDGE
typedef uint32_t (*swoole_version_fn)(void);
typedef const swoole_buffer_read_api_v1 *(*swoole_api_fn)(uint32_t, size_t);

/* ELF weak undefined references resolve at the final static link, without a
 * dynamic symbol table or dlsym. The producer's MINIT strongly references
 * php_swoole_buffer_minit in the same object as these exports. A weak reference
 * alone would NOT extract an otherwise unreferenced member of a static archive.
 * Other object formats use the module handle, or explicit direct linkage. */
#if !defined(PSAMPLER_SWOOLE_STATIC) && defined(__ELF__) && defined(__GNUC__)
# pragma weak swoole_buffer_read_api_version
# pragma weak swoole_buffer_get_read_api
# define PSAMPLER_SWOOLE_WEAK 1
#endif

static zend_result bridge_acquire(zend_object *source, psampler_binary_view *view)
{
    swoole_buffer_read_view_v1 native = {0};
    zend_result result = swoole_api->acquire(source, &native);

    if (result != SUCCESS) return result;
    /* Preserve source identity and token even for invalid metadata: the binary
     * dispatcher validates and releases every successful acquisition exactly
     * once. No fallible operation between acquire and publishing this view. */
    view->source = native.source;
    view->spans[0].data = native.data;
    view->spans[0].length = native.length;
    view->spans[1].data = NULL;
    view->spans[1].length = 0;
    view->capacity = native.capacity;
    view->token = native.token;
    return SUCCESS;
}

static void bridge_release(zend_object *source, const psampler_binary_view *view)
{
    swoole_api->release(source, view->token);
}

static void bridge_resolve(zend_module_entry *module,
    swoole_version_fn *version, swoole_api_fn *get_api)
{
#if defined(PSAMPLER_SWOOLE_STATIC) || defined(PSAMPLER_SWOOLE_WEAK)
    *version = swoole_buffer_read_api_version;
    *get_api = swoole_buffer_get_read_api;
    if (*version != NULL && *get_api != NULL) return;
#endif
    *version = NULL;
    *get_api = NULL;
#ifdef DL_FETCH_SYMBOL
    /* Both DSOs have been loaded before normal module startup, even when INI
     * order puts psampler first. Resolve from Swoole's handle, including when
     * the ELF weak relocations were bound to NULL while loading psampler. */
    if (module->handle != NULL) {
        *version = (swoole_version_fn) DL_FETCH_SYMBOL(module->handle,
            "swoole_buffer_read_api_version");
        *get_api = (swoole_api_fn) DL_FETCH_SYMBOL(module->handle,
            "swoole_buffer_get_read_api");
    }
#if defined(COMPILE_DL_PSAMPLER) && defined(RTLD_DEFAULT) && !defined(PHP_WIN32)
    else {
        /* Shared psampler over a static producer needs executable exports.
         * This fallback is not used by a fully static ELF PHP. */
        *version = (swoole_version_fn) DL_FETCH_SYMBOL(RTLD_DEFAULT,
            "swoole_buffer_read_api_version");
        *get_api = (swoole_api_fn) DL_FETCH_SYMBOL(RTLD_DEFAULT,
            "swoole_buffer_get_read_api");
    }
#endif
#else
    (void) module;
#endif
}
#endif

void psampler_swoole_bridge_init(void)
{
    swoole_api = NULL;
#ifdef PSAMPLER_SWOOLE_BRIDGE
    zend_module_entry *module = zend_hash_str_find_ptr(&module_registry,
        "swoole", sizeof("swoole") - 1);
    zend_class_entry *ce;
    const swoole_buffer_read_api_v1 *api;
    swoole_version_fn version;
    swoole_api_fn get_api;
    psampler_binary_provider provider;

    bridge_status = "Swoole not loaded";
    if (module == NULL || !module->module_started) return;
    bridge_status = "Swoole Buffer class unavailable";
    ce = zend_hash_str_find_ptr(CG(class_table), "swoole\\buffer",
        sizeof("swoole\\buffer") - 1);
    if (ce == NULL || ce->type != ZEND_INTERNAL_CLASS
        || ce->info.internal.module != module) return;

    bridge_resolve(module, &version, &get_api);
    bridge_status = "Swoole read ABI symbols unavailable";
    if (version == NULL || get_api == NULL) return;
    bridge_status = "Swoole read ABI incompatible";
    if (version() != SWOOLE_BUFFER_READ_API_VERSION) return;
    api = get_api(SWOOLE_BUFFER_READ_API_VERSION, sizeof(*api));
    /* Read only the prefix before trusting the rest of this v1 descriptor. */
    if (api == NULL || api->api_version != SWOOLE_BUFFER_READ_API_VERSION
        || api->struct_size != sizeof(*api)) return;
    if (api->buffer_ce != ce || api->acquire == NULL || api->release == NULL) return;

    provider.api_version = PSAMPLER_BINARY_API_VERSION;
    provider.struct_size = sizeof(provider);
    provider.source_ce = ce;
    provider.acquire = bridge_acquire;
    provider.release = bridge_release;
    bridge_status = "Swoole provider registration refused";
    if (!psampler_binary_register_provider(&provider)) return;
    swoole_api = api;
    bridge_status = "enabled (Swoole read ABI v1)";
#else
    bridge_status = "disabled at build time";
#endif
}

void psampler_swoole_bridge_shutdown(void)
{
    if (swoole_api != NULL) {
        psampler_binary_unregister_provider(swoole_api->buffer_ce);
        swoole_api = NULL;
    }
    bridge_status = "unavailable";
}

const char *psampler_swoole_bridge_status(void)
{
    return bridge_status;
}
