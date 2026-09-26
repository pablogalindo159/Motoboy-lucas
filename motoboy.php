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
$comFoto = [];
if ($paradas) {
    $s = db()->prepare("SELECT parada_id, MAX(id) id FROM comprovantes WHERE parada_id IN (" . implode(',', array_map('intval', array_column($paradas, 'id'))) . ") GROUP BY parada_id");
    $s->execute();
    $comFoto = array_column($s->fetchAll(), 'id', 'parada_id');
}

function destino(array $p): string {
    if ($p['lat']) return $p['lat'] . ',' . $p['lng'];
    return trim($p['endereco'] . ', ' . $p['numero_casa'] . ', ' . ($p['bairro'] ? $p['bairro'] . ', ' : '') . ($p['cidade'] ? $p['cidade'] . ' - ' : '') . UF_PADRAO);
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
<link rel="stylesheet" href="assets/sacas.css?v=14">
<div class="app-moto">
  <header class="moto-topo">
    <img src="assets/icone.svg" alt="" width="40" height="40" class="icone-topo">
    <div class="quem">
      <strong><?= e($u['nome']) ?></strong>
      <span id="gps" class="gps">Ligando GPS…</span>
    </div>
    <a href="logout.php" class="sair" onclick="window.NetPointApp && NetPointApp.pararRastreio()">Sair</a>
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
    <?php
      // fases: ir ao CD -> coletar caixas -> entregas
      $fase = 'entregas';
      if ($sacas && !$rota['saida_cd']) $fase = $rota['chegada_cd'] ? 'coleta' : 'ir_cd';
      $estiloCor = '--cor-rota:' . e($corRota ?: '#8CF20A') . ';--texto-rota:' . texto_sobre($corRota ?: '#8CF20A');
    ?>
    <?php if ($fase === 'ir_cd'): ?>
    <section class="chegada" style="<?= $estiloCor ?>">
      <div class="faixa-cor"><span class="cor-motoboy"><?= e($rota['descricao'] ?: 'Sua cor') ?></span> Sua cor de hoje</div>
      <h1>Vá até o CD</h1>
      <p>Hoje você tem <b><?= count($paradas) ?></b> entregas, <b><?= array_sum(array_column($sacas, 'quantidade')) ?></b> pacotes em <b><?= count($sacas) ?></b> caixas.</p>
      <button class="btn primario grande largo" id="btn-cheguei" onclick="fase('chegou_cd')">Cheguei no CD</button>
    </section>
    <?php elseif ($sacas): $todas = $sacasColetadas === count($sacas); ?>
    <details class="sacas" id="sacas" <?= $fase === 'coleta' ? 'open' : '' ?> style="<?= $estiloCor ?>">
      <summary>
        <span class="cor-motoboy"><?= e($rota['descricao'] ?: 'Sua cor') ?></span>
        <span class="titulo-sacas" id="sacas-titulo"><?= $todas ? 'Sacas coletadas' : 'Sacas para coletar' ?></span>
        <span class="contador" id="sacas-cont"><?= $sacasColetadas ?>/<?= count($sacas) ?></span>
      </summary>
      <?php if ($fase === 'coleta' && $prox): ?>
      <div class="primeira">
        <p class="rotulo">Sua primeira entrega</p>
        <div class="primeira-linha">
          <span class="num-parada"><?= (int)($prox['entrega'] ?: $prox['numero']) ?></span>
          <div><b><?= e($prox['endereco']) ?>, <?= e($prox['numero_casa']) ?></b>
            <small><?= (int)$prox['pacotes'] ?> <?= $prox['pacotes'] > 1 ? 'pacotes' : 'pacote' ?> · caixa <?= $prox['entrega'] !== null ? intdiv((int)$prox['entrega'], 10) * 10 : '—' ?></small></div>
        </div>
      </div>
      <?php endif; ?>
      <p class="dica">Toque em cada saca quando pegar. Total: <b><?= array_sum(array_column($sacas, 'quantidade')) ?></b> pacotes.</p>
      <div class="grade-sacas">
        <?php foreach ($sacas as $sc): ?>
          <button type="button" class="saca <?= $sc['coletada'] ? 'coletada' : '' ?>" data-id="<?= $sc['id'] ?>" onclick="coletar(this)" aria-pressed="<?= $sc['coletada'] ? 'true' : 'false' ?>">
            <b><?= (int)$sc['caixa'] ?></b><small><?= (int)$sc['quantidade'] ?> pct</small>
            <?php if (!empty($sc['compartilhada'])): ?><em>só <?= e(str_replace(',', ', ', $sc['entregas'])) ?></em><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?php if ($fase === 'coleta'): ?>
      <div class="sair-cd">
        <button class="btn primario grande largo" onclick="sairCd()">Sair para as entregas</button>
      </div>
      <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($fase === 'entregas'): ?>

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
      <p class="rotulo">Próxima entrega · parada <?= count($feitas) + 1 ?> de <?= count($paradas) ?></p>
      <div class="placa-parada"><small><?= $prox['entrega'] ? 'Entrega' : 'Parada' ?></small><?= (int)($prox['entrega'] ?: $prox['numero']) ?></div>
      <h1 class="endereco"><?= e($prox['endereco']) ?>, <?= e($prox['numero_casa']) ?></h1>
      <p class="bairro"><?= e($prox['bairro']) ?><?= $prox['bairro'] ? ' · ' : '' ?><?= e($prox['cidade']) ?></p>
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
      <button class="btn largo voador" onclick='pacoteVoador(<?= json_encode([
          "id" => (int)$prox["id"], "entrega" => (int)($prox["entrega"] ?: $prox["numero"]),
          "endereco" => $prox["endereco"] . ", " . $prox["numero_casa"], "motoboy" => $u["nome"]], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
        <span aria-hidden="true">📦</span> Pacote voador <small>foto com GPS · marca como entregue</small>
      </button>
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
          <li><span class="num-parada"><?= (int)($p['entrega'] ?: $p['numero']) ?></span><div><?= e($p['endereco']) ?>, <?= e($p['numero_casa']) ?><small><?= e($p['bairro']) ?> · <?= (int)$p['pacotes'] ?> pct</small></div>
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
          <li><span class="num-parada <?= e($p['status']) ?>"><?= (int)($p['entrega'] ?: $p['numero']) ?></span><div><?= e($p['endereco']) ?>, <?= e($p['numero_casa']) ?><small><?= $p['status'] === 'entregue' ? 'Entregue' : 'Não entregue' ?> às <?= hora_br($p['finalizado_em']) ?><?php if (isset($comFoto[$p['id']])): ?> · <a href="foto.php?id=<?= (int)$comFoto[$p['id']] ?>" target="_blank">📷 foto</a><?php endif; ?></small></div></li>
        <?php endforeach; ?>
      </ol>
    </details>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<dialog id="dlg-voador" class="dlg-voador">
  <div class="voador-topo"><b>📦 Pacote voador</b> <span id="voador-gps">Obtendo GPS…</span></div>
  <div class="voador-camera">
    <video id="voador-video" playsinline muted autoplay></video>
    <img id="voador-previa" alt="Foto registrada" hidden>
    <p id="voador-aviso" class="voador-aviso" hidden></p>
  </div>
  <p class="voador-info" id="voador-info"></p>
  <div class="voador-botoes" id="voador-b-foto">
    <button class="btn grande" onclick="fecharVoador()">Cancelar</button>
    <button class="btn primario grande" id="voador-tirar" onclick="tirarFoto()">Tirar foto</button>
  </div>
  <div class="voador-botoes" id="voador-b-salvar" hidden>
    <button class="btn grande" onclick="refazerFoto()">Refazer</button>
    <button class="btn sucesso grande" id="voador-salvar" onclick="salvarVoador()">Salvar e marcar entregue</button>
  </div>
  <input type="file" id="voador-arquivo" accept="image/*" capture="environment" hidden>
