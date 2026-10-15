#!/usr/bin/env bash
# 在新目录编译匹配宿主 ABI 的 sodium 模块；PHP SDK、vendor 与系统安装保持只读。
set -euo pipefail

task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
[[ $# == 2 ]] || { echo '用法：prepare-sodium-module.sh <PHP SDK根目录> <尚不存在的输出目录>' >&2; exit 1; }
task_sdk="$(cd "$1" && pwd -P)"
task_work="$2"
case "$(uname -s)" in Darwin|Linux) ;; *) echo '此入口仅制备 Unix 开发模块；Windows DLL 与生产静态 SDK 需要各自验收。' >&2; exit 1 ;; esac
[[ -x "$task_sdk/bin/php" && -x "$task_sdk/bin/phpize" && -x "$task_sdk/bin/php-config" ]] || { echo 'SDK 缺少 php/phpize/php-config。' >&2; exit 1; }
[[ ! -e "$task_work" && ! -L "$task_work" ]] || { echo '输出目录必须尚不存在，禁止覆盖共享 SDK。' >&2; exit 1; }
task_work="$("$task_sdk/bin/php" -n -r '
$parent = realpath(dirname($argv[1]));
if ($parent === false || in_array(basename($argv[1]), [".", ".."], true)) {
    fwrite(STDERR, "输出目录的父目录必须已存在\n");
    exit(1);
}
$output = $parent . "/" . basename($argv[1]);
foreach ([$argv[2], $argv[3] . "/vendor"] as $protected) {
    if ($output === $protected || str_starts_with($output, $protected . "/")) {
        fwrite(STDERR, "输出目录不能位于共享 SDK 或 vendor 内\n");
        exit(1);
    }
}
echo $output;
' "$task_work" "$task_sdk" "$task_root")"
for task_archive in "${TYPE_SODIUM_PHP_ARCHIVE:-}" "${TYPE_SODIUM_ARCHIVE:-}"; do
    [[ -z "$task_archive" || -f "$task_archive" ]] || { echo '指定的源码归档不存在，拒绝向该路径下载。' >&2; exit 1; }
