<?php

declare(strict_types=1);

namespace Type\Build;

use Composer\InstalledVersions;
use RuntimeException;
use TypePhp\Translator;

/** 构建期限定适配：保留 Zend 异常边界，并为显式线程应用分离 AOT 请求与主入口。 */
final class TypephpCompatibility extends Translator
{
    private bool $propertyCompatibilityVerified = false;

    private const REFERENCES = [
        'swoole/typephp' => 'f127dadf5dc6e554ff5182fd35a6c499fea47242',
        'swoole/phpx' => '6f2089379cbc7ae22dacf0faa65dd05e40d72c20',
    ];

    /**
     * 核对已安装工具链身份后初始化构建期适配，不执行应用启动代码。
     *
     * @throws RuntimeException 编译器/PHPX引用或实际编译器目录与锁定身份不一致。
     */
    public static function install(string $compilerRoot): void
    {
        foreach (self::REFERENCES as $package => $reference) {
            if (InstalledVersions::getReference($package) !== $reference) {
                throw new RuntimeException('编译器兼容适配需要重新核对工具版本：' . $package);
            }
        }
        if (realpath($compilerRoot) !== realpath((string) InstalledVersions::getInstallPath('swoole/typephp'))) {
            throw new RuntimeException('编译器入口与锁定安装目录不一致');
        }
        // 上游构造函数先创建默认 build，再解析 --build-dir；默认输出属于应用而非只读 vendor。
        $applicationRoot = getcwd();
        if ($applicationRoot === false) {
            throw new RuntimeException('无法确定应用构建工作目录');
        }
        new self($applicationRoot);
    }

    protected function buildFuncCallConfig(): array
    {
        $configuration = parent::buildFuncCallConfig();
        // PHPX 2.9.0 的快速路径调用对象钩子后仍未检查 EG(exception)。标准 php::call 已检查
        // 并传播同一异常，也正确清理序列化资源。普通函数和 universal methods 共用此映射。
        // 上游依据：swoole/phpx@6f208937 的 include/std/json.h 与 include/std/misc.h。
        unset($configuration['json_encode'], $configuration['serialize'], $configuration['unserialize']);
        return $configuration;
    }

    protected function getPlatform(): \TypePhp\Platform\PlatformBase
    {
        if ($this->platform === null && PHP_OS_FAMILY === 'Windows') {
            $this->platform = new WindowsSdkPlatform();
        }
        return parent::getPlatform();
    }

    /** PHPX attrRef 对值钩子返回空引用；原生调用必须恢复虚拟属性的拒绝边界。 */
    protected function emitDynamicPropertyFetchRef(\PhpParser\Node\Expr\PropertyFetch $expr, \PhpParser\NodeAbstract $errorNode): string
    {
        $result = parent::emitDynamicPropertyFetchRef($expr, $errorNode);
        if (preg_match('/^(.*)\.attrRef\((.*)\)$/sD', $result, $parts) !== 1) {
            return $result;
        }
        $this->assertPropertyCompatibility();
        // 使用 Zend 属性访问元数据，仅补齐引擎权限语义；不加载、反射或解释业务模型。
        return '([&]() -> php::Ref { auto object = ' . $parts[1] . '; php::Str name = ' . $parts[2] . '; '
            . 'if (object.isObject()) { auto info = zend_get_property_info(object.ce(), name.str(), true); '
            . 'if (info && info != ZEND_WRONG_PROPERTY_INFO && (info->flags & ZEND_ACC_VIRTUAL) && info->hooks '
            . '&& info->hooks[ZEND_PROPERTY_HOOK_GET] && !(info->hooks[ZEND_PROPERTY_HOOK_GET]->common.fn_flags & ZEND_ACC_RETURN_REFERENCE)) '
            . '{ php::throwError("Cannot take reference of a virtual property without a reference getter"); } } '
            . 'return object.attrRef(name); })()';
    }

