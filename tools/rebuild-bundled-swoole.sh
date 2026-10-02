#!/usr/bin/env bash
# 维护者从固定源码重建共享模块；结果保留在新目录，不覆盖组件内已验收文件。
# PHP 内联片段的变量必须交给 PHP 解释，不由 shell 展开。
# shellcheck disable=SC2016
set -euo pipefail
task_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${PHP_HOME:?需要 PHP 8.5.10 ZTS SDK}"
[[ $# == 1 && "$1" == /* && ! -e "$1" ]] || { echo '用法：rebuild-bundled-swoole.sh <尚不存在的绝对目录>' >&2; exit 1; }
task_work="$1"
mkdir -p "$task_work/output" "$task_work/php.d"
task_php="$PHP_HOME/bin/php"
task_reference="$("$task_php" -n -r 'require $argv[1]; echo Type\Build\SwooleThreadSource::REFERENCE;' "$task_root/plugin/type-build/src/SwooleThreadSource.php")"
task_archive="$task_work/swoole.tar.gz"
task_source="$task_work/swoole-src-$task_reference"
curl --fail --location --silent --show-error --retry 3 "https://codeload.github.com/swoole/swoole-src/tar.gz/$task_reference" --output "$task_archive"
"$task_php" -n -r 'if (hash_file("sha256", $argv[1]) !== "63598eba7d2a36d8820b1501854161e5c326ab30a32a419e3aa0e4d5154936cd") { throw new RuntimeException("Swoole 源码归档摘要不符"); }' "$task_archive"
tar -xzf "$task_archive" -C "$task_work"
"$task_php" -n -r '
$report = [];
foreach (["SwooleThreadSource", "SwooleHttpSource", "SwooleSocketSource"] as $name) {
    require $argv[1] . "/plugin/type-build/src/" . $name . ".php";
    $class = "Type\\Build\\" . $name;
    $report[$name] = (new $class())->apply($argv[2]);
}
$report["tls"] = (new Type\Build\SwooleSocketSource())->applyTls($argv[2]);
file_put_contents($argv[3], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
' "$task_root" "$task_source" "$task_work/output/source.json"

task_options=(--enable-swoole=shared --enable-swoole-thread --enable-sockets --enable-mysqlnd --enable-cares --enable-swoole-pgsql --enable-swoole-sqlite --enable-swoole-curl)
task_links=''
case "$(uname -s)" in
  Linux)
    task_options+=(--with-openssl-dir=/usr)
    ;;
  Darwin)
    [[ "$(uname -m)" == arm64 ]] || { echo 'macOS 模块只提供 ARM64。' >&2; exit 1; }
    export MACOSX_DEPLOYMENT_TARGET=15.0
    task_openssl="$(brew --prefix openssl@3)"
    task_pgsql="$task_work/postgresql"
    curl --fail --location --silent --show-error --retry 3 https://ftp.postgresql.org/pub/source/v17.11/postgresql-17.11.tar.bz2 --output "$task_work/postgresql.tar.bz2"
    printf '%s  %s\n' dd27f2b3c59e73ed14aa3324901242bf69a032a6347805f274e6260322d42979 "$task_work/postgresql.tar.bz2" | shasum -a 256 --check
    tar -xf "$task_work/postgresql.tar.bz2" -C "$task_work"
    (
      cd "$task_work/postgresql-17.11"
      ./configure --prefix="$task_pgsql" --without-readline --without-icu --without-zlib --without-gssapi --without-ldap --with-ssl=openssl "CPPFLAGS=-I$task_openssl/include" "LDFLAGS=-L$task_openssl/lib"
      make -C src/interfaces/libpq -j2
      make -C src/interfaces/libpq install
      make -C src/include install
      cp src/common/libpgcommon_shlib.a src/port/libpgport_shlib.a "$task_pgsql/lib/"
    ) >"$task_work/postgresql.log" 2>&1
    task_pkgconfig="$task_pgsql/lib/pkgconfig"
    for task_formula in openssl@3 c-ares sqlite brotli; do
      task_prefix="$(brew --prefix "$task_formula")"
      task_pkgconfig="$task_pkgconfig:$task_prefix/lib/pkgconfig"
    done
    export PKG_CONFIG_PATH="$task_pkgconfig"
    export LIBPQ_CFLAGS="-I$task_pgsql/include"
    export LIBPQ_LIBS="-L$task_pgsql/lib -lpq -lpgcommon_shlib -lpgport_shlib -L$task_openssl/lib -lssl -lcrypto"
    task_pcre2="$(brew --prefix pcre2)"
    export CPPFLAGS="-I$task_pcre2/include"
    task_os_sdk="$(xcrun --show-sdk-path)"
    export CURL_CFLAGS="-I$task_os_sdk/usr/include" CURL_LIBS='-lcurl'
    task_options+=("--with-openssl-dir=$task_openssl")
    # load_hidden 隐藏静态子依赖，避免与 PHP 宿主已加载的同名库争用符号。
    task_archives=("$task_pgsql/lib/libpq.a" "$task_pgsql/lib/libpgcommon_shlib.a" "$task_pgsql/lib/libpgport_shlib.a"
      "$task_openssl/lib/libssl.a" "$task_openssl/lib/libcrypto.a" "$(brew --prefix sqlite)/lib/libsqlite3.a"
      "$(brew --prefix c-ares)/lib/libcares.a" "$(brew --prefix brotli)/lib/libbrotlienc.a"
      "$(brew --prefix brotli)/lib/libbrotlidec.a" "$(brew --prefix brotli)/lib/libbrotlicommon.a")
    for task_library in "${task_archives[@]}"; do
      [[ -f "$task_library" && "$task_library" != *,* ]] || exit 1
      task_links="$task_links -Wl,-load_hidden,$task_library"
    done
    task_links="$task_links -lcurl -lz -lpthread"
    "$task_php" -n -r '$items=[]; foreach(array_slice($argv, 2) as $file){$items[basename($file)]=hash_file("sha256",$file);}file_put_contents($argv[1],json_encode($items,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");' "$task_work/output/static-archives.json" "${task_archives[@]}"
    mkdir -p "$task_work/output/LICENSES/postgresql"
    cp "$task_work/postgresql-17.11/COPYRIGHT" "$task_work/output/LICENSES/postgresql/COPYRIGHT"
    for task_pair in 'openssl@3/LICENSE.txt' 'c-ares/LICENSE.md' 'brotli/LICENSE' 'sqlite/include/sqlite3.h'; do
      task_formula="${task_pair%%/*}"
      task_relative="${task_pair#*/}"
      mkdir -p "$task_work/output/LICENSES/$task_formula"
      cp "$(brew --prefix "$task_formula")/$task_relative" "$task_work/output/LICENSES/$task_formula/$(basename "$task_relative")"
    done
    ;;
  *) echo 'Unix 重建入口只支持 Linux 和 macOS。' >&2; exit 1 ;;
