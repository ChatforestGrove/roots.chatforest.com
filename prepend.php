<?php
error_reporting(E_ALL);
ini_set("display_errors", "1");

spl_autoload_register(function ($class) {
    $file = __DIR__ . "/classes/" . str_replace("\\", "/", $class) . ".php";
    if (file_exists($file)) {
        require_once $file;
    }
});

$config = new Config();

require_once __DIR__ . "/classes/Database/Base.php";

$roots_database = new Database\Base(
    $config->dbHost,
    $config->dbUser,
    $config->dbPass,
    $config->dbName
);
