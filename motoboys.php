<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id = (int)($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? 'salvar';

    if ($acao === 'ativar') {
        db()->prepare("UPDATE usuarios SET ativo = 1 - ativo WHERE id = ? AND tipo = 'motoboy'")->execute([$id]);
        redirecionar('motoboys.php');
    }

    $dados = [
        trim($_POST['nome'] ?? ''), trim($_POST['login'] ?? ''),
        trim($_POST['telefone'] ?? ''), strtoupper(trim($_POST['placa'] ?? '')),
    ];
    $senha = $_POST['senha'] ?? '';
    $pmin = ($_POST['pacotes_min'] ?? '') !== '' ? max(0, (int)$_POST['pacotes_min']) : null;
    $pmax = ($_POST['pacotes_max'] ?? '') !== '' ? max(1, (int)$_POST['pacotes_max']) : null;
    $valorTxt = str_replace(['R$', ' ', '.'], '', trim($_POST['valor_entrega'] ?? ''));
    $valor = $valorTxt !== '' && is_numeric(str_replace(',', '.', $valorTxt)) ? round((float)str_replace(',', '.', $valorTxt), 2) : null;
    if ($pmin !== null && $pmax !== null && $pmin > $pmax) { flash('O mínimo não pode ser maior que o máximo.', 'erro'); redirecionar('motoboys.php' . ($id ? "?editar=$id" : '')); }
    if ($dados[0] === '' || $dados[1] === '') { flash('Preencha nome e login.', 'erro'); redirecionar('motoboys.php'); }
    if (!$id && strlen($senha) < 4) { flash('Defina uma senha com pelo menos 4 caracteres.', 'erro'); redirecionar('motoboys.php'); }

    try {
        if ($id) {
            db()->prepare("UPDATE usuarios SET nome=?, login=?, telefone=?, placa=? WHERE id=? AND tipo='motoboy'")
                ->execute([...$dados, $id]);
            if ($senha !== '') db()->prepare("UPDATE usuarios SET senha_hash=? WHERE id=?")->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
            db()->prepare("UPDATE usuarios SET pacotes_min=?, pacotes_max=?, valor_entrega=? WHERE id=?")->execute([$pmin, $pmax, $valor, $id]);
            // rotas que ainda não tinham valor passam a usar este (as que já tinham ficam com o valor do dia)
            db()->prepare("UPDATE rotas SET valor_entrega = ? WHERE motoboy_id = ? AND valor_entrega IS NULL")->execute([$valor, $id]);
            flash('Motoboy atualizado.');
        } else {
            db()->prepare("INSERT INTO usuarios (nome, login, telefone, placa, senha_hash, tipo) VALUES (?,?,?,?,?,'motoboy')")
                ->execute([...$dados, password_hash($senha, PASSWORD_DEFAULT)]);
            db()->prepare("UPDATE usuarios SET pacotes_min=?, pacotes_max=?, valor_entrega=? WHERE id=?")->execute([$pmin, $pmax, $valor, db()->lastInsertId()]);
            flash('Motoboy cadastrado.');
        }
    } catch (PDOException $ex) {
        flash('Esse login já está em uso. Escolha outro.', 'erro');
    }
    redirecionar('motoboys.php');
}

$editar = null;
if (isset($_GET['editar'])) {
    $s = db()->prepare("SELECT * FROM usuarios WHERE id = ? AND tipo = 'motoboy'");
    $s->execute([(int)$_GET['editar']]);
    $editar = $s->fetch() ?: null;
}
$lista = db()->query("SELECT * FROM usuarios WHERE tipo = 'motoboy' ORDER BY ativo DESC, nome")->fetchAll();

topo('Motoboys', 'motoboys');
?>
<h1>Motoboys</h1>
<div class="duas-colunas">
  <form method="post" class="form cartao">
    <h2><?= $editar ? 'Editar motoboy' : 'Novo motoboy' ?></h2>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">
    <label>Nome<input name="nome" value="<?= e($editar['nome'] ?? '') ?>" required></label>
    <label>Telefone / WhatsApp<input name="telefone" inputmode="tel" value="<?= e($editar['telefone'] ?? '') ?>"></label>
    <label>Placa da moto<input name="placa" value="<?= e($editar['placa'] ?? '') ?>"></label>
    <div class="linha">
      <label>Mínimo de pacotes<input name="pacotes_min" type="number" min="0" inputmode="numeric" value="<?= e($editar['pacotes_min'] ?? '') ?>" placeholder="ex.: 60"></label>
      <label>Máximo de pacotes<input name="pacotes_max" type="number" min="1" inputmode="numeric" value="<?= e($editar['pacotes_max'] ?? '') ?>" placeholder="ex.: 110"></label>
    </div>
    <p class="dica">Padrão do motoboy. Todo dia dá para ajustar na hora de distribuir.</p>
    <label>Valor por entrega feita (R$)<input name="valor_entrega" inputmode="decimal" value="<?= $editar && $editar['valor_entrega'] !== null ? e(number_format((float)$editar['valor_entrega'], 2, ',', '')) : '' ?>" placeholder="ex.: 3,50"></label>
    <p class="dica">Mudou o valor? Vale para as rotas criadas daqui para frente; as já criadas ficam com o valor do dia.</p>
    <label>Login de acesso<input name="login" autocapitalize="none" value="<?= e($editar['login'] ?? '') ?>" required></label>
    <label>Senha <?= $editar ? '<small>(deixe em branco para manter)</small>' : '' ?>
      <input name="senha" type="password" <?= $editar ? '' : 'required minlength="4"' ?>></label>
    <button class="btn primario"><?= $editar ? 'Salvar alterações' : 'Cadastrar motoboy' ?></button>
    <?php if ($editar): ?><a class="btn" href="motoboys.php">Cancelar</a><?php endif; ?>
  </form>

  <div class="tabela-wrap">
    <table class="tabela">
      <thead><tr><th>Nome</th><th>Telefone</th><th>Placa</th><th>Pacotes (mín–máx)</th><th>R$/entrega</th><th>Login</th><th>Última posição</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lista as $m): ?>
        <tr class="<?= $m['ativo'] ? '' : 'inativo' ?>">
          <td><?= e($m['nome']) ?></td>
          <td><?= e($m['telefone']) ?></td>
          <td><?= e($m['placa']) ?></td>
          <td><?= $m['pacotes_min'] !== null || $m['pacotes_max'] !== null ? e(($m['pacotes_min'] ?? '0') . '–' . ($m['pacotes_max'] ?? '∞')) : '—' ?></td>
          <td><?= $m['valor_entrega'] !== null ? dinheiro($m['valor_entrega']) : '<span class="txt-alerta">definir</span>' ?></td>
          <td><?= e($m['login']) ?></td>
          <td><?= $m['ultima_localizacao'] ? date('d/m H:i', strtotime($m['ultima_localizacao'])) : '—' ?></td>
          <td class="acoes">
            <a class="btn pequeno" href="?editar=<?= $m['id'] ?>">Editar</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="ativar"><input type="hidden" name="id" value="<?= $m['id'] ?>">
              <button class="btn pequeno"><?= $m['ativo'] ? 'Desativar' : 'Ativar' ?></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="8" class="vazio">Cadastre o primeiro motoboy no formulário ao lado.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php rodape();
