<?php
// ===== CONFIGURAÇÃO =====
// Dados do banco ficam em config.local.php (fora do Git), assim o "git pull" nunca conflita.
if (file_exists(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'rotas_motoboy');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

define('APP_NOME', 'NetPoint Rotas Motoboy');
define('CIDADE_PADRAO', 'São José dos Pinhais');
define('UF_PADRAO', 'PR');
// Centro inicial do mapa (São José dos Pinhais)
define('MAPA_LAT', -25.5347);
define('MAPA_LNG', -49.2064);
// E-mail exigido pela política do Nominatim (geocodificação gratuita do OpenStreetMap)
define('EMAIL_CONTATO', 'contato@informaticasaojose.com.br');

date_default_timezone_set('America/Sao_Paulo');
// Sessão dura 14 h (o motoboy não precisa entrar de novo no meio do dia).
// Fica numa pasta própria para a limpeza automática do Ubuntu (24 min) não derrubar o login.
$dirSessao = sys_get_temp_dir() . '/netpoint_sessoes';
if (!is_dir($dirSessao)) @mkdir($dirSessao, 0700, true);
if (is_dir($dirSessao) && is_writable($dirSessao)) session_save_path($dirSessao);
ini_set('session.gc_maxlifetime', '50400');
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
session_set_cookie_params(['lifetime' => 50400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                           'secure' => (($_SERVER['HTTPS'] ?? '') === 'on')]);
session_start();

// ===== BANCO =====
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}

// ===== HELPERS =====
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function usuario(): ?array { return $_SESSION['usuario'] ?? null; }

