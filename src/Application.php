<?php

namespace Mix\Console;

use Mix\Console\CommandLine\Argument;
use Mix\Console\CommandLine\Flag;
use Mix\Concurrent\Coroutine;

/**
 * Class Application
 * @package Mix\Console
 * @author liu,jian <coder.keda@gmail.com>
 */
class Application extends \Mix\Core\Application
{

    /**
     * 命令命名空间
     * @var string
     */
    public $commandNamespace = '';

    /**
     * 命令
     * @var array
     */
    public $commands = [];

    /**
     * Application constructor.
     * @param array $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        // 保存引用
        \Mix::$app = $this;
        // 错误注册
        \Mix\Core\Error::register();
    }

    /**
     * 执行功能 (CLI模式)
     * @return mixed
     */
    public function run()
    {
        if (PHP_SAPI != 'cli') {
            throw new \RuntimeException('Please run in CLI mode.');
        }
        Flag::initialize();
        if (Argument::subCommand() == '' && Argument::command() == '') {
            if (Flag::bool(['h', 'help'], false)) {
                $this->help();
                return;
            }
            if (Flag::bool(['v', 'version'], false)) {
                $this->version();
                return;
            }
            $options = Flag::options();
            if (empty($options)) {
                $this->help();
                return;
            }
            $keys   = array_keys($options);
            $flag   = array_shift($keys);
            $script = Argument::script();
            throw new \Mix\Exception\NotFoundException("flag provided but not defined: '{$flag}', see '{$script} --help'.");
        }
        if ((Argument::command() !== '' || Argument::subCommand() !== '') && Flag::bool(['h', 'help'], false)) {
            $this->commandHelp();
            return;
        }
        $command = trim(implode(' ', [Argument::command(), Argument::subCommand()]));
        return $this->runAction($command);
    }

    /**
     * 帮助
     */
    protected function help()
    {
        $script = Argument::script();
        println("Usage: {$script} [OPTIONS] COMMAND [SUBCOMMAND] [opt...]");
        $this->printOptions();
        $this->printCommands();
        println('');
        println("Run '{$script} COMMAND [SUBCOMMAND] --help' for more information on a command.");
        println('');
        println("Developed with Mix PHP framework. (mixphp.cn)");
    }

    /**
     * 命令帮助
     */
    protected function commandHelp()
    {
        $script  = Argument::script();
        $command = trim(implode(' ', [Argument::command(), Argument::subCommand()]));
        println("Usage: {$script} {$command} [opt...]");
        $this->printCommandOptions();
        println("Developed with Mix PHP framework. (mixphp.cn)");
    }

    /**
     * 版本
     */
    protected function version()
    {
        $appName          = \Mix::$app->appName;
        $appVersion       = \Mix::$app->appVersion;
        $frameworkVersion = \Mix::$version;
        println("{$appName} version {$appVersion}, framework version {$frameworkVersion}");
    }

    /**
     * 打印选项列表
     */
    protected function printOptions()
    {
        $tabs = $this->hasSubCommand() ? "\t\t" : "\t";
        println('');
        println('Options:');
        println("  -h, --help{$tabs}Print usage");
        println("  -v, --version{$tabs}Print version information");
    }

    /**
     * 有子命令
     * @return bool
     */
    protected function hasSubCommand()
    {
        foreach ($this->commands as $key => $item) {
            if (strpos($key, ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 打印命令列表
     */
    protected function printCommands()
    {
        println('');
        println('Commands:');
        foreach ($this->commands as $key => $item) {
            $command     = $key;
            $subCommand  = '';
            $description = $item['description'] ?? '';
            if (strpos($key, ' ') !== false) {
                list($command, $subCommand) = explode(' ', $key);
            }
            if ($subCommand == '') {
                println("  {$command}\t{$description}");
            } else {
                println("  {$command} {$subCommand}\t{$description}");
            }
        }
    }

    /**
     * 打印命令选项列表
     */
    protected function printCommandOptions()
    {
        $command = trim(implode(' ', [Argument::command(), Argument::subCommand()]));
        if (!isset($this->commands[$command]['options'])) {
            return;
        }
        $options = $this->commands[$command]['options'];
        println('');
        println('Options:');
        foreach ($options as $option) {
            $names = array_shift($option);
            if (is_string($names)) {
                $names = [$names];
            }
            $flags = [];
            foreach ($names as $name) {
                if (strlen($name) == 1) {
                    $flags[] = "-{$name}";
                } else {
                    $flags[] = "--{$name}";
                }
            }
            $flag        = implode(', ', $flags);
            $description = $option['description'] ?? '';
            println("  {$flag}\t{$description}");
        }
        println('');
    }

    /**
     * 执行功能并返回
     * @param $command
     * @return mixed
     */
    public function runAction($command)
    {
        if (!isset($this->commands[$command])) {
            $script = Argument::script();
            throw new \Mix\Exception\NotFoundException("'{$command}' is not command, see '{$script} --help'.");
        }
        // 实例化控制器
        $shortClass = $this->commands[$command];
        if (is_array($shortClass)) {
            $shortClass = array_shift($shortClass);
        }
        $shortClass    = str_replace('/', "\\", $shortClass);
        $commandDir    = \Mix\Helper\FileSystemHelper::dirname($shortClass);
        $commandDir    = $commandDir == '.' ? '' : "$commandDir\\";
        $commandName   = \Mix\Helper\FileSystemHelper::basename($shortClass);
        $commandClass  = "{$this->commandNamespace}\\{$commandDir}{$commandName}Command";
        $commandAction = 'main';
        // 判断类是否存在
        if (!class_exists($commandClass)) {
            throw new \Mix\Exception\CommandException("'{$commandClass}' class not found.");
        }
        $commandInstance = new $commandClass();
        // 判断方法是否存在
        if (!method_exists($commandInstance, $commandAction)) {
            throw new \Mix\Exception\CommandException("'{$commandClass}::main' method not found.");
        }
        // 命令行选项效验
        $this->validateOptions($command);
        // 执行方法
        return call_user_func([$commandInstance, $commandAction]);
    }

    /**
     * 命令行选项效验
     * @param $command
     */
    protected function validateOptions($command)
    {
        $options  = $this->commands[$command]['options'] ?? [];
        $regflags = [];
        foreach ($options as $option) {
            $names = array_shift($option);
            if (is_string($names)) {
                $names = [$names];
            }
            foreach ($names as $name) {
                if (strlen($name) == 1) {
                    $regflags[] = "-{$name}";
                } else {
                    $regflags[] = "--{$name}";
                }
            }
        }
        foreach (array_keys(Flag::options()) as $flag) {
            if (!in_array($flag, $regflags)) {
                $script      = Argument::script();
                $command     = Argument::command();
                $subCommand  = Argument::subCommand();
                $fullCommand = $command . ($subCommand ? " {$subCommand}" : '');
                throw new \Mix\Exception\NotFoundException("flag provided but not defined: '{$flag}', see '{$script} {$fullCommand} --help'.");
            }
        }
    }

}
