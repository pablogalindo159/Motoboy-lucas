<?php
// Corrigir o local de uma entrega que não foi achada no mapa (ou achada no lugar errado).
// Pelo CEP e/ou clicando no mapa. O sistema guarda e usa nas próximas listas.
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

$s = db()->prepare("SELECT * FROM entregas WHERE id = ?");
$s->execute([(int)($_REQUEST['id'] ?? 0)]);
$e = $s->fetch();
if (!$e) { flash('Entrega não encontrada.', 'erro'); redirecionar('rotas.php'); }
$voltar = 'rotas.php?data=' . urlencode($e['data']) . '#lista-entregas';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $lat = (float)($_POST['lat'] ?? 0); $lng = (float)($_POST['lng'] ?? 0);
    if (!$lat || !$lng) { flash('Marque o local no mapa antes de salvar.', 'erro'); redirecionar('corrigir_local.php?id=' . $e['id']); }
    $n = aprender_local($e['rua'], (string)$e['numero_casa'], $lat, $lng, trim($_POST['bairro'] ?? '') ?: null, $e['data']);
    flash("Local da entrega {$e['entrega']} salvo" . ($n > 1 ? " (e de mais " . ($n - 1) . " entrega(s) no mesmo endereço)" : '') . '. Nas próximas listas esse endereço já vem localizado.');
    redirecionar($voltar);
}

$quads = array_map(fn($q) => ['nome' => $q['nome'], 'cor' => $q['cor'], 'pontos' => $q['pontos']], quadrantes_ativos());
topo('Corrigir local', 'rotas', true);
?>
<link rel="stylesheet" href="assets/mapa.css?v=1">
<script src="assets/mapa.js?v=1"></script>
<link rel="stylesheet" href="assets/sacas.css?v=27">
<a href="<?= e($voltar) ?>" class="voltar">← Entregas do dia</a>
<h1>Corrigir local · entrega <?= (int)$e['entrega'] ?></h1>
<p class="numeros"><b><?= e($e['rua']) ?>, <?= e($e['numero_casa']) ?></b> · <?= (int)$e['pacotes'] ?> pacote(s)
  <?php if ($e['geo_status'] === 'fora_bairro'): ?> · <span class="txt-erro">achada só em <?= e($e['bairro'] ?: 'outro bairro') ?></span>
  <?php elseif ($e['lat'] === null): ?> · <span class="txt-alerta">não achada no mapa</span><?php endif; ?></p>

<div class="duas-colunas">
  <form method="post" class="form cartao" id="f-local">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <input type="hidden" name="lat" value="<?= e($e['lat']) ?>"><input type="hidden" name="lng" value="<?= e($e['lng']) ?>">
    <label>CEP <small>(opcional, ajuda a achar)</small>
      <div class="linha"><input name="cep" inputmode="numeric" placeholder="00000-000" maxlength="9">
        <button type="button" class="btn" id="btn-cep">Buscar</button></div></label>
    <p class="dica" id="res-cep"></p>
    <label>Bairro<input name="bairro" value="<?= e($e['geo_status'] === 'ok' ? $e['bairro'] : '') ?>" placeholder="ex.: Cajuru"></label>
    <p class="dica">Depois do CEP, confira o ponto no mapa. Se não estiver certo, <b>clique no mapa</b> ou <b>arraste o marcador</b> até a casa.</p>
    <button class="btn primario grande" id="btn-salvar" <?= $e['lat'] !== null && $e['geo_status'] === 'ok' ? '' : 'disabled' ?>>Salvar local</button>
    <p class="dica">O sistema guarda esse local para <b><?= e($e['rua']) ?>, <?= e($e['numero_casa']) ?></b> e usa nas próximas listas. As rotas de hoje que têm esse endereço também são corrigidas.</p>
  </form>
  <div>
    <div id="mapa" class="mapa-rota mapa-corrigir"></div>
  </div>
</div>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const f = document.getElementById('f-local');
const inicio = <?= json_encode($e['lat'] !== null ? [(float)$e['lat'], (float)$e['lng']] : null) ?>;
const mapa = L.map('mapa').setView(inicio || [<?= MAPA_LAT ?>, <?= MAPA_LNG ?>], inicio ? 17 : 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);
NP.quadrantes(mapa, <?= json_encode($quads, JSON_UNESCAPED_UNICODE) ?>);
let marcador = null;
function colocar(ll, centralizar) {
  if (!marcador) { marcador = L.marker(ll, { draggable: true }).addTo(mapa); marcador.on('dragend', () => colocar(marcador.getLatLng(), false)); }
  else marcador.setLatLng(ll);
  const p = marcador.getLatLng(); f.lat.value = p.lat.toFixed(7); f.lng.value = p.lng.toFixed(7);
  document.getElementById('btn-salvar').disabled = false;
  if (centralizar) mapa.setView(p, 17);
}
if (inicio) colocar(inicio, false);
mapa.on('click', ev => colocar(ev.latlng, false));

document.getElementById('btn-cep').onclick = async () => {
  const res = document.getElementById('res-cep'); res.textContent = 'Buscando…'; res.className = 'dica';
  const fd = new FormData(); fd.append('acao', 'buscar_cep'); fd.append('cep', f.cep.value); fd.append('numero', <?= json_encode((string)$e['numero_casa']) ?>);
  try {
    const j = await (await fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })).json();
    if (j.erro) { res.textContent = j.erro; res.className = 'txt-erro'; return; }
    res.innerHTML = '';
    res.append(`Correios: ${j.rua || '(sem rua)'} · ${j.bairro} · ${j.cidade}-${j.uf}. `);
    if (j.bairro && !f.bairro.value) f.bairro.value = j.bairro;
    if (j.lat) { colocar([j.lat, j.lng], true); res.append('Ponto colocado no mapa: confira e ajuste se precisar.'); }
    else res.append('Não achei o ponto exato: clique no mapa no lugar da casa.');
  } catch (e) { res.textContent = 'Não foi possível buscar agora. Marque direto no mapa.'; res.className = 'txt-erro'; }
};
f.cep.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); document.getElementById('btn-cep').click(); } });
</script>
<?php rodape();
