#include <phpx.h>
#include <chrono>
#include <cstdio>
#include <string>
#include <thread>

namespace {
// 只持有原生字符串；此析构发生时 PHP 请求与 TSRM 可能已经关闭。
struct ExitGate {
    std::string directory;

    ~ExitGate() {
        if (directory.empty()) { return; }
        const auto entered = directory + "/gate-entered";
        const auto release = directory + "/gate-release";
        auto file = std::fopen(entered.c_str(), "wb");
        if (!file) { return; }
        std::fputs("entered", file);
        std::fclose(file);
        const auto deadline = std::chrono::steady_clock::now() + std::chrono::seconds(10);
        while (std::chrono::steady_clock::now() < deadline) {
            file = std::fopen(release.c_str(), "rb");
            if (file) {
                std::fclose(file);
                return;
            }
            std::this_thread::sleep_for(std::chrono::milliseconds(1));
        }
    }
};

thread_local ExitGate exit_gate;
}

php::Bool php_type_test_thread_exit_gate(php::String directory) {
    if (!exit_gate.directory.empty()) { return false; }
    exit_gate.directory.assign(directory.data(), directory.length());
    return true;
}

void php_type_test_thread_block(php::Int milliseconds) {
    if (milliseconds > 0 && milliseconds <= 5000) {
        std::this_thread::sleep_for(std::chrono::milliseconds(static_cast<int64_t>(milliseconds)));
    }
}
