/* TEST ONLY. Never linked into psampler. Some modes intentionally return bad
 * descriptors; "huge" may ONLY be used to test destination size overflow. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php.h"
#include "ext/psampler/psampler_binary.h"
#include "Zend/zend_exceptions.h"
#include <string.h>
#include "pcm_lifecycle.inc"

typedef struct {
    unsigned char *data;
    size_t length;
    zend_string *mode;
    zend_bool reading;
    zend_long acquired, released;
    zend_object std;
} test_buffer;

static zend_class_entry *test_ce;
static zend_object_handlers test_handlers;

static test_buffer *test_from_object(zend_object *object)
{
    return (test_buffer *) ((char *) object - XtOffsetOf(test_buffer, std));
}

static zend_bool mode_is(const test_buffer *buffer, const char *name)
{
    return zend_string_equals_cstr(buffer->mode, name, strlen(name));
}

static zend_object *test_create(zend_class_entry *ce)
{
    test_buffer *buffer = zend_object_alloc(sizeof(*buffer), ce);
    zend_object_std_init(&buffer->std, ce);
    object_properties_init(&buffer->std, ce);
    buffer->std.handlers = &test_handlers;
    return &buffer->std;
}

static void test_free(zend_object *object)
{
    test_buffer *buffer = test_from_object(object);
    if (buffer->data != NULL) efree(buffer->data);
    if (buffer->mode != NULL) zend_string_release(buffer->mode);
    zend_object_std_dtor(object);
}

static zend_result test_acquire(zend_object *source, psampler_binary_view *view)
{
    test_buffer *buffer;
    size_t split;
    if (source->ce != test_ce || source->handlers != &test_handlers) {
        zend_value_error("Invalid test source");
        return FAILURE;
    }
    buffer = test_from_object(source);
    if (buffer->data == NULL || buffer->reading || mode_is(buffer, "busy")) {
        zend_throw_error(NULL, "Test source uninitialized or busy");
        return FAILURE;
    }
    if (mode_is(buffer, "failure")) return FAILURE; /* tests missing exception */
    if (mode_is(buffer, "acquire-bailout")) zend_bailout();
    buffer->reading = 1;
    buffer->acquired++;
    view->source = source;
    view->capacity = buffer->length;
    view->token = buffer;
    split = mode_is(buffer, "split") ? buffer->length / 2 : buffer->length;
    view->spans[0].data = buffer->data;
    view->spans[0].length = split;
    view->spans[1].data = buffer->data + split;
    view->spans[1].length = buffer->length - split;
    if (mode_is(buffer, "second-only")) {
        view->spans[0].data = NULL;
        view->spans[0].length = 0;
        view->spans[1].data = buffer->data;
        view->spans[1].length = buffer->length;
    } else if (mode_is(buffer, "empty-null")) {
        view->spans[0].data = view->spans[1].data = NULL;
        view->spans[0].length = view->spans[1].length = view->capacity = 0;
    } else if (mode_is(buffer, "null")) {
        view->spans[0].data = NULL;
        view->spans[0].length = view->capacity = 1;
        view->spans[1].length = 0;
    } else if (mode_is(buffer, "capacity")) {
        view->capacity = 0;
        view->spans[0].length = 1;
    } else if (mode_is(buffer, "second-overflow")) {
        view->capacity = view->spans[0].length = view->spans[1].length = 1;
    } else if (mode_is(buffer, "overflow")) {
        view->capacity = (size_t) ZEND_LONG_MAX;
        view->spans[0].length = view->spans[1].length = SIZE_MAX;
    } else if (mode_is(buffer, "capacity-overflow")) {
        view->capacity = SIZE_MAX;
    } else if (mode_is(buffer, "source")) {
        view->source = NULL;
    } else if (mode_is(buffer, "huge")) {
        view->capacity = view->spans[0].length = (size_t) ZEND_LONG_MAX;
        view->spans[1].length = 0;
    } else if (mode_is(buffer, "exception-success")) {
        zend_throw_exception(zend_ce_exception, "test acquisition exception", 0);
    }
    return SUCCESS;
}

