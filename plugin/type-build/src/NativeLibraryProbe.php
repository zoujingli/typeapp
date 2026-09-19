<?php

declare(strict_types=1);

namespace Type\Build;

/** 生成固定的原生加载器查询，不执行外部命令或读取生产PHP源码。 */
final class NativeLibraryProbe
{
    /** @return array<string, string> 进入同次AOT身份的stub及其真实C++实现。 */
    public function sources(): array
    {
        return ['native-libraries.stub.php' => <<<'PHP'
<?php

declare(strict_types=1);

/** @internal 由已编译的系统加载器接口实现，返回路径与Mach-O UUID。 */
function type_app_native_loaded_images(): array {}

/** @internal macOS完整文件SHA-256；不可用、非普通文件或读取失败返回空字符串。 */
function type_app_native_file_sha256(string $path): string {}
PHP,
            'native-libraries.cc' => <<<'CPP'
#include <phpx.h>
#include <string>
#include <cstdio>
#include <cstring>
#if defined(_WIN32)
#include <windows.h>
#include <tlhelp32.h>
#elif defined(__APPLE__)
#include <mach-o/dyld.h>
#include <mach-o/loader.h>
#include <CommonCrypto/CommonDigest.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <unistd.h>
#endif

// 固定ABI：只报告本进程已经加载的映像，不扫描进程外目录或加载未知DLL。
php::Array php_type_app_native_loaded_images() {
    php::Array images;
#if defined(_WIN32)
    HANDLE snapshot = CreateToolhelp32Snapshot(TH32CS_SNAPMODULE | TH32CS_SNAPMODULE32, GetCurrentProcessId());
    if (snapshot == INVALID_HANDLE_VALUE) { return images; }
    MODULEENTRY32W entry = {};
    entry.dwSize = sizeof(entry);
    if (Module32FirstW(snapshot, &entry)) {
        do {
            int bytes = WideCharToMultiByte(CP_UTF8, WC_ERR_INVALID_CHARS, entry.szExePath, -1, nullptr, 0, nullptr, nullptr);
            if (bytes > 1) {
                std::string path(static_cast<size_t>(bytes), '\0');
                if (WideCharToMultiByte(CP_UTF8, WC_ERR_INVALID_CHARS, entry.szExePath, -1, path.data(), bytes, nullptr, nullptr)) {
                    path.resize(static_cast<size_t>(bytes - 1));
                    images.append(php::String(path.c_str(), path.size()));
                }
            }
        } while (Module32NextW(snapshot, &entry));
    }
    CloseHandle(snapshot);
#elif defined(__APPLE__)
    const uint32_t count = _dyld_image_count();
    for (uint32_t index = 0; index < count; ++index) {
        const char* path = _dyld_get_image_name(index);
        const mach_header* header = _dyld_get_image_header(index);
        if (!path || !header) { continue; }
        const char* cursor = reinterpret_cast<const char*>(header) + (header->magic == MH_MAGIC_64 ? sizeof(mach_header_64) : sizeof(mach_header));
        const char* end = cursor + header->sizeofcmds;
        std::string uuid;
        for (uint32_t commandIndex = 0; commandIndex < header->ncmds && cursor + sizeof(load_command) <= end; ++commandIndex) {
            const load_command* command = reinterpret_cast<const load_command*>(cursor);
            if (command->cmdsize < sizeof(load_command) || cursor + command->cmdsize > end) { break; }
            if (command->cmd == LC_UUID && command->cmdsize >= sizeof(uuid_command)) {
                const uuid_command* item = reinterpret_cast<const uuid_command*>(command);
                char text[37];
                std::snprintf(text, sizeof(text), "%02X%02X%02X%02X-%02X%02X-%02X%02X-%02X%02X-%02X%02X%02X%02X%02X%02X",
                    item->uuid[0], item->uuid[1], item->uuid[2], item->uuid[3], item->uuid[4], item->uuid[5], item->uuid[6], item->uuid[7],
                    item->uuid[8], item->uuid[9], item->uuid[10], item->uuid[11], item->uuid[12], item->uuid[13], item->uuid[14], item->uuid[15]);
                uuid = text;
                break;
            }
            cursor += command->cmdsize;
        }
        const std::string value = std::string(path) + "\n" + uuid;
        images.append(php::String(value.c_str(), value.size()));
    }
#endif
    return images;
}

// 仍逐字节校验整份文件；不以路径、mtime、UUID或已验证缓存替代SHA-256。
php::String php_type_app_native_file_sha256(php::String path) {
#if defined(__APPLE__)
    if (path.empty() || std::memchr(path.data(), '\0', path.length())) { return php::String(""); }
    const int descriptor = open(path.data(), O_RDONLY | O_NONBLOCK | O_CLOEXEC);
    if (descriptor < 0) { return php::String(""); }
    struct stat info = {};
    if (fstat(descriptor, &info) != 0 || !S_ISREG(info.st_mode)) {
        close(descriptor);
        return php::String("");
    }
    FILE* file = fdopen(descriptor, "rb");
    if (!file) { close(descriptor); return php::String(""); }
    CC_SHA256_CTX context;
    CC_SHA256_Init(&context);
    unsigned char buffer[65536];
    size_t bytes = 0;
    while ((bytes = std::fread(buffer, 1, sizeof(buffer), file)) != 0) {
        CC_SHA256_Update(&context, buffer, static_cast<CC_LONG>(bytes));
    }
    const bool failed = std::ferror(file) != 0;
    const int closed = std::fclose(file);
    if (failed || closed != 0) { return php::String(""); }
    unsigned char digest[CC_SHA256_DIGEST_LENGTH];
    CC_SHA256_Final(digest, &context);
    char text[CC_SHA256_DIGEST_LENGTH * 2 + 1];
    for (size_t index = 0; index < CC_SHA256_DIGEST_LENGTH; ++index) {
        std::snprintf(text + index * 2, 3, "%02x", digest[index]);
    }
    return php::String(text, CC_SHA256_DIGEST_LENGTH * 2);
#else
    return php::String("");
#endif
}
CPP];
    }
}
