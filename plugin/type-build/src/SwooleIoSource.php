<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 尚未放行的文件 AIO 适配候选；仅由显式文件验收调用，不进入默认线程构建。 */
final class SwooleIoSource
{
    /** @internal 文件候选与线程适配分别核验自己的固定原文。 */
    public const HASHES = [
        'include/swoole_async.h' => '2cb45dacc4ec9c63a913536f2d96ff671c65e7ca642430357f30dd00ae5c7103',
        'include/swoole_coroutine.h' => '0d9af2316a4d0e890fc988b6d802be81beec357561d72649c33aeb152d4f0984',
        'include/swoole_file_hook.h' => '844629a99d602a81e9309f2d46949fd05c45aa9c6e5df7402e626a43056cc6b7',
        'include/swoole_file.h' => 'e77236625a852f63a69d9d35473e57cef1b92c3087ba6678e750f4ef327f910c',
        'src/os/file.cc' => '29916a57cd02884791c3489044c06703affcc9fd5dd576e1db52c73a3ea353ef',
        'src/os/async_thread.cc' => '3433f3d0bacce41d8545f085d094487f7bd65eb87eeab1be524aec86ffb50802',
        'src/coroutine/system.cc' => '28bad08b4a036801e791559e9f41ea14389c16bc3bac94c20479742bd85497f7',
        'src/coroutine/hook.cc' => '16f01983bba935c1bd8507c09f02e9ebfa8bc78d55c455083a2a7ad21f7443e4',
        'ext-src/swoole_async_coro.cc' => '131a926cfe297855c7789d74b3780b040379bcebba1ab2ce435e3b969c236680',
        'ext-src/swoole_coroutine.cc' => '46346877709b3ef3981802abd07022cf81d2f7d606ad56d86b855d2c822f8831',
        'ext-src/stubs/php_swoole_coroutine.stub.php' => '7778b464fecf3398bbc89293dd3c27d498e62dabcbe93fc02b031f79cb54c9e6',
        'ext-src/swoole_runtime.cc' => '4ee479068044a31153464d6d64dbd9c5968e854c7e82436bf6f828016216c215',
        'thirdparty/php/streams/plain_wrapper.c' => '10cf429262cdfda51e139761755eefed25b344df60fcf435f0f3e0c0f14b79b5',
    ];

    /**
     * 显式准备文件候选；先核验文件原文，再组合既有线程适配。
     *
     * @return array<string, array{before: string, after: string}>
     * @throws RuntimeException 源码不是固定原文或无法保存；失败副本不可用于构建。
     */
    public function apply(string $directory): array
    {
        foreach (self::HASHES as $file => $hash) {
            $path = $directory . '/' . $file;
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('Swoole 文件候选需要固定原文：' . $file);
            }
        }
        $report = (new SwooleThreadSource())->apply($directory);
        $contents = [];
        foreach (self::HASHES as $file => $hash) {
            $contents[$file] = (string) file_get_contents($directory . '/' . $file);
        }
        foreach ($this->adapt($contents) as $file => $content) {
            if (file_put_contents($directory . '/' . $file, $content) !== strlen($content)) {
                throw new RuntimeException('无法保存 Swoole 文件候选：' . $file);
            }
            $report[$file] = ['before' => self::HASHES[$file], 'after' => hash('sha256', $content)];
        }
        return $report;
    }

    /**
     * 在已核验的源码中补齐原生提交、完成和文件缓冲约束。
     *
     * @internal 由文件候选入口核验原文并组合线程适配。
     * @param array<string, string> $contents 固定上游源码；线程补丁可已在内存中应用。
     * @return array<string, string>
     * @throws RuntimeException 精确替换位置不唯一。
     */
    public function adapt(array $contents): array
    {
        $source = $contents['include/swoole_async.h'];
        $source = self::replace($source, <<<'CPP'
#pragma once

#include "swoole_socket.h"

#include <vector>

#ifndef O_DIRECT
#define O_DIRECT 040000
#endif

namespace swoole {

enum AsyncFlag {
    SW_AIO_WRITE_FSYNC = 1u << 1,
    SW_AIO_EOF = 1u << 2,
};

CPP, <<<'CPP'
#pragma once

#include "swoole_socket.h"

#include <vector>
#include <mutex>
#include <chrono>

#ifndef O_DIRECT
#define O_DIRECT 040000
#endif

namespace swoole {

class AsyncThreads;

struct AsyncLimits {
    size_t pending = 128;
    size_t bytes = 16777216;
    double seconds = 60;
};

struct AsyncUsage {
    size_t pending = 0, bytes = 0, queued = 0, executing = 0, completed = 0;
    size_t rejected = 0, settled = 0, peak_pending = 0, peak_bytes = 0;
};

enum AsyncFlag {
    SW_AIO_WRITE_FSYNC = 1u << 1,
    SW_AIO_EOF = 1u << 2,
};

CPP);
        $source = self::replace($source, <<<'CPP'
    network::Socket *pipe_socket;
    double timestamp;
    void *object;
    void (*handler)(AsyncEvent *event);
    void (*callback)(AsyncEvent *event);

    bool catch_error() const {
        return (error == SW_ERROR_AIO_TIMEOUT || error == SW_ERROR_AIO_CANCELED);
    }
};
CPP, <<<'CPP'
    network::Socket *pipe_socket;
    double timestamp;
    void *object;
    void (*handler)(AsyncEvent *event);
    void (*callback)(AsyncEvent *event);
    // Includes a conservative fixed allowance for native metadata and stdio state.
    size_t buffer_bytes = 65536;
    bool cleanup = false;
    unsigned phase = 0;
    uint64_t owner_generation = 0;
    AsyncThreads *owner = nullptr;
    std::chrono::steady_clock::time_point submitted;

    bool catch_error() const {
        return (error == SW_ERROR_AIO_TIMEOUT || error == SW_ERROR_AIO_CANCELED);
    }
};
CPP);
        $source = self::replace($source, <<<'CPP'
    ~GetaddrinfoRequest() override = default;
};

class AsyncThreads {
  public:
    size_t task_num = 0;
    SocketPair *pipe = nullptr;
    std::shared_ptr<async::ThreadPool> pool;
    network::Socket *read_socket = nullptr;
    network::Socket *write_socket = nullptr;
CPP, <<<'CPP'
    ~GetaddrinfoRequest() override = default;
};

class AsyncThreads {
  public:
    uint64_t generation = 0;
    std::mutex completion_mutex;
    size_t task_num = 0;
    SocketPair *pipe = nullptr;
    std::shared_ptr<async::ThreadPool> pool;
    network::Socket *read_socket = nullptr;
    network::Socket *write_socket = nullptr;
CPP);
        $source = self::replace($source, <<<'CPP'
    AsyncEvent *completed_events[SW_AIO_EVENT_NUM] = {};
    size_t completed_event_bytes = 0;
};

namespace async {

typedef void (*Handler)(AsyncEvent *event);

AsyncEvent *dispatch(const AsyncEvent *request);

CPP, <<<'CPP'
    AsyncEvent *completed_events[SW_AIO_EVENT_NUM] = {};
    size_t completed_event_bytes = 0;
};

namespace async {

AsyncLimits limits();
AsyncUsage usage();
bool configure(const AsyncLimits &limits);
bool supervise();
void set_rejection_handler(void (*handler)(int));
void reject(int error);
const Allocator *file_buffer_allocator();
[[noreturn]] void fail_stop();

typedef void (*Handler)(AsyncEvent *event);

AsyncEvent *dispatch(const AsyncEvent *request);

CPP);
        $contents['include/swoole_async.h'] = $source;
        $source = $contents['include/swoole_coroutine.h'];
        $source = self::replace($source, <<<'CPP'
 * For example, in write/read operations,
 * asynchronous tasks cannot transfer the memory ownership of wbuf/rbuf to the AIO thread.
 * In the event of a timeout or cancellation, the memory of wbuf/rbuf will be released by the caller,
 * which may lead the AIO thread to read from an erroneous memory pointer and consequently crash.
 */
bool async(const std::function<void()> &fn);
bool run(const CoroutineFunc &fn, void *arg = nullptr);
bool wait_for(const std::function<bool()> &fn);
}  // namespace coroutine
//-------------------------------------------------------------------------------
}  // namespace swoole
CPP, <<<'CPP'
 * For example, in write/read operations,
 * asynchronous tasks cannot transfer the memory ownership of wbuf/rbuf to the AIO thread.
 * In the event of a timeout or cancellation, the memory of wbuf/rbuf will be released by the caller,
 * which may lead the AIO thread to read from an erroneous memory pointer and consequently crash.
 */
