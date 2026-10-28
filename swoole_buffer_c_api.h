/* Public Swoole Buffer read ABI. Shares the PHP/Zend ABI of the host process. */
#ifndef SWOOLE_BUFFER_READ_C_API_V1_H
#define SWOOLE_BUFFER_READ_C_API_V1_H

#ifdef __cplusplus
extern "C" {
#endif
#include "php.h"
#include <stddef.h>
#include <stdint.h>
#ifdef __cplusplus
}
#endif

#define SWOOLE_BUFFER_READ_API_VERSION 1
#ifdef PHP_WIN32
# if defined(SWOOLE_BUFFER_C_API_STATIC)
#  define SWOOLE_BUFFER_C_API
# elif defined(SWOOLE_BUFFER_C_API_BUILD)
#  define SWOOLE_BUFFER_C_API __declspec(dllexport)
# else
#  define SWOOLE_BUFFER_C_API __declspec(dllimport)
# endif
#elif defined(__GNUC__) && __GNUC__ >= 4
# define SWOOLE_BUFFER_C_API __attribute__((visibility("default")))
#else
# define SWOOLE_BUFFER_C_API
#endif

#ifdef __cplusplus
extern "C" {
#endif

typedef struct {
    zend_object *source;
    const unsigned char *data;
    size_t length;
    size_t capacity;
    void *token;
} swoole_buffer_read_view_v1;

typedef struct {
    uint32_t api_version;
    size_t struct_size;
    zend_class_entry *buffer_ce;
    zend_result (*acquire)(zend_object *source, swoole_buffer_read_view_v1 *view);
    void (*release)(zend_object *source, void *token);
} swoole_buffer_read_api_v1;

/* Exact v1 proposal layout. NULL for unsupported version/size or outside the
 * producer's module lifetime. Table immutable from MINIT through dependent
 * MSHUTDOWN. Cache once at consumer MINIT, with a required swoole dependency.
 *
 * Low-level protocol for trusted native adapters (e.g. a provider registry):
 * caller MUST pin source before acquire until AFTER release, even on bailout.
 * acquire checks exact class/handlers before private access, construction,
 * length <= capacity <= ZEND_LONG_MAX, and absence of receive/another borrow.
 * SUCCESS fills every field, takes an additional producer reference and blocks
 * mutations and Fiber switches. FAILURE throws, without acquiring any guard.
 * release consumes exactly one matching source/token, on the acquiring thread;
 * no allocation, PHP calls, exceptions or suspension. The caller's pin ensures
 * dropping the producer reference cannot destroy the object in release.
 *
 * All fields/pointers are borrowed until release only; never retain, modify,
 * free, share across threads, or use after release. No transfer of ownership.
 * Read operations are request-time only, on live objects owned by the caller.
 * No coroutine/Fiber suspension or async work is permitted in this interval.
 * Swoole coroutine switching during a borrow is a fatal contract violation
 * (bailout before switching); callers MUST release in their zend_catch path.
 * Invalid/stale release tokens are ignored; they grant no access to bytes.
 * Tokens are opaque, never dereference them. NULL data is valid for length 0.
 * The private overflow byte is not part of the view. Request objects and guards
 * are thread-affine, including ZTS; this is not a cross-thread locking API.
 */
SWOOLE_BUFFER_C_API const swoole_buffer_read_api_v1 *swoole_buffer_get_read_api(
    uint32_t api_version, size_t expected_struct_size);
SWOOLE_BUFFER_C_API uint32_t swoole_buffer_read_api_version(void);

typedef zend_result (*swoole_buffer_read_handler_v1)(
    const swoole_buffer_read_view_v1 *view, void *context);

/* Preferred synchronous reader. Pins/acquires, validates offset and length by
 * subtraction, invokes handler once, releases, then unpins, including exception
 * and Zend bailout paths. length == SIZE_MAX means the remaining valid bytes.
 * An empty slice at the end is valid. capacity remains the source capacity;
 * length/data describe only the requested slice. No payload copy or string.
 * The callback may reenter PHP synchronously, but cannot release the token,
 * yield, retain pointers or throw C++ exceptions across the C boundary.
 * FAILURE always has a pending exception. context remains caller-owned.
 */
SWOOLE_BUFFER_C_API zend_result swoole_buffer_with_read_view_v1(
    zval *source, size_t offset, size_t length,
    swoole_buffer_read_handler_v1 handler, void *context);

#ifdef __cplusplus
}
#endif
#endif
