cmake_minimum_required(VERSION 3.20)
project(typeapp_windows_phpx_static LANGUAGES C CXX)

# 仅编排固定上游的完整 SAPI 源码；mpdecimal 由其官方 Makefile.vc 生成。
if(NOT MSVC OR NOT CMAKE_SIZEOF_VOID_P EQUAL 8)
    message(FATAL_ERROR "This target requires native MSVC x64")
endif()
set(CMAKE_MSVC_RUNTIME_LIBRARY MultiThreaded)
foreach(input PHPX_ROOT PHP_SOURCE DEPENDENCY_ROOT)
    # PowerShell 传入原生反斜杠路径；CMake 生成目标时会再次解析，先规范化以保留空格并避免转义。
    file(TO_CMAKE_PATH "${${input}}" normalized_path)
    set(${input} "${normalized_path}")
    if(NOT IS_DIRECTORY "${${input}}")
        message(FATAL_ERROR "Missing source directory: ${input}")
    endif()
endforeach()

file(GLOB PHPX_CORE_SOURCES "${PHPX_ROOT}/src/core/*.cc")
file(GLOB PHPX_STD_SOURCES "${PHPX_ROOT}/src/std/*.cc")
file(GLOB PHPX_TYPEPHP_SOURCES "${PHPX_ROOT}/src/typephp/*.cc")
list(FILTER PHPX_CORE_SOURCES EXCLUDE REGEX "/[^/]+_nano\\.cc$")
list(FILTER PHPX_TYPEPHP_SOURCES EXCLUDE REGEX "/[^/]+_nano\\.cc$")

add_library(wren_gc_obj OBJECT "${PHPX_ROOT}/thirdparty/wren-gc/src/wren_gc.c")
target_include_directories(wren_gc_obj PRIVATE "${PHPX_ROOT}/thirdparty/wren-gc/include")
set_target_properties(wren_gc_obj PROPERTIES C_STANDARD 11 C_STANDARD_REQUIRED ON)

add_library(phpx STATIC ${PHPX_CORE_SOURCES} ${PHPX_STD_SOURCES} ${PHPX_TYPEPHP_SOURCES}
    $<TARGET_OBJECTS:wren_gc_obj>)
set_target_properties(phpx PROPERTIES CXX_STANDARD 20 CXX_STANDARD_REQUIRED ON
    ARCHIVE_OUTPUT_DIRECTORY "${CMAKE_BINARY_DIR}/lib")
target_compile_options(phpx PRIVATE /utf-8 /Zc:__cplusplus /Zc:preprocessor /wd4996)
target_compile_definitions(phpx PRIVATE PHP_WIN32=1 ZEND_WIN32=1 ZTS=1 ZEND_DEBUG=0
    ENABLE_INTSAFE_SIGNED_FUNCTIONS ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 NOMINMAX)
target_include_directories(phpx PRIVATE
    "${PHPX_ROOT}/include" "${PHPX_ROOT}/src/misc"
    "${PHP_SOURCE}" "${PHP_SOURCE}/main" "${PHP_SOURCE}/Zend"
    "${PHP_SOURCE}/TSRM" "${PHP_SOURCE}/ext" "${PHP_SOURCE}/ext/date/lib"
    "${PHPX_ROOT}/thirdparty/mpdecimal/libmpdec"
    "${PHPX_ROOT}/thirdparty/mpdecimal/libmpdec++"
    "${PHPX_ROOT}/thirdparty/wren-gc/include" "${DEPENDENCY_ROOT}/include")