bool async(const std::function<void()> &fn, size_t buffer_bytes = 0, bool cleanup = false);
bool run(const CoroutineFunc &fn, void *arg = nullptr);
bool wait_for(const std::function<bool()> &fn);
}  // namespace coroutine
//-------------------------------------------------------------------------------
}  // namespace swoole
CPP);
        $contents['include/swoole_coroutine.h'] = $source;
        $source = $contents['include/swoole_file_hook.h'];
        $source = self::replace($source, <<<'CPP'
#define readlink(fd, buf, size) swoole_coroutine_readlink(fd, buf, size)
#define mkdir(pathname, mode) swoole_coroutine_mkdir(pathname, mode)
#define rmdir(pathname) swoole_coroutine_rmdir(pathname)
#endif
#define rename(oldpath, newpath) swoole_coroutine_rename(oldpath, newpath)
// fsync and ftruncate may already be defined by PHP's php_network.h on Windows
#ifndef fsync
#define fsync(fd) swoole_coroutine_fsync(fd)
#endif
#define fdatasync(fd) swoole_coroutine_fdatasync(fd)
CPP, <<<'CPP'
#define readlink(fd, buf, size) swoole_coroutine_readlink(fd, buf, size)
#define mkdir(pathname, mode) swoole_coroutine_mkdir(pathname, mode)
#define rmdir(pathname) swoole_coroutine_rmdir(pathname)
#endif
#define rename(oldpath, newpath) swoole_coroutine_rename(oldpath, newpath)
#define unlink(pathname) swoole_coroutine_unlink(pathname)
// fsync and ftruncate may already be defined by PHP's php_network.h on Windows
#ifndef fsync
#define fsync(fd) swoole_coroutine_fsync(fd)
#endif
#define fdatasync(fd) swoole_coroutine_fdatasync(fd)
CPP);
        $contents['include/swoole_file_hook.h'] = $source;
        $source = $contents['src/os/async_thread.cc'];
        $source = self::replace($source, <<<'CPP'
#include <condition_variable>
#include <cstring>
#include <mutex>
#include <queue>
#include <system_error>

static std::mutex async_thread_lock;
static std::shared_ptr<swoole::async::ThreadPool> async_thread_pool;

swoole::AsyncThreads *sw_async_threads() {
    return SwooleTG.async_threads;
}

namespace swoole {
namespace async {
//-------------------------------------------------------------------------------
class EventQueue {
  public:
    void push(AsyncEvent *event) {
        queue_.push(event);
CPP, <<<'CPP'
#include <condition_variable>
#include <cstring>
#include <mutex>
#include <queue>
#include <system_error>
#include <cmath>
#include <cstdlib>
#include <cstddef>
#include <map>

static std::mutex async_thread_lock;
static std::shared_ptr<swoole::async::ThreadPool> async_thread_pool;
static swoole::AsyncLimits async_limits;
static std::atomic<uint64_t> async_generation{0};
static void (*async_rejection_handler)(int) = nullptr;
// The role main reactor outlives every business reactor and owns native idle release notifications.
static swoole::AsyncThreads *async_supervisor = nullptr;

swoole::AsyncThreads *sw_async_threads() {
    return SwooleTG.async_threads;
}

namespace swoole {
namespace async {
// Exit the owning process without running destructors over native borrowed memory.
// The deployment supervisor observes status 75 and replaces the whole process.
[[noreturn]] void fail_stop() { std::_Exit(75); }

void set_rejection_handler(void (*handler)(int)) { async_rejection_handler = handler; }

void reject(int error) {
    errno = error;
    swoole_set_last_error(error);
    if (async_rejection_handler) { async_rejection_handler(error); }
}

AsyncLimits limits() {
    std::lock_guard<std::mutex> lock(async_thread_lock);
    return async_limits;
}

bool configure(const AsyncLimits &value) {
    std::lock_guard<std::mutex> lock(async_thread_lock);
    if (async_thread_pool || value.pending < 8 || value.pending > 65536 ||
        value.bytes < 2097152 || value.bytes > 1073741824 ||
        !std::isfinite(value.seconds) || value.seconds < 0.1 || value.seconds > 300) {
        return false;
    }
    async_limits = value;
    return true;
}
//-------------------------------------------------------------------------------
class EventQueue {
  public:
    void push(AsyncEvent *event) {
        queue_.push(event);
CPP);
        $source = self::replace($source, <<<'CPP'
        return running.load(std::memory_order_acquire);
    }

    bool start() {
        running.store(true, std::memory_order_release);
        current_task_id = 0;
        for (size_t i = 0; i < core_worker_num; i++) {
            create_thread(true);
        }
        if (get_worker_num() != core_worker_num) {
            shutdown();
CPP, <<<'CPP'
        return running.load(std::memory_order_acquire);
    }

    bool start() {
        running.store(true, std::memory_order_release);
        for (size_t i = 0; i < core_worker_num; i++) {
            create_thread(true);
        }
        if (get_worker_num() != core_worker_num) {
            shutdown();
CPP);
        $source = self::replace($source, <<<'CPP'
            create_thread();
        }
    }

    AsyncEvent *dispatch(const AsyncEvent *request) {
        auto _event_copy = new AsyncEvent(*request);
        std::unique_lock<std::mutex> lock(event_mutex);
        if (!running.load(std::memory_order_acquire)) {
            delete _event_copy;
            return nullptr;
        }
        _event_copy->task_id = current_task_id++;
        _event_copy->timestamp = microtime();
        _event_copy->pipe_socket = SwooleTG.async_threads->write_socket;
        queue_.push(_event_copy);
        schedule();
        lock.unlock();
        _cv.notify_one();
        swoole_debug("push and notify one: %f", microtime());
        return _event_copy;
    }

    size_t get_worker_num() const {
        std::lock_guard<std::mutex> lock(threads_mutex);
        return threads.size();
    }

CPP, <<<'CPP'
            create_thread();
        }
    }

    AsyncEvent *dispatch(const AsyncEvent *request) {
        std::unique_lock<std::mutex> lock(event_mutex);
        if (!running.load(std::memory_order_acquire)) {
            errno = ECANCELED;
            swoole_set_last_error(errno);
            return nullptr;
        }
        const size_t reserve = SW_MIN(static_cast<size_t>(16), async_limits.pending / 4);
        const size_t count_limit = request->cleanup ? async_limits.pending : async_limits.pending - reserve;
        const size_t byte_limit = request->cleanup ? async_limits.bytes : async_limits.bytes - reserve * 65536;
        if (outstanding.size() >= count_limit || request->buffer_bytes > byte_limit ||
            metrics.bytes > byte_limit - request->buffer_bytes) {
            if (request->cleanup) {
                fail_stop();
            }
            metrics.rejected++;
            errno = request->buffer_bytes > byte_limit ? EMSGSIZE : EAGAIN;
            swoole_set_last_error(errno);
            if (async_rejection_handler) { async_rejection_handler(errno); }
            return nullptr;
        }
        auto _event_copy = new AsyncEvent(*request);
        if (current_task_id == SIZE_MAX) { fail_stop(); }
        _event_copy->task_id = current_task_id++;
        _event_copy->timestamp = microtime();
        _event_copy->submitted = std::chrono::steady_clock::now();
        _event_copy->pipe_socket = SwooleTG.async_threads->write_socket;
        _event_copy->owner = SwooleTG.async_threads;
        _event_copy->owner_generation = _event_copy->owner->generation;
        outstanding.emplace(_event_copy->task_id, _event_copy);
        metrics.pending++;
        metrics.queued++;
        metrics.bytes += _event_copy->buffer_bytes;
        metrics.peak_pending = SW_MAX(metrics.pending, metrics.peak_pending);
        metrics.peak_bytes = SW_MAX(metrics.bytes, metrics.peak_bytes);
        queue_.push(_event_copy);
        schedule();
        lock.unlock();
        _cv.notify_one();
        swoole_debug("push and notify one: %f", microtime());
        return _event_copy;
    }

    AsyncUsage usage() {
        std::lock_guard<std::mutex> lock(event_mutex);
        return metrics;
    }

    bool within_deadline() {
        std::lock_guard<std::mutex> lock(event_mutex);
        // Submission IDs and steady-clock timestamps increase under this same lock.
        // Reuse the identity map in order; no second queue and no periodic full scan.
        return outstanding.empty() || std::chrono::duration<double>(
            std::chrono::steady_clock::now() - outstanding.begin()->second->submitted).count() < async_limits.seconds;
    }

    void reserve_buffer(size_t bytes) {
        std::lock_guard<std::mutex> lock(event_mutex);
        const size_t reserve = SW_MIN(static_cast<size_t>(16), async_limits.pending / 4);
        const size_t limit = async_limits.bytes - reserve * 65536;
        if (bytes > limit || metrics.bytes > limit - bytes) {
            metrics.rejected++;
            // Never invoke PHP from an AIO worker; the caller reports this after the native wait.
            throw std::system_error(bytes > limit ? EMSGSIZE : EAGAIN, std::generic_category());
        }
        metrics.bytes += bytes;
        metrics.peak_bytes = SW_MAX(metrics.bytes, metrics.peak_bytes);
    }

    void release_buffer(size_t bytes) {
        std::lock_guard<std::mutex> lock(event_mutex);
        if (bytes > metrics.bytes) { fail_stop(); }
        metrics.bytes -= bytes;
    }

    void complete(AsyncEvent *event) {
        std::lock_guard<std::mutex> lock(event_mutex);
        event->phase = 2;
        metrics.executing--;
        metrics.completed++;
    }

    void settle(AsyncEvent *event) {
        std::lock_guard<std::mutex> lock(event_mutex);
        auto found = outstanding.find(event->task_id);
        if (found == outstanding.end() || found->second != event || event->phase != 2) {
            fail_stop();
        }
        metrics.bytes -= event->buffer_bytes;
        metrics.pending--;
        metrics.completed--;
        metrics.settled++;
        outstanding.erase(found);
    }

    size_t get_worker_num() const {
        std::lock_guard<std::mutex> lock(threads_mutex);
        return threads.size();
    }

CPP);
        $source = self::replace($source, <<<'CPP'
    std::unordered_map<std::thread::id, std::thread *> threads;
    mutable std::mutex threads_mutex;
    EventQueue queue_;
    std::mutex event_mutex;
    std::condition_variable _cv;
};

bool ThreadPool::send_event(AsyncEvent *event) {
    AsyncEvent *completed_event = event;
    size_t written = 0;
    auto *buffer = reinterpret_cast<const char *>(&completed_event);
    while (written < sizeof(completed_event)) {
        ssize_t n = event->pipe_socket->write_sync(buffer + written, sizeof(completed_event) - written);
        if (n <= 0) {
            swoole_sys_warning("sendto swoole_aio_pipe_write failed");
            return false;
        }
        written += n;
    }
    return true;
}

void ThreadPool::main_func(const bool is_core_worker) {
    bool exit_flag = false;
    swoole_thread_init(false);

    const auto idle_timeout =
        std::chrono::duration_cast<std::chrono::microseconds>(std::chrono::duration<double>(max_idle_time));
    const auto safe_idle_timeout = idle_timeout.count() > 0 ? idle_timeout : std::chrono::microseconds(1);

    while (running.load(std::memory_order_acquire)) {
        bool timeout = false;
        std::unique_lock<std::mutex> lock(event_mutex);
CPP, <<<'CPP'
    std::unordered_map<std::thread::id, std::thread *> threads;
    mutable std::mutex threads_mutex;
    EventQueue queue_;
    std::mutex event_mutex;
    std::condition_variable _cv;
    std::map<size_t, AsyncEvent *> outstanding;
    AsyncUsage metrics;
};

AsyncUsage usage() {
    std::lock_guard<std::mutex> lock(async_thread_lock);
    return async_thread_pool ? async_thread_pool->usage() : AsyncUsage{};
}

bool supervise() {
    if (!swoole_is_main_thread() || !sw_reactor()) { return false; }
    if (!async_supervisor) {
        // Explicitly attach before business threads or native submissions exist.
        if (SwooleTG.async_threads) { return false; }
        async_supervisor = new AsyncThreads();
    }
    return SwooleTG.async_threads == async_supervisor && async_supervisor->pool->within_deadline();
}

// String already owns allocator callbacks. Keep its allocation/growth/read algorithm intact.
// The small header is relocatable; the retained pool reference stays at a stable address.
struct alignas(std::max_align_t) FileBuffer {
    std::shared_ptr<ThreadPool> *pool;
    size_t charged;
};

static size_t file_buffer_charge(size_t size) {
    const size_t overhead = sizeof(FileBuffer) + sizeof(std::shared_ptr<ThreadPool>) + 64;
    if (size > (SIZE_MAX - overhead) / 2) {
        throw std::system_error(EMSGSIZE, std::generic_category());
    }
    // Reserve the native capacity and its eventual PHP string copy, including bookkeeping.
    return size * 2 + overhead;
}

static void *file_buffer_malloc(size_t size) {
    std::shared_ptr<ThreadPool> pool;
    {
        std::lock_guard<std::mutex> lock(async_thread_lock);
        pool = async_thread_pool;
    }
    if (!pool) { fail_stop(); }
    const size_t charged = file_buffer_charge(size);
    auto owner = std::unique_ptr<std::shared_ptr<ThreadPool>>(new std::shared_ptr<ThreadPool>(pool));
    pool->reserve_buffer(charged);
    auto *buffer = static_cast<FileBuffer *>(std::malloc(sizeof(FileBuffer) + size));
    if (!buffer) {
        pool->release_buffer(charged);
        return nullptr;
    }
    buffer->pool = owner.release();
    buffer->charged = charged;
    return buffer + 1;
}

static void file_buffer_free(void *ptr) {
    if (!ptr) { return; }
    auto *buffer = static_cast<FileBuffer *>(ptr) - 1;
    auto owner = std::unique_ptr<std::shared_ptr<ThreadPool>>(buffer->pool);
    const size_t charged = buffer->charged;
    std::free(buffer);
    (*owner)->release_buffer(charged);
}

static void *file_buffer_realloc(void *ptr, size_t size) {
    if (!ptr) { return file_buffer_malloc(size); }
    auto *previous = static_cast<FileBuffer *>(ptr) - 1;
    auto pool = *previous->pool;
    const size_t old_charge = previous->charged;
    const size_t charged = file_buffer_charge(size);
    // realloc may hold both allocations. Do not return the old reservation before it succeeds.
    pool->reserve_buffer(charged);
    auto *buffer = static_cast<FileBuffer *>(std::realloc(previous, sizeof(FileBuffer) + size));
    if (!buffer) {
        pool->release_buffer(charged);
        return nullptr;
    }
    buffer->charged = charged;
    pool->release_buffer(old_charge);
    return buffer + 1;
}

static void *file_buffer_calloc(size_t count, size_t size) {
    if (size != 0 && count > SIZE_MAX / size) {
        throw std::system_error(EMSGSIZE, std::generic_category());
    }
    const size_t bytes = count * size;
    void *ptr = file_buffer_malloc(bytes);
    if (ptr) { std::memset(ptr, 0, bytes); }
    return ptr;
}

const Allocator *file_buffer_allocator() {
    static const Allocator allocator{file_buffer_malloc, file_buffer_calloc, file_buffer_realloc, file_buffer_free};
    return &allocator;
}

bool ThreadPool::send_event(AsyncEvent *event) {
    // Serialise the entire pointer frame, including short writes, per owner.
    std::lock_guard<std::mutex> lock(event->owner->completion_mutex);
    AsyncEvent *completed_event = event;
    size_t written = 0;
    auto *buffer = reinterpret_cast<const char *>(&completed_event);
    while (written < sizeof(completed_event)) {
        ssize_t n = event->pipe_socket->write_sync(buffer + written, sizeof(completed_event) - written);
        if (n <= 0) {
            return false;
        }
        written += n;
    }
    return true;
}

void ThreadPool::main_func(const bool is_core_worker) {
    bool exit_flag = false;
    swoole_thread_init(false);

    const auto idle_timeout = std::chrono::duration_cast<std::chrono::microseconds>(
        std::chrono::duration<double>(max_idle_time));
    const auto safe_idle_timeout = idle_timeout.count() > 0 ? idle_timeout : std::chrono::microseconds(1);

    while (running.load(std::memory_order_acquire)) {
        bool timeout = false;
        std::unique_lock<std::mutex> lock(event_mutex);
CPP);
        $source = self::replace($source, <<<'CPP'
            });
        }
        n_waiting.fetch_sub(1, std::memory_order_acq_rel);

        AsyncEvent *event = queue_.pop();
        lock.unlock();
        swoole_debug("%s: %f", event ? "pop 1 event" : "no event", microtime());
        if (event) {
            if (sw_unlikely(event->handler == nullptr)) {
                event->error = SW_ERROR_AIO_BAD_REQUEST;
                event->retval = -1;
CPP, <<<'CPP'
            });
        }
        n_waiting.fetch_sub(1, std::memory_order_acq_rel);

        AsyncEvent *event = queue_.pop();
        if (event) {
            event->phase = 1;
            metrics.queued--;
            metrics.executing++;
        }
        lock.unlock();
        swoole_debug("%s: %f", event ? "pop 1 event" : "no event", microtime());
        if (event) {
            if (sw_unlikely(event->handler == nullptr)) {
                event->error = SW_ERROR_AIO_BAD_REQUEST;
                event->retval = -1;
CPP);
        $source = self::replace($source, <<<'CPP'
                event->error = SW_ERROR_AIO_CANCELED;
                event->retval = -1;
            } else {
                event->handler(event);
            }

            swoole_trace_log(SW_TRACE_AIO,
                             "aio_thread %s. ret=%ld, error=%d",
                             event->retval > 0 ? "ok" : "failed",
                             event->retval,
                             event->error);

        _send_event:
            if (!send_event(event)) {
                delete event;
                if (exit_flag) {
                    n_closing.fetch_sub(1, std::memory_order_acq_rel);
                }
            }
            // exit
            if (exit_flag) {
                break;
            }
        } else if (timeout) {
            if (n_closing != 0) {
                // wait for the next round
                continue;
            }
            /* notifies the main thread to release this thread */
            event = new AsyncEvent{};
            event->object = new std::thread::id(std::this_thread::get_id());
            event->callback = release_callback;
            event->pipe_socket = SwooleG.aio_default_socket;
            event->canceled = false;

            n_closing.fetch_add(1, std::memory_order_acq_rel);
            exit_flag = true;
            goto _send_event;
        }
    }
    swoole_thread_clean(false);
}

CPP, <<<'CPP'
                event->error = SW_ERROR_AIO_CANCELED;
                event->retval = -1;
            } else {
                event->handler(event);
            }
            complete(event);

            swoole_trace_log(SW_TRACE_AIO,
                             "aio_thread %s. ret=%ld, error=%d",
                             event->retval > 0 ? "ok" : "failed",
                             event->retval,
                             event->error);

        _send_event:
            if (!send_event(event)) {
                fail_stop();
            }
            if (exit_flag) {
                break;
            }
        } else if (timeout) {
            // Native idle timeout, release notification and callback keep their original ownership.
            if (n_closing != 0) {
                continue;
            }
            event = new AsyncEvent{};
            event->object = new std::thread::id(std::this_thread::get_id());
            event->callback = release_callback;
            event->pipe_socket = SwooleG.aio_default_socket;
            event->owner = async_supervisor;
            event->owner_generation = async_supervisor->generation;
            event->canceled = false;
            n_closing.fetch_add(1, std::memory_order_acq_rel);
            exit_flag = true;
            goto _send_event;
        }
    }
    swoole_thread_clean(false);
}