    /** unset 数组元素也属于间接写入，不能只读取值钩子的临时数组。 */
    protected function parseUnset(\PhpParser\Node\Stmt\Unset_ $node): string
    {
        $restore = [];
        foreach ($node->vars as $variable) {
            $target = $variable;
            $arrays = [];
            while ($target instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
                $arrays[] = $target;
                $target = $target->var;
            }
            if ($arrays === [] || !$target instanceof \PhpParser\Node\Expr\PropertyFetch) {
                continue;
            }
            $this->assertPropertyCompatibility();
            foreach ($arrays as $array) {
                $restore[] = [$array, $array->getAttributes()];
                $array->setAttribute(self::ATTR_ARRAY_DIM_FETCH_UPDATE, true);
            }
            $restore[] = [$target, $target->getAttributes()];
            $target->setAttribute(self::ATTR_PROPERTY_FETCH_UPDATE, true);
        }
        try {
            return parent::parseUnset($node);
        } finally {
            foreach (array_reverse($restore) as [$target, $attributes]) {
                $target->setAttributes($attributes);
            }
        }
    }

    private function assertPropertyCompatibility(): void
    {
        if ($this->propertyCompatibilityVerified) {
            return;
        }
        $source = InstalledVersions::getInstallPath('swoole/typephp') . '/src/Parser/PropertyAccessTrait.php';
        $runtime = InstalledVersions::getInstallPath('swoole/phpx') . '/src/core/variant.cc';
        if (hash_file('sha256', $source) !== '7320defa230b8ac68314604aea2ffcd9b37455fe586bb777af5d9cc8f00dd859'
            || hash_file('sha256', $runtime) !== '0fb7b9cae9b4b5681e825333cce4b91ac1dc4d6cc6383b28a35ed44ecaf2b10d') {
            throw new RuntimeException('虚拟属性适配需要重新核对 TypePHP/PHPX 原文');
        }
        $this->propertyCompatibilityVerified = true;
    }

    /** 仅显式生成了线程入口表的完整应用使用新的模块生命周期。 */
    private function threaded(): bool
    {
        return $this->isBuildModeBin() && $this->hasFunction('type_app_compiled_thread_run');
    }

    /** 未静态解析的属性读取必须携带调用方权限，不能沿用 PHPX 的接收对象权限。 */
    protected function parsePropertyFetch(\PhpParser\Node\Expr\PropertyFetch $expr): string
    {
        $result = parent::parsePropertyFetch($expr);
        if ($this->isPropertyFetchUpdate($expr) && $this->getNativePropertyDef($expr)?->virtual
            && !$this->isNativeObjectPropertyHook($expr)) {
            // 静态类型的 getter 快路径也必须经过引擎的间接写入检查。
            $result = $this->parseIdentifier($expr->var) . '.attr(' . $this->propertyNameToStr($expr->name, literal: true) . ', php::AttrMode::Update)';
        }
        if ($this->threaded() && $this->getNativePropertyDef($expr) === null) {
            $result = $this->threadPropertyRead($result);
        }
        return $this->guardVirtualPropertyUpdate($result);
    }

    /** Zend 值钩子读取的数组不能作为间接赋值目标；不能静默修改临时数组。 */
    private function guardVirtualPropertyUpdate(string $result): string
    {
        if (preg_match('/^typephp_read_property_cached\((.*), (.*), php::AttrMode::Update, (.*)\)$/sD', $result, $parts) === 1) {
            $body = 'typephp_read_property_cached(object, name, php::AttrMode::Update, ' . $parts[3] . ')';
        } elseif (preg_match('/^type_app_read_property\((.*), (.*), (.*), php::AttrMode::Update\)$/sD', $result, $parts) === 1) {
            $body = 'type_app_read_property(object, name, ' . $parts[3] . ', php::AttrMode::Update)';
        } elseif (preg_match('/^(.*)\.attr\((.*), php::AttrMode::Update\)$/sD', $result, $parts) === 1) {
            $body = 'object.attr(name, php::AttrMode::Update)';
        } else {
            return $result;
        }
        if (str_starts_with($parts[2], 'get_persistent_prop(')) {
            // 已解析的普通属性通过属性指针读取；虚拟属性使用 getter 或名称访问。
            return $result;
        }
        $this->assertPropertyCompatibility();
        return '([&]() -> php::Var { auto object = ' . $parts[1] . '; php::Str name = ' . $parts[2] . '; '
            . 'if (object.isObject()) { auto info = zend_get_property_info(object.ce(), name.str(), true); '
            . 'if (info && info != ZEND_WRONG_PROPERTY_INFO && (info->flags & ZEND_ACC_VIRTUAL) && info->hooks '
            . '&& info->hooks[ZEND_PROPERTY_HOOK_GET] && !(info->hooks[ZEND_PROPERTY_HOOK_GET]->common.fn_flags & ZEND_ACC_RETURN_REFERENCE)) '
            . '{ php::throwError("Indirect modification of a virtual property without a reference getter is not allowed"); } } '
            . 'return ' . $body . '; })()';
    }

