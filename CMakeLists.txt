cmake_minimum_required(VERSION 4.1)
project(psampler)

set(CMAKE_CXX_STANDARD 14)

include_directories(.)

add_executable(psampler
        config.m4
        byte_buffer.c
        byte_buffer.h
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
