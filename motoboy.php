<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
$u = exigir('motoboy');
$hoje = date('Y-m-d');

$s = db()->prepare("SELECT r.*, COUNT(p.id) total, COALESCE(SUM(p.status='pendente'),0) pendentes
                    FROM rotas r LEFT JOIN paradas p ON p.rota_id = r.id
                    WHERE r.motoboy_id = ? AND r.data = ? GROUP BY r.id ORDER BY r.id");
$s->execute([$u['id'], $hoje]);
$rotas = $s->fetchAll();

// rota escolhida, ou a primeira que ainda tem entrega pendente
$rota = null;
foreach ($rotas as $r) if ((int)($_GET['rota'] ?? 0) === (int)$r['id']) $rota = $r;
if (!$rota) foreach ($rotas as $r) if ($r['pendentes'] > 0 || (int)$r['total'] === 0) { $rota = $r; break; }
if (!$rota && $rotas) $rota = end($rotas);

$paradas = []; $sacas = [];
if ($rota) {
    $s = db()->prepare("SELECT * FROM sacas WHERE rota_id = ? ORDER BY caixa");
    $s->execute([$rota['id']]);
    $sacas = $s->fetchAll();
    $s = db()->prepare("SELECT * FROM paradas WHERE rota_id = ? ORDER BY numero, id");
    $s->execute([$rota['id']]);
    $paradas = $s->fetchAll();
}
$pendentes = array_values(array_filter($paradas, fn($p) => $p['status'] === 'pendente'));
$feitas = array_values(array_filter($paradas, fn($p) => $p['status'] !== 'pendente'));
$entregues = count(array_filter($paradas, fn($p) => $p['status'] === 'entregue'));
$prox = $pendentes[0] ?? null;

function destino(array $p): string {
    if ($p['lat']) return $p['lat'] . ',' . $p['lng'];
    return trim($p['endereco'] . ', ' . $p['numero_casa'] . ', ' . ($p['bairro'] ? $p['bairro'] . ', ' : '') . ($p['cidade'] ?: CIDADE_PADRAO) . ' - ' . UF_PADRAO);
}
function link_gmaps(array $p): string {
    return 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . urlencode(destino($p));
}
function link_waze(array $p): string {
    return $p['lat'] ? 'https://waze.com/ul?navigate=yes&ll=' . urlencode(destino($p)) : 'https://waze.com/ul?navigate=yes&q=' . urlencode(destino($p));
}
// Rota completa no Google Maps: próximas paradas como pontos de passagem (máx. 9 + destino)
function link_rota_completa(array $pendentes): string {
    $lote = array_slice($pendentes, 0, 10);
    $destino = array_pop($lote);
    $url = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . urlencode(destino($destino));
    if ($lote) $url .= '&waypoints=' . urlencode(implode('|', array_map('destino', $lote)));
    return $url;
}

topo('Minhas entregas');
$sacasColetadas = count(array_filter($sacas, fn($x) => $x['coletada']));
$corRota = $rota['cor'] ?? null;
?>
<link rel="stylesheet" href="assets/sacas.css?v=1">
<div class="app-moto">
  <header class="moto-topo">
    <div>
      <strong><?= e($u['nome']) ?></strong>
      <span id="gps" class="gps">Ligando GPS…</span>
    </div>
    <a href="logout.php" class="sair">Sair</a>
  </header>

  <?php if (count($rotas) > 1): ?>
  <nav class="abas-rotas">
    <?php foreach ($rotas as $r): ?>
      <a href="?rota=<?= $r['id'] ?>" class="<?= $rota && $r['id'] == $rota['id'] ? 'ativo' : '' ?>"><?= e($r['descricao'] ?: 'Rota ' . $r['id']) ?> <small><?= (int)$r['pendentes'] ?></small></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if (!$rota): ?>
    <section class="vazio-moto">
      <h1>Sem rota hoje</h1>
      <p>Quando a loja lançar sua rota, ela aparece aqui. Deixe esta tela aberta para enviar sua localização.</p>
      <button class="btn grande" onclick="location.reload()">Verificar de novo</button>
    </section>
  <?php else: ?>
    <?php if ($sacas): $todas = $sacasColetadas === count($sacas); ?>
    <details class="sacas" id="sacas" <?= $todas ? '' : 'open' ?> style="--cor-rota:<?= e($corRota ?: '#F2B705') ?>;--texto-rota:<?= texto_sobre($corRota ?: '#F2B705') ?>">
      <summary>
        <span class="cor-motoboy"><?= e($rota['descricao'] ?: 'Sua cor') ?></span>
        <span class="titulo-sacas" id="sacas-titulo"><?= $todas ? 'Sacas coletadas' : 'Sacas para coletar' ?></span>
        <span class="contador" id="sacas-cont"><?= $sacasColetadas ?>/<?= count($sacas) ?></span>
      </summary>
      <p class="dica">Toque em cada saca quando pegar. Total: <b><?= array_sum(array_column($sacas, 'quantidade')) ?></b> pacotes.</p>
      <div class="grade-sacas">
        <?php foreach ($sacas as $sc): ?>
          <button type="button" class="saca <?= $sc['coletada'] ? 'coletada' : '' ?>" data-id="<?= $sc['id'] ?>" onclick="coletar(this)" aria-pressed="<?= $sc['coletada'] ? 'true' : 'false' ?>">
            <b><?= (int)$sc['caixa'] ?></b><small><?= (int)$sc['quantidade'] ?> pct</small>
          </button>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>

    <?php if (!$paradas): ?>
    <section class="vazio-moto">
      <h1><?= $sacas ? 'Colete suas sacas' : 'Rota sem paradas' ?></h1>
      <p>As paradas ainda não foram lançadas. Quando a loja lançar, elas aparecem aqui.</p>
      <button class="btn grande" onclick="location.reload()">Verificar de novo</button>
    </section>
    <?php else: ?>
    <section class="placar">
      <div><b><?= $entregues ?></b><span>entregues</span></div>
      <div><b><?= count($pendentes) ?></b><span>faltam</span></div>
      <div><b><?= array_sum(array_column($pendentes, 'pacotes')) ?></b><span>pacotes na moto</span></div>
    </section>
    <div class="progresso grosso"><span style="width:<?= count($paradas) ? round(count($feitas) / count($paradas) * 100) : 0 ?>%"></span></div>

    <?php if ($prox): ?>
    <section class="proxima">
      <p class="rotulo">Próxima entrega</p>
      <div class="placa-parada"><small>Parada</small><?= (int)$prox['numero'] ?></div>
      <h1 class="endereco"><?= e($prox['endereco']) ?>, <?= e($prox['numero_casa']) ?></h1>
      <p class="bairro"><?= e($prox['bairro']) ?><?= $prox['bairro'] ? ' · ' : '' ?><?= e($prox['cidade'] ?: CIDADE_PADRAO) ?></p>
      <p class="pacotes"><b><?= (int)$prox['pacotes'] ?></b> <?= $prox['pacotes'] > 1 ? 'pacotes' : 'pacote' ?></p>
      <?php if ($prox['observacao']): ?><p class="obs"><?= e($prox['observacao']) ?></p><?php endif; ?>

      <div class="navegar">
        <a class="btn primario grande" href="<?= e(link_gmaps($prox)) ?>" target="_blank" rel="noopener">Ir até a parada</a>
        <a class="btn grande" href="<?= e(link_waze($prox)) ?>" target="_blank" rel="noopener">Waze</a>
      </div>
      <div class="confirmar">
        <button class="btn sucesso grande" onclick="marcar(<?= $prox['id'] ?>, 'entregue')">Entregue</button>
        <button class="btn perigo grande" onclick="naoEntregue(<?= $prox['id'] ?>)">Não entregue</button>
      </div>
      <?php if (count($pendentes) > 1): ?>
        <a class="btn largo" href="<?= e(link_rota_completa($pendentes)) ?>" target="_blank" rel="noopener">Fazer a rota completa (<?= min(10, count($pendentes)) ?> próximas paradas)</a>
      <?php endif; ?>
    </section>
    <?php else: ?>
    <section class="vazio-moto concluido">
      <h1>Rota concluída</h1>
      <p><?= $entregues ?> de <?= count($paradas) ?> entregas feitas. Bom trabalho!</p>
    </section>
    <?php endif; ?>

    <?php if (count($pendentes) > 1): ?>
    <details class="lista-moto" open>
      <summary>Depois desta (<?= count($pendentes) - 1 ?>)</summary>
      <ol>
        <?php foreach (array_slice($pendentes, 1) as $p): ?>
          <li><span class="num-parada"><?= (int)$p['numero'] ?></span><div><?= e($p['endereco']) ?>, <?= e($p['numero_casa']) ?><small><?= e($p['bairro']) ?> · <?= (int)$p['pacotes'] ?> pct</small></div>
            <a href="<?= e(link_gmaps($p)) ?>" target="_blank" rel="noopener" class="btn pequeno">Ir</a></li>
        <?php endforeach; ?>
      </ol>
    </details>
    <?php endif; ?>

    <?php if ($feitas): ?>
    <details class="lista-moto">
      <summary>Já feitas (<?= count($feitas) ?>)</summary>
      <ol>
        <?php foreach ($feitas as $p): ?>
          <li><span class="num-parada <?= e($p['status']) ?>"><?= (int)$p['numero'] ?></span><div><?= e($p['endereco']) ?>, <?= e($p['numero_casa']) ?><small><?= $p['status'] === 'entregue' ? 'Entregue' : 'Não entregue' ?> às <?= hora_br($p['finalizado_em']) ?></small></div></li>
        <?php endforeach; ?>
      </ol>
    </details>
    <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<dialog id="dlg-motivo">
  <form method="dialog" class="form">
    <h2>Por que não entregou?</h2>
    <label><input type="radio" name="m" value="Ninguém em casa" checked> Ninguém em casa</label>
    <label><input type="radio" name="m" value="Endereço não encontrado"> Endereço não encontrado</label>
    <label><input type="radio" name="m" value="Cliente recusou"> Cliente recusou</label>
    <label><input type="radio" name="m" value="Outro"> Outro <input name="outro" placeholder="Descreva"></label>
    <div class="confirmar">
      <button value="cancelar" class="btn">Voltar</button>
      <button value="ok" class="btn perigo">Confirmar</button>
    </div>
  </form>
</dialog>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const gpsEl = document.getElementById('gps');

async function post(dados) {
  const fd = new FormData();
  Object.entries(dados).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } });
  if (r.status === 401) { location.href = 'index.php'; throw new Error('sessão'); }
  return r;
}

