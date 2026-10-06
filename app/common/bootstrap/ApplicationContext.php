<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\common\database\DatabaseFactory;
use app\iot\service\ExportService;
use InvalidArgumentException;
use Type\Core\Configuration;
use Type\Core\Config\Repository;

/** 单次应用装配持有的启动数据；配置只在需要的角色读取，不持有活动连接或请求身份。 */
final class ApplicationContext
{
    private ?Repository $repository = null;
    public bool $development;

    /** snapshot 只由宿主传入，不落盘或进入构建声明及诊断；空值表示延迟读取启动配置。 */
    public function __construct(public string $basePath, public string $program, string $development, private string $snapshot)
    {
        $this->development = $development === '1';
    }

    /** 为命令和 HTTP 提供同一配置接口；线程仍只传受控 JSON 值。 */
    public static function configuration(string $basePath, string $program, bool $development, ?Repository $settings = null): Configuration
    {
        $snapshot = $settings === null ? '' : json_encode(['app' => $settings->array('app'), 'database' => $settings->array('database'), 'cache' => $settings->array('cache')], JSON_THROW_ON_ERROR);
        return new Configuration(['base' => $basePath, 'program' => $program, 'development' => $development ? '1' : '0', 'settings' => $snapshot]);
    }

    /** 每个装配实例只解析一次配置；不创建数据库或 Redis 管理器。 */
    public function settings(): Repository
    {
        if ($this->repository === null) {
            $this->repository = $this->snapshot === '' ? Settings::load($this->basePath)
                : new Repository(json_decode($this->snapshot, true, 64, JSON_THROW_ON_ERROR));
            $this->snapshot = '';
        }
        return $this->repository;
    }

    /** 导出文件服务不打开资源；HTTP 与后台使用相同根目录规则。 */
    public function exportService(): ExportService
    {
        return self::exports($this->settings(), $this->basePath);
    }

    /** 相对私有文件目录以APP_BASE_PATH为基准，HTTP和后台角色采用同一启动配置。 */
    public static function exports(Repository $settings, string $basePath): ExportService
    {
        RuntimeCapabilities::requireFeature('exports');
        $directory = $settings->text('app.exports.directory');
        return new ExportService(Settings::absolutePath($directory) ? $directory : $basePath . '/' . $directory);
    }


    /**
     * 独立角色共用一个受控原生worker命令；未配置时保留QoS0开发入口，不宣称可靠接收就绪。
     * @return list<string> 完整存储角色命令；空列表表示未配置。
     */
    public static function brokerWorker(Repository $settings): array
    {
        $encoded = $settings->text('app.broker.command');
        if ($encoded === '') {
            return [];
        }
        if (DatabaseFactory::name($settings) !== 'pgsql' || trim($settings->text('app.broker.standby')) === '') {
            throw new InvalidArgumentException('BROKER_COMMAND需要PostgreSQL及明确的BROKER_STANDBY_NAMES同步后端');
        }
        $command = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($command) || !array_is_list($command) || $command === [] || count($command) > 16) {
            throw new InvalidArgumentException('BROKER_COMMAND必须为当前应用的显式命令参数数组');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || strlen($argument) > 4096 || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('BROKER_COMMAND参数无效');
            }
        }
        return [...$command, 'broker:store'];
    }


    /**
     * 管理额度预览读取持久用量；独立宿主用 BROKER_COMMAND，双端宿主用 MQTT 存储命令。
     *
     * @return list<string>
     */
    public static function quotaWorker(Repository $settings): array
    {
        $worker = self::brokerWorker($settings);
        if ($worker !== []) {
            return $worker;
        }
        $encoded = $settings->text('app.mqtt.command');
        if ($encoded === '' || $encoded === '[]') {
            return [];
        }
        $command = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($command) || !array_is_list($command) || $command === [] || count($command) > 16) {
            return [];
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                return [];
            }
        }
        return $command;
    }

}
