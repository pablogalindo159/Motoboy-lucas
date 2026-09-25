<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['data'] ?? '') ? $_REQUEST['data'] : date('Y-m-d');
$motoboys = db()->query("SELECT id, nome FROM usuarios WHERE tipo='motoboy' AND ativo=1 ORDER BY nome")->fetchAll();
$temQuadrantes = (bool)quadrantes_ativos();
$modo = ($_REQUEST['modo'] ?? '') ?: ($temQuadrantes ? 'quadrantes' : 'setores');
$escolhidos = array_values(array_map('intval', (array)($_REQUEST['moto'] ?? array_column($motoboys, 'id'))));

$grupos = []; $avisos = [];
if ($modo === 'quadrantes') [$grupos, $avisos] = grupos_por_quadrante($data);
elseif ($escolhidos) [$grupos, $avisos] = grupos_por_setor($data, $escolhidos);

if (($_POST['acao'] ?? '') === 'confirmar' && csrf_ok()) {
    $sel = $_POST['motoboy'] ?? [];
    foreach ($grupos as &$g) $g['motoboy_id'] = ($sel[$g['chave']] ?? '') !== '' ? (int)$sel[$g['chave']] : null;
    unset($g);
    if (!array_filter(array_column($grupos, 'motoboy_id'))) { flash('Escolha pelo menos um motoboy.', 'erro'); redirecionar("distribuir.php?data=$data&modo=$modo"); }
    // quadrante: guarda o motoboy escolhido como padrão para os próximos dias
    if ($modo === 'quadrantes' && !empty($_POST['lembrar'])) {
        $up = db()->prepare("UPDATE quadrantes SET motoboy_id = ? WHERE id = ?");
        foreach ($grupos as $g) $up->execute([$g['motoboy_id'], (int)substr($g['chave'], 1)]);
    }
    set_time_limit(180);
    try {
        $rotas = criar_rotas_do_dia($data, $grupos);
    } catch (Throwable $ex) {
        flash('Erro ao criar as rotas: ' . $ex->getMessage(), 'erro'); redirecionar("distribuir.php?data=$data&modo=$modo");
    }
    $semMoto = array_sum(array_map(fn($g) => $g['motoboy_id'] ? 0 : count($g['entregas']), $grupos));
    flash(count($rotas) . ' rotas criadas na ordem da lista.' . ($semMoto ? " $semMoto entregas ficaram sem motoboy." : ''), $semMoto ? 'alerta' : 'ok');
    redirecionar('admin.php?data=' . urlencode($data));
}

$entregas = entregas_do_dia($data);
$s = db()->prepare("SELECT COUNT(*) FROM rotas r WHERE r.data = ? AND (r.chegada_cd IS NOT NULL OR EXISTS (SELECT 1 FROM paradas p WHERE p.rota_id = r.id AND p.status <> 'pendente'))");
$s->execute([$data]);
$iniciadas = (int)$s->fetchColumn();

topo('Distribuir entregas', 'rotas', true);
?>
<link rel="stylesheet" href="assets/sacas.css?v=9">
<a href="rotas.php?data=<?= e($data) ?>" class="voltar">← Rotas</a>
<h1>Distribuir entregas de <?= data_br($data) ?></h1>

<?php if (!$entregas): ?>
  <div class="cartao estreito"><p>Nenhuma lista carregada para este dia.</p><a class="btn primario" href="importar_entregas.php?data=<?= e($data) ?>">Carregar lista de entregas</a></div>
<?php rodape(); exit; endif; ?>

<div class="abas-modo">
  <a class="<?= $modo === 'quadrantes' ? 'ativo' : '' ?>" href="?data=<?= e($data) ?>&modo=quadrantes">Pelos quadrantes do mapa</a>
  <a class="<?= $modo === 'setores' ? 'ativo' : '' ?>" href="?data=<?= e($data) ?>&modo=setores">Dividir automático</a>
</div>

<?php if ($modo === 'quadrantes' && !$temQuadrantes): ?>
  <div class="aviso alerta">Nenhum quadrante desenhado ainda. <a href="quadrantes.php">Desenhar ou importar quadrantes</a>, ou use "Dividir automático".</div>
<?php endif; ?>

<?php if ($modo === 'setores'): ?>
  <form class="cartao motos-dia">
    <input type="hidden" name="data" value="<?= e($data) ?>"><input type="hidden" name="modo" value="setores">
    <p><b>Quem trabalha hoje?</b> O sistema divide a cidade em fatias saindo do CD, com a mesma quantidade de pacotes para cada um.</p>
    <div class="chips-motos">
      <?php foreach ($motoboys as $m): ?>
        <label><input type="checkbox" name="moto[]" value="<?= $m['id'] ?>" <?= in_array((int)$m['id'], $escolhidos, true) ? 'checked' : '' ?>> <?= e($m['nome']) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn">Refazer divisão</button>
  </form>