</dialog>

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

// ---- Pacote voador: foto com carimbo (entrega, endereço, data, hora e GPS) ----
let voador = null, fluxo = null, gpsVoador = null, vigiaGps = null, fotoBlob = null;
let endGps = null, endGpsPonto = null, endGpsBusca = null;
// busca o endereço do ponto do GPS (no máximo uma busca por vez; de novo se andar mais de 25 m)
function buscarEnderecoGps() {
  if (!gpsVoador || gpsVoador.accuracy > 150) return endGpsBusca;
  if (endGpsBusca) return endGpsBusca;
  if (endGpsPonto && distancia(endGpsPonto, gpsVoador) < 25) return Promise.resolve(endGps);
  const ponto = { lat: gpsVoador.lat, lng: gpsVoador.lng };
  endGpsBusca = post({ acao: 'endereco_gps', lat: ponto.lat, lng: ponto.lng })
    .then(r => r.json()).then(j => { if (j.endereco) { endGps = j.endereco; endGpsPonto = ponto; } atualizarInfo(); return endGps; })
    .catch(() => endGps).finally(() => { endGpsBusca = null; });
  return endGpsBusca;
}
const dlgV = () => document.getElementById('dlg-voador');
const doisDig = n => String(n).padStart(2, '0');
const agoraTxt = d => `${doisDig(d.getDate())}/${doisDig(d.getMonth() + 1)}/${d.getFullYear()} ${doisDig(d.getHours())}:${doisDig(d.getMinutes())}:${doisDig(d.getSeconds())}`;
const agoraSql = d => `${d.getFullYear()}-${doisDig(d.getMonth() + 1)}-${doisDig(d.getDate())} ${doisDig(d.getHours())}:${doisDig(d.getMinutes())}:${doisDig(d.getSeconds())}`;

