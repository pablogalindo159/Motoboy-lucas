<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

// KML do Google My Maps: cada polígono vira um quadrante com o nome do Placemark
function ler_kml(string $xml): array {
    $xml = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $xml); // ignora namespaces
    $doc = @simplexml_load_string($xml);
    if (!$doc) return [];
    $out = [];
    foreach ($doc->xpath('//Placemark') as $pm) {
        $nome = trim((string)$pm->name) ?: 'Quadrante';
        foreach ($pm->xpath('.//Polygon//outerBoundaryIs//coordinates') as $c) {
            $pts = [];
            foreach (preg_split('/\s+/', trim((string)$c)) as $t) {
                $v = explode(',', $t);
                if (count($v) >= 2) $pts[] = [round((float)$v[1], 6), round((float)$v[0], 6)];
            }
            if (count($pts) >= 3) $out[] = ['nome' => $nome, 'pontos' => $pts];
        }
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $acao = $_POST['acao'] ?? '';
    $json = str_starts_with($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    if ($acao === 'salvar_forma') { // vindo do mapa (desenho novo ou edição)
        $pts = json_decode($_POST['pontos'] ?? '[]', true);
        $pts = array_map(fn($p) => [round((float)$p[0], 6), round((float)$p[1], 6)], is_array($pts) ? $pts : []);
        if (count($pts) < 3) { http_response_code(422); exit('{"erro":"Polígono precisa de 3 pontos"}'); }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db()->prepare("UPDATE quadrantes SET pontos = ? WHERE id = ?")->execute([json_encode($pts), $id]);
        else {
            $n = (int)db()->query("SELECT COUNT(*) FROM quadrantes")->fetchColumn();
            db()->prepare("INSERT INTO quadrantes (nome, cor, pontos) VALUES (?,?,?)")
                ->execute([trim($_POST['nome'] ?? '') ?: 'Quadrante ' . ($n + 1), PALETA[$n % count(PALETA)], json_encode($pts)]);
        }
        header('Content-Type: application/json'); exit('{"ok":true}');
    }
    if ($acao === 'dados') {
        db()->prepare("UPDATE quadrantes SET nome = ?, cor = ?, motoboy_id = ? WHERE id = ?")->execute([
            trim($_POST['nome'] ?? '') ?: 'Quadrante', preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['cor'] ?? '') ? strtoupper($_POST['cor']) : '#8CF20A',
            ($_POST['motoboy_id'] ?? '') !== '' ? (int)$_POST['motoboy_id'] : null, (int)$_POST['id']]);
        flash('Quadrante salvo.');
    }
    if ($acao === 'excluir') {
        db()->prepare("DELETE FROM quadrantes WHERE id = ?")->execute([(int)$_POST['id']]);
        flash('Quadrante excluído.');
    }
    if ($acao === 'fixos') {
        $n = carregar_quadrantes_fixos(true);
        flash("$n zonas fixas restauradas (motoboys padrão mantidos).");
    }
    if ($acao === 'kml') {
        $lidos = [];
        foreach ((array)($_FILES['kml']['tmp_name'] ?? []) as $arq) {
            if (!$arq || !is_uploaded_file($arq)) continue;
            $conteudo = file_get_contents($arq);
            if (str_starts_with($conteudo, "PK") && class_exists('ZipArchive')) { // .kmz ou .zip com vários .kml
                $z = new ZipArchive(); $z->open($arq);
                for ($i = 0; $i < $z->numFiles; $i++) if (str_ends_with(strtolower($z->getNameIndex($i)), '.kml')) $lidos = array_merge($lidos, ler_kml($z->getFromIndex($i)));
                $z->close();
            } else $lidos = array_merge($lidos, ler_kml($conteudo));
        }
        if (!$lidos) flash('Não achei polígonos. Envie .kml, .kmz ou um .zip com os .kml.', 'erro');
        else {
            if (!empty($_POST['substituir'])) db()->exec("DELETE FROM quadrantes");
            $n = (int)db()->query("SELECT COUNT(*) FROM quadrantes")->fetchColumn();
            $ins = db()->prepare("INSERT INTO quadrantes (nome, cor, pontos) VALUES (?,?,?)");
            foreach ($lidos as $q) $ins->execute([$q['nome'], PALETA[$n++ % count(PALETA)], json_encode($q['pontos'])]);
            flash(count($lidos) . ' quadrantes importados.');
        }
    }
    redirecionar('quadrantes.php');
}

