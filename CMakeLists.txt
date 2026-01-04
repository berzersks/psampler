cmake_minimum_required(VERSION 4.1)
project(psampler)

set(CMAKE_CXX_STANDARD 14)

include_directories(.)

add_executable(psampler
        config.m4
        php_psampler.h
        psampler.c
        README.md)
