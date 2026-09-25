<?php
// Mostra a foto de um comprovante (só para o admin ou para o motoboy dono da entrega)
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
$u = usuario();
if (!$u) { http_response_code(403); exit('Entre no sistema para ver a foto.'); }
$s = db()->prepare("SELECT c.*, r.motoboy_id dono FROM comprovantes c JOIN paradas p ON p.id = c.parada_id JOIN rotas r ON r.id = p.rota_id WHERE c.id = ?");
$s->execute([(int)($_GET['id'] ?? 0)]);
$c = $s->fetch();
if (!$c || ($u['tipo'] !== 'admin' && (int)$c['dono'] !== (int)$u['id'])) { http_response_code(404); exit('Foto não encontrada.'); }
$arq = pasta_comprovantes() . '/' . $c['arquivo'];
if (!preg_match('#^\d{4}-\d{2}/[a-f0-9]{32}\.(jpg|png|webp)$#', $c['arquivo']) || !is_file($arq)) { http_response_code(404); exit('Arquivo não encontrado.'); }
$tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $tipos[pathinfo($arq, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($arq));
header('Cache-Control: private, max-age=86400');
if (isset($_GET['baixar'])) header('Content-Disposition: attachment; filename="pacote-voador-' . (int)$c['parada_id'] . '.' . pathinfo($arq, PATHINFO_EXTENSION) . '"');
readfile($arq);