    /** 普通、复合与数组间接写入复用同一调用方权限边界。 */
    private function threadPropertyRead(string $result): string
    {
        $source = InstalledVersions::getInstallPath('swoole/typephp') . '/src/Parser/PropertyAccessTrait.php';
        if (hash_file('sha256', $source) !== '7320defa230b8ac68314604aea2ffcd9b37455fe586bb777af5d9cc8f00dd859') {
            throw new RuntimeException('属性作用域适配需要重新核对 TypePHP 原文');
        }
        $scope = $this->classDef?->trait ? 'const_cast<zend_class_entry *>(php::FakeScopeGuard::current())'
            : ($this->class ? $this->getLocalClassEntryPtr($this->getFullClassName()) : 'nullptr');
        if (preg_match('/^typephp_read_property_cached\((.*), (php::AttrMode::\w+), [^,]+\)$/sD', $result, $parts) === 1) {
            return 'type_app_read_property(' . $parts[1] . ', ' . $scope . ', ' . $parts[2] . ')';
        }
        if (preg_match('/^(.*)\.attr\((.*), (php::AttrMode::\w+)\)$/sD', $result, $parts) === 1) {
            if (str_starts_with($parts[2], 'get_persistent_prop(')) {
                // nullsafe 参数也可能包含已解析属性；偏移不能交给按名称读取的适配。
                return $result;
            }
            return 'type_app_read_property(' . $parts[1] . ', ' . $parts[2] . ', ' . $scope . ', ' . $parts[3] . ')';
        }
        if (preg_match('/^(.*)\.getProperty\((.*)\)$/sD', $result, $parts) === 1) {
            return 'type_app_read_property(' . $parts[1] . ', ' . $parts[2] . ', ' . $scope . ', php::AttrMode::Get)';
        }
        return $result;
    }

    protected function emitDynamicPropertyRead(string $object, string $property, ?string $cache = null): string
    {
        $result = parent::emitDynamicPropertyRead($object, $property, $cache);
        return $this->threaded() ? $this->threadPropertyRead($result) : $result;
    }

    protected function emitDynamicPropertyAppendArray(string $object, string $property, string $value, ?string $cache = null): string
    {
        if (str_starts_with($property, 'get_persistent_prop(') || $this->usesTraitPropertyScope($object)) {
            return parent::emitDynamicPropertyAppendArray($object, $property, $value, $cache);
        }
        if ($this->threaded() && !$this->usesTraitPropertyScope($object)) {
            return $this->guardVirtualPropertyUpdate($this->threadPropertyRead($object . '.attr(' . $property . ', php::AttrMode::Update)')) . '.newItem() = ' . $value;
        }
        return $this->guardVirtualPropertyUpdate($object . '.attr(' . $property . ', php::AttrMode::Update)') . '.newItem() = ' . $value;
    }

    protected function emitDynamicPropertyUpdateArray(string $object, string $property, string $dim, string $value, ?string $cache = null): string
    {
        if (str_starts_with($property, 'get_persistent_prop(') || $this->usesTraitPropertyScope($object)) {
            return parent::emitDynamicPropertyUpdateArray($object, $property, $dim, $value, $cache);
        }
        if ($this->threaded() && !$this->usesTraitPropertyScope($object)) {
            return $this->guardVirtualPropertyUpdate($this->threadPropertyRead($object . '.attr(' . $property . ', php::AttrMode::Update)')) . '.item(' . $dim . ', true) = ' . $value;
        }
        return $this->guardVirtualPropertyUpdate($object . '.attr(' . $property . ', php::AttrMode::Update)') . '.item(' . $dim . ', true) = ' . $value;
    }