async function marcar(id, status, motivo = '') {
  document.querySelectorAll('.confirmar button').forEach(b => b.disabled = true);
  try {
    const r = await post({ acao: 'status_parada', parada_id: id, status, motivo });
    if (!r.ok) throw new Error();
    if (navigator.vibrate) navigator.vibrate(80);
    location.reload();
  } catch (e) {
    alert('Não foi possível salvar. Confira a internet e toque de novo.');
    document.querySelectorAll('.confirmar button').forEach(b => b.disabled = false);
  }
}

function naoEntregue(id) {
  const dlg = document.getElementById('dlg-motivo');
  dlg.showModal();
  dlg.onclose = () => {
    if (dlg.returnValue !== 'ok') return;
    const f = dlg.querySelector('form');
    let m = f.m.value;
    if (m === 'Outro') m = f.outro.value.trim() || 'Outro';
    marcar(id, 'falhou', m);
  };
}

// ---- Coleta das sacas ----
async function coletar(btn) {
  btn.disabled = true;
  try {
    const r = await post({ acao: 'coletar_saca', saca_id: btn.dataset.id });
    const j = await r.json();
    if (!r.ok) throw new Error(j.erro || '');
    btn.classList.toggle('coletada', j.coletada);
    btn.setAttribute('aria-pressed', j.coletada ? 'true' : 'false');
    document.getElementById('sacas-cont').textContent = j.coletadas + '/' + j.total;
    document.getElementById('sacas-titulo').textContent = j.coletadas === j.total ? 'Sacas coletadas' : 'Sacas para coletar';
    if (navigator.vibrate) navigator.vibrate(40);
  } catch (e) {
    alert('Não foi possível marcar a saca. Confira a internet e toque de novo.');
  }
  btn.disabled = false;
}