CPP);
        $source = self::replace($source, <<<'CPP'

    async_threads->completed_event_bytes += n;
    const size_t event_num = async_threads->completed_event_bytes / sizeof(AsyncEvent *);
    for (size_t i = 0; i < event_num; i++) {
        AsyncEvent *_event = async_threads->completed_events[i];
        if (!_event->canceled) {
            _event->callback(_event);
        }
        async_threads->task_num--;
        delete _event;
    }

    const size_t completed_bytes = event_num * sizeof(AsyncEvent *);
CPP, <<<'CPP'

    async_threads->completed_event_bytes += n;
    const size_t event_num = async_threads->completed_event_bytes / sizeof(AsyncEvent *);
    for (size_t i = 0; i < event_num; i++) {
        AsyncEvent *_event = async_threads->completed_events[i];
        if (_event->owner != async_threads || _event->owner_generation != async_threads->generation) {
            async::fail_stop();
        }
        if (!_event->canceled) {
            _event->callback(_event);
        }
        if (_event->callback != async::ThreadPool::release_callback) {
            async_threads->pool->settle(_event);
        }
        async_threads->task_num--;
        delete _event;
    }

    const size_t completed_bytes = event_num * sizeof(AsyncEvent *);
CPP);
        $source = self::replace($source, <<<'CPP'
        pool->notify_one();
    }
}

