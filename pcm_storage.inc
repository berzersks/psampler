/* Shared storage growth and replacement; the including adapter supplies
 * psampler_pcm_buffer and the same size/capacity limits. */
static bool pcm_reserve(psampler_pcm_buffer *pcm, size_t required)
{
    size_t capacity;
    unsigned char *data;
    if (PSAMPLER_UNEXPECTED(required > PCM_BUFFER_MAX_SIZE)) {
        psampler_value_error("PcmBuffer exceeds the maximum supported size");
        return 0;
    }
    if (required <= pcm->capacity) {
        return 1;
    }
    capacity = pcm->capacity != 0 ? pcm->capacity : PCM_BUFFER_INITIAL_CAPACITY;
    while (capacity < required) {
        if (capacity > PCM_BUFFER_MAX_SIZE / 2) {
            capacity = required;
            break;
        }
        capacity *= 2;
    }
    data = psampler_alloc(capacity);
    if (pcm->size != 0) {
        memcpy(data, pcm->data, pcm->size);
    }
    if (pcm->data != NULL) {
        psampler_free_mem(pcm->data);
    }
    pcm->data = data;
    pcm->capacity = capacity;
    return 1;
}

static psampler_result pcm_storage_resample(psampler_pcm_buffer *pcm, uint32_t rate)
{
    unsigned char *output;
    size_t size, capacity;
    if (pcm->sample_rate != (uint32_t) rate) {
        if (pcm->size != 0) {
            if (psampler_resample_pcm16(pcm->data, pcm->size, pcm->sample_rate,
                (uint32_t) rate, pcm->channels, &output, &size, &capacity) == PSAMPLER_FAILURE) {
                return PSAMPLER_FAILURE;
            }
            if (pcm->data != NULL) {
                psampler_free_mem(pcm->data);
            }
            pcm->data = output;
            pcm->size = size;
            pcm->capacity = capacity;
        }
        pcm->sample_rate = (uint32_t) rate;
    }
    return PSAMPLER_SUCCESS;
}
