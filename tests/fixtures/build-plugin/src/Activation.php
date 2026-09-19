<?php

declare(strict_types=1);

namespace TypeTests\BuildPlugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

final class Activation implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void { file_put_contents(__DIR__ . '/activated.txt', '执行了不应启用的插件'); }
    public function deactivate(Composer $composer, IOInterface $io): void {}
    public function uninstall(Composer $composer, IOInterface $io): void {}
}