done
task_version="$("$task_sdk/bin/php" -n -r 'echo PHP_VERSION, ":", (int) PHP_ZTS, ":", (int) PHP_DEBUG;')"
[[ "$task_version" == '8.5.10:1:0' && "$("$task_sdk/bin/php-config" --version)" == '8.5.10' ]] || { echo '需要 PHP 8.5.10 ZTS 非调试 SDK。' >&2; exit 1; }
for task_tool in curl tar make cc autoconf pkg-config nm; do command -v "$task_tool" >/dev/null || { echo "缺少构建工具：$task_tool" >&2; exit 1; }; done
[[ "$task_root" != *[[:space:]]* ]] || { echo '上游 libtool 要求脚本所在检出路径不含空白；最终输出目录允许空格。' >&2; exit 1; }
mkdir -p "$task_root/build"
task_scratch="$(mktemp -d "$task_root/build/sodium-module.XXXXXX")"
cleanup() {
    local task_status=$?
    if [[ "$task_status" != 0 ]]; then
        echo "sodium 制备失败，日志保留于输出目录；共享 SDK 未修改。" >&2
    fi
    rm -rf -- "$task_scratch"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -p "$task_scratch/src" "$task_work/downloads" "$task_work/modules" "$task_work/licenses" "$task_work/libsodium/lib"
task_work="$(cd "$task_work" && pwd)"

source_archive() {
    local task_file="$1" task_url="$2" task_digest="$3" task_actual
    if [[ ! -f "$task_file" ]]; then
        curl --fail --location --silent --show-error --retry 2 "$task_url" --output "$task_file"
    fi
    task_actual="$("$task_sdk/bin/php" -n -r 'echo hash_file("sha256", $argv[1]);' "$task_file")"
    [[ "$task_actual" == "$task_digest" ]] || { echo '源码归档摘要不一致，停止制备。' >&2; return 1; }
    tar -xf "$task_file" -C "$task_scratch/src"
}

task_php_archive="${TYPE_SODIUM_PHP_ARCHIVE:-$task_work/downloads/php-8.5.10.tar.xz}"
task_sodium_archive="${TYPE_SODIUM_ARCHIVE:-$task_work/downloads/libsodium-1.0.22.tar.gz}"
source_archive "$task_php_archive" 'https://www.php.net/distributions/php-8.5.10.tar.xz' '6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957'
source_archive "$task_sodium_archive" 'https://download.libsodium.org/libsodium/releases/libsodium-1.0.22.tar.gz' 'adbdd8f16149e81ac6078a03aca6fc03b592b89ef7b5ed83841c086191be3349'

(
    cd "$task_scratch/src/libsodium-1.0.22"
    ./configure --prefix="$task_scratch/libsodium" --disable-shared --enable-static --with-pic
    make -j2
    make check -j2
    make install
) >"$task_work/libsodium-build.log" 2>&1
(
    cd "$task_scratch/src/php-8.5.10/ext/sodium"
    "$task_sdk/bin/phpize"
    # PHP_EVAL_LIBLINE 识别 -L/-l，不接受裸归档路径；私有目录中只生成 .a，避免遗漏或链接共享库。
    LIBSODIUM_CFLAGS="-I$task_scratch/libsodium/include" LIBSODIUM_LIBS="-L$task_scratch/libsodium/lib -lsodium" \
        CFLAGS='-O2 -g0' ./configure --with-php-config="$task_sdk/bin/php-config" --with-sodium=shared
    make -j2
) >"$task_work/sodium-build.log" 2>&1
cp "$task_scratch/src/php-8.5.10/ext/sodium/modules/sodium.so" "$task_work/modules/sodium.so"
cp "$task_scratch/libsodium/lib/libsodium.a" "$task_work/libsodium/lib/libsodium.a"
cp -R "$task_scratch/libsodium/include" "$task_work/libsodium/include"
cp "$task_scratch/src/php-8.5.10/LICENSE" "$task_work/licenses/PHP-LICENSE"
cp "$task_scratch/src/libsodium-1.0.22/LICENSE" "$task_work/licenses/libsodium-LICENSE"

# macOS 扩展允许延迟解析 PHP 符号，但 sodium 符号必须已经静态链接，不能靠加载时崩溃发现漏链。
nm -u "$task_work/modules/sodium.so" >"$task_work/undefined-symbols.txt"
if grep -Eq '[[:space:]]_?(sodium_|crypto_)' "$task_work/undefined-symbols.txt"; then
    echo 'sodium 模块仍含未解析的 libsodium 符号，拒绝载入。' >&2
    exit 1
fi

# 载入真实模块并运行签名、验签和哈希，不能只检查文件存在或 PHP 主版本。
"$task_sdk/bin/php" -n -d "extension=$task_work/modules/sodium.so" -r '
$pair = sodium_crypto_sign_keypair();
$message = "typeapp-sodium-abi\0probe";
$signed = sodium_crypto_sign($message, sodium_crypto_sign_secretkey($pair));
if (sodium_crypto_sign_open($signed, sodium_crypto_sign_publickey($pair)) !== $message
    || strlen(sodium_crypto_generichash($message)) !== 32) {
    throw new RuntimeException("sodium 原生行为探针失败");
}
echo json_encode(["php" => PHP_VERSION, "zts" => PHP_ZTS, "debug" => PHP_DEBUG,
    "architecture" => php_uname("m"), "sodium" => phpversion("sodium"),
    "libsodium" => SODIUM_LIBRARY_VERSION, "sign_verify" => true], JSON_THROW_ON_ERROR), "\n";
' >"$task_work/probe.json"
if [[ "$(uname -s)" == Darwin ]]; then
    otool -L "$task_work/modules/sodium.so" >"$task_work/system-libraries.txt"
else
    ldd "$task_work/modules/sodium.so" >"$task_work/system-libraries.txt"
fi
if grep -Eq 'libsodium[^[:space:]]*\.(dylib|so)' "$task_work/system-libraries.txt"; then
    echo 'sodium 模块仍依赖外部 libsodium 动态库，拒绝交付。' >&2
    exit 1
fi
"$task_sdk/bin/php" -n -r '
$out = $argv[1];
$files = ["modules/sodium.so", "libsodium/lib/libsodium.a", "licenses/PHP-LICENSE", "licenses/libsodium-LICENSE"];
$hashes = [];
foreach ($files as $file) { $hashes[$file] = hash_file("sha256", $out . "/" . $file); }
$manifest = ["purpose" => "isolated-development-module", "probe" => json_decode(file_get_contents($out . "/probe.json"), true, 16, JSON_THROW_ON_ERROR),
    "php_source_sha256" => hash_file("sha256", $argv[3]), "libsodium_source_sha256" => hash_file("sha256", $argv[4]),
    "php_binary_sha256" => hash_file("sha256", $argv[2] . "/bin/php"), "php_config_sha256" => hash_file("sha256", $argv[2] . "/bin/php-config"),
    "recipe_sha256" => hash_file("sha256", $argv[5]), "files" => $hashes];
file_put_contents($out . "/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
' "$task_work" "$task_sdk" "$task_php_archive" "$task_sodium_archive" "$task_root/tools/prepare-sodium-module.sh"
printf '%s\n' "$task_work/manifest.json"