static void test_release(zend_object *source, const psampler_binary_view *view)
{
    test_buffer *buffer = test_from_object(source);
    (void) view;
    buffer->reading = 0;
    buffer->released++;
}

PHP_METHOD(PsamplerBinaryTestBuffer, __construct)
{
    zend_string *data, *mode = NULL, *new_mode;
    test_buffer *buffer;
    unsigned char *copy;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(data)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR(mode)
    ZEND_PARSE_PARAMETERS_END();
    buffer = test_from_object(Z_OBJ_P(getThis()));
    if (buffer->reading) {
        zend_throw_error(NULL, "Test source cannot mutate during a native read");
        RETURN_THROWS();
    }
    copy = emalloc(ZSTR_LEN(data) == 0 ? 1 : ZSTR_LEN(data));
    if (ZSTR_LEN(data) != 0) memcpy(copy, ZSTR_VAL(data), ZSTR_LEN(data));
    new_mode = mode != NULL ? zend_string_copy(mode) : zend_string_init("valid", 5, 0);
    if (buffer->data != NULL) efree(buffer->data);
    if (buffer->mode != NULL) zend_string_release(buffer->mode);
    buffer->data = copy;
    buffer->length = ZSTR_LEN(data);
    buffer->mode = new_mode;
}

PHP_METHOD(PsamplerBinaryTestBuffer, stats)
{
    test_buffer *buffer;
    ZEND_PARSE_PARAMETERS_NONE();
    buffer = test_from_object(Z_OBJ_P(getThis()));
    array_init(return_value);
    add_next_index_long(return_value, buffer->acquired);
    add_next_index_long(return_value, buffer->released);
    add_next_index_bool(return_value, buffer->reading);
}

typedef struct {
    zval *hook;
    zend_string *outcome;
    size_t length;
    zend_long checksum, spans;
} read_context;

static zend_result test_read_view(const psampler_binary_view *view, void *opaque)
{
    read_context *context = opaque;
    unsigned int i;
    size_t j;
    if (context->hook != NULL && Z_TYPE_P(context->hook) != IS_NULL) {
        zval result;
        ZVAL_UNDEF(&result);
        if (call_user_function(EG(function_table), NULL, context->hook, &result, 0, NULL) != SUCCESS) {
            if (!EG(exception)) zend_throw_error(NULL, "Test hook failed");
        }
        if (!Z_ISUNDEF(result)) zval_ptr_dtor(&result);
        if (EG(exception)) return FAILURE;
    }
    if (context->outcome != NULL) {
        if (zend_string_equals_literal(context->outcome, "failure")) return FAILURE;
        if (zend_string_equals_literal(context->outcome, "exception")) {
            zend_throw_exception(zend_ce_exception, "test reader exception", 0);
            return FAILURE;
        }
        if (zend_string_equals_literal(context->outcome, "exception-success")) {
            zend_throw_exception(zend_ce_exception, "test reader exception", 0);
            return SUCCESS;
        }
    }
    /* Inspect the borrowed memory directly: this reader creates no string. */
    for (i = 0; i < 2; i++) {
        if (view->spans[i].length != 0) context->spans++;
        context->length += view->spans[i].length;
        for (j = 0; j < view->spans[i].length; j++) {
            context->checksum = (context->checksum + view->spans[i].data[j]) % 65521;
        }
    }
    return SUCCESS;
}