    /** nullsafe 的属性访问在前置 lambda 中生成，同样必须保留调用方权限和短路。 */
    protected function parseNullsafeExpr(\PhpParser\Node\Expr\PropertyFetch|\PhpParser\Node\Expr\MethodCall|\PhpParser\Node\Expr\NullsafePropertyFetch|\PhpParser\Node\Expr\NullsafeMethodCall $expr): string
    {
        $start = count($this->context->beforeStmtLines);
        $result = parent::parseNullsafeExpr($expr);
        if (!$this->threaded()) {
            return $result;
        }
        $source = InstalledVersions::getInstallPath('swoole/typephp') . '/src/Parser/NullsafeAccessTrait.php';
        if (hash_file('sha256', $source) !== 'f05775fb8e4657d93566a3466bb1f4b36b36031d7c31cd8211e0a6a81b6d621f') {
            throw new RuntimeException('nullsafe 属性适配需要重新核对 TypePHP 原文');
        }
        for ($index = $start; $index < count($this->context->beforeStmtLines); ++$index) {
            $this->context->beforeStmtLines[$index] = preg_replace_callback(
                '/^([ \t]*[A-Za-z0-9_]+ = )([A-Za-z0-9_]+\.attr\(.*\));$/m',
                fn (array $parts): string => $parts[1] . $this->threadPropertyRead($parts[2]) . ';',
                $this->context->beforeStmtLines[$index]
            ) ?? throw new RuntimeException('nullsafe 属性生成结果无法适配');
        }
        return $result;
    }

    /** AOT 回调显式绑定声明作用域，不改写或伪造 Zend 用户函数栈。 */
    protected function markUserCodeCallableScope(): void
    {
        if (!$this->threaded()) {
            parent::markUserCodeCallableScope();
        }
    }

    protected function markInternalFunctionCallbackCall(string $function, array $args): void
    {
        parent::markInternalFunctionCallbackCall($function, $args);
        if (!$this->threaded()) {
            return;
        }
        foreach ($args as $argument) {
            $mode = $argument->getAttribute(self::ATTR_SCOPED_CALLBACK);
            if ($mode === 'normalize') {
                $argument->setAttribute(self::ATTR_SCOPED_CALLBACK, 'callable');
            } elseif ($mode === 'normalize-unpacked') {
                $argument->setAttribute(self::ATTR_SCOPED_CALLBACK, null);
            }
        }
    }

    /** 解包后才知道位置的回调复用锁定编译器自己的描述表和 PHPX 的 CallableScope。 */
    protected function parseCallArgs(array $args, string $funcName = '', string $className = '', bool $separateNamedArgs = true, bool $forceArrayArgs = false, bool $preserveExistingReferences = false): string
    {
        $result = parent::parseCallArgs($args, $funcName, $className, $separateNamedArgs, $forceArrayArgs, $preserveExistingReferences);
        if (!$this->threaded() || !$this->methodDef || $className !== '' || $forceArrayArgs || !$separateNamedArgs) {
            return $result;
        }
        $base = InstalledVersions::getInstallPath('swoole/typephp') . '/src/CompilerBase.php';
        if (hash_file('sha256', $base) !== '794fb9680acd9e869ffc60a1b58ef9d2179fbe8e68f0eb4ef53979d671de6c65') {
            throw new RuntimeException('回调适配需要重新核对 TypePHP 描述表');
        }
        // 读取构建器的固定静态表，不在生产反射业务签名或猜测 callable 参数。
        $variables = (new \ReflectionMethod(\TypePhp\CompilerBase::class, 'markInternalFunctionCallbackCall'))->getStaticVariables();
        $descriptors = $variables['callbackArgs'][strtolower(ltrim($funcName, '\\'))] ?? [];
        if ($descriptors === [] || (!$this->hasUnpackCallArg($args) && ($descriptors[0][2] ?? '') !== 'dynamic-map')) {
            return $result;
        }
        if (preg_match('/^(php::VarList\{.*\}|[A-Za-z0-9_]+)(?:, ([A-Za-z0-9_]+)\.array\(\))?$/sD', $result, $parts) !== 1) {
            throw new RuntimeException('回调适配遇到未审查的参数容器');
        }
        $temporary = $this->genTmpVarName();
        $values = str_replace('php::VarList{', 'php::ArgList{', $parts[1]);
        $this->context->beforeStmtLines[] = 'php::Args ' . $temporary . '{' . $values . '};';
        $named = isset($parts[2]) ? $parts[2] . '.array()' : 'nullptr';
        foreach ($descriptors as $descriptor) {
            $this->context->beforeStmtLines[] = 'type_app_prepare_callback(' . $temporary . ', ' . $named . ', '
                . $descriptor[0] . ', ' . $this->genCharPtr($descriptor[1], true) . ', '
                . (($descriptor[2] ?? '') === 'dynamic-map' ? 'true' : 'false') . ', ' . $this->getCallableScopeExpr() . ');';
        }
        return $temporary . (isset($parts[2]) ? ', ' . $named : '');
    }

