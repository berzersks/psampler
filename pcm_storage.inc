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
    size_t size = 0;
    if (pcm->sample_rate == rate) return PSAMPLER_SUCCESS;
    if (pcm->flushed) {
        psampler_value_error("PCM stream was flushed; call reset() before resampling again");
        return PSAMPLER_FAILURE;
    }
    if (pcm->size == 0) {
        pcm->sample_rate = rate;
        return PSAMPLER_SUCCESS;
    }
    if (pcm->stream == NULL || pcm->stream_src_rate != pcm->sample_rate
        || pcm->stream_dst_rate != rate || pcm->stream_channels != pcm->channels) {
        psampler_pcm_stream *next = psampler_pcm_stream_create(pcm->sample_rate,
            rate, pcm->channels);
        if (next == NULL) return PSAMPLER_FAILURE;
        psampler_pcm_stream_destroy(pcm->stream);
        pcm->stream = next;
        pcm->stream_src_rate = pcm->sample_rate;
        pcm->stream_dst_rate = rate;
        pcm->stream_channels = pcm->channels;
    }
    if (psampler_pcm_stream_process(pcm->stream, pcm->data, pcm->size, false,
        &pcm->scratch, &size, &pcm->scratch_capacity) == PSAMPLER_FAILURE) {
        return PSAMPLER_FAILURE;
    }
    if (!pcm_reserve(pcm, size)) return PSAMPLER_FAILURE;
    if (size != 0) memcpy(pcm->data, pcm->scratch, size);
    pcm->size = size;
    pcm->sample_rate = rate;
    return PSAMPLER_SUCCESS;
}
