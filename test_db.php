<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=audio_novel_db;charset=utf8mb4', 'root', 'wendage123');
$pdo->exec("SET NAMES utf8mb4");
$res = $pdo->exec("INSERT INTO tag (name) VALUES ('发')");
var_dump($res);
