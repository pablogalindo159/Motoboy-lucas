<?php
require __DIR__ . '/config.php';
// este celular deixa de receber os avisos deste usuário
if (!empty($_SESSION['fcm_token'])) { try { db()->prepare("DELETE FROM dispositivos WHERE token = ?")->execute([$_SESSION['fcm_token']]); } catch (Throwable $ex) {} }
$_SESSION = [];
session_destroy();
redirecionar('index.php');