PHP_FUNCTION(psampler_binary_test_read)
{
    zval *source, *hook = NULL;
    zend_string *outcome = NULL;
    read_context context = {0};
    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_OBJECT(source)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(hook)
        Z_PARAM_STR(outcome)
    ZEND_PARSE_PARAMETERS_END();
    context.hook = hook;
    context.outcome = outcome;
    if (psampler_binary_with_view(source, test_read_view, &context) != SUCCESS) RETURN_THROWS();
    array_init(return_value);
    add_assoc_long(return_value, "length", (zend_long) context.length);
    add_assoc_long(return_value, "checksum", context.checksum);
    add_assoc_long(return_value, "spans", context.spans);
}

static zend_result test_bailout_view(const psampler_binary_view *view, void *context)
{
    zend_bailout();
}

PHP_FUNCTION(psampler_binary_test_catch)
{
    zval *source;
    zend_object *object;
    uint32_t refs;
    volatile zend_bool caught = 0;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT(source)
    ZEND_PARSE_PARAMETERS_END();
    object = Z_OBJ_P(source);
    refs = GC_REFCOUNT(object);
    zend_try {
        psampler_binary_with_view(source, test_bailout_view, NULL);
    } zend_catch {
        caught = 1;
    } zend_end_try();
    array_init(return_value);
    add_next_index_bool(return_value, caught);
    add_next_index_bool(return_value, GC_REFCOUNT(object) == refs);
}

static psampler_binary_provider test_provider(void)
{
    psampler_binary_provider provider = {
        PSAMPLER_BINARY_API_VERSION, sizeof(psampler_binary_provider),
        test_ce, test_acquire, test_release
    };
    return provider;
}

PHP_FUNCTION(psampler_binary_test_registry_locked)
{
    psampler_binary_provider provider = test_provider();
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(!psampler_binary_register_provider(&provider)
        && !psampler_binary_unregister_provider(test_ce));
}

PHP_FUNCTION(psampler_binary_test_uninitialized)
{
    zval *prototype;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT(prototype)
    ZEND_PARSE_PARAMETERS_END();
    if (!psampler_binary_source_supported(prototype)) {
        zend_value_error("Test prototype must have a registered provider");
        RETURN_THROWS();
    }
    /* Reflection deliberately forbids bypassing constructors of final
     * internal classes with custom allocation. Only this C fixture can do it. */
    if (object_init_ex(return_value, Z_OBJCE_P(prototype)) != SUCCESS) RETURN_THROWS();
}

static zend_result test_drop_owner(const psampler_binary_view *view, void *opaque)
{
    zval *owner = opaque;
    zval_ptr_dtor(owner);
    ZVAL_UNDEF(owner);
    /* with_view must be the sole remaining owner here. ASan should detect any
     * lifetime bug in these reads when this fixture is eventually executed. */
    if (view->source->ce != test_ce || view->spans[0].length != 1
        || view->spans[0].data[0] != 0xa5) {
        zend_throw_error(NULL, "Source was not pinned correctly");
        return FAILURE;
    }
    return SUCCESS;
}

