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
        db()->prepare("INSERT INTO rotas (motoboy_id, data, descricao) VALUES (?,?,?)")
            ->execute([$mid, $data, trim($_POST['descricao'] ?? '')]);
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
$rotuloStatus = ['aberta' => 'Aguardando', 'em_andamento' => 'Em andamento', 'finalizada' => 'Finalizada'];

topo('Rotas', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=7">
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
<?php rodape();
