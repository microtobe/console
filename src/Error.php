<?php

namespace Mix\Console;

use Mix\Bean\BeanInjector;

/**
 * Class Error
 * @package Mix\Console
 * @author liu,jian <coder.keda@gmail.com>
 */
class Error
{

    /**
     * 错误级别
     * @var int
     */
    public $level = E_ALL;

    /**
     * Authorization constructor.
     * @param array $config
     */
    public function __construct(array $config)
    {
        BeanInjector::inject($this, $config);
    }

    /**
     * 异常处理
     * @param \Throwable $e
     */
    public function handleException(\Throwable $e)
    {
        // 错误参数定义
        $errors = [
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'type'    => get_class($e),
            'trace'   => $e->getTraceAsString(),
        ];
        // 日志处理
        if (!($e instanceof \Mix\Exception\NotFoundException)) {
            static::log($errors);
            return;
        }
        // 打印到屏幕
        println($errors['message']);
    }

    /**
     * 写入日志
     * @param array $errors
     */
    protected static function log(array $errors)
    {
        // 构造消息
        $message = "{message}\n[code] {code} [type] {type}\n[file] {file} [line] {line}\n[trace] {trace}";
        if (!\Mix::$app->appDebug) {
            $message = "{message} [{code}] {type} in {file} line {line}";
        }
        // 写入
        $level = \Mix\Core\Error::getLevel($errors['code']);
        switch ($level) {
            case 'error':
                \Mix::$app->log->error($message, $errors);
                break;
            case 'warning':
                \Mix::$app->log->warning($message, $errors);
                break;
            case 'notice':
                \Mix::$app->log->notice($message, $errors);
                break;
        }
    }

}
