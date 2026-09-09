<?php
require_once __DIR__ . '/../config_credenciais.php';

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
];

$pdo = new PDO($dsn, $username, $password, $options);