    /** 线程应用的 exit 结束当前 Zend 请求，由外层 Swoole/embed 边界回收，不直接终止进程。 */
    protected function parseExit(\PhpParser\Node\Expr\Exit_ $node): string
    {
        $call = parent::parseExit($node);
        if (!$this->threaded()) {
            return $call;
        }
        $call = $node->expr === null ? 'php::exit(0)' : str_replace('php::aotExit(', 'php::exit(', $call);
        return '[&]() -> php::Var { ' . $call . '; return php::null; }()';
    }

    /** 全局嵌套数组沿用编译器的数组初始化计划，保留原实现遗漏的临时变量。 */
    protected function parseConstDef(mixed $statement): void
    {
        parent::parseConstDef($statement);
        if (!$this->threaded() || $this->compilerPhase !== self::PHASE_CONVERT) {
            return;
        }
        foreach ($statement->consts as $constant) {
            if ($constant->value instanceof \PhpParser\Node\Expr\Array_) {
                $this->resetFunction();
                $plan = $this->buildLiteralArrayInitPlan($constant->value);
                $name = ($this->namespace === '' ? '' : $this->namespace . '\\') . $constant->name->toString();
                $this->constants[$this->escapeConstVar($name)]->value = "[&]() {\n" . $plan->init
                    . 'php::Array type_app_constant_value = ' . $plan->expr . ";\n" . $plan->clean
                    . "return type_app_constant_value;\n}()";
            }
        }
    }