<?php endif; ?>

<?php if (!empty($avisos['fora'])): ?><p class="dica"><?= (int)$avisos['fora'] ?> entregas estavam fora dos quadrantes e foram para o quadrante mais perto.</p><?php endif; ?>
<?php if ($iniciadas): ?><div class="aviso erro"><?= $iniciadas ?> rotas deste dia já começaram (chegada no CD ou entrega marcada). Distribuir de novo apaga as rotas atuais do dia.</div><?php endif; ?>

<?php if ($grupos): ?>
<div class="duas-colunas distribuir">
  <form method="post" id="f-dist">
    <?= csrf_field() ?><input type="hidden" name="acao" value="confirmar">
    <input type="hidden" name="data" value="<?= e($data) ?>"><input type="hidden" name="modo" value="<?= e($modo) ?>">
    <?php foreach ($escolhidos as $id): ?><input type="hidden" name="moto[]" value="<?= $id ?>"><?php endforeach; ?>
    <div class="lista-grupos">
    <?php foreach ($grupos as $g):
        $pac = array_sum(array_column($g['entregas'], 'pacotes'));
        $cx = count(array_unique(array_map(fn($e) => intdiv((int)$e['entrega'], 10) * 10, $g['entregas']))); ?>
      <div class="grupo" style="--cor-rota:<?= e($g['cor']) ?>;--texto-rota:<?= texto_sobre($g['cor']) ?>">
        <div class="faixa"><?= e($g['nome']) ?></div>
        <div class="corpo">
          <p><b><?= count($g['entregas']) ?></b> entregas · <b><?= $pac ?></b> pacotes · <?= $cx ?> caixas</p>
          <label>Motoboy
            <select name="motoboy[<?= e($g['chave']) ?>]">
              <option value="">Não distribuir</option>
              <?php foreach ($motoboys as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$g['motoboy_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if ($modo === 'quadrantes'): ?><label class="lembrar"><input type="checkbox" name="lembrar" value="1" checked> Lembrar estes motoboys nos quadrantes para os próximos dias</label><?php endif; ?>
    <div class="rodape-previa">
      <button class="btn primario grande">Criar rotas</button>
    </div>
    <p class="dica">Cada motoboy entrega na ordem do número da lista. Um motoboy pode ficar com mais de um quadrante: vira uma rota só. As caixas de cada um saem das entregas dele.</p>
  </form>
  <div>
    <div id="mapa" class="mapa-rota mapa-dist"></div>
    <p class="dica"><?= count($entregas) ?> entregas · <?= array_sum(array_column($entregas, 'pacotes')) ?> pacotes</p>
  </div>
</div>

<script>
const grupos = <?= json_encode(array_map(fn($g) => ['nome' => $g['nome'], 'cor' => $g['cor'], 'pts' => array_values(array_filter(array_map(fn($e) => $e['lat'] ? [(float)$e['lat'], (float)$e['lng'], (int)$e['entrega']] : null, $g['entregas'])))], $grupos)) ?>;
const quads = <?= json_encode($modo === 'quadrantes' ? array_map(fn($q) => ['nome' => $q['nome'], 'cor' => $q['cor'], 'pontos' => $q['pontos']], quadrantes_ativos()) : []) ?>;
const cd = <?= json_encode(cd_posicao()) ?>;
const mapa = L.map('mapa', { preferCanvas: true }).setView(cd || [<?= MAPA_LAT ?>, <?= MAPA_LNG ?>], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);
const lim = [];
quads.forEach(q => L.polygon(q.pontos, { color: q.cor, weight: 2, fillOpacity: .08 }).addTo(mapa).bindTooltip(q.nome));
grupos.forEach(g => g.pts.forEach(p => { lim.push([p[0], p[1]]);
  L.circleMarker([p[0], p[1]], { radius: 5, color: '#000', weight: 1, fillColor: g.cor, fillOpacity: .95 }).addTo(mapa).bindTooltip(`${g.nome} · entrega ${p[2]}`); }));
if (cd) { L.marker(cd, { icon: L.divIcon({ className: '', html: '<div class="mapa-cd">CD</div>', iconSize: [34, 24], iconAnchor: [17, 12] }) }).addTo(mapa); lim.push(cd); }
if (lim.length) mapa.fitBounds(lim, { padding: [20, 20] });
</script>
<?php endif; ?>
<?php rodape();