PHP_FUNCTION(psampler_binary_test_pin)
{
    zval source;
    test_buffer *buffer;
    zend_result result;
    ZEND_PARSE_PARAMETERS_NONE();
    object_init_ex(&source, test_ce);
    buffer = test_from_object(Z_OBJ(source));
    buffer->data = emalloc(1);
    buffer->data[0] = 0xa5;
    buffer->length = 1;
    buffer->mode = zend_string_init("valid", 5, 0);
    result = psampler_binary_with_view(&source, test_drop_owner, &source);
    if (!Z_ISUNDEF(source)) zval_ptr_dtor(&source);
    if (result != SUCCESS) RETURN_THROWS();
    RETURN_TRUE;
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_test_construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, mode, IS_STRING, 0, "\"valid\"")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_test_stats, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_test_read, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, source, IS_OBJECT, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, hook, IS_CALLABLE, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, outcome, IS_STRING, 0, "\"success\"")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_test_locked, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_test_uninitialized, 0, 1, IS_OBJECT, 0)
    ZEND_ARG_TYPE_INFO(0, prototype, IS_OBJECT, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_test_catch, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, source, IS_OBJECT, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry test_methods[] = {
    PHP_ME(PsamplerBinaryTestBuffer, __construct, arginfo_test_construct, ZEND_ACC_PUBLIC)
    PHP_ME(PsamplerBinaryTestBuffer, stats, arginfo_test_stats, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
static const zend_function_entry test_functions[] = {
    PHP_FE(psampler_binary_test_catch, arginfo_test_catch)
    PHP_FE(psampler_pcm_test_catch, arginfo_pcm_test_catch)
    PHP_FE(psampler_test_bailout, arginfo_test_bailout)
    PHP_FE(psampler_binary_test_read, arginfo_test_read)
    PHP_FE(psampler_binary_test_registry_locked, arginfo_test_locked)
    PHP_FE(psampler_binary_test_pin, arginfo_test_locked)
    PHP_FE(psampler_binary_test_uninitialized, arginfo_test_uninitialized)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(psampler_binary_test)
{
    zend_class_entry ce;
    psampler_binary_provider provider;
    if (psampler_binary_api_version() != PSAMPLER_BINARY_API_VERSION) return FAILURE;
    if (psampler_native_api_version() != PSAMPLER_NATIVE_API_VERSION
        || !psampler_register_pcm_operation("psampler.test.lifecycle", test_pcm_lifecycle)) return FAILURE;
    memcpy(&test_handlers, &std_object_handlers, sizeof(test_handlers));
    test_handlers.offset = XtOffsetOf(test_buffer, std);
    test_handlers.free_obj = test_free;
    test_handlers.clone_obj = NULL;
    INIT_CLASS_ENTRY(ce, "PsamplerBinaryTestBuffer", test_methods);
    test_ce = zend_register_internal_class(&ce);
    test_ce->create_object = test_create;
    test_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NOT_SERIALIZABLE;
    provider = test_provider();
    if (psampler_binary_register_provider(NULL)) return FAILURE;
    provider.api_version++;
    if (psampler_binary_register_provider(&provider)) return FAILURE;
    provider = test_provider();
    provider.struct_size--;
    if (psampler_binary_register_provider(&provider)) return FAILURE;
    provider = test_provider();
    provider.acquire = NULL;
    if (psampler_binary_register_provider(&provider)) return FAILURE;
    provider = test_provider();
    provider.release = NULL;
    if (psampler_binary_register_provider(&provider)) return FAILURE;
    provider = test_provider();
    provider.source_ce = NULL;
    if (psampler_binary_register_provider(&provider)) return FAILURE;
    provider = test_provider();
    if (!psampler_binary_register_provider(&provider)) return FAILURE;
    if (psampler_binary_register_provider(&provider)) {
        psampler_binary_unregister_provider(test_ce);
        return FAILURE;
    }
    if (!psampler_binary_unregister_provider(test_ce)) return FAILURE;
    if (psampler_binary_unregister_provider(test_ce)) return FAILURE;
    if (!psampler_binary_register_provider(&provider)) return FAILURE;
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(psampler_binary_test)
{
    if (!psampler_unregister_pcm_operation("psampler.test.lifecycle")) return FAILURE;
    return psampler_binary_unregister_provider(test_ce) ? SUCCESS : FAILURE;
}

static const zend_module_dep test_deps[] = {
    ZEND_MOD_REQUIRED("psampler")
    ZEND_MOD_END
};
zend_module_entry psampler_binary_test_module_entry = {
    STANDARD_MODULE_HEADER_EX, NULL, test_deps,
    "psampler_binary_test", test_functions,
    PHP_MINIT(psampler_binary_test), PHP_MSHUTDOWN(psampler_binary_test),
    NULL, NULL, NULL, "0.0.0", STANDARD_MODULE_PROPERTIES
};
#ifdef COMPILE_DL_PSAMPLER_BINARY_TEST
ZEND_GET_MODULE(psampler_binary_test)
#endif
