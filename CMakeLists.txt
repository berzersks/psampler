cmake_minimum_required(VERSION 4.1)
project(psampler)

set(CMAKE_CXX_STANDARD 14)

include_directories(.)

add_executable(psampler
        config.m4
        byte_buffer.c
        byte_buffer.h
        binary_buffer.c
        binary_buffer.h
        psampler_binary.h
        swoole_bridge.c
        swoole_bridge.h
        swoole_buffer_c_api.h
        php_psampler.h
        pcm_core.c
        pcm_core.h
        pcm_buffer.c
        pcm_buffer.h
        psampler_native.h
        psampler_resample.h
        psampler.c
        pcm_analyzer.c
        pcm_analyzer.h
        README.md)