AsyncThreads::AsyncThreads() {
    if (!SwooleTG.reactor) {
        swoole_warning("no event loop, cannot initialized");
        throw Exception(SW_ERROR_WRONG_OPERATION);
    }

CPP, <<<'CPP'
        pool->notify_one();
    }
}

AsyncThreads::AsyncThreads() {
    generation = ++async_generation;
    if (!SwooleTG.reactor) {
        swoole_warning("no event loop, cannot initialized");
        throw Exception(SW_ERROR_WRONG_OPERATION);
    }

CPP);
        $source = self::replace($source, '    SwooleG.aio_default_socket = write_socket;', <<<'CPP'
    if (swoole_is_main_thread()) {
        SwooleG.aio_default_socket = write_socket;
    }
CPP);
        $source = self::replace($source, <<<'CPP'
AsyncEvent *dispatch(const AsyncEvent *request) {
    if (sw_unlikely(!SwooleTG.async_threads)) {
CPP, <<<'CPP'
AsyncEvent *dispatch(const AsyncEvent *request) {
    if (!async_supervisor) {
        reject(EPERM);
        return nullptr;
    }
    if (sw_unlikely(!SwooleTG.async_threads)) {
CPP);
        $source = self::replace($source, <<<'CPP'
    pipe = nullptr;
    swoole_throw_error(SW_ERROR_SYSTEM_CALL_FAIL);
}

AsyncThreads::~AsyncThreads() {
    pool.reset();
    async_thread_lock.lock();
    /**
     * When the reference count is 1, it means that all reactor threads have ended
     * and all aio threads can be terminated.
CPP, <<<'CPP'
    pipe = nullptr;
    swoole_throw_error(SW_ERROR_SYSTEM_CALL_FAIL);
}

AsyncThreads::~AsyncThreads() {
    if (task_num != 0 || completed_event_bytes != 0) {
        async::fail_stop();
    }
    pool.reset();
    async_thread_lock.lock();
    /**
     * When the reference count is 1, it means that all reactor threads have ended
     * and all aio threads can be terminated.
CPP);
        $source = self::replace($source, <<<'CPP'
    async_thread_lock.unlock();
    pipe->close();
CPP, <<<'CPP'
    if (this == async_supervisor) {
        // Only the main reactor can release the process pool, after business reactors joined.
        if (async_thread_pool.use_count() != 1) { async::fail_stop(); }
        async_supervisor = nullptr;
        SwooleG.aio_default_socket = nullptr;
    }
    async_thread_lock.unlock();
    pipe->close();
CPP);
        $contents['src/os/async_thread.cc'] = $source;
        $source = $contents['src/coroutine/system.cc'];
        $source = self::replace($source, '#include "swoole_socket_impl.h"', "#include \"swoole_socket_impl.h\"\n#include <system_error>");
        $source = self::replace($source, <<<'CPP'
std::shared_ptr<String> System::read_file(const char *file, bool lock) {
    std::shared_ptr<String> result;
    async([&result, file, lock]() {
        File fp(file, O_RDONLY);
        if (!fp.ready()) {
            swoole_sys_warning("open(%s, O_RDONLY) failed", file);
            return;
        }
        if (lock && !fp.lock(LOCK_SH)) {
            swoole_sys_warning("flock(%s, LOCK_SH) failed", file);
            return;
        }
        ssize_t filesize = fp.get_size();
        if (filesize > 0) {
            auto content = make_string(filesize + 1);
            content->length = fp.read_all(content->str, filesize);
            content->str[content->length] = 0;
            result = std::shared_ptr<String>(content);
        } else {
            result = fp.read_content();
        }
        if (lock && !fp.unlock()) {
            swoole_sys_warning("flock(%s, LOCK_UN) failed", file);
        }
    });
    return result;
}
CPP, <<<'CPP'
std::shared_ptr<String> System::read_file(const char *file, bool lock) {
    std::shared_ptr<String> result;
    int allocation_error = 0;
    async([&result, &allocation_error, file, lock]() {
        try {
            File fp(file, O_RDONLY);
            if (!fp.ready()) {
                swoole_sys_warning("open(%s, O_RDONLY) failed", file);
                return;
            }
            if (lock && !fp.lock(LOCK_SH)) {
                swoole_sys_warning("flock(%s, LOCK_SH) failed", file);
                return;
            }
            ssize_t filesize = fp.get_size();
            if (filesize > 0) {
                auto content = std::shared_ptr<String>(make_string(static_cast<size_t>(filesize) + 1, async::file_buffer_allocator()));
                content->length = fp.read_all(content->str, filesize);
                content->str[content->length] = 0;
                result = std::move(content);
            } else {
                result = fp.read_content(async::file_buffer_allocator());
            }
            if (lock && !fp.unlock()) {
                swoole_sys_warning("flock(%s, LOCK_UN) failed", file);
            }
        } catch (const std::system_error &error) {
            allocation_error = error.code().value();
        } catch (const std::bad_alloc &) {
            allocation_error = ENOMEM;
        }
    });
    if (allocation_error != 0) {
        // The worker has unwound File/String first. Only the PHP owner may raise its exception.
        async::reject(allocation_error);
    }
    return result;
}
CPP);
        $source = self::replace($source, <<<'CPP'
            swoole_sys_warning("flock(%s, LOCK_EX) failed", file);
            return;
        }
        size_t bytes = _file.write_all(buf, length);
        if ((file_flags & SW_AIO_WRITE_FSYNC) && !_file.sync()) {
            swoole_sys_warning("fsync(%s) failed", file);
        }
        if (lock && !_file.unlock()) {
            swoole_sys_warning("flock(%s, LOCK_UN) failed", file);
        }
        retval = bytes;
    });
    return retval;
}

std::string gethostbyname_impl_with_async(const std::string &hostname, int domain, double timeout) {
    AsyncEvent ev{};
CPP, <<<'CPP'
            swoole_sys_warning("flock(%s, LOCK_EX) failed", file);
            return;
        }
        size_t bytes = _file.write_all(buf, length);
        if ((file_flags & SW_AIO_WRITE_FSYNC) && !_file.sync()) {
            return;
        }
        if (lock && !_file.unlock()) {
            return;
        }
        if (!_file.close()) { return; }
        retval = bytes;
    }, length);
    return retval;
}

std::string gethostbyname_impl_with_async(const std::string &hostname, int domain, double timeout) {
    AsyncEvent ev{};
CPP);
        $source = self::replace($source, <<<'CPP'
    event.handler = handler;
    event.callback = async_task_completed;

    AsyncEvent *_ev = async::dispatch(&event);
    if (_ev == nullptr) {
        return false;
    }

    if (!co->yield_ex(timeout)) {
        event.canceled = _ev->canceled = true;
CPP, <<<'CPP'
    event.handler = handler;
    event.callback = async_task_completed;

    AsyncEvent *_ev = async::dispatch(&event);
    if (_ev == nullptr) {
        event.retval = -1;
        event.error = errno;
        return false;
    }

    if (!co->yield_ex(timeout)) {
        event.canceled = _ev->canceled = true;
CPP);
        $source = self::replace($source, <<<'CPP'
    std::function<void()> fn;
};

static void async_lambda_handler(AsyncEvent *event) {
    auto *task = static_cast<AsyncLambdaTask *>(event->object);
    task->fn();
    event->error = errno;
    event->retval = 0;
}

static void async_lambda_callback(AsyncEvent *event) {
    auto *task = static_cast<AsyncLambdaTask *>(event->object);
    task->co->resume();
}

bool async(const std::function<void()> &fn) {
    AsyncEvent event{};
    AsyncLambdaTask task{Coroutine::get_current_safe(), fn};

    event.object = &task;
    event.handler = async_lambda_handler;
    event.callback = async_lambda_callback;

    AsyncEvent *_ev = async::dispatch(&event);
    if (_ev == nullptr) {
        return false;
    }

    task.co->yield();
    errno = _ev->error;
    return true;
}

CPP, <<<'CPP'
    std::function<void()> fn;
};

static void async_lambda_handler(AsyncEvent *event) {
    auto *task = static_cast<AsyncLambdaTask *>(event->object);
    errno = 0;
    task->fn();
    event->error = errno;
    event->retval = 0;
}

static void async_lambda_callback(AsyncEvent *event) {
    auto *task = static_cast<AsyncLambdaTask *>(event->object);
    task->co->resume();
}

bool async(const std::function<void()> &fn, size_t buffer_bytes, bool cleanup) {
    AsyncEvent event{};
    AsyncLambdaTask task{Coroutine::get_current_safe(), fn};

    if (buffer_bytes > SIZE_MAX - event.buffer_bytes) {
        errno = EMSGSIZE;
        swoole_set_last_error(errno);
        return false;
    }
    event.buffer_bytes += buffer_bytes;
    event.cleanup = cleanup;

    event.object = &task;
    event.handler = async_lambda_handler;
    event.callback = async_lambda_callback;

    AsyncEvent *_ev = async::dispatch(&event);
    if (_ev == nullptr) {
        return false;
    }

    // Keep Swoole's standard non-cancellable native wait: the worker borrows this stack.
    task.co->yield();
    errno = _ev->error;
    return true;
}

CPP);
        $contents['src/coroutine/system.cc'] = $source;
        $contents['include/swoole_file.h'] = self::replace(
            $contents['include/swoole_file.h'],
            'std::shared_ptr<String> read_content() const;',
            'std::shared_ptr<String> read_content(const Allocator *allocator = nullptr) const;'
        );
        $source = self::replace(
            $contents['src/os/file.cc'],
            'std::shared_ptr<String> File::read_content() const {',
            'std::shared_ptr<String> File::read_content(const Allocator *allocator) const {'
        );
        $contents['src/os/file.cc'] = self::replace(
            $source,
            'auto data = std::make_shared<String>(SW_BUFFER_SIZE_STD);',
            'auto data = std::make_shared<String>(SW_BUFFER_SIZE_STD, allocator);'
        );
        $source = $contents['src/coroutine/hook.cc'];
        $source = self::replace($source, <<<'CPP'
    if (sw_unlikely(is_no_coro())) {
        return fread(ptr, size, nmemb, stream);
    }

    size_t retval = 0;
    async([&]() { retval = fread(ptr, size, nmemb, stream); });
    return retval;
}

size_t swoole_coroutine_fwrite(const void *ptr, size_t size, size_t nmemb, FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
        return fwrite(ptr, size, nmemb, stream);
    }

    size_t retval = 0;
    async([&]() { retval = fwrite(ptr, size, nmemb, stream); });
    return retval;
}

char *swoole_coroutine_fgets(char *s, int size, FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
        return fgets(s, size, stream);
    }

    char *retval = nullptr;
    async([&]() { retval = fgets(s, size, stream); });
    return retval;
}

int swoole_coroutine_fputs(const char *s, FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
        return fputs(s, stream);
    }

    int retval = -1;
    async([&]() { retval = fputs(s, stream); });
    return retval;
}

int swoole_coroutine_feof(FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
CPP, <<<'CPP'
    if (sw_unlikely(is_no_coro())) {
        return fread(ptr, size, nmemb, stream);
    }

    size_t retval = 0;
    if (size != 0 && nmemb > SIZE_MAX / size) { errno = EMSGSIZE; return 0; }
    async([&]() { retval = fread(ptr, size, nmemb, stream); }, size * nmemb);
    return retval;
}

size_t swoole_coroutine_fwrite(const void *ptr, size_t size, size_t nmemb, FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
        return fwrite(ptr, size, nmemb, stream);
    }

    size_t retval = 0;
    if (size != 0 && nmemb > SIZE_MAX / size) { errno = EMSGSIZE; return 0; }
    async([&]() { retval = fwrite(ptr, size, nmemb, stream); }, size * nmemb);
    return retval;
}

char *swoole_coroutine_fgets(char *s, int size, FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
        return fgets(s, size, stream);
    }

    char *retval = nullptr;
    async([&]() { retval = fgets(s, size, stream); }, size > 0 ? size : 0);
    return retval;
}

int swoole_coroutine_fputs(const char *s, FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
        return fputs(s, stream);
    }

    int retval = -1;
    async([&]() { retval = fputs(s, stream); }, strlen(s) + 1);
    return retval;
}

int swoole_coroutine_feof(FILE *stream) {
    if (sw_unlikely(is_no_coro())) {
CPP);
        $source = self::replace($source, <<<'CPP'
    if (sw_unlikely(is_no_coro())) {
        return fclose(stream);
    }

    int retval = -1;
    async([&]() { retval = fclose(stream); });
    return retval;
}

DIR *swoole_coroutine_opendir(const char *name) {
    if (sw_unlikely(is_no_coro())) {
CPP, <<<'CPP'
    if (sw_unlikely(is_no_coro())) {
        return fclose(stream);
    }

    int retval = -1;
    async([&]() { retval = fclose(stream); }, 0, true);
    return retval;
}

DIR *swoole_coroutine_opendir(const char *name) {
    if (sw_unlikely(is_no_coro())) {
CPP);
        $source = self::replace($source, <<<'CPP'
struct dirent *swoole_coroutine_readdir(DIR *dirp) {
    if (sw_unlikely(is_no_coro())) {
        return sw_readdir(dirp);
    }

    struct dirent *retval;
    async([&retval, dirp]() { retval = sw_readdir(dirp); });
    return retval;
}

int swoole_coroutine_closedir(DIR *dirp) {
    if (sw_unlikely(is_no_coro())) {
        return sw_closedir(dirp);
    }

    int retval = -1;
    async([&]() { retval = sw_closedir(dirp); });
    return retval;
}

void swoole_coroutine_sleep(int sec) {
    System::sleep((double) sec);
CPP, <<<'CPP'
struct dirent *swoole_coroutine_readdir(DIR *dirp) {
    if (sw_unlikely(is_no_coro())) {
        return sw_readdir(dirp);
    }

    struct dirent *retval = nullptr;
    async([&retval, dirp]() { retval = sw_readdir(dirp); });
    return retval;
}

int swoole_coroutine_closedir(DIR *dirp) {
    if (sw_unlikely(is_no_coro())) {
        return sw_closedir(dirp);
    }

    int retval = -1;
    async([&]() { retval = sw_closedir(dirp); }, 0, true);
    return retval;
}

void swoole_coroutine_sleep(int sec) {
    System::sleep((double) sec);
CPP);
        $source = self::replace($source, <<<'CPP'

#ifdef SW_USE_IOCP_FILE
    return Iocp::close_file(sockfd);
#elif defined(SW_USE_ASYNC)
    int ret = -1;
    async([&]() { ret = close(sockfd); });
    return ret;
#else
    return Iouring::close(sockfd);
#endif
}
CPP, <<<'CPP'

#ifdef SW_USE_IOCP_FILE
    return Iocp::close_file(sockfd);
#elif defined(SW_USE_ASYNC)
    int ret = -1;
    async([&]() { ret = close(sockfd); }, 0, true);
    return ret;
#else
    return Iouring::close(sockfd);
#endif
}
CPP);
        $source = self::replace($source, <<<'CPP'
    ssize_t ret = -1;
    NetSocket sock = {};
    sock.fd = sockfd;
    sock.nonblock = 1;
    sock.read_timeout = -1;
    async([&]() { ret = sock.read_sync(buf, count); });
    return ret;
#else
    return Iouring::read(sockfd, buf, count);
#endif
}
CPP, <<<'CPP'
    ssize_t ret = -1;
    NetSocket sock = {};
    sock.fd = sockfd;
    sock.nonblock = 1;
    sock.read_timeout = -1;
    async([&]() { ret = sock.read_sync(buf, count); }, count);
    return ret;
#else
    return Iouring::read(sockfd, buf, count);
#endif
}
CPP);
        $source = self::replace($source, <<<'CPP'
    ssize_t ret = -1;
    NetSocket sock = {};
    sock.fd = sockfd;
    sock.nonblock = 1;
    sock.write_timeout = -1;
    async([&]() { ret = sock.write_sync(buf, count); });
    return ret;
#else
    return Iouring::write(sockfd, buf, count);
#endif
}
CPP, <<<'CPP'
    ssize_t ret = -1;
    NetSocket sock = {};
    sock.fd = sockfd;
    sock.nonblock = 1;
    sock.write_timeout = -1;
    async([&]() { ret = sock.write_sync(buf, count); }, count);
    return ret;
#else
    return Iouring::write(sockfd, buf, count);
#endif
}
CPP);
        $source = self::replace($source, <<<'CPP'
    ssize_t ret = -1;
#ifdef _WIN32
    errno = ENOSYS;
    ret = -1;
#else
    async([&]() { ret = readlink(pathname, buf, len); });
#endif
    return ret;
}

int swoole_coroutine_statvfs(const char *path, struct statvfs *buf) {
CPP, <<<'CPP'
    ssize_t ret = -1;
#ifdef _WIN32
    errno = ENOSYS;
    ret = -1;
#else
    async([&]() { ret = readlink(pathname, buf, len); }, len);
#endif
    return ret;
}

int swoole_coroutine_statvfs(const char *path, struct statvfs *buf) {
CPP);
        $contents['src/coroutine/hook.cc'] = $source;
        $source = $contents['ext-src/swoole_async_coro.cc'];
        $source = self::replace($source, <<<'CPP'
  +----------------------------------------------------------------------+
*/

#include "php_swoole_cxx.h"
#include "swoole_socket.h"

#include <string>
#include <vector>
#include <unordered_map>

CPP, <<<'CPP'
  +----------------------------------------------------------------------+
*/

#include "php_swoole_cxx.h"
#include "swoole_socket.h"
#include "swoole_async.h"

#include <string>
#include <vector>
#include <unordered_map>

CPP);
        $source = self::replace($source, <<<'CPP'
    request_cache_map.clear();
}

void php_swoole_set_aio_option(const HashTable *vht) {
    zval *ztmp;
    /* AIO */
    if (php_swoole_array_get_value(vht, "aio_core_worker_num", ztmp)) {
        zend_long v = zval_get_long(ztmp);
        v = SW_MAX(1, SW_MIN(v, UINT32_MAX));
        SwooleG.aio_core_worker_num = v;
CPP, <<<'CPP'
    request_cache_map.clear();
}

void php_swoole_set_aio_option(const HashTable *vht) {
    zval *ztmp;
    auto bounded = swoole::async::limits();
    bool changed = false;
    if (php_swoole_array_get_value(vht, "aio_max_pending", ztmp)) {
        bounded.pending = zval_get_long(ztmp);
        changed = true;
    }
    if (php_swoole_array_get_value(vht, "aio_max_bytes", ztmp)) {
        bounded.bytes = zval_get_long(ztmp);
        changed = true;
    }
    if (php_swoole_array_get_value(vht, "aio_max_task_time", ztmp)) {
        bounded.seconds = zval_get_double(ztmp);
        changed = true;
    }
    if (changed && (!sw_is_main_thread() || sw_active_thread_count() > 1 || !swoole::async::configure(bounded))) {
        zend_value_error("AIO limits require valid budgets before any AIO or business thread starts");
        return;
    }
    /* AIO */
    if (php_swoole_array_get_value(vht, "aio_core_worker_num", ztmp)) {
        zend_long v = zval_get_long(ztmp);
        v = SW_MAX(1, SW_MIN(v, UINT32_MAX));
        SwooleG.aio_core_worker_num = v;
CPP);
        $contents['ext-src/swoole_async_coro.cc'] = $source;
        $source = $contents['ext-src/swoole_coroutine.cc'];
        $source = self::replace($source, 'static PHP_METHOD(swoole_coroutine, stats);', <<<'CPP'
static PHP_METHOD(swoole_coroutine, stats);
static PHP_METHOD(swoole_coroutine, typeappSuperviseIo);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_swoole_coroutine_typeapp_supervise_io, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
CPP);
        $source = self::replace($source, "static const zend_function_entry swoole_coroutine_methods[] =\n{", <<<'CPP'
static const zend_function_entry swoole_coroutine_methods[] =
{
    PHP_ME(swoole_coroutine, typeappSuperviseIo, arginfo_swoole_coroutine_typeapp_supervise_io, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
CPP);
        $source = self::replace($source, 'static PHP_METHOD(swoole_coroutine, stats) {', <<<'CPP'
// Private candidate ABI: called by the independent role main loop, never a business reactor.
static PHP_METHOD(swoole_coroutine, typeappSuperviseIo) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (!sw_is_main_thread() || (!sw_async_threads() && sw_active_thread_count() != 1)) {
        RETURN_FALSE;
    }
    RETURN_BOOL(swoole::async::supervise());
}

static PHP_METHOD(swoole_coroutine, stats) {
CPP);
        $source = self::replace($source, <<<'CPP'
}

static PHP_METHOD(swoole_coroutine, stats) {
    array_init(return_value);

    add_assoc_long_ex(return_value, ZEND_STRL("event_num"), sw_reactor() ? sw_reactor()->get_event_num() : 0);
#ifndef _WIN32
    add_assoc_long_ex(return_value, ZEND_STRL("signal_listener_num"), swoole_signal_get_listener_num());
#endif

CPP, <<<'CPP'
}

static PHP_METHOD(swoole_coroutine, stats) {
    array_init(return_value);

    const auto io = swoole::async::usage();
    const auto limits = swoole::async::limits();
    add_assoc_long(return_value, "aio_process_pending", io.pending);
    add_assoc_long(return_value, "aio_process_bytes", io.bytes);
    add_assoc_long(return_value, "aio_process_queued", io.queued);
    add_assoc_long(return_value, "aio_process_executing", io.executing);
    add_assoc_long(return_value, "aio_process_completed", io.completed);
    add_assoc_long(return_value, "aio_process_rejected", io.rejected);
    add_assoc_long(return_value, "aio_process_settled", io.settled);
    add_assoc_long(return_value, "aio_process_peak_pending", io.peak_pending);
    add_assoc_long(return_value, "aio_process_peak_bytes", io.peak_bytes);
    add_assoc_long(return_value, "aio_max_pending", limits.pending);
    add_assoc_long(return_value, "aio_max_bytes", limits.bytes);
    add_assoc_double(return_value, "aio_max_task_time", limits.seconds);
    add_assoc_long(return_value, "aio_owner_generation", sw_async_threads() ? sw_async_threads()->generation : 0);

    add_assoc_long_ex(return_value, ZEND_STRL("event_num"), sw_reactor() ? sw_reactor()->get_event_num() : 0);
#ifndef _WIN32
    add_assoc_long_ex(return_value, ZEND_STRL("signal_listener_num"), swoole_signal_get_listener_num());
#endif

CPP);
        $contents['ext-src/swoole_coroutine.cc'] = $source;
        $contents['ext-src/stubs/php_swoole_coroutine.stub.php'] = self::replace(
            $contents['ext-src/stubs/php_swoole_coroutine.stub.php'],
            '        public static function stats(): array {}',
            "        public static function typeappSuperviseIo(): bool {}\n        public static function stats(): array {}"
        );
        $source = $contents['ext-src/swoole_runtime.cc'];
        $source = self::replace($source, <<<'CPP'
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_TLS", PHPCoroutine::HOOK_TLS);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_STREAM_FUNCTION", PHPCoroutine::HOOK_STREAM_FUNCTION);
    // backward compatibility
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_STREAM_SELECT", PHPCoroutine::HOOK_STREAM_FUNCTION);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_FILE", PHPCoroutine::HOOK_FILE);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_STDIO", PHPCoroutine::HOOK_STDIO);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_SLEEP", PHPCoroutine::HOOK_SLEEP);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_PROC", PHPCoroutine::HOOK_PROC);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_CURL", PHPCoroutine::HOOK_CURL);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_NATIVE_CURL", PHPCoroutine::HOOK_NATIVE_CURL);
CPP, <<<'CPP'
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_TLS", PHPCoroutine::HOOK_TLS);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_STREAM_FUNCTION", PHPCoroutine::HOOK_STREAM_FUNCTION);
    // backward compatibility
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_STREAM_SELECT", PHPCoroutine::HOOK_STREAM_FUNCTION);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_FILE", PHPCoroutine::HOOK_FILE);
    swoole::async::set_rejection_handler([](int error) {
        zend_throw_exception(swoole_exception_ce, error == EPERM ? "aio_supervisor_required" : "aio_capacity_exceeded", error);
    });
#if defined(__APPLE__)
    SW_REGISTER_LONG_CONSTANT("SWOOLE_FILE_IO_ABI", 2);
#endif
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_STDIO", PHPCoroutine::HOOK_STDIO);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_SLEEP", PHPCoroutine::HOOK_SLEEP);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_PROC", PHPCoroutine::HOOK_PROC);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_CURL", PHPCoroutine::HOOK_CURL);
    SW_REGISTER_LONG_CONSTANT("SWOOLE_HOOK_NATIVE_CURL", PHPCoroutine::HOOK_NATIVE_CURL);
CPP);
        $contents['ext-src/swoole_runtime.cc'] = $source;
        $source = $contents['thirdparty/php/streams/plain_wrapper.c'];
        $source = self::replace($source, <<<'CPP'
    FILE *fp;
    int fd;

    if (php_stream_cast(stream, PHP_STREAM_AS_STDIO, (void**)&fp, REPORT_ERRORS) == FAILURE) {
        return -1;
    }

    if (sw_php_stdiop_flush(stream) == 0) {
CPP, <<<'CPP'
    int fd;

    // This wrapper already owns the descriptor (or its real stdio buffer).
    // Generic STDIO casting creates a PHP cookie for the hooked wrapper; on macOS
    // its seek/flush can deadlock across business threads before AIO submission.
    if (sw_php_stdiop_flush(stream) == 0) {
CPP);
        $source = self::replace($source, <<<'CPP'

#include "thirdparty/php/streams/php_streams_int.h"

#include "swoole_file_hook.h"

#define sw_php_stream_fopen_from_fd_rel(fd, mode, persistent_id, zero_position)                                         \
    _sw_php_stream_fopen_from_fd((fd), (mode), (persistent_id), (zero_position) STREAMS_REL_CC)

#define sw_php_stream_fopen_from_fd_int_rel(fd, mode, persistent_id)                                                    \
    _sw_php_stream_fopen_from_fd_int((fd), (mode), (persistent_id) STREAMS_REL_CC)
CPP, <<<'CPP'

#include "thirdparty/php/streams/php_streams_int.h"

#include "swoole_file_hook.h"

// File metadata and stdio may block too. Only the syscall runs in a worker;
// request state, PHP allocations and diagnostics remain on the owning thread.
static long sw_php_file_operation(const std::function<long()> &operation) {
    if (!swoole::Coroutine::get_current()) { return operation(); }
    long result = -1;
    swoole::coroutine::async([&]() { result = operation(); });
    return result;
}

static int sw_php_stdio_seek(FILE *file, zend_off_t offset, int whence) {
    return sw_php_file_operation([&]() { return fseek(file, offset, whence); });
}

static long sw_php_stdio_tell(FILE *file) {
    return sw_php_file_operation([&]() { return ftell(file); });
}

#ifdef __APPLE__
static int sw_php_metadata_stat(const char *path, zend_stat_t *result, bool link) {
    if (!swoole::Coroutine::get_current()) {
        return link ? VCWD_LSTAT(path, result) : VCWD_STAT(path, result);
    }
    char absolute[MAXPATHLEN];
    // Match PHP virtual_stat/virtual_lstat, including their different symlink rules.
    if (!expand_filepath_with_mode(path, absolute, nullptr, 0, link ? CWD_EXPAND : CWD_REALPATH)) {
        return -1;
    }
    return link ? swoole_coroutine_lstat(absolute, result) : swoole_coroutine_stat(absolute, result);
}

#else
static int sw_php_metadata_stat(const char *path, zend_stat_t *result, bool link) {
    return link ? VCWD_LSTAT(path, result) : VCWD_STAT(path, result);
}
#endif

#define sw_php_stream_fopen_from_fd_rel(fd, mode, persistent_id, zero_position)                                         \
    _sw_php_stream_fopen_from_fd((fd), (mode), (persistent_id), (zero_position) STREAMS_REL_CC)

#define sw_php_stream_fopen_from_fd_int_rel(fd, mode, persistent_id)                                                    \
    _sw_php_stream_fopen_from_fd_int((fd), (mode), (persistent_id) STREAMS_REL_CC)
CPP);
        $source = self::replace($source, <<<'CPP'
    if (!d->cached_fstat || force) {
        int fd;
        int r;

        PHP_STDIOP_GET_FD(fd, d);
        r = zend_fstat(fd, &d->sb);
        d->cached_fstat = r == 0;

        return r;
    }
    return 0;
CPP, <<<'CPP'
    if (!d->cached_fstat || force) {
        int fd;
        int r;

        PHP_STDIOP_GET_FD(fd, d);
#ifdef __APPLE__
        r = swoole_coroutine_fstat(fd, &d->sb);
#else
        r = zend_fstat(fd, &d->sb);
#endif
        d->cached_fstat = r == 0;

        return r;
    }
    return 0;
CPP);
        $source = self::replace($source, <<<'CPP'
        }
        return bytes_written;
    } else {
#ifdef HAVE_FLUSHIO
        if (data->is_seekable && data->last_op == 'r') {
            fseek(data->file, 0, SEEK_CUR);
        }
        data->last_op = 'w';
#endif

        return (ssize_t) fwrite(buf, 1, count, data->file);
CPP, <<<'CPP'
        }
        return bytes_written;
    } else {
#ifdef HAVE_FLUSHIO
        if (data->is_seekable && data->last_op == 'r') {
            sw_php_stdio_seek(data->file, 0, SEEK_CUR);
        }
        data->last_op = 'w';
#endif

        return (ssize_t) fwrite(buf, 1, count, data->file);
CPP);
        $source = self::replace($source, <<<'CPP'
        }

    } else {
#ifdef HAVE_FLUSHIO
        if (data->is_seekable && data->last_op == 'w')
            fseek(data->file, 0, SEEK_CUR);
        data->last_op = 'r';
#endif
        ret = fread(buf, 1, count, data->file);

        stream->eof = feof(data->file);
CPP, <<<'CPP'
        }

    } else {
#ifdef HAVE_FLUSHIO
        if (data->is_seekable && data->last_op == 'w')
            sw_php_stdio_seek(data->file, 0, SEEK_CUR);
        data->last_op = 'r';
#endif
        ret = fread(buf, 1, count, data->file);

        stream->eof = feof(data->file);
CPP);
        $source = self::replace($source, <<<'CPP'

        *newoffset = result;
        return 0;

    } else {
        ret = fseek(data->file, offset, whence);
        *newoffset = ftell(data->file);
        return ret;
    }
}

