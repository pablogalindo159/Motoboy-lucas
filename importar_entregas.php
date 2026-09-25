<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

// caixa -> rota do dia, pelas sacas (planilha de cores) já importadas
function mapa_caixas(string $data): array {
    $s = db()->prepare("SELECT s.caixa, r.id rota_id, r.cor, r.descricao, u.nome
                        FROM sacas s JOIN rotas r ON r.id = s.rota_id JOIN usuarios u ON u.id = r.motoboy_id
                        WHERE r.data = ? ORDER BY r.id");
    $s->execute([$data]);
    $m = [];
    foreach ($s->fetchAll() as $x) $m[(int)$x['caixa']] ??= $x;
    return $m;
}

$acao = $_POST['acao'] ?? '';

if ($acao === 'ler' && csrf_ok()) {
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['data'] ?? '') ? $_POST['data'] : date('Y-m-d');
    $texto = trim($_POST['texto'] ?? '');
    if (!empty($_FILES['lista']['tmp_name']) && $_FILES['lista']['error'] === UPLOAD_ERR_OK) $texto = file_get_contents($_FILES['lista']['tmp_name']);
    $lido = ler_lista_entregas($texto);
    if (!$lido['itens']) { flash('Não encontrei entregas. O arquivo precisa ter: número, endereço e "N unidades" em linhas separadas.', 'erro'); redirecionar('importar_entregas.php'); }
    $_SESSION['import_entregas'] = ['data' => $data, 'itens' => $lido['itens'], 'ignoradas' => $lido['ignoradas'],
                                    'arquivo' => $_FILES['lista']['name'] ?? 'texto colado'];
    redirecionar('importar_entregas.php?previa=1');
}

