// 仅用于 Windows 单程序部署验收。受限令牌执行第二次 ACL 检查，不改变控制端身份。
#define NOMINMAX
#include <windows.h>
#include <sddl.h>
#include <cstdio>
#include <string>
#include <vector>

static int failure(const char *operation) {
    std::fprintf(stderr, "sandbox %s failed: %lu\n", operation, GetLastError());
    return 125;
}

// 遵循 CommandLineToArgvW 的反斜线与引号规则；不经过 cmd 或 PowerShell 解析业务参数。
static std::wstring quote(const wchar_t *value) {
    std::wstring result = L"\"";
    unsigned slashes = 0;
    for (const wchar_t *cursor = value; *cursor; ++cursor) {
        if (*cursor == L'\\') { ++slashes; continue; }
        result.append(*cursor == L'"' ? slashes * 2 + 1 : slashes, L'\\');
        result += *cursor;
        slashes = 0;
    }
    result.append(slashes * 2, L'\\');
    return result + L'"';
}

static BOOL WINAPI control(DWORD event) {
    // 子进程与设置器共用当前控制台进程组，CTRL_BREAK 同时送达应用，由其正常排空。
    return event == CTRL_BREAK_EVENT || event == CTRL_C_EVENT;
}

static bool readable(const wchar_t *path) {
    HANDLE file = CreateFileW(path, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
                              nullptr, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, nullptr);
    if (file == INVALID_HANDLE_VALUE) { return false; }
    CloseHandle(file);
    return true;
}

static int probe(int argc, wchar_t **argv) {
    // 真实原文件由 PHP 控制端先确认可读，拒绝必须来自访问检查，不接受缺失路径假通过。
    if (argc < 7 || !readable(argv[2])) { return failure("positive read"); }
    const std::wstring writable = std::wstring(argv[3]) + L"/sandbox-write-probe";
    HANDLE file = CreateFileW(writable.c_str(), GENERIC_WRITE, 0, nullptr, CREATE_NEW, FILE_ATTRIBUTE_NORMAL, nullptr);
    if (file == INVALID_HANDLE_VALUE) { return failure("data write"); }
    CloseHandle(file);
    if (!DeleteFileW(writable.c_str())) { return failure("data cleanup"); }
    const std::wstring forbidden = std::wstring(argv[4]) + L"/sandbox-write-probe";
    file = CreateFileW(forbidden.c_str(), GENERIC_WRITE, 0, nullptr, CREATE_NEW, FILE_ATTRIBUTE_NORMAL, nullptr);
    if (file != INVALID_HANDLE_VALUE) { CloseHandle(file); DeleteFileW(forbidden.c_str()); return 1; }
    if (GetLastError() != ERROR_ACCESS_DENIED) { return failure("readonly directory"); }
    for (int index = 5; index < argc; ++index) {
        if (readable(argv[index]) || GetLastError() != ERROR_ACCESS_DENIED) { return failure("source denial"); }
    }
    std::puts("restricted source and SDK reads denied; program readable; data writable; program directory readonly");
    return 0;
}

int wmain(int argc, wchar_t **argv) {
    if (argc > 1 && std::wstring(argv[1]) == L"--probe") { return probe(argc, argv); }
    if (argc < 3) { return 64; }
    HANDLE original = nullptr, restricted = nullptr;
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_DUPLICATE | TOKEN_ASSIGN_PRIMARY | TOKEN_QUERY, &original)) {
        return failure("open token");
    }
    std::vector<PSID> allocated;
    std::vector<SID_AND_ATTRIBUTES> restricting;
    // 系统目录沿用普通用户可读取的范围；本轮唯一 SID 的拒绝 ACE 始终优先。
    const wchar_t *sidTexts[] = {L"S-1-1-0", L"S-1-5-11", L"S-1-5-32-545", argv[1]};
    for (const wchar_t *text : sidTexts) {
        PSID sid = nullptr;
        if (!ConvertStringSidToSidW(text, &sid)) { return failure("SID"); }
        allocated.push_back(sid);
        restricting.push_back({sid, 0});
    }
    DWORD bytes = 0;
    GetTokenInformation(original, TokenGroups, nullptr, 0, &bytes);
    std::vector<unsigned char> buffer(bytes);
    if (!GetTokenInformation(original, TokenGroups, buffer.data(), bytes, &bytes)) { return failure("token groups"); }
    auto groups = reinterpret_cast<TOKEN_GROUPS *>(buffer.data());
    for (DWORD index = 0; index < groups->GroupCount; ++index) {
        if ((groups->Groups[index].Attributes & SE_GROUP_LOGON_ID) == SE_GROUP_LOGON_ID) {
            restricting.push_back({groups->Groups[index].Sid, 0});
        }
    }
    if (!CreateRestrictedToken(original, DISABLE_MAX_PRIVILEGE, 0, nullptr, 0, nullptr,
                               static_cast<DWORD>(restricting.size()), restricting.data(), &restricted)) {
        return failure("restrict token");
    }
    CloseHandle(original);
    for (PSID sid : allocated) { LocalFree(sid); }
    if (!IsTokenRestricted(restricted)) { return failure("restricted identity"); }
    HANDLE job = CreateJobObjectW(nullptr, nullptr);
    JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits{};
    limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
    if (!job || !SetInformationJobObject(job, JobObjectExtendedLimitInformation, &limits, sizeof(limits))) {
        return failure("job ownership");
    }
    std::wstring command;
    for (int index = 2; index < argc; ++index) {
        if (index != 2) { command += L' '; }
        command += quote(argv[index]);
    }
    STARTUPINFOW startup{};
    startup.cb = sizeof(startup);
    startup.dwFlags = STARTF_USESTDHANDLES;
    startup.hStdInput = GetStdHandle(STD_INPUT_HANDLE);
    startup.hStdOutput = GetStdHandle(STD_OUTPUT_HANDLE);
    startup.hStdError = GetStdHandle(STD_ERROR_HANDLE);
    PROCESS_INFORMATION child{};
    if (!SetConsoleCtrlHandler(control, TRUE)
        || !CreateProcessAsUserW(restricted, argv[2], command.data(), nullptr, nullptr, TRUE,
                                 CREATE_SUSPENDED | CREATE_UNICODE_ENVIRONMENT, nullptr, nullptr, &startup, &child)) {
        return failure("restricted process");
    }
    CloseHandle(restricted);
    if (!AssignProcessToJobObject(job, child.hProcess)) {
        TerminateProcess(child.hProcess, 125);
        return failure("assign child");
    }
    if (ResumeThread(child.hThread) == static_cast<DWORD>(-1)) { return failure("resume child"); }
    CloseHandle(child.hThread);
    if (WaitForSingleObject(child.hProcess, INFINITE) != WAIT_OBJECT_0) { return failure("wait child"); }
    DWORD result = 125;
    if (!GetExitCodeProcess(child.hProcess, &result)) { return failure("exit status"); }
    CloseHandle(child.hProcess);
    CloseHandle(job);
    return static_cast<int>(result);
}