static int sw_php_stdiop_cast(php_stream *stream, int castas, void **ret) {
CPP, <<<'CPP'

        *newoffset = result;
        return 0;

    } else {
        ret = sw_php_stdio_seek(data->file, offset, whence);
        *newoffset = sw_php_stdio_tell(data->file);
        return ret;
    }
}

static int sw_php_stdiop_cast(php_stream *stream, int castas, void **ret) {
CPP);
        $source = self::replace($source, <<<'CPP'
    return closedir((DIR *) stream->abstract);
}

#ifndef PHP_WIN32
static int php_plain_files_dirstream_rewind(php_stream *stream, zend_off_t offset, int whence, zend_off_t *newoffs) {
    rewinddir((DIR *) stream->abstract);
    return 0;
}
#endif

static php_stream_ops php_plain_files_dirstream_ops = {
    NULL,
CPP, <<<'CPP'
    return closedir((DIR *) stream->abstract);
}

#ifndef PHP_WIN32
static int php_plain_files_dirstream_rewind(php_stream *stream, zend_off_t offset, int whence, zend_off_t *newoffs) {
    return sw_php_file_operation([&]() { rewinddir((DIR *) stream->abstract); return 0; });
}
#endif

static php_stream_ops php_plain_files_dirstream_ops = {
    NULL,
CPP);
        $source = self::replace($source, <<<'CPP'
        }
