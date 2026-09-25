<?php
require __DIR__ . '/config.php';

if ($u = usuario()) redirecionar($u['tipo'] === 'admin' ? 'admin.php' : 'motoboy.php');

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = db()->prepare("SELECT * FROM usuarios WHERE login = ? AND ativo = 1");
    $s->execute([trim($_POST['login'] ?? '')]);
    $u = $s->fetch();
    if ($u && password_verify($_POST['senha'] ?? '', $u['senha_hash'])) {
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['id' => (int)$u['id'], 'nome' => $u['nome'], 'tipo' => $u['tipo']];
        redirecionar($u['tipo'] === 'admin' ? 'admin.php' : 'motoboy.php');
    }
    $erro = 'Login ou senha incorretos.';
}
topo('Entrar');
?>
<section class="login">
  <img class="logo-login" src="assets/logo.svg" alt="<?= APP_NOME ?>" width="260" height="260">
  <?php if ($erro): ?><div class="aviso erro"><?= e($erro) ?></div><?php endif; ?>
  <form method="post" class="form">
    <label>Login<input name="login" autocomplete="username" autocapitalize="none" required autofocus></label>
    <label>Senha<input name="senha" type="password" autocomplete="current-password" required></label>
    <button class="btn primario grande">Entrar</button>
  </form>
  <a class="baixar-app" id="baixar-app" href="https://github.com/pablogalindo159/Motoboy-lucas/releases/latest/download/NetPoint-Rotas.apk">Baixar app Android</a>
  <script>
    // esconde o botão para quem já está no app ou não usa Android
    if (window.NetPointApp || !/Android/i.test(navigator.userAgent)) document.getElementById('baixar-app').remove();
  </script>
</section>
<?php rodape();
