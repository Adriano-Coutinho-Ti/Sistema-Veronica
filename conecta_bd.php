<?php
require_once __DIR__ . '/../../brechodaveve_config_credenciais.php';

// Notices/warnings/deprecations nunca devem ser impressos direto na resposta (isso já
// corrompeu JSON de endpoint AJAX duas vezes neste projeto) — loga mas nunca exibe.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$options[class_exists('Pdo\\Mysql') ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";

$pdo = new PDO($dsn, $username, $password, $options);
