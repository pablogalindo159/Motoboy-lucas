<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'buscar') {
        $end = trim($_POST['endereco'] ?? '');
        $pos = strrpos($end, ',');
        ['lat' => $lat, 'lng' => $lng] = geo_consultar(trim($pos ? substr($end, 0, $pos) : $end), trim($pos ? substr($end, $pos + 1) : ''), false); // o CD pode ficar fora dos bairros atendidos
        if ($lat) { cfg_salvar('cd_lat', (string)$lat); cfg_salvar('cd_lng', (string)$lng); cfg_salvar('cd_endereco', $end); flash('CD localizado. Confira no mapa e arraste o marcador se precisar.'); }
        else flash('Endereço não encontrado. Clique no mapa no lugar do CD.', 'erro');
    }
    if ($acao === 'posicao') {
        cfg_salvar('cd_lat', (string)(float)$_POST['lat']); cfg_salvar('cd_lng', (string)(float)$_POST['lng']);
        flash('Posição do CD salva.');
    }
    if ($acao === 'carto') {
        $k = trim($_POST['carto_key'] ?? '');
        cfg_salvar('carto_key', preg_match('/^[A-Za-z0-9_\-]{8,120}$/', $k) ? $k : null);
        flash($k === '' ? 'Chave do mapa da TV removida.' : (cfg('carto_key') ? 'Chave do mapa da TV salva.' : 'Chave inválida.'), cfg('carto_key') || $k === '' ? 'ok' : 'erro');
    }
    if ($acao === 'google') {
        cfg_salvar('google_key', trim($_POST['google_key'] ?? '') ?: null);
        flash('Chave salva.');
    }
    redirecionar('cd.php');
}
$cd = cd_posicao();
topo('Centro de distribuição', 'rotas', true);
?>
<a href="rotas.php" class="voltar">← Rotas</a>
<h1>Centro de distribuição (CD)</h1>
<div class="duas-colunas">
  <div>
    <form method="post" class="form cartao">
      <?= csrf_field() ?><input type="hidden" name="acao" value="buscar">
      <p>As rotas começam aqui. Digite o endereço ou clique no mapa no lugar exato do CD.</p>
      <label>Endereço do CD<input name="endereco" value="<?= e(cfg('cd_endereco', '')) ?>" placeholder="Rua Exemplo, 100"></label>
      <button class="btn primario">Localizar</button>
    </form>
    <form method="post" class="form cartao" style="margin-top:1rem">
      <?= csrf_field() ?><input type="hidden" name="acao" value="google">
      <p><b>Chave do Google Maps</b> <small>(opcional)</small><br>Com ela os endereços são achados em segundos e com mais precisão. Sem ela o sistema usa o OpenStreetMap, grátis, mas leva cerca de 1 segundo por endereço novo.</p>
      <label>Chave da Geocoding API<input name="google_key" value="<?= e(cfg('google_key', '')) ?>" autocomplete="off"></label>
      <button class="btn">Salvar chave</button>
    </form>
    <form method="post" class="form cartao" style="margin-top:1rem">
      <?= csrf_field() ?><input type="hidden" name="acao" value="carto">
      <p><b>Mapa escuro da TV (CARTO)</b><br>Sem a chave, a TV usa o mapa comum escurecido. Chave grátis em carto.com/basemaps/apikey.</p>
      <label>Chave CARTO<input name="carto_key" value="<?= e(cfg('carto_key', '')) ?>" autocomplete="off" placeholder="cb1_..."></label>
      <button class="btn">Salvar chave do mapa</button>
    </form>
  </div>
  <div>
    <div id="mapa" class="mapa-rota"></div>
    <p class="dica"><?= $cd ? 'Arraste o marcador para ajustar.' : 'Clique no mapa para marcar o CD.' ?></p>
    <form method="post" id="f-pos"><?= csrf_field() ?><input type="hidden" name="acao" value="posicao"><input type="hidden" name="lat"><input type="hidden" name="lng"></form>
  </div>
</div>
<script>
const cd = <?= json_encode($cd) ?>;
const mapa = L.map('mapa').setView(cd || [-25.47, -49.23], cd ? 16 : 11);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);
const f = document.getElementById('f-pos');
function salvar(ll) { f.lat.value = ll.lat; f.lng.value = ll.lng; f.submit(); }
if (cd) L.marker(cd, { draggable: true }).addTo(mapa).bindPopup('CD').on('dragend', e => salvar(e.target.getLatLng()));
mapa.on('click', e => { if (confirm('Marcar o CD aqui?')) salvar(e.latlng); });
</script>
<?php rodape();
