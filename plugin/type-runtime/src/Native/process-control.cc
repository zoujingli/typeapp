#include <phpx.h>
#include <cstdlib>
#if defined(_WIN32)
#include <windows.h>
#include <atomic>

namespace {
std::atomic<bool> type_control_attached{false};
std::atomic<bool> type_control_requested{false};

// Windows在新线程调用此函数；只能写原子标记，绝不访问Zend或PHP对象。
BOOL WINAPI type_control_handler(DWORD event) {
    if (event != CTRL_C_EVENT && event != CTRL_BREAK_EVENT) { return FALSE; }
    type_control_requested.store(true, std::memory_order_release);
    return TRUE;
}
}
#endif

// 独立主控在无法安全回收业务线程时结束本角色进程，跳过可能再次阻塞的析构。
void php_type_runtime_native_control_fail_stop() {
    std::_Exit(75);
}

php::Bool php_type_runtime_native_control_start() {
#if defined(_WIN32)
    DWORD process = 0;
    if (GetConsoleProcessList(&process, 1) == 0) { return false; }
    bool expected = false;
    if (!type_control_attached.compare_exchange_strong(expected, true)) { return false; }
    type_control_requested.store(false, std::memory_order_release);
    if (!SetConsoleCtrlHandler(type_control_handler, TRUE)) {
        type_control_attached.store(false, std::memory_order_release);
        return false;
    }
    return true;
#else
    return false;
#endif
}

php::Bool php_type_runtime_native_control_pending() {
#if defined(_WIN32)
    return type_control_attached.load(std::memory_order_acquire)
        && type_control_requested.load(std::memory_order_acquire);
#else
    return false;
#endif
}

php::Bool php_type_runtime_native_control_stop() {
#if defined(_WIN32)
    if (!type_control_attached.load(std::memory_order_acquire)) { return false; }
    if (!SetConsoleCtrlHandler(type_control_handler, FALSE)) { return false; }
    type_control_attached.store(false, std::memory_order_release);
    type_control_requested.store(false, std::memory_order_release);
    return true;
#else
    return false;
#endif
}
