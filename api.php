<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
header('Content-Type: application/json; charset=utf-8');

$acao = $_POST['acao'] ?? ($_GET['acao'] ?? '');
function responder($dados, int $codigo = 200) { http_response_code($codigo); echo json_encode($dados); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) responder(['erro' => 'Sessão inválida. Recarregue a página.'], 403);

switch ($acao) {

// ---------- ADMIN: dados do painel ----------
case 'painel':
    exigir('admin', true);
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
    $s = db()->prepare("SELECT DISTINCT u.id, u.nome, u.telefone, u.placa, u.lat, u.lng, u.ultima_localizacao
                        FROM usuarios u JOIN rotas r ON r.motoboy_id = u.id AND r.data = ? ORDER BY u.nome");
    $s->execute([$data]);
    $motoboys = $s->fetchAll();

    $sp = db()->prepare("SELECT p.id, p.rota_id, p.numero, p.endereco, p.numero_casa, p.bairro, p.pacotes, p.lat, p.lng, p.status, p.finalizado_em
                         FROM paradas p JOIN rotas r ON r.id = p.rota_id WHERE r.motoboy_id = ? AND r.data = ? ORDER BY r.id, p.numero");
    $sc = db()->prepare("SELECT MAX(r.cor) cor, MAX(r.chegada_cd) chegada_cd, MAX(r.saida_cd) saida_cd, COUNT(s.id) sacas, COALESCE(SUM(s.coletada),0) coletadas
                         FROM rotas r LEFT JOIN sacas s ON s.rota_id = r.id WHERE r.motoboy_id = ? AND r.data = ?");
    $st = db()->prepare("SELECT lat, lng FROM localizacoes WHERE motoboy_id = ? AND DATE(criado_em) = ? ORDER BY id DESC LIMIT 300");

    foreach ($motoboys as &$m) {
        $sp->execute([$m['id'], $data]);
        $m['paradas'] = $sp->fetchAll();
        $st->execute([$m['id'], $data]);
        $m['trajeto'] = array_reverse(array_map(fn($p) => [(float)$p['lat'], (float)$p['lng']], $st->fetchAll()));
        $sc->execute([$m['id'], $data]);
        $x = $sc->fetch();
        $m['cor'] = $x['cor']; $m['chegada_cd'] = $x['chegada_cd']; $m['saida_cd'] = $x['saida_cd']; $m['sacas'] = (int)$x['sacas']; $m['sacas_coletadas'] = (int)$x['coletadas'];
        $m['minutos_sem_sinal'] = $m['ultima_localizacao'] ? (int)round((time() - strtotime($m['ultima_localizacao'])) / 60) : null;
    }
    responder(['data' => $data, 'motoboys' => $motoboys, 'cd' => cd_posicao(), 'atualizado' => date('H:i:s')]);

// ---------- ADMIN: arrastar ponto da parada ----------
case 'mover_parada':
    exigir('admin', true);
    db()->prepare("UPDATE paradas SET lat = ?, lng = ? WHERE id = ?")
        ->execute([(float)$_POST['lat'], (float)$_POST['lng'], (int)$_POST['id']]);
    responder(['ok' => true]);

// ---------- MOTOBOY: enviar posição ----------
case 'localizacao':
    $u = exigir('motoboy', true);
    $lat = (float)($_POST['lat'] ?? 0); $lng = (float)($_POST['lng'] ?? 0);
    if (!$lat || !$lng) responder(['erro' => 'Posição inválida'], 422);
    db()->prepare("UPDATE usuarios SET lat = ?, lng = ?, ultima_localizacao = NOW() WHERE id = ?")->execute([$lat, $lng, $u['id']]);
    db()->prepare("INSERT INTO localizacoes (motoboy_id, lat, lng) VALUES (?,?,?)")->execute([$u['id'], $lat, $lng]);
    responder(['ok' => true]);

// ---------- MOTOBOY: marcar entrega ----------
case 'status_parada':
    $u = exigir('motoboy', true);
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['entregue', 'falhou'], true)) responder(['erro' => 'Status inválido'], 422);
    $s = db()->prepare("SELECT p.id, p.rota_id FROM paradas p JOIN rotas r ON r.id = p.rota_id WHERE p.id = ? AND r.motoboy_id = ?");
    $s->execute([(int)$_POST['parada_id'], $u['id']]);
    $p = $s->fetch();
    if (!$p) responder(['erro' => 'Parada não encontrada'], 404);
    db()->prepare("UPDATE paradas SET status = ?, motivo = ?, finalizado_em = NOW() WHERE id = ?")
        ->execute([$status, $status === 'falhou' ? mb_substr(trim($_POST['motivo'] ?? ''), 0, 255) : null, $p['id']]);
    atualizar_status_rota((int)$p['rota_id']);
    responder(['ok' => true]);

// ---------- MOTOBOY: marcar saca coletada (toque de novo desmarca) ----------
case 'coletar_saca':
    $u = exigir('motoboy', true);
    $s = db()->prepare("SELECT s.id, s.rota_id, s.coletada FROM sacas s JOIN rotas r ON r.id = s.rota_id WHERE s.id = ? AND r.motoboy_id = ?");
    $s->execute([(int)($_POST['saca_id'] ?? 0), $u['id']]);
    $sc = $s->fetch();
    if (!$sc) responder(['erro' => 'Saca não encontrada'], 404);
    $nova = $sc['coletada'] ? 0 : 1;
    db()->prepare("UPDATE sacas SET coletada = ?, coletada_em = IF(? = 1, NOW(), NULL) WHERE id = ?")->execute([$nova, $nova, $sc['id']]);
    $c = db()->prepare("SELECT COUNT(*) total, COALESCE(SUM(coletada),0) coletadas FROM sacas WHERE rota_id = ?");
    $c->execute([$sc['rota_id']]);
    $t = $c->fetch();
    responder(['ok' => true, 'coletada' => (bool)$nova, 'total' => (int)$t['total'], 'coletadas' => (int)$t['coletadas']]);

// ---------- ADMIN: localizar endereços em lotes (chamado em sequência pela tela processar.php) ----------
case 'geocodificar_lote':
    exigir('admin', true);
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['data'] ?? '') ? $_POST['data'] : date('Y-m-d');
    set_time_limit(60);
    $fim = microtime(true) + 20;
    $prox = db()->prepare("SELECT p.id, p.endereco, p.numero_casa FROM paradas p JOIN rotas r ON r.id = p.rota_id
                           WHERE r.data = ? AND p.geo_tentado = 0 AND p.lat IS NULL LIMIT 1");
    $up = db()->prepare("UPDATE paradas SET lat = ?, lng = ?, geo_tentado = 1
                         WHERE id = ? OR (lat IS NULL AND rota_id IN (SELECT id FROM rotas WHERE data = ?) AND endereco <=> ? AND numero_casa <=> ?)");
    while (microtime(true) < $fim) {
        $prox->execute([$data]);
        $p = $prox->fetch();
        if (!$p) break;
        $consultou = false;
        [$lat, $lng] = geo_localizar($p['endereco'], (string)$p['numero_casa'], $consultou);
        $up->execute([$lat, $lng, $p['id'], $data, $p['endereco'], $p['numero_casa']]);
        if ($consultou) usleep(1100000); // limite do OpenStreetMap: 1 consulta por segundo
    }
    $c = db()->prepare("SELECT COUNT(*) total, COALESCE(SUM(p.geo_tentado = 0 AND p.lat IS NULL),0) pendentes, COALESCE(SUM(p.geo_tentado = 1 AND p.lat IS NULL),0) sem_local
                        FROM paradas p JOIN rotas r ON r.id = p.rota_id WHERE r.data = ?");
    $c->execute([$data]);
    $t = $c->fetch();
    responder(['total' => (int)$t['total'], 'pendentes' => (int)$t['pendentes'], 'sem_local' => (int)$t['sem_local']]);

// ---------- ADMIN: montar a ordem de todas as rotas do dia ----------
case 'otimizar_dia':
    exigir('admin', true);
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['data'] ?? '') ? $_POST['data'] : date('Y-m-d');
    set_time_limit(120);
    $s = db()->prepare("SELECT DISTINCT r.id FROM rotas r JOIN paradas p ON p.rota_id = r.id WHERE r.data = ?");
    $s->execute([$data]);
    $tot = ['rotas' => 0, 'paradas' => 0, 'sem_local' => 0];
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $r = otimizar_rota((int)$rid);
        $tot['rotas']++; $tot['paradas'] += $r['paradas']; $tot['sem_local'] += $r['sem_local'];
    }
    responder($tot);

// ---------- MOTOBOY: cheguei no CD / saí para as entregas ----------
case 'chegou_cd':
case 'saiu_cd':
    $u = exigir('motoboy', true);
    $campo = $acao === 'chegou_cd' ? 'chegada_cd' : 'saida_cd';
    $s = db()->prepare("UPDATE rotas SET $campo = COALESCE($campo, NOW()) WHERE id = ? AND motoboy_id = ?");
    $s->execute([(int)($_POST['rota_id'] ?? 0), $u['id']]);
    responder(['ok' => $s->rowCount() >= 0]);

default:
    responder(['erro' => 'Ação desconhecida'], 400);
}
