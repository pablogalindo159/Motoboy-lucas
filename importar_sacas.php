<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

$acao = $_POST['acao'] ?? '';

// 1) Recebe o arquivo e guarda a prévia na sessão
if ($acao === 'ler' && csrf_ok()) {
    $arq = $_FILES['planilha'] ?? null;
    if (!$arq || $arq['error'] !== UPLOAD_ERR_OK) { flash('Escolha a planilha .xlsx para enviar.', 'erro'); redirecionar('importar_sacas.php'); }
    try {
        $lido = ler_planilha_sacas($arq['tmp_name']);
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'erro'); redirecionar('importar_sacas.php');
    }
    if (!$lido['grupos']) { flash('Não achei sacas na planilha. Confira se a coluna A tem o número da caixa e a B a quantidade.', 'erro'); redirecionar('importar_sacas.php'); }
    $_SESSION['import_sacas'] = [
        'data' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['data'] ?? '') ? $_POST['data'] : date('Y-m-d'),
        'data_planilha' => $lido['data'],
        'arquivo' => $arq['name'],
        'grupos' => $lido['grupos'],
    ];
    redirecionar('importar_sacas.php?previa=1');
}

// 2) Confirma: cria rotas (e motoboys novos, se escolhido) e grava as sacas
if ($acao === 'confirmar' && csrf_ok() && !empty($_SESSION['import_sacas'])) {
    $imp = $_SESSION['import_sacas'];
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['data'] ?? '') ? $_POST['data'] : $imp['data'];
    $escolhas = $_POST['motoboy'] ?? [];
    $criados = []; $feitos = 0; $limpas = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($imp['grupos'] as $i => $g) {
            $sel = $escolhas[$i] ?? '';
            if ($sel === '') continue;

            if ($sel === 'novo') {
                $nome = $g['nome'] ?: 'Motoboy ' . ($i + 1);
                $base = preg_replace('/[^a-z0-9]/', '', sem_acento(explode(' ', $nome)[0])) ?: 'motoboy';
                $login = $base; $n = 2;
                $chk = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE login = ?");
                while (true) { $chk->execute([$login]); if (!$chk->fetchColumn()) break; $login = $base . $n++; }
                $senha = (string)random_int(1000, 9999);
                $pdo->prepare("INSERT INTO usuarios (nome, login, senha_hash, tipo) VALUES (?,?,?,'motoboy')")
                    ->execute([$nome, $login, password_hash($senha, PASSWORD_DEFAULT)]);
                $mid = (int)$pdo->lastInsertId();
                $criados[] = ['nome' => $nome, 'login' => $login, 'senha' => $senha];
            } else {
                $mid = (int)$sel;
            }

            // usa a rota do dia desse motoboy, ou cria uma
            $s = $pdo->prepare("SELECT id FROM rotas WHERE motoboy_id = ? AND data = ? ORDER BY id LIMIT 1");
            $s->execute([$mid, $data]);
            $rotaId = (int)$s->fetchColumn();
            if (!$rotaId) {
                $pdo->prepare("INSERT INTO rotas (motoboy_id, data, descricao, cor) VALUES (?,?,?,?)")
                    ->execute([$mid, $data, $g['rota'] ?: null, $g['cor']]);
                $rotaId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE rotas SET cor = ?, descricao = COALESCE(NULLIF(descricao,''), ?) WHERE id = ?")
                    ->execute([$g['cor'], $g['rota'] ?: null, $rotaId]);
            }

            // importar de novo substitui as sacas anteriores dessa rota
            if (!isset($limpas[$rotaId])) {
                $pdo->prepare("DELETE FROM sacas WHERE rota_id = ?")->execute([$rotaId]);
                $limpas[$rotaId] = true;
            }
            $ins = $pdo->prepare("INSERT INTO sacas (rota_id, caixa, quantidade) VALUES (?,?,?)");
            foreach ($g['sacas'] as $sc) $ins->execute([$rotaId, $sc['caixa'], $sc['quantidade']]);
            $feitos++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('Erro ao importar: ' . $ex->getMessage(), 'erro');
        redirecionar('importar_sacas.php?previa=1');
    }

    unset($_SESSION['import_sacas']);
    $_SESSION['import_resultado'] = ['feitos' => $feitos, 'criados' => $criados, 'data' => $data];
    redirecionar('importar_sacas.php?feito=1');
}

if ($acao === 'cancelar') { unset($_SESSION['import_sacas']); redirecionar('importar_sacas.php'); }

$motoboys = db()->query("SELECT id, nome FROM usuarios WHERE tipo='motoboy' AND ativo=1 ORDER BY nome")->fetchAll();

// tenta achar o motoboy pelo nome escrito na planilha
function sugerir_motoboy(array $g, array $motoboys): string {
    $palavras = preg_split('/\s+/', sem_acento($g['rotulo']));
    foreach ($motoboys as $m) {
        $primeiro = explode(' ', sem_acento(trim($m['nome'])))[0];
        if ($primeiro !== '' && in_array($primeiro, $palavras, true)) return (string)$m['id'];
    }
    return 'novo';
}