    /** 在上游格式化前按已登记符号调整生成存储；不修改已安装编译器源码。 */
    public function writeFile(string $file, string $content, bool $force = false): void
    {
        if ($this->threaded()) {
            if (hash_file('sha256', InstalledVersions::getInstallPath('swoole/typephp') . '/src/Translator.php') !== '85735bdf7a0a584b3abf91c011fab369e9d7994abd8f3a0943a3bbdc96c5c3bc') {
                throw new RuntimeException('线程生成适配需要重新核对 TypePHP 原文');
            }
            $extension = basename($file) === 'extension-' . $this->targetName . '.cc';
            // TypePHP 0.9 将声明拆成多个 *_decl.h；只有运行时声明头仍带旧版标记。
            $declarations = str_ends_with($file, '_decl.h');
            $runtimeDeclarations = str_contains($content, 'enum class RequestClassId : uint32_t {};');
            if ($runtimeDeclarations) {
                $content .= <<<'CPP'

#include <optional>

// 全局 AOT 函数没有 Zend 调用帧；用无类权限的内部边界隔离调用者，保留 Zend 异常传播。
static inline php::Var type_app_read_property(const php::Var &object, const php::Var &property,
                                              zend_class_entry *scope, php::AttrMode mode) {
    auto previous_frame = EG(current_execute_data);
    auto previous_scope = EG(fake_scope);
    zend_function boundary{};
    boundary.type = ZEND_INTERNAL_FUNCTION;
    zend_execute_data frame{};
    zend_vm_init_call_frame(&frame, ZEND_CALL_TOP_FUNCTION, &boundary, 0, nullptr);
    std::optional<php::Var> result;
    std::exception_ptr failure;
    if (!scope) { EG(current_execute_data) = &frame; }
    zend_try {
        try {
            // Var 的赋值和移动均解开间接值；显式构造保留属性目标或原引用。
            auto value = typephp_read_property_scoped(object, property, scope, mode);
            result.emplace(value.direct_ptr(), value.isIndirect() ? php::Ctor::Indirect : php::Ctor::CopyRef);
        } catch (...) {
            // C++ 异常不能越过 zend_end_try，否则 EG(bailout) 会指向已释放的栈。
            failure = std::current_exception();
        }
    } zend_catch {
        EG(current_execute_data) = previous_frame;
        EG(fake_scope) = previous_scope;
        zend_bailout();
    } zend_end_try();
    EG(current_execute_data) = previous_frame;
    EG(fake_scope) = previous_scope;
    if (failure) { std::rethrow_exception(failure); }
    return php::Var(result->direct_ptr(), result->isIndirect() ? php::Ctor::Indirect : php::Ctor::CopyRef);
}

// 只改写当前调用的参数副本；私有回调由 Zend Closure 保有自身声明作用域。
static inline void type_app_prepare_callback(php::Args &args, zend_array *named, int position,
                                            const char *name, bool map, const php::CallableScope &scope) {
    auto index = position < 0 ? static_cast<int64_t>(args.count()) + position : position;
    zval *slot = named ? zend_hash_str_find(named, name, strlen(name)) : nullptr;
    if (!slot && index >= 0 && index < args.count()) { slot = args.ptr() + index; }
    if (!slot) { return; }
    php::Var value(slot);
    if (map && value.isArray()) {
        php::Array callbacks = value.toArray();
        php::Array prepared;
        for (const auto &item : callbacks) {
            prepared.set(item.key, php::prepareScopedCallback(item.value, scope));
        }
        value = prepared;
    } else if (!map && !value.isNull()) {
        value = php::prepareScopedCallback(value, scope);
    } else { return; }
    zval_ptr_dtor(slot);
    ZVAL_COPY(slot, value.unwrap_ptr());
}
CPP;
            }
            if ($extension || $declarations) {
                $storage = [];
                foreach ($this->constants as $name => $constant) {
                    $storage[$name] = $constant->type;
                }
                foreach (array_merge($this->symbols->classes(), $this->symbols->interfaces()) as $class) {
                    if ($class instanceof \TypePhp\Entity\ClassDef && $class->trait !== null) {
                        continue;
                    }
                    foreach ($class->constants as $constant) {
                        if ($constant->type === \TypePhp\Type::ARRAY) {
                            $storage[self::PREFIX . $this->getNativeName($constant->name, $class->namespace, $class->name)] = \TypePhp\Type::VAR;
                        }
                    }
                }
                foreach ($storage as $name => $type) {
                    $before = ($declarations ? 'extern ' : '') . $type . ' ' . $name . ';';
                    $after = ($declarations ? 'extern ' : '') . 'THREAD_LOCAL ' . $type . ' ' . $name . ';';
                    // TypePHP 0.9 按源文件拆分声明头；当前头文件不一定包含每个全局/类常量。
                    $content = $this->threadReplace($content, $before, $after, true);
                }
                if ($extension) {
                    $cleanup = '';
                    foreach ($this->constants as $name => $constant) {
                        if (in_array($constant->type, [\TypePhp\Type::STR, \TypePhp\Type::ARRAY], true)) {
                            $cleanup .= $name . ".unset();\n";
                        }
                    }
                    $content = $this->threadReplace($content, 'static void module_clean() {', "static void module_clean() {\n" . $cleanup);
                }
            }
            if ($extension) {
                $module = $this->getModuleName();
                $start = strpos($content, 'PHP_RINIT_FUNCTION(' . $module . ') {');
                $end = strpos($content, 'PHP_RSHUTDOWN_FUNCTION(' . $module . ') {', $start === false ? 0 : $start);
                if ($start === false || $end === false) {
                    throw new RuntimeException('线程生成适配缺少请求生命周期');
                }
                $request = substr($content, $start, $end - $start);
                $request = preg_replace('/^php::eval\([^\r\n]+\);\r?\n/m', '', $request, 1, $count);
                if ($count !== 1 || !is_string($request)) {
                    throw new RuntimeException('线程生成适配缺少唯一主入口分派');
                }
                $request = $this->threadReplace($request, 'php::request_init();', "type_app_request_phase = 1;\nzend_try {\ntry {\nphp::request_init();\ntype_app_request_phase = 2;");
                // 报告异常时其对象和编译缓存仍然存活；PHP 8.5 的异常报告可能返回，必须显式 bailout。
                $request = $this->threadReplace($request, 'module_init();', "type_app_request_phase = 3;\nmodule_init();\n} catch (zend_object *error) {\n    zend_exception_error(error, E_ERROR);\n    zend_bailout();\n} catch (const std::exception &error) {\n    zend_error(E_ERROR, \"compiled request initialization failed: %s\", error.what());\n    zend_bailout();\n}\n} zend_catch {\n    type_app_request_clean();\n    zend_bailout();\n} zend_end_try();");
                $request = <<<'CPP'
THREAD_LOCAL unsigned type_app_request_phase = 0;

// 每阶段先解除拥有标记；bailout 越过 C++ 栈时仍继续释放剩余已获得阶段。
static void type_app_request_clean() {
    unsigned phase = type_app_request_phase;
    type_app_request_phase = 0;
    if (phase >= 2) {
        zend_try { php::request_shutdown(); } zend_end_try();
    }
    if (phase >= 3) {
        zend_try {
            try { module_clean(); } catch (zend_object *) {}
        } zend_end_try();
    }
    auto cache = php_request_cache;
    php_request_cache = nullptr;
    delete cache;
}

CPP . "\n" . $request;
                $content = substr_replace($content, $request, $start, $end - $start);
                $content = $this->threadReplace($content, <<<'CPP'
    php::request_shutdown();
    delete php_request_cache;
    php_request_cache = nullptr;
    module_clean();
CPP, '    type_app_request_clean();');
                $classes = array_values(array_filter($this->classCeList, fn (string $ce): bool => isset($this->classCeInfo[$ce])));
                $prepare = '';
                foreach ($classes as $ce) {
                    $prepare .= 'type_app_thread_constants(' . $ce . ');' . "\n";
                }
                $content = $this->threadReplace(
                    $content,
                    'static void module_init() {',
                    $this->threadConstantsHelper() . "\nstatic void module_init() {\n" . $prepare
                );
                // 只调整本模块生成的类表，在 PHP 发布进程级 ZTS 符号前准备请求槽。
                $flags = '';
                foreach ($classes as $ce) {
                    $flags .= 'if (zend_hash_num_elements(&' . $ce . "->constants_table) != 0) {\n";
                    $flags .= $ce . "->ce_flags |= ZEND_ACC_HAS_AST_CONSTANTS;\n";
                    $flags .= 'if (!ZEND_MAP_PTR(' . $ce . '->mutable_data)) { ZEND_MAP_PTR(' . $ce . "->mutable_data) = static_cast<zend_class_mutable_data *>(zend_map_ptr_new()); }\n}\n";
                }
                $minitStart = strpos($content, 'PHP_MINIT_FUNCTION(' . $module . ') {');
                $minitEnd = strpos($content, 'PHP_MSHUTDOWN_FUNCTION(' . $module . ') {');
                if ($minitStart === false || $minitEnd === false) {
                    throw new RuntimeException('线程生成适配缺少模块生命周期');
                }
                $minit = substr($content, $minitStart, $minitEnd - $minitStart);
                $minit = $this->threadReplace($minit, 'return SUCCESS;', $flags . 'return SUCCESS;');
                $content = substr_replace($content, $minit, $minitStart, $minitEnd - $minitStart);
                $arguments = count($this->symbols->function(self::ENTRY_FUNCTION)->argInfoList) === 2 ? ', php::ArgList{php::Var(argc), arguments}' : '';
                $content .= "\nextern \"C\" void type_app_compiled_process_main(int argc, char **argv) {\n";
                $content .= "php::Array arguments;\nfor (int index = 0; index < argc; ++index) { arguments.append(php::String(argv[index])); }\n";
                $content .= "php::global(\"argc\") = argc;\nphp::global(\"argv\") = arguments;\n";
                $entryFile = $this->genCharPtr($this->symbols->function(self::ENTRY_FUNCTION)->sourceFile, true);
                $content .= "auto server = php::global(\"_SERVER\");\n";
                foreach (['PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PATH_TRANSLATED'] as $field) {
                    $content .= 'server.item("' . $field . '", true) = ' . $entryFile . ";\n";
                }
                $content .= "server.item(\"DOCUMENT_ROOT\", true) = \"\";\n";
                $content .= 'php::call("main"' . $arguments . ");\n"
                    . "auto application_exit_status = php::global(\"type_app_exit_status\");\n"
                    . "if (application_exit_status.isInt()) { EG(exit_status) = application_exit_status.toInt(); }\n}\n";
            }
        }
        parent::writeFile($file, $content, $force);
    }

