/* Test-only PCM v1 consumer and bailout boundary. Included by the binary fixture. */
#include "ext/psampler/psampler_native.h"
#include "Zend/zend_execute.h"

static zend_result test_pcm_lifecycle(psampler_pcm_buffer *pcm,
    uint32_t argc, zval *argv, zval *out)
{
    zend_string *mode;
    size_t i, size = psampler_pcm_size(pcm);
    const unsigned char *data = psampler_pcm_data(pcm);
    zend_long checksum = 0;
    if (argc == 0 || Z_TYPE(argv[0]) != IS_STRING) {
        zend_value_error("Test PCM mode required");
        return FAILURE;
    }
    mode = Z_STR(argv[0]);
    if (zend_string_equals_literal(mode, "hook")
        || zend_string_equals_literal(mode, "hook-failure")
        || zend_string_equals_literal(mode, "hook-bailout")) {
        if (argc < 2) return FAILURE;
        if (call_user_function(EG(function_table), NULL, &argv[1], out,
            argc - 2, argv + 2) != SUCCESS || EG(exception)) return FAILURE;
        /* Read again after PHP reentry: the method frame must keep pcm alive. */
        if (psampler_pcm_size(pcm) != size || psampler_pcm_data(pcm) != data) {
            zend_throw_error(NULL, "PCM changed during invoke");
            return FAILURE;
        }
        if (zend_string_equals_literal(mode, "hook-failure")) return FAILURE;
        if (zend_string_equals_literal(mode, "hook-bailout")) zend_bailout();
        return SUCCESS;
    }
    if (zend_string_equals_literal(mode, "inspect")) {
        if (argc != 2 || psampler_pcm_from_zval(&argv[1]) != pcm
            || psampler_register_pcm_operation("psampler.test.request", test_pcm_lifecycle)
            || psampler_unregister_pcm_operation("psampler.test.lifecycle")) {
            zend_throw_error(NULL, "PCM v1 ownership/registry contract failed");
            return FAILURE;
        }
        for (i = 0; i < size; i++) checksum += data[i];
        array_init(out);
        add_next_index_long(out, size);
        add_next_index_long(out, checksum);
        add_next_index_long(out, psampler_pcm_sample_rate(pcm));
        add_next_index_long(out, psampler_pcm_channels(pcm));
        return SUCCESS;
    }
    if (argc > 1) ZVAL_COPY(out, &argv[1]);
    if (zend_string_equals_literal(mode, "failure")) return FAILURE;
    if (zend_string_equals_literal(mode, "exception")
        || zend_string_equals_literal(mode, "exception-success")) {
        zend_throw_exception(zend_ce_exception, "test PCM exception", 0);
        return zend_string_equals_literal(mode, "exception") ? FAILURE : SUCCESS;
    }
    if (zend_string_equals_literal(mode, "bailout")) zend_bailout();
    if (zend_string_equals_literal(mode, "oom")) {
        /* Deterministic failure under the PHPT memory_limit, before assignment. */
        ZVAL_STR(out, zend_string_alloc(64 * 1024 * 1024, 0));
    }
    return SUCCESS;
}

PHP_FUNCTION(psampler_test_bailout)
{
    ZEND_PARSE_PARAMETERS_NONE();
    zend_bailout();
}

PHP_FUNCTION(psampler_pcm_test_catch)
{
    zval *source, *args;
    uint32_t argc, i;
    zend_execute_data *call, *previous = EG(current_execute_data);
    zend_function *method;
    zend_class_entry *pcm_ce = zend_hash_str_find_ptr(CG(class_table), "pcmbuffer", sizeof("pcmbuffer") - 1);
    zval *out;
    volatile zend_bool caught = 0;
    ZEND_PARSE_PARAMETERS_START(2, -1)
        Z_PARAM_OBJECT_OF_CLASS(source, pcm_ce)
        Z_PARAM_VARIADIC('+', args, argc)
    ZEND_PARSE_PARAMETERS_END();
    method = zend_hash_str_find_ptr(&Z_OBJCE_P(source)->function_table, "invoke", sizeof("invoke") - 1);
    /* Call the real internal method in a fixture-owned frame. No PHP VM frames
     * are skipped by the intercepted longjmp, so their args can be freed here.
     * Both the frame and result live outside this function's setjmp locals. */
    call = zend_vm_stack_push_call_frame(ZEND_CALL_HAS_THIS, method, argc, Z_OBJ_P(source));
    call->prev_execute_data = previous;
    for (i = 0; i < argc; i++) ZVAL_COPY(ZEND_CALL_ARG(call, i + 1), &args[i]);
    out = emalloc(sizeof(*out));
    ZVAL_NULL(out);
    EG(current_execute_data) = call;
    zend_try {
        method->internal_function.handler(call, out);
    } zend_catch {
        caught = 1;
    } zend_end_try();
    EG(current_execute_data) = previous;
    array_init(return_value);
    add_next_index_bool(return_value, caught);
    add_next_index_bool(return_value, Z_TYPE_P(out) == IS_NULL);
    zval_ptr_dtor(out);
    efree(out);
    zend_vm_stack_free_args(call);
    zend_vm_stack_free_call_frame(call);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pcm_test_catch, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, source, PcmBuffer, 0)
    ZEND_ARG_VARIADIC_INFO(0, args)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_test_bailout, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
