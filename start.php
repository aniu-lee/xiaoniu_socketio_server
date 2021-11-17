<?php
/**
 * run with command 
 * php start.php start
 */
use Workerman\Worker;
// composer 的 autoload 文件
include __DIR__ . '/vendor/autoload.php';
// 标记是全局启动
define('GLOBAL_START', 1);

// 加载IO
require_once __DIR__ . '/start_io.php';

// 运行所有服务
Worker::runAll();