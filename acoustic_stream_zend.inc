/* Included by psampler.c; Zend adapter only, all acoustic work stays native. */
#include "acoustic_stream.h"
#define AS_MAX_PUSH 240000
#define AS_MAX_QUEUED ((AS_MAX_PUSH / 320 + 1) * ACOUSTIC_EVENTS)
typedef struct {
    acoustic_stream core;
    zval callbacks, argument, result;
    acoustic_event *queue;
    size_t queued, queue_capacity;
    zend_bool busy;
    zend_object std;
} acoustic_object;
static zend_class_entry *acoustic_ce;
static zend_object_handlers acoustic_handlers;
static acoustic_object *acoustic_from(zend_object *o) {
    return (acoustic_object *)((char *)o-XtOffsetOf(acoustic_object,std));
}
static zend_object *acoustic_create(zend_class_entry *ce) {
    acoustic_object *o=zend_object_alloc(sizeof(*o),ce);
    zend_object_std_init(&o->std,ce);object_properties_init(&o->std,ce);
    o->std.handlers=&acoustic_handlers;
    array_init(&o->callbacks);o->queue=NULL;o->queued=0;o->queue_capacity=0;o->busy=0;
    ZVAL_UNDEF(&o->argument);ZVAL_UNDEF(&o->result);
    acoustic_stream_init(&o->core);
    return &o->std;
}
static HashTable *acoustic_gc(zend_object *object,zval **table,int *n) {
    acoustic_object *o=acoustic_from(object);
    *table=&o->callbacks;*n=3;
    return zend_std_get_properties(object);
}
static void acoustic_free(zend_object *object) {
    acoustic_object *o=acoustic_from(object);
    zval_ptr_dtor(&o->callbacks);
    if(!Z_ISUNDEF(o->argument))zval_ptr_dtor(&o->argument);
    if(!Z_ISUNDEF(o->result))zval_ptr_dtor(&o->result);
    if(o->queue)efree(o->queue);
    zend_object_std_dtor(object);
}
static int acoustic_mutable(acoustic_object *o) {
    if(o->busy){zend_throw_error(NULL,"AcousticStreamAnalyzer cannot be mutated during push()");return 0;}
    return 1;
}
PHP_METHOD(AcousticStreamAnalyzer,__construct) {
    zend_long rate=8000,ms=20;
    ZEND_PARSE_PARAMETERS_START(0,2)
        Z_PARAM_OPTIONAL Z_PARAM_LONG(rate) Z_PARAM_LONG(ms)
    ZEND_PARSE_PARAMETERS_END();
    acoustic_object *o=acoustic_from(Z_OBJ_P(getThis()));
    if(!acoustic_mutable(o))RETURN_THROWS();
    if(rate!=8000||ms!=20){zend_value_error("Only PCM16LE mono 8000 Hz with 20 ms frames is supported");RETURN_THROWS();}
    acoustic_stream_init(&o->core);
}
PHP_METHOD(AcousticStreamAnalyzer,on) {
    zend_string *name;zval *callback;
    ZEND_PARSE_PARAMETERS_START(2,2) Z_PARAM_STR(name) Z_PARAM_ZVAL(callback) ZEND_PARSE_PARAMETERS_END();
    acoustic_object *o=acoustic_from(Z_OBJ_P(getThis()));
    if(!acoustic_mutable(o))RETURN_THROWS();
    int found=0;
    for(int i=0;i<=AS_SPEECH_END;i++)if(strcmp(ZSTR_VAL(name),acoustic_event_name(i))==0 && ZSTR_LEN(name)==strlen(acoustic_event_name(i)))found=1;
    if(!found){zend_value_error("Unknown acoustic event");RETURN_THROWS();}
    if(!zend_is_callable(callback,0,NULL)){zend_type_error("callback must be callable");RETURN_THROWS();}
    zval *list=zend_hash_find(Z_ARRVAL(o->callbacks),name);
    if(!list){zval a;array_init(&a);list=zend_hash_add_new(Z_ARRVAL(o->callbacks),name,&a);}
    Z_TRY_ADDREF_P(callback);add_next_index_zval(list,callback);
    RETURN_OBJ_COPY(Z_OBJ_P(getThis()));
}
static void acoustic_event_array(zval *out,const acoustic_event *e) {
    array_init(out);
    add_assoc_string(out,"type",(char *)acoustic_event_name(e->type));
    add_assoc_long(out,"at_ms",(zend_long)e->at_ms);
    add_assoc_long(out,"detected_at_ms",(zend_long)e->detected_ms);
    add_assoc_string(out,"signal",(char *)pcm_signal_name(e->signal));
    add_assoc_string(out,"reason",(char *)e->reason);
    if(e->period_ms)add_assoc_long(out,"period_ms",e->period_ms);
}
PHP_METHOD(AcousticStreamAnalyzer,push) {
    zend_string *pcm;
    ZEND_PARSE_PARAMETERS_START(1,1) Z_PARAM_STR(pcm) ZEND_PARSE_PARAMETERS_END();
    acoustic_object *o=acoustic_from(Z_OBJ_P(getThis()));
    if(!acoustic_mutable(o))RETURN_THROWS();
    if(ZSTR_LEN(pcm)>AS_MAX_PUSH){zend_value_error("push() accepts at most 240000 bytes (15 seconds)");RETURN_THROWS();}
    /* Odd byte fragments are intentional: keep the incomplete sample. */
    o->busy=1;o->queued=0;

    zend_try {
        const unsigned char *data=(unsigned char *)ZSTR_VAL(pcm);
        size_t left=ZSTR_LEN(pcm);
        while(left) {
            size_t take=320-o->core.pending_bytes;if(take>left)take=left;
            memcpy(o->core.pending+o->core.pending_bytes,data,take);
            o->core.pending_bytes+=take;data+=take;left-=take;
            if(o->core.pending_bytes!=320)continue;
            acoustic_stream_frame(&o->core,o->core.pending);o->core.pending_bytes=0;
            if(o->core.event_count) {
                size_t required=o->queued+(size_t)o->core.event_count;
                if(required>o->queue_capacity) {
                    size_t capacity=o->queue_capacity?o->queue_capacity*2:16;
                    if(capacity<required)capacity=required;
                    if(capacity>AS_MAX_QUEUED)capacity=AS_MAX_QUEUED;
                    o->queue=safe_erealloc(o->queue,capacity,sizeof(acoustic_event),0);
                    o->queue_capacity=capacity;
                }
                memcpy(o->queue+o->queued,o->core.events,o->core.event_count*sizeof(acoustic_event));
                o->queued+=o->core.event_count;
            }
        }
        /* No PHP calls occur until every new frame has been processed. */
        for(size_t i=0;i<o->queued&&!EG(exception);i++) {
            acoustic_event *e=&o->queue[i];
            zval *list=zend_hash_str_find(Z_ARRVAL(o->callbacks),acoustic_event_name(e->type),strlen(acoustic_event_name(e->type)));
            if(!list)continue;
            zval *callback;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(list),callback) {
                acoustic_event_array(&o->argument,e);ZVAL_UNDEF(&o->result);
                if(call_user_function(EG(function_table),NULL,callback,&o->result,1,&o->argument)==FAILURE&&!EG(exception))
                    zend_throw_error(NULL,"Unable to invoke acoustic callback");
                zval_ptr_dtor(&o->argument);ZVAL_UNDEF(&o->argument);
                if(!Z_ISUNDEF(o->result)){zval_ptr_dtor(&o->result);ZVAL_UNDEF(&o->result);}
                if(EG(exception))break;
            } ZEND_HASH_FOREACH_END();
        }
    } zend_catch {
        o->busy=0;o->queued=0;
        if(!Z_ISUNDEF(o->argument)){zval_ptr_dtor(&o->argument);ZVAL_UNDEF(&o->argument);}
        if(!Z_ISUNDEF(o->result)){zval_ptr_dtor(&o->result);ZVAL_UNDEF(&o->result);}
        zend_bailout();
    } zend_end_try();
    o->busy=0;o->queued=0;
    if(EG(exception))RETURN_THROWS();
}
PHP_METHOD(AcousticStreamAnalyzer,reset) {
    ZEND_PARSE_PARAMETERS_NONE();
    acoustic_object *o=acoustic_from(Z_OBJ_P(getThis()));
    if(!acoustic_mutable(o))RETURN_THROWS();
    acoustic_stream_init(&o->core);o->queued=0;
}
PHP_METHOD(AcousticStreamAnalyzer,state) {
    ZEND_PARSE_PARAMETERS_NONE();
    acoustic_stream *s=&acoustic_from(Z_OBJ_P(getThis()))->core;
    array_init(return_value);
    add_assoc_long(return_value,"processed_ms",(zend_long)(s->frames*20));
    add_assoc_long(return_value,"pending_bytes",s->pending_bytes);
    add_assoc_string(return_value,"signal",(char *)pcm_signal_name(s->signal));
    /* Existing per-frame classification, before transition debounce. Consumers
     * must not count debounced speech hangover as qualified vocal activity. */
    add_assoc_string(return_value,"frame_signal",(char *)pcm_signal_name(s->candidate));
    add_assoc_bool(return_value,"speech_active",s->speech);
    add_assoc_bool(return_value,"ring_active",s->ring_seen);
    add_assoc_bool(return_value,"cadence_detected",s->cadence);
    add_assoc_bool(return_value,"ring_interrupted",s->interrupted);
    add_assoc_double(return_value,"ring_frequency_hz",s->last_ring.ring_frequency_hz);
    add_assoc_double(return_value,"ring_level_dbfs",s->last_ring.ring_level_dbfs);
    add_assoc_double(return_value,"ring_ac_rms_dbfs",s->last_ring.ac_rms_dbfs);
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_as_construct,0,0,0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0,sampleRate,IS_LONG,0,"8000")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0,frameDurationMs,IS_LONG,0,"20")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_as_on,0,2,AcousticStreamAnalyzer,0)
    ZEND_ARG_TYPE_INFO(0,event,IS_STRING,0)
    ZEND_ARG_TYPE_INFO(0,callback,IS_CALLABLE,0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_as_push,0,1,IS_VOID,0)
    ZEND_ARG_TYPE_INFO(0,pcm,IS_STRING,0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_as_reset,0,0,IS_VOID,0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_as_state,0,0,IS_ARRAY,0)
