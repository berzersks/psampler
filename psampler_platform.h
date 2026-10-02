#ifndef PSAMPLER_PLATFORM_H
#define PSAMPLER_PLATFORM_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/* Only host services differ. No DSP or buffer policy belongs in this adapter. */
#ifndef PSAMPLER_STANDALONE
#include "php.h"
#include "Zend/zend_exceptions.h"
typedef zend_result psampler_result;
#define PSAMPLER_SUCCESS SUCCESS
#define PSAMPLER_FAILURE FAILURE
#define PSAMPLER_LONG_MAX ZEND_LONG_MAX
#define PSAMPLER_STRING_MAX ZSTR_MAX_LEN
#define PSAMPLER_ASSERT ZEND_ASSERT
#define PSAMPLER_UNEXPECTED UNEXPECTED
#define psampler_alloc emalloc
#define psampler_calloc ecalloc
#define psampler_free_mem efree
#define psampler_palloc pemalloc
#define psampler_pfree pefree
#define psampler_value_error zend_value_error
#define psampler_throw_error zend_throw_error
#ifdef ZTS
#include "TSRM.h"
#define PSAMPLER_DSP_THREADS
#define psampler_mutex MUTEX_T
#define psampler_mutex_alloc tsrm_mutex_alloc
#define psampler_mutex_free tsrm_mutex_free
#define psampler_mutex_lock tsrm_mutex_lock
#define psampler_mutex_unlock tsrm_mutex_unlock
#endif
#else
#include <assert.h>
#include <limits.h>
#include <stdlib.h>
#include <stdio.h>
typedef enum { PSAMPLER_SUCCESS = 0, PSAMPLER_FAILURE = -1 } psampler_result;
#define PSAMPLER_LONG_MAX LONG_MAX
/* The effective limit is LONG_MAX on the supported 64-bit benchmark host. */
#define PSAMPLER_STRING_MAX SIZE_MAX
#define PSAMPLER_ASSERT assert
#define PSAMPLER_UNEXPECTED(x) __builtin_expect(!!(x), 0)
static inline void *psampler_alloc(size_t n)
{
    void *p = malloc(n);
    if (!p) { fputs("ERROR: out of memory\n", stderr); exit(1); }
    return p;
}
static inline void *psampler_calloc(size_t n, size_t size)
{
    void *p = calloc(n, size);
    if (!p) { fputs("ERROR: out of memory\n", stderr); exit(1); }
    return p;
}
#define psampler_free_mem free
#define psampler_palloc(n, persistent) psampler_alloc(n)
#define psampler_pfree(p, persistent) free(p)
#define psampler_value_error(message) fprintf(stderr, "ERROR: %s\n", message)
#define psampler_throw_error(unused, message) psampler_value_error(message)
#ifdef PSAMPLER_DSP_THREADS
#include <pthread.h>
typedef pthread_mutex_t *psampler_mutex;
static inline psampler_mutex psampler_mutex_alloc(void)
{
    psampler_mutex m = psampler_alloc(sizeof(*m));
    if (pthread_mutex_init(m, NULL)) abort();
    return m;
}
static inline void psampler_mutex_free(psampler_mutex m)
{
    pthread_mutex_destroy(m);
    free(m);
}
#define psampler_mutex_lock pthread_mutex_lock
#define psampler_mutex_unlock pthread_mutex_unlock
#endif
#endif
#endif
