<?php

declare(strict_types=1);

namespace Type\Build;

use Composer\InstalledVersions;
use RuntimeException;
use TypePhp\Build\CompilerRuntime;
use TypePhp\Translator;

/** 构建期限定适配：保留 Zend 异常边界，并为显式线程应用分离 AOT 请求与主入口。 */
final class TypephpCompatibility extends Translator
{
    private bool $propertyCompatibilityVerified = false;
    private bool $defaultCompatibilityVerified = false;
    private bool $interfaceConstantCompatibilityVerified = false;
    private bool $declaredMethodCompatibilityVerified = false;
    private bool $callArgumentsCompatibilityVerified = false;
    /** @var array<string, true> 需要真实 Zend 参数帧的具名函数与方法。 */
    private array $argumentFunctions = [];
    /** @var \WeakMap<\TypePhp\Context\FunctionContext, string>|null 编译期作用域到局部帧变量的映射。 */
    private ?\WeakMap $argumentFrames = null;
    /** @var list<\PhpParser\Node\Param> 当前闭包自己的参数，不包含捕获变量。 */
    private array $argumentClosureParams = [];
    private ?StaticRuntimeSdk $staticRuntimeSdk = null;

    /** 静态构建直接绑定归档，不让编译器按 -l 名称优先选中 SDK 或 Homebrew 的共享库。 */
    protected function getLinkCommandOptions(): \TypePhp\Build\LinkOptions
    {
        $options = parent::getLinkCommandOptions();
        $static = $this->staticRuntime();
        if ($static === null) {
            return $options;
        }
        $values = $options->toArray();
        $values['section_gc'] = empty($values['debug']);
        $values['libraries'] = [];
        $values['library_paths'] = [];
        $values['rpath'] = [];
        if (PHP_OS_FAMILY === 'Windows') {
            // MSVC 后端不消费 Unix 的 post_ldflags；使用其完整路径库参数入口。
            $values['libraries'] = $static->linkFlags();
            $values['post_ldflags'] = '';
        } else {
            $values['post_ldflags'] = implode(' ', array_map('escapeshellarg', $static->linkFlags()));
        }
        return new \TypePhp\Build\LinkOptions($values);
    }

    /** 内部 INI 和直接启动策略只作用于明确选择了静态 SDK 的产物。 */
    protected function getCommonCompileCommandOptions(): \TypePhp\Build\CompileOptions
    {
        $options = parent::getCommonCompileCommandOptions();
        $static = $this->staticRuntime();
        if ($static !== null) {
            $values = $options->toArray();
            // 使用上游统一参数为 C/C++ 生成可裁剪分节；Unix 链接标志仍由 SDK 提供。
            $values['section_gc'] = empty($values['debug']);
            $values['user_defines'][] = 'TYPE_APP_STATIC_RUNTIME=1';
            $hostHeaders = rtrim(BuildPlatform::path((string) getenv('PHP_HOME')), '/') . (PHP_OS_FAMILY === 'Windows' ? '/SDK/include' : '/include/php');
            $hostPhpx = rtrim(BuildPlatform::path($this->getPhpxDir()), '/');
            $includes = [];
            foreach ($values['include_paths'] as $path) {
                $path = BuildPlatform::path($path);
                if (PHP_OS_FAMILY === 'Windows' && ($path === $hostPhpx || str_starts_with($path, $hostPhpx . '/'))) {
                    continue;
                }
                $includes[] = $path === $hostHeaders || str_starts_with($path, $hostHeaders . '/')
                    ? $static->includeDirectory() . substr($path, strlen($hostHeaders)) : $path;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                array_push($includes, ...$static->windowsIncludeDirectories());
                array_push($values['user_defines'], 'ZEND_ENABLE_STATIC_TSRMLS_CACHE=1', 'NOMINMAX');
            }
            $values['include_paths'] = $includes;
            return new \TypePhp\Build\CompileOptions($values);
        }
        return $options;
    }

    private function staticRuntime(): ?StaticRuntimeSdk
    {
        // 一个编译进程只读入一次 SDK；构建结束仍由 NativeBuilder 重核全部输入。
        $profile = getenv('TYPEAPP_BUILD_PROFILE');
        return $this->staticRuntimeSdk ??= StaticRuntimeSdk::selected(is_string($profile) && $profile !== '' ? $profile : null);
    }

    /** 静态 Windows 构建保留 TypePHP 的翻译、并行编译与链接流程，只替换 CRT 选择。 */
    protected function getCompilerBackend(): \TypePhp\Backend\CompilerBackend
    {
        $backend = parent::getCompilerBackend();
        if (PHP_OS_FAMILY === 'Windows' && $this->staticRuntime() !== null && !$backend instanceof WindowsStaticBackend) {
            if (!$backend instanceof \TypePhp\Backend\Msvc) {
                throw new RuntimeException('Windows 静态 SDK 需要 MSVC 后端');
            }
            $this->compilerBackend = new WindowsStaticBackend($backend->getPlatform(), $backend->getCompilerCommand(), $backend->getLinkerCommand());
        }
        return $this->compilerBackend;
    }