ZEND_END_ARG_INFO()
static const zend_function_entry acoustic_methods[]={
    PHP_ME(AcousticStreamAnalyzer,__construct,arginfo_as_construct,ZEND_ACC_PUBLIC)
    PHP_ME(AcousticStreamAnalyzer,on,arginfo_as_on,ZEND_ACC_PUBLIC)
    PHP_ME(AcousticStreamAnalyzer,push,arginfo_as_push,ZEND_ACC_PUBLIC)
    PHP_ME(AcousticStreamAnalyzer,state,arginfo_as_state,ZEND_ACC_PUBLIC)
    PHP_ME(AcousticStreamAnalyzer,reset,arginfo_as_reset,ZEND_ACC_PUBLIC)
    PHP_FE_END
};
static void acoustic_register(void) {
    zend_class_entry ce;
    memcpy(&acoustic_handlers,zend_get_std_object_handlers(),sizeof(acoustic_handlers));
    acoustic_handlers.offset=XtOffsetOf(acoustic_object,std);
    acoustic_handlers.free_obj=acoustic_free;acoustic_handlers.get_gc=acoustic_gc;
    acoustic_handlers.clone_obj=NULL;
    INIT_CLASS_ENTRY(ce,"AcousticStreamAnalyzer",acoustic_methods);
    acoustic_ce=zend_register_internal_class(&ce);
    acoustic_ce->ce_flags|=ZEND_ACC_FINAL|ZEND_ACC_NOT_SERIALIZABLE;
    acoustic_ce->create_object=acoustic_create;
}
