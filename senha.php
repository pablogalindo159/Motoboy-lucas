<?php
require __DIR__ . '/config.php';
$u = usuario();
if (!$u) redirecionar('index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $s = db()->prepare("SELECT senha_hash FROM usuarios WHERE id = ?");
    $s->execute([$u['id']]);
    $nova = $_POST['nova'] ?? '';
    if (!password_verify($_POST['atual'] ?? '', $s->fetchColumn())) flash('Senha atual incorreta.', 'erro');
    elseif (strlen($nova) < 6) flash('A nova senha precisa ter pelo menos 6 caracteres.', 'erro');
    else {
        db()->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?")->execute([password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
        flash('Senha alterada.');
    }
    redirecionar('senha.php');
}
topo('Minha senha', 'senha');
?>
<h1>Minha senha</h1>
<form method="post" class="form estreito">
  <?= csrf_field() ?>
  <label>Senha atual<input name="atual" type="password" required></label>
  <label>Nova senha<input name="nova" type="password" minlength="6" required></label>
  <button class="btn primario">Salvar senha</button>
  <a class="btn" href="<?= $u['tipo'] === 'admin' ? 'admin.php' : 'motoboy.php' ?>">Voltar</a>
</form>
<?php rodape();