    private const REFERENCES = [
        'swoole/typephp' => '874b82e96a2383712e8faf8177c6c715587e3546',
        'swoole/phpx' => 'a0138bbdd6cbfda62225adc56c558d0742114c8a',
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
        new self($applicationRoot, CompilerRuntime::source($compilerRoot, $compilerRoot . '/bin/tpc.php'));
    }

    protected function buildFuncCallConfig(): array
    {
        $configuration = parent::buildFuncCallConfig();
        // PHPX 2.9.3 的快速路径调用对象钩子后仍未检查 EG(exception)。标准 php::call 已检查
        // 并传播同一异常，也正确清理序列化资源。普通函数和 universal methods 共用此映射。
        // 上游依据：swoole/phpx@a0138bbd 的 include/std/json.h 与 include/std/misc.h。
        unset($configuration['json_encode'], $configuration['serialize'], $configuration['unserialize']);
        $configuration['func_get_arg']['namedArgs'] = ['position'];
        return $configuration;
    }

    /** 内省的参数展开不能落回读取错误调用帧的内部函数入口。 */
    protected function parseFuncCallWithOptimizer(string $name, \PhpParser\Node\Expr\FuncCall $expr): string|false
    {
        if (in_array($name, ['func_get_args', 'func_get_arg', 'func_num_args'], true) && $this->hasUnpackCallArg($expr->args)) {
            throw new RuntimeException('参数内省不支持动态展开，请直接调用 ' . $name . '()');
        }
        return parent::parseFuncCallWithOptimizer($name, $expr);
    }

    /** 用户态 Zend 包装只检查必需参数下限；未知命名参数和类型仍由原入口检查。 */
    protected function genParameterCountCheck(int $requiredArgCount, int $declaredArgCount, bool $variadic): string
    {
        $this->assertCallArgumentsCompatibility();
        return parent::genParameterCountCheck($requiredArgCount, $declaredArgCount, true);
    }

    /** 预处理时标记参数内省，避免直接 C++ 调用借用外层 Zend 帧。 */
    protected function parseFunctionDecl(\PhpParser\Node\Stmt\Function_|\PhpParser\Node\Stmt\ClassMethod $v): \TypePhp\Entity\FunctionDef
    {
        $function = parent::parseFunctionDecl($v);
        if ($this->usesCallArguments($v->stmts ?? [])) {
            $this->assertCallArgumentsCompatibility();
            if ($function->generator || $this->classDef?->nativeObject || $this->functionRequiresNativeAbi($function)) {
                throw new RuntimeException('参数内省需要普通 Zend 函数或方法，不支持 Generator 或 Native ABI：' . $v->name);
            }
            $this->argumentFunctions[$this->getFunctionName($v)] = true;
            // 元组返回的独立 C++ 入口不能绕过参数帧。
            $function->multiReturnCount = 0;
        }
        return $function;
    }

