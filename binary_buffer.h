#ifndef PSAMPLER_BINARY_BUFFER_H
#define PSAMPLER_BINARY_BUFFER_H

#include "php.h"

PHP_FUNCTION(psampler_binary_slice);

void psampler_binary_registry_init(void);
void psampler_binary_registry_shutdown(void);

#endif