#if PHP_VERSION_ID >= 80100
    }
#endif

#ifdef PHP_WIN32
    if (flags & PHP_STREAM_URL_STAT_LINK) {
        return VCWD_LSTAT(url, &ssb->sb);
    }
#else
CPP, <<<'CPP'
        }
#if PHP_VERSION_ID >= 80100
    }
#endif

#ifdef __APPLE__
    return sw_php_metadata_stat(url, &ssb->sb, flags & PHP_STREAM_URL_STAT_LINK);
#endif

#ifdef PHP_WIN32
    if (flags & PHP_STREAM_URL_STAT_LINK) {
        return VCWD_LSTAT(url, &ssb->sb);
    }
#else
CPP);
        $source = self::replace($source, <<<'CPP'
            /* not sure what to do in ZTS case, umask is not thread-safe */
            int oldmask = umask(077);
#endif
            int success = 0;
            if (php_copy_file(url_from, url_to) == SUCCESS) {
                if (VCWD_STAT(url_from, &sb) == 0) {
                    success = 1;
#ifndef TSRM_WIN32
                    /*
                     * Try to set user and permission info on the target.
                     * If we're not root, then some of these may fail.
                     * We try chown first, to set proper group info, relying
                     * on the system environment to have proper umask to not allow
                     * access to the file in the meantime.
                     */
                    if (chown(url_to, sb.st_uid, sb.st_gid)) {
                        php_error_docref2(NULL, url_from, url_to, E_WARNING, "%s", strerror(errno));
                        if (errno != EPERM) {
                            success = 0;
                        }
                    }

                    if (success) {
                        if (chmod(url_to, sb.st_mode)) {
                            php_error_docref2(NULL, url_from, url_to, E_WARNING, "%s", strerror(errno));
                            if (errno != EPERM) {
                                success = 0;
                            }
                        }
                    }
#endif
                    if (success) {
                        unlink(url_from);
                    }
                } else {
                    php_error_docref2(NULL, url_from, url_to, E_WARNING, "%s", strerror(errno));
                }
            } else {
CPP, <<<'CPP'
            /* not sure what to do in ZTS case, umask is not thread-safe */
            int oldmask = umask(077);
#endif
            int success = 0;
            if (php_copy_file(url_from, url_to) == SUCCESS) {
                if (sw_php_metadata_stat(url_from, &sb, false) == 0) {
                    success = 1;
#ifndef TSRM_WIN32
                    /*
                     * Try to set user and permission info on the target.
                     * If we're not root, then some of these may fail.
                     * We try chown first, to set proper group info, relying
                     * on the system environment to have proper umask to not allow
                     * access to the file in the meantime.
                     */
                    if (sw_php_file_operation([&]() { return chown(url_to, sb.st_uid, sb.st_gid); })) {
                        php_error_docref2(NULL, url_from, url_to, E_WARNING, "%s", strerror(errno));
                        if (errno != EPERM) {
                            success = 0;
                        }
                    }

                    if (success) {
                        if (sw_php_file_operation([&]() { return chmod(url_to, sb.st_mode); })) {
                            php_error_docref2(NULL, url_from, url_to, E_WARNING, "%s", strerror(errno));
                            if (errno != EPERM) {
                                success = 0;
                            }
                        }
                    }
#endif
                    if (success) {
                        if (unlink(url_from) != 0) { success = 0; }
                    }
                } else {
                    php_error_docref2(NULL, url_from, url_to, E_WARNING, "%s", strerror(errno));
                }
            } else {
CPP);
        $source = self::replace($source, <<<'CPP'
            while (p > buf && *(p - 1) == DEFAULT_SLASH) {
                ++n;
                --p;
                *p = '\0';
            }
            if (VCWD_STAT(buf, &sb) == 0) {
                while (1) {
                    *p = DEFAULT_SLASH;
                    if (!n) break;
                    --n;
                    ++p;
CPP, <<<'CPP'
            while (p > buf && *(p - 1) == DEFAULT_SLASH) {
                ++n;
                --p;
                *p = '\0';
            }
            if (sw_php_metadata_stat(buf, &sb, false) == 0) {
                while (1) {
                    *p = DEFAULT_SLASH;
                    if (!n) break;
                    --n;
                    ++p;
CPP);
        $source = self::replace($source, <<<'CPP'
                return 0;
            }
            close(file);
        }

        ret = utime(url, newtime);
        break;
#ifndef PHP_WIN32
    case PHP_STREAM_META_OWNER_NAME:
    case PHP_STREAM_META_OWNER:
        if (option == PHP_STREAM_META_OWNER_NAME) {
CPP, <<<'CPP'
                return 0;
            }
            close(file);
        }

        ret = sw_php_file_operation([&]() { return utime(url, newtime); });
        break;
#ifndef PHP_WIN32
    case PHP_STREAM_META_OWNER_NAME:
    case PHP_STREAM_META_OWNER:
        if (option == PHP_STREAM_META_OWNER_NAME) {
CPP);
        $source = self::replace($source, <<<'CPP'
                return 0;
            }
        } else {
            uid = (uid_t) * (long *) value;
        }
        ret = chown(url, uid, -1);
        break;
    case PHP_STREAM_META_GROUP:
    case PHP_STREAM_META_GROUP_NAME:
        if (option == PHP_STREAM_META_GROUP_NAME) {
            if (php_get_gid_by_name((char *) value, &gid) != SUCCESS) {
CPP, <<<'CPP'
                return 0;
            }
        } else {
            uid = (uid_t) * (long *) value;
        }
        ret = sw_php_file_operation([&]() { return chown(url, uid, -1); });
        break;
    case PHP_STREAM_META_GROUP:
    case PHP_STREAM_META_GROUP_NAME:
        if (option == PHP_STREAM_META_GROUP_NAME) {
            if (php_get_gid_by_name((char *) value, &gid) != SUCCESS) {
CPP);
        $source = self::replace($source, <<<'CPP'
                return 0;
            }
        } else {
            gid = (gid_t) * (long *) value;
        }
        ret = chown(url, -1, gid);
        break;
#endif
    case PHP_STREAM_META_ACCESS:
        mode = (mode_t) * (zend_long *) value;
        ret = chmod(url, mode);
        break;
    default:
        zend_value_error("Unknown option %d for stream_metadata", option);
        return 0;
    }
CPP, <<<'CPP'
                return 0;
            }
        } else {
            gid = (gid_t) * (long *) value;
        }
        ret = sw_php_file_operation([&]() { return chown(url, -1, gid); });
        break;
#endif
    case PHP_STREAM_META_ACCESS:
        mode = (mode_t) * (zend_long *) value;
        ret = sw_php_file_operation([&]() { return chmod(url, mode); });
        break;
    default:
        zend_value_error("Unknown option %d for stream_metadata", option);
        return 0;
    }
CPP);
        $contents['thirdparty/php/streams/plain_wrapper.c'] = $source;
        return $contents;
    }

    private static function replace(string $source, string $before, string $after): string
    {
        if (substr_count($source, $before) !== 1) {
            throw new RuntimeException('Swoole I/O 原文替换位置不唯一，拒绝继续');
        }
        return str_replace($before, $after, $source);
    }
}
