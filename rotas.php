<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'criar') {
        $mid = (int)($_POST['motoboy_id'] ?? 0);
        $data = $_POST['data'] ?? date('Y-m-d');
        if (!$mid) { flash('Escolha o motoboy.', 'erro'); redirecionar('rotas.php'); }
        db()->prepare("INSERT INTO rotas (motoboy_id, data, descricao, valor_entrega) VALUES (?,?,?,(SELECT valor_entrega FROM usuarios WHERE id = ?))")
            ->execute([$mid, $data, trim($_POST['descricao'] ?? ''), $mid]);
        flash('Rota criada. Agora lance as paradas.');
        redirecionar('rota.php?id=' . db()->lastInsertId());
    }
    if ($acao === 'excluir') {
        db()->prepare("DELETE FROM rotas WHERE id = ?")->execute([(int)$_POST['id']]);
        flash('Rota excluída.');
        redirecionar('rotas.php?data=' . urlencode($_POST['data'] ?? ''));
    }
}

$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
$motoboys = db()->query("SELECT id, nome FROM usuarios WHERE tipo='motoboy' AND ativo=1 ORDER BY nome")->fetchAll();
$s = db()->prepare("
  SELECT r.*, u.nome motoboy,
         COUNT(p.id) paradas, COALESCE(SUM(p.status='entregue'),0) entregues, COALESCE(SUM(p.status='falhou'),0) falhas,
         COALESCE(SUM(p.pacotes),0) pacotes
  FROM rotas r JOIN usuarios u ON u.id = r.motoboy_id
  LEFT JOIN paradas p ON p.rota_id = r.id
  WHERE r.data = ? GROUP BY r.id ORDER BY u.nome, r.id");
$s->execute([$data]);
$rotas = $s->fetchAll();

// todas as entregas da lista do dia, com o motoboy que ficou com cada uma (ou sem motoboy) e a zona
$s = db()->prepare("
  SELECT e.entrega, e.rua, e.numero_casa, e.pacotes, e.lat, e.lng, x.status, x.nome motoboy, x.cor
  FROM entregas e
  LEFT JOIN (SELECT p.entrega, p.status, u.nome, r.cor FROM paradas p JOIN rotas r ON r.id = p.rota_id JOIN usuarios u ON u.id = r.motoboy_id
             WHERE r.data = ? AND p.entrega IS NOT NULL) x ON x.entrega = e.entrega
  WHERE e.data = ? ORDER BY e.entrega");
$s->execute([$data, $data]);
$lista = $s->fetchAll();
$quadsLista = quadrantes_ativos();
foreach ($lista as &$it) {
    if ($it['lat'] === null) { $it['zona'] = null; $it['fora'] = false; $it['sem_local'] = true; continue; }
    [$qid, $perto, $dist] = zona_do_ponto([(float)$it['lat'], (float)$it['lng']], $quadsLista);
    $it['sem_local'] = false;
    $it['fora'] = $quadsLista && $qid === null;
    $it['zona'] = $qid !== null ? $perto : ($perto ? "fora · " . ($dist >= 1000 ? number_format($dist / 1000, 1, ',', '') . ' km' : round($dist) . ' m') . " de $perto" : null);
}
unset($it);
$contagem = ['todas' => count($lista), 'sem' => count(array_filter($lista, fn($i) => !$i['motoboy'])), 'fora' => count(array_filter($lista, fn($i) => $i['fora']))];
$rotStatus = ['pendente' => 'Pendente', 'entregue' => 'Entregue', 'falhou' => 'Não entregue'];
$rotuloStatus = ['aberta' => 'Aguardando', 'em_andamento' => 'Em andamento', 'finalizada' => 'Finalizada'];

topo('Rotas', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=16">
<div class="cabecalho-rota"><h1>Rotas</h1>
  <div class="acoes">
    <a class="btn" href="cd.php">Posição do CD</a>
    <a class="btn" href="quadrantes.php">Quadrantes</a>
    <a class="btn" href="distribuir.php?data=<?= e($data) ?>">Distribuir</a>
    <a class="btn primario" href="importar_entregas.php?data=<?= e($data) ?>">Lista de entregas do dia</a>
  </div>
</div>
<div class="duas-colunas">
  <form method="post" class="form cartao">
    <h2>Nova rota</h2>
    <?= csrf_field() ?><input type="hidden" name="acao" value="criar">
    <label>Motoboy
      <select name="motoboy_id" required>
        <option value="">Escolha…</option>
        <?php foreach ($motoboys as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nome']) ?></option><?php endforeach; ?>
      </select></label>
    <label>Data<input type="date" name="data" value="<?= e($data) ?>" required></label>
    <label>Descrição <small>(opcional)</small><input name="descricao" placeholder="Ex.: Manhã – Centro / Afonso Pena"></label>
    <button class="btn primario">Criar rota e lançar paradas</button>
    <?php if (!$motoboys): ?><p class="dica">Cadastre um motoboy antes em <a href="motoboys.php">Motoboys</a>.</p><?php endif; ?>
  </form>

  <div>
    <form class="filtro-data">
      <label>Rotas do dia <input type="date" name="data" value="<?= e($data) ?>" onchange="this.form.submit()"></label>
    </form>
    <div class="tabela-wrap">
      <table class="tabela">
        <thead><tr><th>Motoboy</th><th>Descrição</th><th>Paradas</th><th>Pacotes</th><th>Progresso</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rotas as $r): $pct = $r['paradas'] ? round(($r['entregues'] + $r['falhas']) / $r['paradas'] * 100) : 0; ?>
          <tr>
            <td><?php if (!empty($r['cor'])): ?><span class="bolinha" style="background:<?= e($r['cor']) ?>"></span><?php endif; ?><?= e($r['motoboy']) ?></td>
            <td><?= e($r['descricao']) ?></td>
            <td><?= (int)$r['paradas'] ?></td>
            <td><?= (int)$r['pacotes'] ?></td>
            <td><div class="progresso"><span style="width:<?= $pct ?>%"></span></div>
                <small><?= (int)$r['entregues'] ?> entregues<?= $r['falhas'] ? ', ' . (int)$r['falhas'] . ' sem sucesso' : '' ?></small></td>
            <td><span class="selo <?= e($r['status']) ?>"><?= $rotuloStatus[$r['status']] ?></span></td>
            <td class="acoes">
              <a class="btn pequeno" href="rota.php?id=<?= $r['id'] ?>">Abrir</a>
              <form method="post" onsubmit="return confirm('Excluir esta rota e todas as paradas?')">
                <?= csrf_field() ?><input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="data" value="<?= e($data) ?>">
                <button class="btn pequeno perigo">Excluir</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rotas): ?><tr><td colspan="7" class="vazio">Nenhuma rota em <?= data_br($data) ?>. Crie uma no formulário ao lado.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($lista): ?>
<section class="lista-entregas" id="lista-entregas">
  <div class="lista-topo">
    <h2>Entregas do dia <small><?= data_br($data) ?></small></h2>
    <input type="search" id="busca" placeholder="Buscar pelo número da entrega ou endereço" inputmode="search" autocomplete="off">
  </div>
  <div class="filtros" role="group" aria-label="Filtrar">
    <button type="button" class="ativo" data-f="todas">Todas <b><?= $contagem['todas'] ?></b></button>
    <button type="button" data-f="sem">Sem motoboy <b><?= $contagem['sem'] ?></b></button>
    <?php if ($quadsLista): ?><button type="button" data-f="fora">Fora do quadrante <b><?= $contagem['fora'] ?></b></button><?php endif; ?>
  </div>
  <p class="dica" id="achados"></p>
  <div class="tabela-wrap tabela-longa">
    <table class="tabela">
      <thead><tr><th>Nº</th><th>Endereço</th><th>Pacotes</th><th>Zona</th><th>Motoboy</th><th>Situação</th></tr></thead>
      <tbody id="corpo-lista">
      <?php foreach ($lista as $it): ?>
        <tr data-num="<?= (int)$it['entrega'] ?>" data-busca="<?= e(sem_acento($it['rua'] . ' ' . $it['numero_casa'])) ?>" data-sem="<?= $it['motoboy'] ? 0 : 1 ?>" data-fora="<?= $it['fora'] ? 1 : 0 ?>">
          <td><span class="num-parada"><?= (int)$it['entrega'] ?></span></td>
          <td><?= e($it['rua']) ?>, <?= e($it['numero_casa']) ?></td>
          <td><?= (int)$it['pacotes'] ?></td>
          <td><?php if ($it['sem_local']): ?><small class="txt-alerta">não achado no mapa</small>
              <?php elseif ($it['fora']): ?><small class="txt-erro">⚠ <?= e($it['zona']) ?></small>
              <?php else: ?><small><?= e($it['zona'] ?? '—') ?></small><?php endif; ?></td>
          <td><?php if ($it['motoboy']): ?><span class="bolinha" style="background:<?= e($it['cor'] ?: '#999') ?>"></span><?= e($it['motoboy']) ?>
              <?php else: ?><span class="selo sem-moto">Sem motoboy</span><?php endif; ?></td>
          <td><?= $it['status'] ? '<span class="selo ' . e($it['status']) . '">' . $rotStatus[$it['status']] . '</span>' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script>
(() => {
  const busca = document.getElementById('busca'), linhas = [...document.querySelectorAll('#corpo-lista tr')], achados = document.getElementById('achados');
  const semAcento = t => t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  let filtro = 'todas';
  function aplicar() {
    const q = semAcento(busca.value.trim());
    const soNumero = /^\d+$/.test(q);
    let n = 0;
    linhas.forEach(tr => {
      let ok = filtro === 'todas' || (filtro === 'sem' && tr.dataset.sem === '1') || (filtro === 'fora' && tr.dataset.fora === '1');
      if (ok && q) ok = soNumero ? tr.dataset.num === q || tr.dataset.num.startsWith(q) : tr.dataset.busca.includes(q);
      tr.hidden = !ok; if (ok) n++;
    });
    achados.textContent = q || filtro !== 'todas' ? `${n} de ${linhas.length} entregas` : '';
    // número exato: destaca e leva até ela
    linhas.forEach(tr => tr.classList.toggle('achada', soNumero && tr.dataset.num === q));
  }
  busca.addEventListener('input', aplicar);
  document.querySelectorAll('.filtros button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.filtros button').forEach(x => x.classList.remove('ativo'));
    b.classList.add('ativo'); filtro = b.dataset.f; aplicar();
  }));
})();
</script>
<?php endif; ?>
<?php rodape();