function exigir(string $tipo, bool $json = false): array {
    $u = usuario();
    if (!$u || $u['tipo'] !== $tipo) {
        if ($json) { http_response_code(401); echo json_encode(['erro' => 'Sessão expirada. Entre novamente.']); exit; }
        header('Location: index.php'); exit;
    }
    return $u;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_ok(): bool {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($t) && hash_equals(csrf_token(), $t);
}

function flash(?string $msg = null, string $tipo = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function redirecionar(string $url) { header('Location: ' . $url); exit; }

// Converte endereço em coordenadas (Nominatim/OpenStreetMap, gratuito, 1 consulta por segundo)
function geocodificar(string $endereco, string $numero, string $bairro, string $cidade): array {
    $partes = array_filter([trim($endereco . ' ' . $numero), $bairro, $cidade, UF_PADRAO, 'Brasil']);
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q=' . urlencode(implode(', ', $partes));
    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: RotasMotoboy/1.0 (" . EMAIL_CONTATO . ")\r\n",
        'timeout' => 8,
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    if (!$r) return [null, null];
    $j = json_decode($r, true);
    if (empty($j[0]['lat'])) {
        // tenta de novo sem o bairro (às vezes o bairro atrapalha)
        if ($bairro !== '') { usleep(1100000); return geocodificar($endereco, $numero, '', $cidade); }
        return [null, null];
    }
    return [(float)$j[0]['lat'], (float)$j[0]['lon']];
}

// Atualiza o status da rota conforme as paradas
function atualizar_status_rota(int $rotaId): void {
    $s = db()->prepare("SELECT COUNT(*) total, SUM(status <> 'pendente') feitas FROM paradas WHERE rota_id = ?");
    $s->execute([$rotaId]);
    $c = $s->fetch();
    $status = 'aberta';
    if ($c['total'] > 0 && (int)$c['feitas'] === (int)$c['total']) $status = 'finalizada';
    elseif ((int)$c['feitas'] > 0) $status = 'em_andamento';
    db()->prepare("UPDATE rotas SET status = ? WHERE id = ?")->execute([$status, $rotaId]);
}

function data_br(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function hora_br(?string $d): string { return $d ? date('H:i', strtotime($d)) : '—'; }

// ===== LAYOUT =====
function topo(string $titulo, string $ativo = '', bool $mapa = false): void {
    $u = usuario(); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<link rel="icon" href="assets/icone.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="assets/manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="NetPoint Rotas">
<title><?= e($titulo) ?> · <?= APP_NOME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600&family=Barlow+Semi+Condensed:wght@600;700;800&display=swap" rel="stylesheet">
<?php if ($mapa): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<?php endif; ?>
<link rel="stylesheet" href="assets/style.css?v=6">
</head>
<body>
<?php if ($u && $u['tipo'] === 'admin'): ?>
<header class="barra">
  <a class="marca" href="admin.php"><img src="assets/logo-horizontal.svg" alt="<?= APP_NOME ?>" height="40"></a>
  <nav>
    <a href="admin.php" class="<?= $ativo === 'painel' ? 'ativo' : '' ?>">Painel</a>
    <a href="rotas.php" class="<?= $ativo === 'rotas' ? 'ativo' : '' ?>">Rotas</a>
    <a href="motoboys.php" class="<?= $ativo === 'motoboys' ? 'ativo' : '' ?>">Motoboys</a>
    <a href="financeiro.php" class="<?= $ativo === 'financeiro' ? 'ativo' : '' ?>">Financeiro</a>
    <a href="senha.php" class="<?= $ativo === 'senha' ? 'ativo' : '' ?>">Minha senha</a>
    <button type="button" id="btn-sino" class="sino" title="Notificações no computador" onclick="pedirNotificacao()">🔔</button>
    <a href="logout.php">Sair</a>
  </nav>
</header>
<?php
    // pedidos de socorro abertos: faixa vermelha em todas as páginas do admin
    $pedidos = [];
    try { $pedidos = db()->query("SELECT ps.*, u.nome FROM pedidos_socorro ps JOIN usuarios u ON u.id = ps.motoboy_id WHERE ps.status = 'aberto' ORDER BY ps.id")->fetchAll(); } catch (Throwable $ex) {}
    foreach ($pedidos as $pd): ?>
  <div class="faixa-sos">🆘 <b><?= e($pd['nome']) ?></b> pediu socorro às <?= hora_br($pd['criado_em']) ?> — <?= e($pd['motivo']) ?>
    <?php if ($pd['rota_id']): ?><a class="btn pequeno" href="rota.php?id=<?= (int)$pd['rota_id'] ?>#ambulancia">Chamar ambulância</a><?php endif; ?>
    <?php if ($pd['lat']): ?><a class="btn pequeno" target="_blank" href="https://www.google.com/maps?q=<?= e($pd['lat']) ?>,<?= e($pd['lng']) ?>">Ver no mapa</a><?php endif; ?>
    <button type="button" class="btn pequeno" onclick="resolverPedido(<?= (int)$pd['id'] ?>)">Resolvido</button>
  </div>
<?php endforeach; ?>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script>
(() => {
  const CSRF_ADM = <?= json_encode(csrf_token()) ?>;
  let ultimo = null;
  const sino = document.getElementById('btn-sino');
  const marcaSino = () => { if (!('Notification' in window)) { sino.hidden = true; return; } sino.classList.toggle('ligado', Notification.permission === 'granted'); };
  window.pedirNotificacao = async () => { if ('Notification' in window) { await Notification.requestPermission(); marcaSino(); } };
  window.resolverPedido = async id => {
    const fd = new FormData(); fd.append('acao', 'resolver_pedido'); fd.append('pedido_id', id);
    await fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF_ADM } }); location.reload();
  };
  function bip() { try { const c = new (window.AudioContext || window.webkitAudioContext)(); const o = c.createOscillator(), g = c.createGain(); o.frequency.value = 880; o.connect(g); g.connect(c.destination); g.gain.setValueAtTime(.25, c.currentTime); o.start(); o.stop(c.currentTime + .3); } catch (e) {} }
  function mostrar(a) {
    const t = document.createElement(a.link ? 'a' : 'div');
    t.className = 'toast' + (a.prioridade === 'alta' ? ' alta' : ''); if (a.link) t.href = a.link;
    t.innerHTML = '<b></b><span></span>'; t.querySelector('b').textContent = a.titulo; t.querySelector('span').textContent = a.texto || '';
    document.getElementById('toasts').prepend(t); setTimeout(() => t.remove(), 15000);
    if ('Notification' in window && Notification.permission === 'granted' && document.visibilityState !== 'visible') {
      const n = new Notification(a.titulo, { body: a.texto || '', icon: 'assets/icone-192.png', tag: 'np' + a.id });
      n.onclick = () => { window.focus(); if (a.link) location.href = a.link; };
    }
  }
  async function conferir() {
    try {
      const r = await fetch('api.php?acao=avisos&desde=' + (ultimo ?? 0), { cache: 'no-store' });
      if (!r.ok) return;
      const j = await r.json();
      if (ultimo !== null && j.avisos.length) { j.avisos.forEach(mostrar); bip(); if (j.avisos.some(a => a.tipo === 'pedido_socorro')) setTimeout(() => location.reload(), 1500); }
      ultimo = j.ultimo;
    } catch (e) {}
  }
  marcaSino(); conferir(); setInterval(conferir, 15000);
  // admin abrindo o painel pelo app: registra o celular para receber os alertas (socorro, recusa…)
  window.registrarTokenFcm = token => { const fd = new FormData(); fd.append('acao', 'registrar_token'); fd.append('token', token); fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF_ADM } }).catch(() => {}); };
  if (window.NetPointApp && NetPointApp.temFcm && NetPointApp.temFcm()) registrarTokenFcm(NetPointApp.tokenFcm());
})();
</script>
<?php endif; ?>
<main class="<?= ($ativo === 'painel' || ($u && $u['tipo'] === 'motoboy' && $ativo === '')) ? 'cheio' : 'conteudo' ?>">
<?php
    if ($f = flash()) echo '<div class="aviso ' . e($f[1]) . '">' . e($f[0]) . '</div>';
}

function rodape(): void { echo "</main>\n</body>\n</html>"; }