    /**
     * 线程运行时必须通过 compileFile() 替换 PHPX 的公共 runtime 源码。
     * TypePHP 0.9 的进程池直接生成编译命令，绕过了该覆写；线程构建只
     * 临时关闭这层进程池，仍复用上游的源码队列、缓存、编译选项和链接流程。
     *
     * @param list<string> $sourceFiles
     * @return list<string>
     */
    public function compile(array $sourceFiles): array
    {
        if (!$this->threaded()) {
            return parent::compile($sourceFiles);
        }
        $maxJob = $this->maxJob;
        $this->maxJob = 1;
        try {
            return parent::compile($sourceFiles);
        } finally {
            $this->maxJob = $maxJob;
        }
    }

    /** 复用编译器的编译选项与对象缓存，仅替换显式线程应用的 embed 生命周期源。 */
    public function compileFile(string $cppFile, string $objectFile, bool $parallel = false): void
    {
        $runtimeSource = realpath($this->getPhpxDir() . '/src/misc/typephp_runtime.cc');
        $compiledSource = realpath($cppFile);
        if ($this->threaded() && $runtimeSource !== false && $compiledSource !== false && $compiledSource === $runtimeSource) {
            $cppFile = __DIR__ . '/Native/thread-runtime.cc';
        }
        parent::compileFile($cppFile, $objectFile, $parallel);
    }

