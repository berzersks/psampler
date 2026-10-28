/* TEST ONLY alternative mode of the EXISTING fixture module. Do not load with
 * real Swoole. No production source includes this file. The same module entry
 * and test object implementation are reused, with no psampler startup calls. */
#define SWOOLE_BUFFER_C_API_BUILD
#include "../../swoole_buffer_c_api.h"
#include <stdlib.h>

static swoole_buffer_read_api_v1 fixture_swoole_api;
static const char *fixture_swoole_mode;

static zend_bool fixture_mode(const char *mode)
{
    return fixture_swoole_mode != NULL && strcmp(fixture_swoole_mode, mode) == 0;
}

static zend_result fixture_swoole_acquire(zend_object *source, swoole_buffer_read_view_v1 *view)
{
    psampler_binary_view binary = {0};
    zend_result result = test_acquire(source, &binary);
    if (result != SUCCESS) return result;
    view->source = binary.source;
    view->data = binary.spans[0].data;
    view->length = binary.spans[0].length;
    view->capacity = binary.capacity;
    view->token = binary.token;
    return SUCCESS;
}

static void fixture_swoole_release(zend_object *source, void *token)
{
    psampler_binary_view binary = {0};
    binary.token = token;
    test_release(source, &binary);
}

SWOOLE_BUFFER_C_API uint32_t swoole_buffer_read_api_version(void)
{
    return fixture_mode("version") ? 2 : SWOOLE_BUFFER_READ_API_VERSION;
}

SWOOLE_BUFFER_C_API const swoole_buffer_read_api_v1 *swoole_buffer_get_read_api(
    uint32_t version, size_t expected_size)
{
    if (version != SWOOLE_BUFFER_READ_API_VERSION || expected_size != sizeof(fixture_swoole_api)
        || fixture_mode("null-table")) return NULL;
    return &fixture_swoole_api;
}

PHP_MINIT_FUNCTION(psampler_binary_test)
{
    zend_class_entry ce;
    fixture_swoole_mode = getenv("PSAMPLER_SWOOLE_TEST_ABI_MODE");
    memcpy(&test_handlers, &std_object_handlers, sizeof(test_handlers));
    test_handlers.offset = XtOffsetOf(test_buffer, std);
    test_handlers.free_obj = test_free;
    test_handlers.clone_obj = NULL;
    INIT_CLASS_ENTRY(ce, "Swoole\\Buffer", test_methods);
    test_ce = zend_register_internal_class(&ce);
    test_ce->create_object = test_create;
    test_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NOT_SERIALIZABLE;
    fixture_swoole_api = (swoole_buffer_read_api_v1) {
        SWOOLE_BUFFER_READ_API_VERSION, sizeof(fixture_swoole_api),
        test_ce, fixture_swoole_acquire, fixture_swoole_release
    };
    if (fixture_mode("table-version")) fixture_swoole_api.api_version++;
    if (fixture_mode("table-size")) fixture_swoole_api.struct_size--;
    if (fixture_mode("null-class")) fixture_swoole_api.buffer_ce = NULL;
    if (fixture_mode("foreign-class")) fixture_swoole_api.buffer_ce = zend_standard_class_def;
    if (fixture_mode("null-acquire")) fixture_swoole_api.acquire = NULL;
    if (fixture_mode("null-release")) fixture_swoole_api.release = NULL;
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(psampler_binary_test)
{
    return SUCCESS;
}

static const zend_module_dep test_deps[] = { ZEND_MOD_END };