function textoGps() {
  const el = document.getElementById('voador-gps');
  if (!gpsVoador) { el.textContent = 'Obtendo GPS…'; el.className = ''; return; }
  el.textContent = `GPS ±${Math.round(gpsVoador.accuracy)} m`; el.className = gpsVoador.accuracy <= 50 ? 'ok' : 'fraco';
}
function atualizarInfo() {
  document.getElementById('voador-info').textContent = `Entrega ${voador.entrega} · ${voador.endereco}` +
    (endGps ? `\n📍 Você está em: ${endGps}` : (gpsVoador ? '\n📍 Buscando endereço do GPS…' : ''));
}

async function pacoteVoador(dados) {
  voador = dados; gpsVoador = null; fotoBlob = null; endGps = null; endGpsPonto = null;
  atualizarInfo(); textoGps();
  document.getElementById('voador-previa').hidden = true;
  document.getElementById('voador-video').hidden = false;
  document.getElementById('voador-b-foto').hidden = false;
  document.getElementById('voador-b-salvar').hidden = true;
  document.getElementById('voador-aviso').hidden = true;
  dlgV().showModal();
  if ('geolocation' in navigator) {
    vigiaGps = navigator.geolocation.watchPosition(p => { if (!gpsVoador || p.coords.accuracy <= gpsVoador.accuracy || Date.now() - gpsVoador.hora > 15000) { gpsVoador = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy, hora: Date.now() }; textoGps(); buscarEnderecoGps(); } },
      () => { document.getElementById('voador-gps').textContent = 'Sem GPS'; document.getElementById('voador-gps').className = 'fraco'; },
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
  }
  try {
    fluxo = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
    document.getElementById('voador-video').srcObject = fluxo;
  } catch (e) {
    // sem acesso direto à câmera (navegador antigo): usa a câmera do sistema
    const av = document.getElementById('voador-aviso');
    av.textContent = 'Toque em "Tirar foto" para abrir a câmera.'; av.hidden = false;
    document.getElementById('voador-video').hidden = true;
  }
}

function pararCamera() {
  if (fluxo) { fluxo.getTracks().forEach(t => t.stop()); fluxo = null; }
  if (vigiaGps !== null) { navigator.geolocation.clearWatch(vigiaGps); vigiaGps = null; }
}
function fecharVoador() { pararCamera(); dlgV().close(); }
dlgV().addEventListener('cancel', pararCamera);

async function esperarEndereco() {
  const busca = buscarEnderecoGps();
  if (!busca || endGps) return;
  await Promise.race([busca, new Promise(r => setTimeout(r, 4000))]);
}
async function tirarFoto() {
  const video = document.getElementById('voador-video');
  if (fluxo && video.videoWidth) {
    const bt = document.getElementById('voador-tirar');
    // congela o quadro na hora do toque e só depois espera o endereço
    const quadro = document.createElement('canvas'); quadro.width = video.videoWidth; quadro.height = video.videoHeight;
    quadro.getContext('2d').drawImage(video, 0, 0);
    bt.disabled = true; bt.textContent = 'Carimbando…';
    await esperarEndereco();
    bt.disabled = false; bt.textContent = 'Tirar foto';
    return carimbar(quadro, quadro.width, quadro.height);
  }
  const inp = document.getElementById('voador-arquivo');
  inp.onchange = () => {
    const f = inp.files[0]; if (!f) return;
    const img = new Image();
    img.onload = async () => { await esperarEndereco(); carimbar(img, img.naturalWidth, img.naturalHeight); };
    img.src = URL.createObjectURL(f);
  };
  inp.click();
}

