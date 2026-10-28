#ifndef PSAMPLER_BINARY_H
#define PSAMPLER_BINARY_H

#include "php.h"
#include <stddef.h>
#include <stdint.h>

#define PSAMPLER_BINARY_API_VERSION 1

#ifdef PHP_WIN32
# ifdef PSAMPLER_BINARY_BUILD
#  define PSAMPLER_BINARY_API __declspec(dllexport)
# else
#  define PSAMPLER_BINARY_API __declspec(dllimport)
# endif
#elif defined(__GNUC__) && __GNUC__ >= 4
# define PSAMPLER_BINARY_API __attribute__((visibility("default")))
#else
# define PSAMPLER_BINARY_API
#endif

#ifdef __cplusplus
extern "C" {
#endif

typedef struct {
    const unsigned char *data;
    size_t length;
} psampler_binary_span;

/* Logical bytes are spans[0] followed by spans[1], without a terminator.
 * Empty spans may have NULL data. capacity and the sum of lengths must fit
 * ZEND_LONG_MAX. source must be the exact object passed to acquire().
 * token belongs exclusively to the producer; consumers must not interpret it.
 * All pointers, including this descriptor, are borrowed for this call only. */
typedef struct {
    zend_object *source;
    psampler_binary_span spans[2];
    size_t capacity;
    void *token;
} psampler_binary_view;

typedef zend_result (*psampler_binary_acquire_handler)(zend_object *source,
    psampler_binary_view *view);
typedef void (*psampler_binary_release_handler)(zend_object *source,
    const psampler_binary_view *view);
typedef zend_result (*psampler_binary_read_handler)(const psampler_binary_view *view,
    void *context);

/* Register only in MINIT, after psampler MINIT. Exact internal class only;
 * no inherited layout assumptions. Descriptor copied, no ownership transfer.
 * acquire validates initialization, handlers, length/capacity, and absence of
 * a pending writer; on SUCCESS it blocks ALL mutations until release.
 * FAILURE must throw and undo any locks itself (release is not called).
 * release is called exactly once per successful acquire, even on invalid
 * metadata, handler exceptions or handler bailout. It must not throw, allocate,
 * yield or call PHP. acquire must not call PHP or yield either.
 * Providers are trusted native code: psampler cannot prove pointer validity.
 * Neither callback may retain this view or its borrowed pointers. */
typedef struct {
    uint32_t api_version;
    size_t struct_size;
    zend_class_entry *source_ce;
    psampler_binary_acquire_handler acquire;
    psampler_binary_release_handler release;
} psampler_binary_provider;

PSAMPLER_BINARY_API uint32_t psampler_binary_api_version(void);
PSAMPLER_BINARY_API zend_bool psampler_binary_register_provider(
    const psampler_binary_provider *provider);
/* Only startup/shutdown; unregister only your own class, before code unload. */
PSAMPLER_BINARY_API zend_bool psampler_binary_unregister_provider(zend_class_entry *source_ce);
/* Capability check only: does not acquire or validate current object state. */
PSAMPLER_BINARY_API zend_bool psampler_binary_source_supported(zval *source);
/* Pins the source object, acquires/validates bytes, calls handler synchronously,
 * releases the borrow, then releases the object. No byte copy. FAILURE throws.
 * handler/context cannot retain pointers, mutate bytes, yield, or transfer them
 * to another thread. Handler must return SUCCESS or FAILURE with an exception.
 * It may reenter PHP synchronously; producer must enforce its mutation guard.
 * Requests (including ZTS) read an immutable process registry. Objects and
 * per-call state belong to the calling request/thread; no cross-thread sharing.
 * No request-time dl/unload. Providers/consumers stay loaded until MSHUTDOWN.
 * See docs/native-buffers.md for lifecycle and optional Swoole bridge. */
PSAMPLER_BINARY_API zend_result psampler_binary_with_view(zval *source,
    psampler_binary_read_handler handler, void *context);

#ifdef __cplusplus
}
#endif
#endif