topo('Importar sacas', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=2">
<a href="rotas.php" class="voltar">← Rotas</a>
<h1>Importar planilha de sacas</h1>

<?php if (isset($_GET['feito']) && ($res = $_SESSION['import_resultado'] ?? null)): unset($_SESSION['import_resultado']); ?>
  <div class="cartao">
    <h2><?= (int)$res['feitos'] ?> motoboys com sacas em <?= data_br($res['data']) ?></h2>
    <p>Cada um já vê as sacas dele ao entrar no celular.</p>
    <?php if ($res['criados']): ?>
      <p><b>Motoboys criados agora</b> — anote ou mande para cada um, a senha não aparece de novo:</p>
      <div class="tabela-wrap"><table class="tabela">
        <thead><tr><th>Nome</th><th>Login</th><th>Senha</th></tr></thead>
        <tbody><?php foreach ($res['criados'] as $c): ?>
          <tr><td><?= e($c['nome']) ?></td><td><b><?= e($c['login']) ?></b></td><td><b><?= e($c['senha']) ?></b></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    <p><a class="btn primario" href="importar_entregas.php?data=<?= e($res['data']) ?>">Agora importar a lista de entregas</a>
       <a class="btn" href="rotas.php?data=<?= e($res['data']) ?>">Ver rotas do dia</a>
       <a class="btn" href="admin.php?data=<?= e($res['data']) ?>">Abrir painel</a></p>
  </div>

<?php elseif (isset($_GET['previa']) && ($imp = $_SESSION['import_sacas'] ?? null)): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="confirmar">
    <div class="cartao previa-topo">
      <label>Data da entrega <input type="date" name="data" value="<?= e($imp['data']) ?>" required></label>
      <p class="dica"><?= e($imp['arquivo']) ?> · <?= count($imp['grupos']) ?> cores · <?= array_sum(array_map(fn($g) => count($g['sacas']), $imp['grupos'])) ?> sacas · <?= array_sum(array_column($imp['grupos'], 'pacotes')) ?> pacotes
      <?php if ($imp['data_planilha'] && $imp['data_planilha'] !== $imp['data']): ?><br>O cabeçalho da planilha diz <?= data_br($imp['data_planilha']) ?>. Confira a data acima.<?php endif; ?></p>
    </div>

    <div class="lista-previa">
    <?php foreach ($imp['grupos'] as $i => $g): $sug = sugerir_motoboy($g, $motoboys); $ok = $g['total_planilha'] === null || $g['total_planilha'] === $g['pacotes']; ?>
      <div class="grupo" style="--cor-rota:<?= e($g['cor']) ?>;--texto-rota:<?= texto_sobre($g['cor']) ?>">
        <div class="faixa"><?= e($g['rotulo']) ?></div>
        <div class="corpo">
          <div class="chips">
            <?php foreach ($g['sacas'] as $sc): ?><span class="chip"><b><?= (int)$sc['caixa'] ?></b> <?= (int)$sc['quantidade'] ?></span><?php endforeach; ?>
          </div>
          <p class="<?= $ok ? 'dica' : 'txt-alerta' ?>"><?= count($g['sacas']) ?> sacas · <?= (int)$g['pacotes'] ?> pacotes<?= $ok ? '' : ' — a planilha diz ' . (int)$g['total_planilha'] . ', confira' ?></p>
          <label>Motoboy
            <select name="motoboy[<?= $i ?>]">
              <option value="novo" <?= $sug === 'novo' ? 'selected' : '' ?>>Cadastrar novo: <?= e($g['nome'] ?: 'sem nome') ?></option>
              <?php foreach ($motoboys as $m): ?><option value="<?= $m['id'] ?>" <?= $sug === (string)$m['id'] ? 'selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?>
              <option value="">Não importar esta cor</option>
            </select>
          </label>
        </div>
      </div>
    <?php endforeach; ?>
    </div>

    <div class="rodape-previa">
      <button class="btn primario grande">Importar sacas</button>
      <button class="btn" name="acao" value="cancelar" formnovalidate>Cancelar</button>
    </div>
  </form>

<?php else: ?>
  <form method="post" enctype="multipart/form-data" class="form cartao estreito">
    <?= csrf_field() ?><input type="hidden" name="acao" value="ler">
    <p>Envie a planilha de controle do dia: coluna A com o número da saca, B com a quantidade e C com o nome. Cada cor de linha vira as sacas de um motoboy.</p>
    <label>Planilha (.xlsx)<input type="file" name="planilha" accept=".xlsx" required></label>
    <label>Data da entrega<input type="date" name="data" value="<?= date('Y-m-d') ?>" required></label>
    <button class="btn primario">Ler planilha</button>
  </form>
<?php endif; ?>
<?php rodape();
