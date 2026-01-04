#ifndef PHP_PSAMPLER_H
#define PHP_PSAMPLER_H

extern zend_module_entry psampler_module_entry;
#define phpext_psampler_ptr &psampler_module_entry

#define PHP_PSAMPLER_VERSION "0.1.0"

#ifdef PHP_WIN32
#   define PHP_PSAMPLER_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#   define PHP_PSAMPLER_API __attribute__ ((visibility("default")))
#else
#   define PHP_PSAMPLER_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

#endif /* PHP_PSAMPLER_H */
