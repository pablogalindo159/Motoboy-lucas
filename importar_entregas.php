<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

if (($_POST['acao'] ?? '') === 'ler' && csrf_ok()) {
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['data'] ?? '') ? $_POST['data'] : date('Y-m-d');
    $texto = trim($_POST['texto'] ?? '');
    if (!empty($_FILES['lista']['tmp_name']) && $_FILES['lista']['error'] === UPLOAD_ERR_OK) $texto = file_get_contents($_FILES['lista']['tmp_name']);
    $lido = ler_lista_entregas($texto);
    if (!$lido['itens']) { flash('Não encontrei entregas. O arquivo precisa ter número, endereço e "N unidades" em linhas separadas.', 'erro'); redirecionar('importar_entregas.php'); }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM entregas WHERE data = ?")->execute([$data]);
    $ins = $pdo->prepare("INSERT INTO entregas (data, entrega, rua, numero_casa, pacotes, lat, lng, geo_tentado) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($lido['itens'] as $it) {
        $c = geo_cache($it['rua'], $it['numero_casa']);
        $ins->execute([$data, $it['entrega'], $it['rua'], $it['numero_casa'], $it['pacotes'], $c[0] ?? null, $c[1] ?? null, $c === null ? 0 : 1]);
    }
    $pdo->commit();
    $pac = array_sum(array_column($lido['itens'], 'pacotes'));
    flash(count($lido['itens']) . " entregas e $pac pacotes lidos" . ($lido['ignoradas'] ? ' (' . count($lido['ignoradas']) . ' linhas não entendidas ficaram de fora)' : '') . '.');
    redirecionar('processar.php?data=' . urlencode($data));
}

$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
$s = db()->prepare("SELECT COUNT(*) FROM entregas WHERE data = ?"); $s->execute([$data]); $ja = (int)$s->fetchColumn();
topo('Lista de entregas', 'rotas');
?>
<a href="rotas.php" class="voltar">← Rotas</a>
<h1>Lista de entregas do dia</h1>
<form method="post" enctype="multipart/form-data" class="form cartao estreito">
  <?= csrf_field() ?><input type="hidden" name="acao" value="ler">
  <p>Envie o .txt do dia (número da entrega, endereço e "N unidades"). O sistema localiza os endereços, separa por quadrante e distribui para os motoboys. A caixa de cada entrega é a dezena dela: entregas 0 a 9 = caixa 0, 10 a 19 = caixa 10, e assim por diante.</p>
  <label>Data da entrega<input type="date" name="data" value="<?= e($data) ?>" required onchange="location='?data='+this.value"></label>
  <?php if ($ja): ?><p class="txt-alerta">Este dia já tem <?= $ja ?> entregas carregadas. Enviar de novo substitui a lista. <a href="distribuir.php?data=<?= e($data) ?>">Ir para a distribuição</a></p><?php endif; ?>
  <label>Arquivo (.txt)<input type="file" name="lista" accept=".txt,.csv,text/plain"></label>
  <label>Ou cole a lista<textarea name="texto" rows="5" placeholder="1&#10;Rua Luiz França, 1390&#10;1 unidades"></textarea></label>
  <button class="btn primario">Carregar lista</button>
</form>
<?php rodape();