esac
(
  cd "$task_source"
  "$PHP_HOME/bin/phpize"
  CFLAGS='-O2 -g0' CXXFLAGS='-O2 -g0' ./configure --with-php-config="$PHP_HOME/bin/php-config" "${task_options[@]}"
  if [[ -n "$task_links" ]]; then
    "$task_php" -n -r '$file=$argv[1];$text=file_get_contents($file);$text=preg_replace("/^SWOOLE_SHARED_LIBADD =.*$/m", "SWOOLE_SHARED_LIBADD =".$argv[2],$text,-1,$count);if($count!==1||!is_string($text)){exit(1);}file_put_contents($file,$text);' Makefile "$task_links"
  fi
  make -j2 'EXTRA_CFLAGS=-O2 -g0' 'EXTRA_CXXFLAGS=-O2 -g0 -std=c++20'
) >"$task_work/build.log" 2>&1
cp "$task_source/modules/swoole.so" "$task_work/output/swoole.so"
if [[ "$(uname -s)" == Darwin ]]; then
  strip -x "$task_work/output/swoole.so"
  codesign --force --sign - "$task_work/output/swoole.so"
  otool -L "$task_work/output/swoole.so" > "$task_work/output/dynamic-libraries.txt"
  # 所有非系统子库已静态链接，不能发布带构建机路径的扩展。
  awk 'NR>1 {if ($1 !~ /^\/usr\/lib\// && $1 !~ /^\/System\/Library\//) exit 1}' "$task_work/output/dynamic-libraries.txt"
else
  strip --strip-unneeded "$task_work/output/swoole.so"
  ldd "$task_work/output/swoole.so" > "$task_work/output/dynamic-libraries.txt"
  if grep -q 'not found' "$task_work/output/dynamic-libraries.txt"; then exit 1; fi
  dpkg-query -W > "$task_work/output/debian-packages.txt"
fi
mkdir -p "$task_work/output/LICENSES/swoole"
while IFS= read -r task_relative; do
  mkdir -p "$task_work/output/LICENSES/swoole/$(dirname "$task_relative")"
  cp "$task_source/$task_relative" "$task_work/output/LICENSES/swoole/$task_relative"
done < <("$task_php" -n -r '$data=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);foreach(array_keys($data["licenses"]) as $file){if(str_starts_with($file,"LICENSES/swoole/")){echo substr($file,16),"\n";}}' "$task_root/plugin/type-build/resources/swoole/manifest.json")
"$task_php" -d "extension=$task_work/output/swoole.so" "$task_root/tools/verify-swoole-module.php" "$task_work/output/swoole.so" > "$task_work/output/verification.json"
printf '%s\n' "$task_work/output"
