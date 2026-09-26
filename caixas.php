<?php
// Caixas do dia: quais entregas (pacotes) vão em cada caixa e com qual motoboy. Para montar as caixas no CD.
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
$s = db()->prepare("
  SELECT e.entrega, e.rua, e.numero_casa, e.pacotes, x.status, x.motoboy_id, x.nome motoboy, x.cor
  FROM entregas e
  LEFT JOIN (SELECT p.entrega, p.status, r.motoboy_id, u.nome, r.cor FROM paradas p JOIN rotas r ON r.id = p.rota_id JOIN usuarios u ON u.id = r.motoboy_id
             WHERE r.data = ? AND p.entrega IS NOT NULL) x ON x.entrega = e.entrega
  WHERE e.data = ? ORDER BY e.entrega");
$s->execute([$data, $data]);
$caixas = [];
foreach ($s->fetchAll() as $e) {
    $c = intdiv((int)$e['entrega'], 10) * 10;
    $caixas[$c]['itens'][] = $e;
    $caixas[$c]['pacotes'] = ($caixas[$c]['pacotes'] ?? 0) + (int)$e['pacotes'];
    $caixas[$c]['motos'][$e['motoboy_id'] ?? 0] = ['nome' => $e['motoboy'] ?? 'Sem motoboy', 'cor' => $e['cor'] ?: '#BBBBBB'];
}
ksort($caixas);
$motos = [];
foreach ($caixas as $c) foreach ($c['motos'] as $id => $m) $motos[$id] = $m;
uasort($motos, fn($a, $b) => strcmp($a['nome'], $b['nome']));
$totPac = array_sum(array_column($caixas, 'pacotes'));

topo('Caixas do dia', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=22">
<div class="cabecalho-rota">
  <div>
    <a href="rotas.php?data=<?= e($data) ?>" class="voltar nao-imprimir">← Rotas</a>
    <h1>Caixas de <?= data_br($data) ?></h1>
    <p class="numeros"><b><?= count($caixas) ?></b> caixas <b><?= $totPac ?></b> pacotes</p>
  </div>
  <div class="acoes nao-imprimir">
    <form><label>Dia <input type="date" name="data" value="<?= e($data) ?>" onchange="this.form.submit()"></label></form>
    <button class="btn" onclick="window.print()">Imprimir</button>
  </div>
</div>

<?php if (!$caixas): ?>
  <p class="vazio">Nenhuma lista de entregas neste dia. <a href="importar_entregas.php?data=<?= e($data) ?>">Carregar lista</a></p>
<?php else: ?>
<div class="filtro-caixas nao-imprimir">
  <input type="search" id="busca-cx" placeholder="Número da entrega ou da caixa" inputmode="numeric">
  <select id="filtro-moto">
    <option value="">Todos os motoboys</option>
    <?php foreach ($motos as $id => $m): ?><option value="<?= (int)$id ?>"><?= e($m['nome']) ?></option><?php endforeach; ?>
  </select>
  <span id="cx-achadas" class="dica"></span>
</div>

<div class="grade-caixas" id="grade-caixas">
<?php foreach ($caixas as $num => $c): $dividida = count($c['motos']) > 1; ?>
  <section class="cx" data-caixa="<?= $num ?>" data-motos="<?= e(implode(',', array_keys($c['motos']))) ?>" data-nums="<?= e(implode(',', array_column($c['itens'], 'entrega'))) ?>">
    <header>
      <span class="cx-num">Caixa <?= $num ?></span>
      <span class="cx-pac"><?= (int)$c['pacotes'] ?> pct</span>
    </header>
    <div class="cx-motos">
      <?php foreach ($c['motos'] as $m): ?><span><span class="bolinha" style="background:<?= e($m['cor']) ?>"></span><?= e($m['nome']) ?></span><?php endforeach; ?>
      <?php if ($dividida): ?><b class="txt-alerta">dividida</b><?php endif; ?>
    </div>
    <ol>
      <?php foreach ($c['itens'] as $it): ?>
        <li data-moto="<?= (int)($it['motoboy_id'] ?? 0) ?>" data-num="<?= (int)$it['entrega'] ?>">
          <b><?= (int)$it['entrega'] ?></b>
          <span class="cx-end" title="<?= e($it['rua'] . ', ' . $it['numero_casa']) ?>"><?= e($it['rua']) ?>, <?= e($it['numero_casa']) ?></span>
          <span class="cx-q"><?= (int)$it['pacotes'] ?> pct</span>
          <?php if ($dividida): ?><span class="bolinha" title="<?= e($it['motoboy'] ?? 'Sem motoboy') ?>" style="background:<?= e($it['cor'] ?: '#BBBBBB') ?>"></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php endforeach; ?>
</div>
<script>
(() => {
  const busca = document.getElementById('busca-cx'), filtro = document.getElementById('filtro-moto'), achadas = document.getElementById('cx-achadas');
  const caixas = [...document.querySelectorAll('.cx')];
  function aplicar() {
    const q = busca.value.trim(), m = filtro.value; let n = 0;
    caixas.forEach(cx => {
      let ok = !m || cx.dataset.motos.split(',').includes(m);
      if (ok && q) ok = cx.dataset.caixa === q || cx.dataset.nums.split(',').includes(q) || String(Math.floor(+q / 10) * 10) === cx.dataset.caixa;
      cx.hidden = !ok; if (ok) n++;
      cx.querySelectorAll('li').forEach(li => {
        li.classList.toggle('achada', q !== '' && li.dataset.num === q);
        li.classList.toggle('apagada', !!m && li.dataset.moto !== m);
      });
    });
    achadas.textContent = q || m ? `${n} caixas` : '';
  }
  busca.addEventListener('input', aplicar); filtro.addEventListener('change', aplicar);
})();
</script>
<?php endif; ?>
<?php rodape();
