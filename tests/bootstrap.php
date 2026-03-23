<?php
declare(strict_types=1);

$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chandra-phpunit-sessions';

if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
}

if (is_dir($sessionPath) && is_writable($sessionPath)) {
    ini_set('session.save_path', $sessionPath);
}

require_once __DIR__ . '/../vendor/autoload.php';