if ($acao === 'confirmar' && csrf_ok() && !empty($_SESSION['import_entregas'])) {
    $imp = $_SESSION['import_entregas'];
    $mapa = mapa_caixas($imp['data']);
    $porRota = [];
    foreach ($imp['itens'] as $it) if (isset($mapa[$it['caixa']])) $porRota[$mapa[$it['caixa']]['rota_id']][] = $it;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO paradas (rota_id, numero, entrega, endereco, numero_casa, cidade, pacotes, lat, lng, geo_tentado) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($porRota as $rotaId => $itens) {
            $pdo->prepare("DELETE FROM paradas WHERE rota_id = ?")->execute([$rotaId]);
            usort($itens, fn($a, $b) => $a['entrega'] <=> $b['entrega']);
            $n = 0;
            foreach ($itens as $it) {
                $c = geo_cache($it['rua'], $it['numero_casa']);
                $ins->execute([$rotaId, ++$n, $it['entrega'], $it['rua'], $it['numero_casa'], '', $it['pacotes'],
                               $c[0] ?? null, $c[1] ?? null, $c === null ? 0 : 1]);
            }
            $pdo->prepare("UPDATE rotas SET status = 'aberta' WHERE id = ?")->execute([$rotaId]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('Erro ao importar: ' . $ex->getMessage(), 'erro'); redirecionar('importar_entregas.php?previa=1');
    }
    foreach (array_keys($porRota) as $rotaId) recalcular_sacas_rota($rotaId);
    unset($_SESSION['import_entregas']);
    redirecionar('processar.php?data=' . urlencode($imp['data']));
}

if ($acao === 'cancelar') { unset($_SESSION['import_entregas']); redirecionar('importar_entregas.php'); }

$dataForm = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
topo('Importar entregas', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=2">
<a href="rotas.php" class="voltar">← Rotas</a>
<h1>Lista de entregas do dia</h1>

<?php if (isset($_GET['previa']) && ($imp = $_SESSION['import_entregas'] ?? null)):
    $mapa = mapa_caixas($imp['data']);
    $rotas = []; $fora = [];
    foreach ($imp['itens'] as $it) {
        if (!isset($mapa[$it['caixa']])) { $fora[$it['caixa']][] = $it; continue; }
        $r = $mapa[$it['caixa']];
        $rotas[$r['rota_id']] ??= ['info' => $r, 'entregas' => 0, 'pacotes' => 0, 'caixas' => []];
        $rotas[$r['rota_id']]['entregas']++;
        $rotas[$r['rota_id']]['pacotes'] += $it['pacotes'];
        $rotas[$r['rota_id']]['caixas'][$it['caixa']] = true;
    }
    $s = db()->prepare("SELECT COUNT(*) FROM paradas p JOIN rotas r ON r.id = p.rota_id WHERE r.data = ?");
    $s->execute([$imp['data']]);
    $existentes = (int)$s->fetchColumn();
?>
  <div class="cartao previa-topo">
    <p><b><?= data_br($imp['data']) ?></b> · <?= e($imp['arquivo']) ?> · <?= count($imp['itens']) ?> entregas · <?= array_sum(array_column($imp['itens'], 'pacotes')) ?> pacotes</p>
  </div>

  <?php if (!$mapa): ?>
    <div class="aviso erro">Ainda não tem planilha de cores (sacas) para <?= data_br($imp['data']) ?>. É ela que diz qual motoboy leva cada caixa.
      <a href="importar_sacas.php">Importar a planilha de sacas</a> e depois volte aqui.</div>
  <?php else: ?>
    <?php if ($fora): ?>
      <div class="aviso alerta"><?= array_sum(array_map('count', $fora)) ?> entregas estão em caixas que não têm motoboy na planilha (caixas <?= e(implode(', ', array_keys($fora))) ?>). Elas ficam de fora.</div>
    <?php endif; ?>
    <?php if ($existentes): ?>
      <div class="aviso alerta">As rotas deste dia já têm <?= $existentes ?> paradas. Importar substitui as paradas dos motoboys da lista.</div>
    <?php endif; ?>
    <?php if ($imp['ignoradas']): ?>
      <p class="dica"><?= count($imp['ignoradas']) ?> linhas não foram entendidas e ficaram de fora (ex.: "<?= e(mb_substr($imp['ignoradas'][0], 0, 60)) ?>").</p>
    <?php endif; ?>

    <div class="lista-previa">
    <?php foreach ($rotas as $r): $i = $r['info']; ?>
      <div class="grupo" style="--cor-rota:<?= e($i['cor'] ?: '#F2B705') ?>;--texto-rota:<?= texto_sobre($i['cor'] ?: '#F2B705') ?>">
        <div class="faixa"><?= e($i['nome']) ?><?= $i['descricao'] ? ' · ' . e($i['descricao']) : '' ?></div>
        <div class="corpo">
          <p><b><?= $r['entregas'] ?></b> entregas · <b><?= $r['pacotes'] ?></b> pacotes · <?= count($r['caixas']) ?> caixas</p>
        </div>
      </div>
    <?php endforeach; ?>
    </div>

    <form method="post" class="rodape-previa">
      <?= csrf_field() ?>
      <button class="btn primario grande" name="acao" value="confirmar">Criar rotas do dia</button>
      <button class="btn" name="acao" value="cancelar">Cancelar</button>
    </form>
    <p class="dica">Depois disso o sistema localiza os endereços no mapa e monta a ordem de cada rota saindo do CD.</p>
  <?php endif; ?>

<?php else:
    $s = db()->prepare("SELECT COUNT(DISTINCT r.id) FROM sacas s JOIN rotas r ON r.id = s.rota_id WHERE r.data = ?");
    $s->execute([$dataForm]);
    $temSacas = (int)$s->fetchColumn();
?>
  <form method="post" enctype="multipart/form-data" class="form cartao estreito">
    <?= csrf_field() ?><input type="hidden" name="acao" value="ler">
    <p>Envie a lista do dia (.txt) com número da entrega, endereço e quantidade. O sistema separa por motoboy pela caixa de cada entrega (entregas 380 a 389 = caixa 380).</p>
    <label>Data da entrega<input type="date" name="data" value="<?= e($dataForm) ?>" required onchange="location='?data='+this.value"></label>
    <p class="<?= $temSacas ? 'dica' : 'txt-alerta' ?>"><?= $temSacas ? "Planilha de sacas deste dia: $temSacas motoboys." : 'Falta importar a planilha de sacas (cores) deste dia.' ?>
      <a href="importar_sacas.php"><?= $temSacas ? 'Importar de novo' : 'Importar agora' ?></a></p>
    <label>Arquivo da lista (.txt)<input type="file" name="lista" accept=".txt,.csv,text/plain"></label>
    <label>Ou cole a lista aqui<textarea name="texto" rows="5" placeholder="1&#10;Rua Luiz França, 1390&#10;1 unidades"></textarea></label>
    <button class="btn primario">Ler lista</button>
  </form>
<?php endif; ?>
<?php rodape();