// ---- Localização: envia a cada 20 s ou quando andar mais de 30 m ----
let ultimoEnvio = 0, ultimaPos = null;
function distancia(a, b) {
  const R = 6371000, r = x => x * Math.PI / 180;
  const dLat = r(b.lat - a.lat), dLng = r(b.lng - a.lng);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(r(a.lat)) * Math.cos(r(b.lat)) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}
function enviar(pos) {
  const p = { lat: pos.coords.latitude, lng: pos.coords.longitude };
  const agora = Date.now();
  if (ultimaPos && agora - ultimoEnvio < 20000 && distancia(ultimaPos, p) < 30) return;
  ultimoEnvio = agora; ultimaPos = p;
  post({ acao: 'localizacao', lat: p.lat, lng: p.lng })
    .then(r => { gpsEl.textContent = r.ok ? 'Localização enviada ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : 'Falha ao enviar'; gpsEl.className = 'gps ' + (r.ok ? 'on' : 'off'); })
    .catch(() => { gpsEl.textContent = 'Sem internet'; gpsEl.className = 'gps off'; });
}
if (!('geolocation' in navigator)) {
  gpsEl.textContent = 'Este celular não libera GPS'; gpsEl.className = 'gps off';
} else {
  navigator.geolocation.watchPosition(enviar, err => {
    gpsEl.textContent = err.code === 1 ? 'GPS bloqueado: libere a localização para este site' : 'Procurando sinal de GPS…';
    gpsEl.className = 'gps off';
  }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 30000 });
}

// Mantém a tela acesa enquanto a página está aberta (para o GPS continuar enviando)
async function manterTela() { try { if ('wakeLock' in navigator) await navigator.wakeLock.request('screen'); } catch (e) {} }
manterTela();
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') manterTela(); });

// Atualiza sozinho a cada 2 min se a loja mudar a rota
setInterval(() => { if (!document.querySelector('dialog[open]')) location.reload(); }, 120000);
</script>
<?php rodape();
