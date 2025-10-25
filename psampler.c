#include "php.h"
#include "php_psampler.h"
#include <math.h>
#include <zend_smart_str.h>

static zend_class_entry *psampler_ce;
typedef struct {
    double ratio;
    double src_rate;
    double dst_rate;
    double last_dc;
    zend_object std;
} psampler_object;

#define PSAMPLER_OBJ(zv) ((psampler_object *)((char *)(Z_OBJ_P(zv)) - XtOffsetOf(psampler_object, std)))

static zend_object *psampler_create(zend_class_entry *ce)
{
    psampler_object *obj = zend_object_alloc(sizeof(psampler_object), ce);
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = &std_object_handlers;
    return &obj->std;
}

PHP_METHOD(Resampler, __construct)
{
    zend_long src, dst;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(src)
        Z_PARAM_LONG(dst)
    ZEND_PARSE_PARAMETERS_END();

    psampler_object *obj = PSAMPLER_OBJ(getThis());
    obj->src_rate = src;
    obj->dst_rate = dst;
    obj->ratio = (double)dst / (double)src;
    obj->last_dc = 0.0;
}

PHP_METHOD(Resampler, reset)
{
    psampler_object *obj = PSAMPLER_OBJ(getThis());
    obj->last_dc = 0.0;
    RETURN_TRUE;
}

PHP_METHOD(Resampler, process)
{
    zend_string *input;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(input)
    ZEND_PARSE_PARAMETERS_END();

    psampler_object *obj = PSAMPLER_OBJ(getThis());
    const int16_t *in = (const int16_t *)ZSTR_VAL(input);
    size_t in_samples = ZSTR_LEN(input) / 2;
    double step = 1.0 / obj->ratio;
    double pos = 0.0;
    smart_str out = {0};
    smart_str_alloc(&out, ceil(in_samples * obj->ratio) * 2, 0);

    for (size_t i = 0; i < (size_t)(in_samples * obj->ratio); i++) {
        size_t idx = (size_t)pos;
        double frac = pos - idx;

        int16_t y0 = (idx > 0) ? in[idx - 1] : in[idx];
        int16_t y1 = in[idx];
        int16_t y2 = (idx + 1 < in_samples) ? in[idx + 1] : y1;
        int16_t y3 = (idx + 2 < in_samples) ? in[idx + 2] : y2;

        double a0 = -0.5 * y0 + 1.5 * y1 - 1.5 * y2 + 0.5 * y3;
        double a1 = y0 - 2.5 * y1 + 2.0 * y2 - 0.5 * y3;
        double a2 = -0.5 * y0 + 0.5 * y2;
        double a3 = y1;

        double sample = ((a0 * frac + a1) * frac + a2) * frac + a3;

        obj->last_dc = 0.999 * obj->last_dc + 0.001 * sample;
        sample -= obj->last_dc;

        if (sample > 32767.0) sample = 32767.0;
        if (sample < -32768.0) sample = -32768.0;
        int16_t out_sample = (int16_t)lrint(sample);

        smart_str_appendl(&out, (char *)&out_sample, 2);
        pos += step;
    }

    smart_str_0(&out);
    RETURN_STR(out.s);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_void, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, srcRate, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, dstRate, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_process, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, pcm, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry psampler_methods[] = {
    PHP_ME(Resampler, __construct, arginfo_construct, ZEND_ACC_PUBLIC)
    PHP_ME(Resampler, reset, arginfo_void, ZEND_ACC_PUBLIC)
    PHP_ME(Resampler, process, arginfo_process, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(psampler)
{
    zend_class_entry ce;
    INIT_CLASS_ENTRY(ce, "Resampler", psampler_methods);
    psampler_ce = zend_register_internal_class(&ce);
    psampler_ce->create_object = psampler_create;
    return SUCCESS;
}

zend_module_entry psampler_module_entry = {
    STANDARD_MODULE_HEADER,
    "psampler",
    NULL,
    PHP_MINIT(psampler),
    NULL,
    NULL,
    NULL,
    NULL,
    PHP_PSAMPLER_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_PSAMPLER
ZEND_GET_MODULE(psampler)
#endif
