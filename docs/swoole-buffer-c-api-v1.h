/* PROPOSAL ONLY: not implemented by pcg729 v1.1.3, not a psampler header,
 * not installed or linked by this repository. Producer-owned public C ABI
 * required for the optional bridge described in native-buffers.md. */
#ifndef SWOOLE_BUFFER_READ_C_API_V1_H
#define SWOOLE_BUFFER_READ_C_API_V1_H

#include "php.h"
#include <stddef.h>
#include <stdint.h>

#define SWOOLE_BUFFER_READ_API_VERSION 1
#ifdef PHP_WIN32
# ifdef SWOOLE_BUFFER_C_API_BUILD
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

/* Returns NULL for unsupported version/size, or before producer MINIT.
 * Table/class/functions immutable and valid through dependent MSHUTDOWN.
 * acquire checks exact class+handlers BEFORE its private-layout access,
 * initialization, length <= capacity <= ZEND_LONG_MAX, data != NULL for
 * nonempty data, no pending receive or other borrow. On SUCCESS it takes a
 * per-object read guard and fills ALL fields. On FAILURE it throws and leaves
 * no guard. Caller pins source before acquisition until after release.
 * release accepts the token from exactly one successful acquire and clears
 * the guard, without allocating, throwing, yielding or calling PHP.
 * acquire is also non-yielding and cannot call PHP. No ownership transfer.
 * Every mutation/receive must reject an active read BEFORE changing length,
 * storage, metadata or yielding; guard must cover the private overflow byte.
 * Request-local objects cannot be concurrently shared with other threads.
 * See native-buffers.md for the complete proposed lifecycle contract. */
SWOOLE_BUFFER_C_API const swoole_buffer_read_api_v1 *swoole_buffer_get_read_api(
    uint32_t api_version, size_t expected_struct_size);

#ifdef __cplusplus
}
#endif
#endif