    /** 只扫描当前函数体，内层闭包和类拥有独立参数作用域。 */
    private function usesCallArguments(mixed $nodes): bool
    {
        foreach (is_array($nodes) ? $nodes : [$nodes] as $node) {
            if (!$node instanceof \PhpParser\Node
                || $node instanceof \PhpParser\Node\FunctionLike
                || $node instanceof \PhpParser\Node\Stmt\ClassLike) {
                continue;
            }
            if ($node instanceof \PhpParser\Node\Expr\FuncCall && $node->name instanceof \PhpParser\Node\Name) {
                $target = $this->resolveStaticFunctionCallTarget($node->name);
                if (in_array($target['lower'], ['func_get_args', 'func_get_arg', 'func_num_args'], true)
                    && ($target['definitelyGlobal'] || $target['namespacedFallback'])) {
                    return true;
                }
            }
            foreach ($node->getSubNodeNames() as $field) {
                if ($this->usesCallArguments($node->{$field})) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 具名调用允许额外位置参数，但仍检查缺参、命名覆盖和未知命名参数。 */
    protected function checkNativeCallArgs(\PhpParser\Node\Expr\CallLike $expr, \TypePhp\Entity\FunctionDef $funcDef, array $args, string $name): void
    {
        if (!$funcDef->stub && !$this->functionRequiresNativeAbi($funcDef)
            && !$funcDef->hasVariadicArg() && count($args) > count($funcDef->argInfoList)) {
            $this->assertCallArgumentsCompatibility();
            $this->validateNativeNamedCallArgs($funcDef, $args);
            // 只限制数量检查所看的签名段；原调用 AST 与实际传入的所有值完整保留。
            parent::checkNativeCallArgs($expr, $funcDef, array_slice($args, 0, count($funcDef->argInfoList)), $name);
            return;
        }
        parent::checkNativeCallArgs($expr, $funcDef, $args, $name);
    }

    /** 需要内省或含额外实参的普通调用统一进入已编译的 Zend 包装。 */
    protected function shouldUseDynamicCallForNativeArgs(string $nativeFunc, array $args): bool
    {
        $function = $this->getFunction($nativeFunc);
        if (isset($this->argumentFunctions[$nativeFunc])
            || (!$function->stub && !$this->functionRequiresNativeAbi($function)
                && !$function->hasVariadicArg() && count($args) > count($function->argInfoList))) {
            return true;
        }
        return parent::shouldUseDynamicCallForNativeArgs($nativeFunc, $args);
    }

    /** 已解析方法指针直接进入 Zend；PHPX 的 callScoped 只接受方法名或 callable。 */
    protected function genRuntimeObjectMethodCall(
        string $object,
        string $method,
        array $args,
        string $funcName = '',
        string $className = '',
        bool $requiresDynamicScope = true,
    ): string {
        $function = $className === '' ? null : $this->findAotMethodFunctionDef($className, $funcName);
        if ($function !== null && ($this->needsCallArgumentsFrame($function)
            || (!$function->hasVariadicArg() && count($args) > count($function->argInfoList)))) {
            // 可见性已由原生方法解析验证，直接传指针避免其被 Variant(bool) 转换为 1。
            $requiresDynamicScope = false;
        }
        return parent::genRuntimeObjectMethodCall($object, $method, $args, $funcName, $className, $requiresDynamicScope);
    }

    /** 动态分派仍用 AOT 符号定位具名函数的引用参数，不将引用退化为值副本。 */
    protected function genRuntimeFunctionCall(
        string $callable,
        array $args,
        string $funcName = '',
        string $className = '',
        bool $separateNamedArgs = true,
        string $scope = '',
    ): string {
        if ($className === '' && $funcName !== '') {
            $native = $this->findNativeFunction('\\' . ltrim($funcName, '\\'));
            if ($native !== false && $this->shouldUseDynamicCallForNativeArgs($native, $args)) {
                $funcName = $native;
            }
        }
        return parent::genRuntimeFunctionCall($callable, $args, $funcName, $className, $separateNamedArgs, $scope);
    }

    /** self/static 的快捷入口也必须保留被调用者自己的帧及晚绑定类。 */
    protected function parseStaticCall(\PhpParser\Node\Expr\StaticCall $expr): string
    {
        if ($expr->class instanceof \PhpParser\Node\Name && in_array(strtolower($expr->class->toString()), ['self', 'static'], true)
            && $expr->name instanceof \PhpParser\Node\Identifier) {
            $method = $this->findAotMethodFunctionDef($this->getFullClassName(), $expr->name->toString());
            if ($method !== null && ($this->needsCallArgumentsFrame($method)
                || (!$method->hasVariadicArg() && count($expr->args) > count($method->argInfoList)))) {
                // 沿用上游可见性和签名检查，实际参数仍完整传入 Zend。
                $class = $this->getFullClassName();
                $this->getNativeMethod($expr, $class, $method->name);
                $calledClass = $this->getCalledCeExpr();
                $pointer = strtolower($expr->class->toString()) === 'static'
                    ? 'php::getMethod(' . $calledClass . ', ' . $this->getLiteralString($method->name) . ')'
                    : $this->getMethodPtr($class, $method->name);
                if (!($this->getMethodFlags($class, $method->name) & \PhpParser\Modifiers::STATIC)) {
                    return $this->genRuntimeObjectMethodCall('this_', $pointer, $expr->args, $method->name, $class);
                }
                return $this->genRuntimeFunctionCall($calledClass . ', ' . $pointer, $expr->args, $method->name, $class);
            }
        }
        return parent::parseStaticCall($expr);
    }

    /** 魔术方法不能从优化入口直接进入需要参数内省的函数体。 */
    protected function parseDirectNativeMagicCall(\PhpParser\Node\Expr\MethodCall $expr, string $object, string $class, string $method): ?string
    {
        $function = $class === '' ? null : $this->findAotMethodFunctionDef($class, '__call');
        return $function !== null && $this->needsCallArgumentsFrame($function)
            ? null : parent::parseDirectNativeMagicCall($expr, $object, $class, $method);
    }

    /** 方法元数据归一到预处理登记的原生名称，继承方法使用实际声明类。 */
    private function needsCallArgumentsFrame(\TypePhp\Entity\FunctionDef $function): bool
    {
        $class = $function->declaringClass === '' ? '' : $this->getClass($function->declaringClass)->name;
        return isset($this->argumentFunctions[$this->getNativeName($function->name, $function->namespace, $class)]);
    }

    /** 闭包内省只读取本次闭包参数，不能读取外层具名函数的签名。 */
    protected function genClosure(\PhpParser\Node\Expr\ArrowFunction|\PhpParser\Node\Expr\Closure $expr, array $params, array $uses = []): string
    {
        $previous = $this->argumentClosureParams;
        $this->argumentClosureParams = $params;
        try {
            if ($this->usesCallArguments($expr instanceof \PhpParser\Node\Expr\ArrowFunction ? $expr->expr : $expr->stmts)
                && $this->closureContainsYield($expr)) {
                throw new RuntimeException('参数内省暂不支持 Generator 闭包');
            }
            return parent::genClosure($expr, $params, $uses);
        } finally {
            $this->argumentClosureParams = $previous;
        }
    }

    /** 含内省别名的局部闭包同样保留 Zend 入口，不降级为无调用帧的 C++ lambda。 */
    protected function parseNativeLocalClosureAssignment(\PhpParser\Node\Expr\Assign $assign): ?string
    {
        $closure = $assign->expr;
        if (($closure instanceof \PhpParser\Node\Expr\Closure || $closure instanceof \PhpParser\Node\Expr\ArrowFunction)
            && $this->usesCallArguments($closure instanceof \PhpParser\Node\Expr\ArrowFunction ? $closure->expr : $closure->stmts)) {
            return null;
        }
        return parent::parseNativeLocalClosureAssignment($assign);
    }

    /** 每个 C++ 栈帧独立持有对应 Zend 帧，协程挂起和递归都不共享可变状态。 */
    private function callArgumentsFrame(): string
    {
        $this->assertCallArgumentsCompatibility();
        $this->argumentFrames ??= new \WeakMap();
        return $this->argumentFrames[$this->context] ??= $this->genTmpVarName();
    }

    /** 在用户函数体执行前捕获参数帧，并拒绝意外绕过 Zend 包装的调用。 */
    protected function genScopeVarDecl(): string
    {
        $frame = $this->argumentFrames[$this->context] ?? null;
        if ($frame === null) {
            return parent::genScopeVarDecl();
        }
        $capture = 'zend_execute_data *const ' . $frame . ' = EG(current_execute_data);' . PHP_EOL;
        if (!$this->context->inClosure) {
            $function = $this->functionDef;
            $pointer = $function->method ? $this->getMethodPtr($function->declaringClass, $function->name)
                : $this->getFuncPtr($function->getNamespacedName());
            $capture .= 'if (!' . $frame . ' || ' . $frame . '->func->internal_function.handler != '
                . $pointer . '->internal_function.handler) { zend_throw_error(nullptr, "runtime_argument_frame_mismatch"); php::throwErrorIfOccurred(); }' . PHP_EOL;
        }
        // getMethodPtr/getFuncPtr 可能登记新的局部符号，在最终声明生成前完成。
        return parent::genScopeVarDecl() . $capture;
    }

    /** 参数列表使用本次实参数量；已声明位置读取当前局部值，额外位置保留原值。 */
    protected function genFuncGetArgs(string $name, \PhpParser\Node\Expr\FuncCall $expr, array $config): string
    {
        $frame = $this->callArgumentsFrame();
        $values = $this->genTmpVarName();
        $index = $this->genTmpVarName();
        $code = '([&]() { php::Array ' . $values . '; for (uint32_t ' . $index . ' = 0; '
            . $index . ' < ZEND_CALL_NUM_ARGS(' . $frame . '); ++' . $index . ') { ';
        $arguments = $this->context->inClosure ? $this->argumentClosureParams : $this->functionDef->argInfoList;
        foreach ($arguments as $position => $argument) {
            if ($argument->variadic) {
                break;
            }
            $variable = $argument instanceof \PhpParser\Node\Param ? $this->parseIdentifier($argument->var) : $argument->name;
            $code .= 'if (' . $index . ' == ' . $position . ') { ' . $values . '.appendValue(php::Var(' . $variable . ')); } else ';
        }
        return $code . '{ ' . $values . '.appendValue(php::Var(ZEND_CALL_ARG(' . $frame . ', ' . $index . ' + 1))); } } return ' . $values . '; }())';
    }

    /** 数量来自当前调用帧，省略的默认参数不增加数量，命名跳位遵守 Zend 规则。 */
    protected function genFuncNumArgs(string $name, \PhpParser\Node\Expr\FuncCall $expr, array $config): string
    {
        return '(static_cast<php::Int>(ZEND_CALL_NUM_ARGS(' . $this->callArgumentsFrame() . ')))';
    }

    /** 允许运行时位置，越界按 PHP 抛出 ValueError，而非编译期截断到签名。 */
    protected function genFuncGetArg(string $name, \PhpParser\Node\Expr\FuncCall $expr, array $config): string
    {
        $index = $this->genTmpVarName();
        $value = $this->getArg($expr, 0);
        $frame = $this->callArgumentsFrame();
        $arguments = $this->genFuncGetArgs($name, $expr, $config);
        return '([&](php::Var ' . $index . ') -> php::Var { '
            . 'if (!' . $index . '.isInt()) { php::throwArgumentTypeError(' . $index . ', "func_get_arg", 1, "position", "int"); } '
            . 'if (php::toInt(' . $index . ') < 0) { zend_value_error("func_get_arg(): Argument #1 ($position) must be greater than or equal to 0"); php::throwErrorIfOccurred(); } '
            . 'if (php::toInt(' . $index . ') >= ZEND_CALL_NUM_ARGS(' . $frame . ')) { '
            . 'zend_value_error("func_get_arg(): Argument #1 ($position) must be less than the number of the arguments passed to the currently executed function"); php::throwErrorIfOccurred(); } '
            . 'return ' . $arguments . '.offsetGet(php::toInt(' . $index . ')); })(' . $value . ')';
    }

    /** 参数兼容只接受审查过的声明、分发、闭包和优化器源码。 */
    private function assertCallArgumentsCompatibility(): void
    {
        if ($this->callArgumentsCompatibilityVerified) {
            return;
        }
        $compiler = InstalledVersions::getInstallPath('swoole/typephp');
        foreach ([
            'src/CompilerBase.php' => '8767d9599d1d2ab7ed7ae218fe33df90524f2f961cf76c5355574cc01cb310c3',
            'src/Preprocessor.php' => 'efb567b27334d35ef15948399ca453e67afcaa199f2406fed0359ea280813adc',
            'src/Translator.php' => '8160f9c3f01ebe7eec0bbbc54fd54f9a21b67dcd4213c309d72d8de1a161603c',
            'src/Generator/ParameterCountCheckGenerator.php' => '491fffd20a20d554ecc46bfaedfa0179535b9cfa36264e8e2f81a785e79fa273',
            'src/Generator/ClosureGenerator.php' => 'fc23c9113e8b90e0f24e4819506190de592aa565fae340caed594b15e7af5882',
            'src/Generator/CallArgumentGenerator.php' => 'eddce4623049343393cb1e8e28ad0d0a7eaca4d6ad9233d4f85e1eeae9b4c900',
            'src/Optimizer/FuncCallOptimizer.php' => 'e232cdec93e92aa8b1a74290730a0a9aa983156be771b2a7c1c5266ea3a9607c',
            'src/Parser/FunctionCallTrait.php' => 'd7bf37b372b8541b8205079a1f04e9ea942d6e58148fe1094e88ad8bb6d9e7e7',
            'src/Parser/MethodCallTrait.php' => '64f941d2b5fe0a263282d4fc871539239477dc9ff769cf2e1961f3e68ffc50f1',
            'src/Analysis/LocalClosureAnalyzer.php' => 'f1cf6e659bd03fbc6282cc028fca3790a0603b36e8753b5b13d45eb82f0525b2',
        ] as $file => $sha256) {
            if (!is_file($compiler . '/' . $file) || hash_file('sha256', $compiler . '/' . $file) !== $sha256) {
                throw new RuntimeException('调用实参适配需要重新核对 TypePHP：' . $file);
            }
        }
        $this->callArgumentsCompatibilityVerified = true;
    }

    /** 上游匿名类使用 opcode 或 eval，不能计入本项目的全量 AOT 产物。 */
    protected function parseNew(\PhpParser\Node\Expr\New_ $expr): string
    {
        if ($expr->class instanceof \PhpParser\Node\Stmt\Class_) {
            throw new RuntimeException('全量 AOT 不支持匿名类解释回退，请声明具名类');
        }
        return parent::parseNew($expr);
    }

    /** 已声明的 toString 是用户方法；仅未声明时沿用 TypePHP 的转换关键字。 */
    protected function parseMethodCall(\PhpParser\Node\Expr\MethodCall $expr): string
    {
        if ($expr->name instanceof \PhpParser\Node\Identifier
            && strtolower($expr->name->toString()) === 'tostring') {
            if (!$this->declaredMethodCompatibilityVerified) {
                $compiler = InstalledVersions::getInstallPath('swoole/typephp');
                foreach ([
                    'src/CompilerBase.php' => '8767d9599d1d2ab7ed7ae218fe33df90524f2f961cf76c5355574cc01cb310c3',
                    'src/Parser/MethodCallTrait.php' => '64f941d2b5fe0a263282d4fc871539239477dc9ff769cf2e1961f3e68ffc50f1',
                    'src/Parser/PropertyAccessTrait.php' => 'b9ce2e5a082dc568589f360c84c8b92f4aaa0698921863d2e638192cf32c908d',
                    'src/Generator/CallArgumentGenerator.php' => 'eddce4623049343393cb1e8e28ad0d0a7eaca4d6ad9233d4f85e1eeae9b4c900',
                ] as $file => $sha256) {
                    if (!is_file($compiler . '/' . $file) || hash_file('sha256', $compiler . '/' . $file) !== $sha256) {
                        throw new RuntimeException('已声明方法适配需要重新核对 TypePHP：' . $file);
                    }
                }
                $this->declaredMethodCompatibilityVerified = true;
            }
            $declaration = $this->resolveCalledFunctionDef($expr);
            if ($declaration === null
                && ($expr->var instanceof \PhpParser\Node\Expr\PropertyFetch || $expr->var instanceof \PhpParser\Node\Expr\StaticPropertyFetch)
                && $expr->var->name instanceof \PhpParser\Node\Identifier) {
                if ($expr->var instanceof \PhpParser\Node\Expr\PropertyFetch) {
                    $this->getPropertyIdentifier($expr->var, $expr->var->var, $expr->var->name);
                } elseif ($expr->var instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
                    $this->resolveNativeStaticPropertyFetch($expr->var);
                }
                $property = $this->getNativePropertyDef($expr->var);
                if ($property !== null && $property->class !== '') {
                    $declaration = $this->findAotMethodFunctionDef($property->class, $expr->name->toString());
                }
            }
            if ($declaration !== null) {
                // 复用上游跳过关键字的入口；后续仍负责可见性、继承和接收者单次求值。
                $expr = clone $expr;
                $expr->setAttribute('nativeKeywordCall', true);
            }
        }
        return parent::parseMethodCall($expr);
    }

    /** 接口中的 self 属于当前接口；上游类专用名称入口会丢掉接口名。 */
    protected function parseClassConstFetch(\PhpParser\Node\Expr\ClassConstFetch $expr): string
    {
        if ($this->classDef === null && $this->interface !== '' && $this->interfaceDef !== null
            && $this->isNameExpr($expr->class) && $this->parseIdentifier($expr->class) === 'self') {
            if (!$this->interfaceConstantCompatibilityVerified) {
                $compiler = InstalledVersions::getInstallPath('swoole/typephp');
                foreach ([
                    'src/CompilerBase.php' => '8767d9599d1d2ab7ed7ae218fe33df90524f2f961cf76c5355574cc01cb310c3',
                    'src/Preprocessor.php' => 'efb567b27334d35ef15948399ca453e67afcaa199f2406fed0359ea280813adc',
                    'src/Parser/ClassConstantFetchTrait.php' => '4d2bd3c7e2639bebd78dfc1aeeb1e5fdfbb966cea74ec90298349c4ed38aaa63',
                ] as $file => $sha256) {
                    if (!is_file($compiler . '/' . $file) || hash_file('sha256', $compiler . '/' . $file) !== $sha256) {
                        throw new RuntimeException('接口 self 常量适配需要重新核对 TypePHP：' . $file);
                    }
                }
                $this->interfaceConstantCompatibilityVerified = true;
            }
            // 仅规范化本次查找的目标；保留原声明 AST、常量注册顺序和上游解析流程。
            $resolved = clone $expr;
            $resolved->class = new \PhpParser\Node\Name\FullyQualified($this->interfaceDef->getNamespacedName(false), $expr->class->getAttributes());
            return parent::parseClassConstFetch($resolved);
        }
        return parent::parseClassConstFetch($expr);
    }

    /**
     * 默认对象工厂独立持有嵌套构造的临时值、顺序求值与清理语句。
     * 上游 0.9.4 只保存返回表达式，会丢失 new Outer(new Inner()) 的临时声明。
     * 复用上游降级及声明接口，不改应用 AST、共享 vendor 或默认参数的逐次创建语义。
     */
    protected function parseParamDefaultValue(?\PhpParser\NodeAbstract $default): ?string
    {
        if (!$default instanceof \PhpParser\Node\Expr\New_) {
            return parent::parseParamDefaultValue($default);
        }
        $this->assertDefaultCompatibility();
        $previous = $this->context;
        $this->context = new \TypePhp\Context\FunctionContext();
        try {
            $value = parent::parseParamDefaultValue($default);
            $declarations = $this->genScopeVarDecl();
            $before = $this->parseBeforeStmtLines();
            $after = $this->parseAfterStmtLines();
            if ($declarations === '' && $before === '' && $after === '') {
                return $value;
            }
            // 先持有返回对象再清理参数临时值；C++ 栈对象同样覆盖构造异常路径。
            return '([]() {' . PHP_EOL . $declarations . $before
                . 'auto type_app_default_result = ' . $value . ';' . PHP_EOL . $after
                . 'return type_app_default_result;' . PHP_EOL . '})()';
        } finally {
            $this->context = $previous;
        }
    }

    /** Zend 回调只在参数缺失时创建默认值，避免 C++ 实参求值提前触发构造及异常。 */
    protected function genWrapperFunctionArgs(
        string $fn,
        \TypePhp\Entity\FunctionDef $functionDef,
        string $displayName,
        array $implicitMethodArgs = [],
    ): string {
        $code = parent::genWrapperFunctionArgs($fn, $functionDef, $displayName, $implicitMethodArgs);
        $nativeName = str_starts_with($fn, self::PREFIX) ? substr($fn, strlen(self::PREFIX)) : $fn;
        foreach ($functionDef->argInfoList as $index => $argument) {
            if (!$argument->variadic && $argument->type === \TypePhp\Type::ARRAY) {
                // 上游直接 toArray 会把错误类型转换为数组；Zend 用户函数应先拒绝。
                $value = 'php::getCallArg(' . $index . ')';
                $check = 'if (' . $index . ' < php::getCallArgNum() && !Z_ISUNDEF_P(ZEND_CALL_ARG(EG(current_execute_data), '
                    . ($index + 1) . ')) && !' . $value . '.isArray()) { php::throwArgumentTypeError('
                    . $value . ', ' . $this->getLiteralString($displayName) . ', ' . ($index + 1) . ', '
                    . $this->getLiteralString($argument->phpName ?: $this->unescapeVarName($argument->name)) . ', "array"); }' . PHP_EOL;
                $declaration = $this->getDefaultArgumentType($argument) . ' arg_' . $argument->name . ' = ';
                $code = str_replace($declaration, $check . $declaration, $code, $count);
                if ($count !== 1) {
                    throw new RuntimeException('数组参数包装适配需要重新核对生成结构：' . $displayName);
                }
            }
            if ($argument->variadic || !$argument->hasDefaultValue()) {
                continue;
            }
            $this->assertDefaultCompatibility();
            $default = $this->genDefaultArgumentExpr($nativeName, $index);
            $getter = $argument->byRef ? 'php::getCallArgByRef' : 'php::getCallArg';
            $type = $argument->byRef ? 'php::Ref' : 'php::Var';
            // 与 PHPX 2.9.3 的 getCallArg/getCallArgByRef 保持一致：命名参数跳位是
            // IS_UNDEF，显式 null 则是已提供的值；短路判断避免读取越界调用帧。
            $missing = $index . ' >= php::getCallArgNum() || Z_ISUNDEF_P(ZEND_CALL_ARG(EG(current_execute_data), ' . ($index + 1) . '))';
            $replacement = '((' . $missing . ') ? ' . $type . '(' . $default . ') : ' . $getter . '(' . $index . '))';
            $code = str_replace($getter . '(' . $index . ', ' . $default . ')', $replacement, $code, $count);
            if ($count !== 1) {
                throw new RuntimeException('默认参数包装适配需要重新核对生成结构：' . $displayName);
            }
        }
        return $code;
    }

    /** 降级、临时作用域和包装入口必须是同一受审上游实现，版本字符串相同也不能绕过。 */
    private function assertDefaultCompatibility(): void
    {
        if ($this->defaultCompatibilityVerified) {
            return;
        }
        $compiler = InstalledVersions::getInstallPath('swoole/typephp');
        foreach ([
            'src/CompilerBase.php' => '8767d9599d1d2ab7ed7ae218fe33df90524f2f961cf76c5355574cc01cb310c3',
            'src/Preprocessor.php' => 'efb567b27334d35ef15948399ca453e67afcaa199f2406fed0359ea280813adc',
            'src/Translator.php' => '8160f9c3f01ebe7eec0bbbc54fd54f9a21b67dcd4213c309d72d8de1a161603c',
            'src/Generator/DefaultArgumentGenerator.php' => 'a7f16aa7f332dbc4182db77ed52b35a4d93e952e8259e6e6e63893ce7e40112f',
            'src/Context/FunctionContext.php' => '466114a79e5823aac3b3f99fb508708aaafc67a7937608bc02144195378f0551',
        ] as $file => $sha256) {
            if (!is_file($compiler . '/' . $file) || hash_file('sha256', $compiler . '/' . $file) !== $sha256) {
                throw new RuntimeException('默认对象参数适配需要重新核对 TypePHP：' . $file);
            }
        }
        $this->defaultCompatibilityVerified = true;
    }

    protected function getPlatform(): \TypePhp\Platform\PlatformBase
    {
        if (PHP_OS_FAMILY === 'Windows' && !$this->platform instanceof WindowsSdkPlatform) {
            $this->platform = new WindowsSdkPlatform(isZts: (bool) PHP_ZTS);
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
        if (hash_file('sha256', $source) !== 'b9ce2e5a082dc568589f360c84c8b92f4aaa0698921863d2e638192cf32c908d'
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
        if (hash_file('sha256', $source) !== 'b9ce2e5a082dc568589f360c84c8b92f4aaa0698921863d2e638192cf32c908d') {
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
                '/^([ \t]*[A-Za-z0-9_]+ = )([A-Za-z0-9_]+\.attr\(.*\));(\r?)$/m',
                fn (array $parts): string => $parts[1] . $this->threadPropertyRead($parts[2]) . ';' . $parts[3],
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
        if (hash_file('sha256', $base) !== '8767d9599d1d2ab7ed7ae218fe33df90524f2f961cf76c5355574cc01cb310c3') {
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
        if ($this->staticRuntime() !== null && basename($file) === 'extension-' . $this->targetName . '.cc') {
            if (hash_file('sha256', InstalledVersions::getInstallPath('swoole/typephp') . '/src/Translator.php') !== '8160f9c3f01ebe7eec0bbbc54fd54f9a21b67dcd4213c309d72d8de1a161603c') {
                throw new RuntimeException('静态 profile 模块依赖适配需要重新核对 TypePHP 原文');
            }
            // 上游根据所有已编译类推断依赖，包括未选中驱动中的 Pdo\\Mysql
            // 和关闭能力中的 Redis。仅这些受 profile 门禁保护的可选驱动允许
            // 省略启动依赖；其他未知扩展仍失败，不能掩盖真实依赖遗漏。
            $content = BuildProfile::targetModuleDependencies($content, $this->extensionDependencies);
        }
        if ($this->threaded()) {
            if (hash_file('sha256', InstalledVersions::getInstallPath('swoole/typephp') . '/src/Translator.php') !== '8160f9c3f01ebe7eec0bbbc54fd54f9a21b67dcd4213c309d72d8de1a161603c') {
                throw new RuntimeException('线程生成适配需要重新核对 TypePHP 原文');
            }
            $extension = basename($file) === 'extension-' . $this->targetName . '.cc';
            // TypePHP 0.9 将声明拆成多个 *_decl.h；只有运行时声明头仍带旧版标记。
            $declarations = str_ends_with($file, '_decl.h');
            $runtimeDeclarations = str_contains($content, 'enum class RequestClassId : uint32_t {};');
            if ($runtimeDeclarations) {
                $content .= <<<'CPP'

#include <optional>
#include <cstring>

// PHPX 在 Windows 的线程外部调用帧上可能让 nullsafe 动态读取绕过 Zend
// 的可见性分支；显式作用域仍交给 PHPX。只补无作用域的读/更新，isset/empty
// 继续使用 PHP 的“不可见即不存在”语义。
static inline void type_app_check_property_read(const php::Var &object, const php::Var &property,
                                                zend_class_entry *scope, php::AttrMode mode) {
    if (scope || mode == php::AttrMode::Isset || !object.isObject()) { return; }
    auto property_name = property.toString();
    auto *info = static_cast<zend_property_info *>(
        zend_hash_find_ptr(&object.ce()->properties_info, property_name.str()));
    if (!info) {
        info = zend_get_property_info(object.ce(), property_name.str(), true);
    }
    if (!info || info == ZEND_WRONG_PROPERTY_INFO) {
        void *entry;
        ZEND_HASH_FOREACH_PTR(&object.ce()->properties_info, entry) {
            auto *candidate = static_cast<zend_property_info *>(entry);
            if (candidate && std::strcmp(zend_get_unmangled_property_name(candidate->name), property_name.data()) == 0) {
                info = candidate;
                break;
            }
        } ZEND_HASH_FOREACH_END();
    }
    if (!info || info == ZEND_WRONG_PROPERTY_INFO) { return; }
    if (info->flags & ZEND_ACC_PRIVATE) {
        php::throwError("Cannot access private property %s::$%s", ZSTR_VAL(info->ce->name), property_name.str());
    }
    if (info->flags & ZEND_ACC_PROTECTED) {
        php::throwError("Cannot access protected property %s::$%s", ZSTR_VAL(info->ce->name), property_name.str());
    }
}

// 全局 AOT 函数没有 Zend 调用帧；用无类权限的内部边界隔离调用者，保留 Zend 异常传播。
static inline php::Var type_app_read_property(const php::Var &object, const php::Var &property,
                                              zend_class_entry *scope, php::AttrMode mode) {
    type_app_check_property_read(object, property, scope, mode);
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
