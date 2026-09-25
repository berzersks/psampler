cmake_minimum_required(VERSION 4.1)
project(psampler)

set(CMAKE_CXX_STANDARD 14)

include_directories(.)

add_executable(psampler
        config.m4
        byte_buffer.c
        byte_buffer.h
        php_psampler.h
        psampler.c
        pcm_analyzer.c
        pcm_analyzer.h
        README.md)
