#ifndef PSAMPLER_NATIVE_H
#define PSAMPLER_NATIVE_H

#include "php.h"
#include <stddef.h>
#include <stdint.h>

#define PSAMPLER_NATIVE_API_VERSION 1

/* Export from psampler; import from consumers on Windows. */
#ifdef PHP_WIN32
# ifdef PSAMPLER_NATIVE_BUILD
#  define PSAMPLER_NATIVE_API __declspec(dllexport)
# else
#  define PSAMPLER_NATIVE_API __declspec(dllimport)
# endif
#elif defined(__GNUC__) && __GNUC__ >= 4
# define PSAMPLER_NATIVE_API __attribute__((visibility("default")))
#else
# define PSAMPLER_NATIVE_API
#endif

#ifdef __cplusplus
extern "C" {
#endif

/* Opaque, borrowed object handle. Never retain beyond the synchronous call. */
typedef struct _psampler_pcm_buffer psampler_pcm_buffer;

/* argv and pcm are borrowed; return_value belongs to Zend. The handler must
 * initialize the result on SUCCESS, and throw on FAILURE. No PCM ownership
 * transfer. PCM mutation/reentrant invoke on this object is blocked in invoke.
 * A handler may retain a PHP object only with normal Zend reference ownership;
 * it must reacquire its native handle/data on every later use. */
typedef zend_result (*psampler_pcm_operation_handler)(psampler_pcm_buffer *pcm,
    uint32_t argc, zval *argv, zval *return_value);

PSAMPLER_NATIVE_API uint32_t psampler_native_api_version(void);
/* Case-sensitive nonempty C names. Duplicate names/NULL handlers fail.
 * Register only during process startup (consumer MINIT after psampler MINIT).
 * Unregister only during startup or process shutdown (consumer MSHUTDOWN).
 * Consumers must declare ZEND_MOD_REQUIRED("psampler") and must not dl/unload
 * at request time. All registry mutations occur on the startup/shutdown thread;
 * requests, including ZTS workers, perform concurrent reads only. */
PSAMPLER_NATIVE_API zend_bool psampler_register_pcm_operation(const char *name,
    psampler_pcm_operation_handler handler);
PSAMPLER_NATIVE_API zend_bool psampler_unregister_pcm_operation(const char *name);

/* Returns NULL for a non-PcmBuffer or an object whose constructor was bypassed.
 * Getters require a valid handle. data may be NULL when size == 0. Data is signed
 * PCM16LE, interleaved for stereo; never write/free it. Borrowed data becomes
 * invalid on mutation/destruction; during invoke it is stable until return. */
PSAMPLER_NATIVE_API psampler_pcm_buffer *psampler_pcm_from_zval(zval *value);
PSAMPLER_NATIVE_API const unsigned char *psampler_pcm_data(const psampler_pcm_buffer *pcm);
PSAMPLER_NATIVE_API size_t psampler_pcm_size(const psampler_pcm_buffer *pcm);
PSAMPLER_NATIVE_API uint32_t psampler_pcm_sample_rate(const psampler_pcm_buffer *pcm);
PSAMPLER_NATIVE_API uint16_t psampler_pcm_channels(const psampler_pcm_buffer *pcm);

#ifdef __cplusplus
}
#endif

#endif
