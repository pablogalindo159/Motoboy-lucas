<?php
// Roda a cada minuto pelo cron da VPS: confere os alertas que dependem do relógio
// (motoboy sem sinal de GPS, motoboy que não chegou no CD até o horário).
// crontab:  * * * * * php /var/www/motoboy/cron.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
cfg_salvar('alertas_verificados_em', '0');
verificar_alertas_periodicos();