function carimbar(fonte, w, h) {
  const d = new Date();
  const max = 1600, esc = Math.min(1, max / Math.max(w, h));
  const W = Math.round(w * esc), H = Math.round(h * esc);
  const c = document.createElement('canvas'); c.width = W; c.height = H;
  const g = c.getContext('2d');
  g.drawImage(fonte, 0, 0, W, H);
  const fs = Math.max(16, Math.round(W / 38)), lh = fs * 1.35, pad = fs * .7;
  const linhas = [
    `PACOTE VOADOR · ENTREGA ${voador.entrega}`,
    voador.endereco,
    agoraTxt(d),
    gpsVoador ? `GPS ${gpsVoador.lat.toFixed(6)}, ${gpsVoador.lng.toFixed(6)} (±${Math.round(gpsVoador.accuracy)} m)` : 'GPS indisponível',
    ...(gpsVoador ? [`Local GPS: ${endGps || 'endereço não encontrado'}`] : []),
    `Motoboy: ${voador.motoboy} · NetPoint Rotas`,
  ];
  const alt = pad * 2 + lh * linhas.length;
  g.fillStyle = 'rgba(0,0,0,.72)'; g.fillRect(0, H - alt, W, alt);
  g.fillStyle = '#8CF20A'; g.fillRect(0, H - alt, Math.max(6, fs * .35), alt);
  g.textBaseline = 'top';
  linhas.forEach((t, i) => {
    g.font = `${i === 0 ? '800' : '600'} ${i === 0 ? Math.round(fs * 1.15) : fs}px system-ui, sans-serif`;
    g.fillStyle = i === 0 ? '#8CF20A' : '#FFFFFF';
    g.fillText(t, pad + fs * .5, H - alt + pad + lh * i, W - pad * 2);
  });
  voador.tiradaEm = agoraSql(d);
  voador.gps = gpsVoador ? { ...gpsVoador } : null;
  voador.endGps = endGps;
  c.toBlob(b => {
    fotoBlob = b;
    const prev = document.getElementById('voador-previa');
    prev.src = URL.createObjectURL(b); prev.hidden = false;
    document.getElementById('voador-video').hidden = true;
    document.getElementById('voador-aviso').hidden = true;
    document.getElementById('voador-b-foto').hidden = true;
    document.getElementById('voador-b-salvar').hidden = false;
    if (fluxo) fluxo.getTracks().forEach(t => t.enabled = false);
  }, 'image/jpeg', 0.82);
}

function refazerFoto() {
  fotoBlob = null;
  document.getElementById('voador-previa').hidden = true;
  document.getElementById('voador-b-salvar').hidden = true;
  document.getElementById('voador-b-foto').hidden = false;
  if (fluxo) { fluxo.getTracks().forEach(t => t.enabled = true); document.getElementById('voador-video').hidden = false; }
}

async function salvarVoador() {
  if (!fotoBlob) return;
  if (!voador.gps && !confirm('A foto ficou sem GPS. Salvar mesmo assim?')) return;
  const bt = document.getElementById('voador-salvar'); bt.disabled = true; bt.textContent = 'Enviando…';
  const fd = new FormData();
  fd.append('acao', 'pacote_voador'); fd.append('parada_id', voador.id);
  fd.append('foto', fotoBlob, `entrega-${voador.entrega}.jpg`);
  fd.append('tirada_em', voador.tiradaEm);
  if (voador.gps) { fd.append('lat', voador.gps.lat); fd.append('lng', voador.gps.lng); fd.append('precisao', Math.round(voador.gps.accuracy)); }
  if (voador.endGps) fd.append('endereco_gps', voador.endGps);
  try {
    const r = await fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } });
    const j = await r.json().catch(() => ({}));
    if (r.status === 401) { location.href = 'index.php'; return; }
    if (!r.ok) throw new Error(j.erro || 'Falha ao enviar');
    if (navigator.vibrate) navigator.vibrate([60, 40, 60]);
    pararCamera(); location.reload();
  } catch (e) {
    alert(e.message + '. Confira a internet e toque em Salvar de novo.');
    bt.disabled = false; bt.textContent = 'Salvar e marcar entregue';
  }
}

// ---- Chegada e saída do CD ----
const ROTA_ID = <?= (int)($rota['id'] ?? 0) ?>;
async function fase(acao) {
  try {
    const r = await post({ acao, rota_id: ROTA_ID });
    if (!r.ok) throw new Error();
    location.reload();
  } catch (e) { alert('Não foi possível salvar. Confira a internet e toque de novo.'); }
}
function sairCd() {
  const faltam = document.querySelectorAll('.saca:not(.coletada)').length;
  if (faltam && !confirm(`Ainda faltam ${faltam} caixa(s). Sair mesmo assim?`)) return;
  fase('saiu_cd');
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
// Dentro do app Android: o próprio app envia a localização, mesmo com o Waze/Maps na frente.
const APP = window.NetPointApp;
const EM_ROTA = <?= ($rota && $pendentes) ? 'true' : 'false' ?>;
if (APP) {
  if (EM_ROTA) {
    APP.iniciarRastreio(CSRF, new URL('api.php', location.href).href);
    gpsEl.textContent = 'Localização ligada pelo app'; gpsEl.className = 'gps on';
  } else {
    APP.pararRastreio();
    gpsEl.textContent = 'Localização desligada (sem entregas pendentes)'; gpsEl.className = 'gps';
  }
} else if (!('geolocation' in navigator)) {
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