$quads = db()->query("SELECT * FROM quadrantes ORDER BY nome")->fetchAll();
$motoboys = db()->query("SELECT id, nome FROM usuarios WHERE tipo='motoboy' AND ativo=1 ORDER BY nome")->fetchAll();
topo('Quadrantes', 'rotas', true);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
<link rel="stylesheet" href="assets/sacas.css?v=8">
<a href="rotas.php" class="voltar">← Rotas</a>
<h1>Quadrantes</h1>
<div class="duas-colunas quadrantes">
  <div>
    <p class="dica">As 19 zonas do Mercado Livre já vêm fixas no sistema. Em cada uma, escolha o motoboy padrão: ele recebe as entregas daquela zona todo dia (dá para trocar na hora de distribuir).</p>
    <?php foreach ($quads as $q): ?>
      <form method="post" class="cartao quad" style="--cor-rota:<?= e($q['cor']) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $q['id'] ?>">
        <div class="linha">
          <input type="color" name="cor" value="<?= e($q['cor']) ?>" aria-label="Cor">
          <input name="nome" value="<?= e($q['nome']) ?>" aria-label="Nome">
        </div>
        <select name="motoboy_id" aria-label="Motoboy padrão">
          <option value="">Sem motoboy padrão</option>
          <?php foreach ($motoboys as $m): ?><option value="<?= $m['id'] ?>" <?= $q['motoboy_id'] == $m['id'] ? 'selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?>
        </select>
        <div class="acoes">
          <button class="btn pequeno" name="acao" value="dados">Salvar</button>
          <button class="btn pequeno perigo" name="acao" value="excluir" onclick="return confirm('Excluir o quadrante <?= e($q['nome']) ?>?')">Excluir</button>
        </div>
      </form>
    <?php endforeach; ?>
    <?php if (!$quads): ?><p class="vazio">Nenhum quadrante ainda. Desenhe no mapa ou importe do Google My Maps.</p><?php endif; ?>

    <form method="post" class="restaurar" onsubmit="return confirm('Voltar para as 19 zonas fixas? Zonas desenhadas ou importadas por você serão apagadas.')">
      <?= csrf_field() ?><button class="btn pequeno" name="acao" value="fixos">Restaurar as 19 zonas fixas</button>
    </form>
    <details class="cartao importar">
      <summary>Importar outras zonas (.kml, .kmz ou .zip)</summary>
      <form method="post" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?><input type="hidden" name="acao" value="kml">
        <p class="dica">Pode enviar vários .kml de uma vez ou um .zip com todos. Cada polígono vira uma zona com o nome do arquivo.</p>
        <input type="file" name="kml[]" accept=".kml,.kmz,.zip" multiple required>
        <label class="lembrar"><input type="checkbox" name="substituir" value="1"> Apagar os quadrantes atuais antes</label>
        <button class="btn primario">Importar</button>
      </form>
    </details>
  </div>
  <div>
    <div id="mapa" class="mapa-rota mapa-quad"></div>
  </div>
</div>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const quads = <?= json_encode(array_map(fn($q) => ['id' => (int)$q['id'], 'nome' => $q['nome'], 'cor' => $q['cor'], 'pontos' => json_decode($q['pontos'], true)], $quads)) ?>;
const cd = <?= json_encode(cd_posicao()) ?>;
const mapa = L.map('mapa').setView(cd || [<?= MAPA_LAT ?>, <?= MAPA_LNG ?>], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);
if (cd) L.marker(cd, { icon: L.divIcon({ className: '', html: '<div class="mapa-cd">CD</div>', iconSize: [34, 24], iconAnchor: [17, 12] }) }).addTo(mapa);
const camada = new L.FeatureGroup().addTo(mapa);
quads.forEach(q => { const p = L.polygon(q.pontos, { color: q.cor, weight: 2, fillOpacity: .15 }).bindTooltip(q.nome, { permanent: true, direction: 'center', className: 'rotulo-quad' }); p.quadId = q.id; camada.addLayer(p); });
if (quads.length) mapa.fitBounds(camada.getBounds(), { padding: [20, 20] });

async function salvar(dados) {
  const fd = new FormData(); fd.append('csrf', CSRF); Object.entries(dados).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('quadrantes.php', { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
  if (!r.ok) throw new Error();
}
const pontosDe = l => l.getLatLngs()[0].map(p => [p.lat, p.lng]);

if (window.L && L.Control.Draw) {
  L.drawLocal.draw.toolbar.buttons.polygon = 'Desenhar quadrante';
  L.drawLocal.edit.toolbar.buttons.edit = 'Editar formatos';
  mapa.addControl(new L.Control.Draw({
    draw: { polygon: { allowIntersection: false, showArea: false }, polyline: false, rectangle: false, circle: false, marker: false, circlemarker: false },
    edit: { featureGroup: camada, remove: false }
  }));
  mapa.on(L.Draw.Event.CREATED, async e => {
    const nome = prompt('Nome do quadrante (ex.: CJ1):');
    if (nome === null) return;
    try { await salvar({ acao: 'salvar_forma', nome, pontos: JSON.stringify(pontosDe(e.layer)) }); location.reload(); }
    catch (x) { alert('Não foi possível salvar o quadrante.'); }
  });
  mapa.on(L.Draw.Event.EDITED, async e => {
    const tarefas = []; e.layers.eachLayer(l => tarefas.push(salvar({ acao: 'salvar_forma', id: l.quadId, pontos: JSON.stringify(pontosDe(l)) })));
    try { await Promise.all(tarefas); location.reload(); } catch (x) { alert('Não foi possível salvar a edição.'); }
  });
}
</script>
<?php rodape();