    private function threadReplace(string $source, string $before, string $after, bool $allowMissing = false): string
    {
        $count = substr_count($source, $before);
        if ($count === 0 && $allowMissing) {
            return $source;
        }
        if ($count !== 1) {
            $preview = preg_replace('/\s+/', ' ', trim($before));
            throw new RuntimeException('线程生成适配的替换位置不唯一: count=' . $count . ', before=' . substr((string) $preview, 0, 240));
        }
        return str_replace($before, $after, $source);
    }

    private function threadConstantsHelper(): string
    {
        return <<<'CPP'
// Zend 请求表负责清理。数组占位记录初始为 NULL，也必须复制为本请求拥有的记录。
static void type_app_thread_constants(zend_class_entry *ce) {
    if (zend_hash_num_elements(&ce->constants_table) == 0) { return; }
    HashTable *table = CE_CONSTANTS_TABLE(ce);
    zend_string *name;
    void *record;
    ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(table, name, record) {
        auto constant = static_cast<zend_class_constant *>(record);
        if (constant->ce == ce) {
            auto original = zend_hash_find_ptr(&ce->constants_table, name);
            if (constant == original) {
                auto copy = static_cast<zend_class_constant *>(zend_arena_alloc(&CG(arena), sizeof(zend_class_constant)));
                memcpy(copy, constant, sizeof(zend_class_constant));
                zend_hash_update_ptr(table, name, copy);
            }
        } else if (ZEND_MAP_PTR(constant->ce->mutable_data)) {
            type_app_thread_constants(constant->ce);
            auto inherited = zend_hash_find_ptr(CE_CONSTANTS_TABLE(constant->ce), name);
            zend_hash_update_ptr(table, name, inherited);
        }
    } ZEND_HASH_FOREACH_END();
}
CPP;
    }

    /** 原生业务/组件源码只读；所有.o/.obj归入本次构建目录，保留原源码的include语义。 */
    public function getObjectFile(string $cppFile): string
    {
        // 空翻译单元清理也会查询一个尚不存在的生成文件，先按构建位置分流。
        $source = BuildPlatform::path($cppFile);
        $build = BuildPlatform::resolve($this->getBuildDir());
        $misc = BuildPlatform::resolve($this->getPhpxDir() . '/src/misc');
        if (BuildPlatform::contains($build, $source) || BuildPlatform::contains($misc, $source)) {
            return parent::getObjectFile($cppFile);
        }
        $source = BuildPlatform::resolve($cppFile);
        $directory = $build . '/native-objects';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建原生中间产物目录');
        }
        return $directory . '/' . hash('sha256', $source) . $this->getPlatform()->getObjectExtension();
    }
}
