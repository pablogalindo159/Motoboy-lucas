<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'buscar') {
        $end = trim($_POST['endereco'] ?? '');
        $pos = strrpos($end, ',');
        ['lat' => $lat, 'lng' => $lng] = geo_consultar(trim($pos ? substr($end, 0, $pos) : $end), trim($pos ? substr($end, $pos + 1) : ''), false); // o CD pode ficar fora dos bairros atendidos
        if ($lat) { cfg_salvar('cd_lat', (string)$lat); cfg_salvar('cd_lng', (string)$lng); cfg_salvar('cd_endereco', $end); flash('CD localizado. Confira no mapa e arraste o marcador se precisar.'); }
        else flash('Endereço não encontrado. Clique no mapa no lugar do CD.', 'erro');
    }
    if ($acao === 'posicao') {
        cfg_salvar('cd_lat', (string)(float)$_POST['lat']); cfg_salvar('cd_lng', (string)(float)$_POST['lng']);
        flash('Posição do CD salva.');
    }
    if ($acao === 'carto') {
        $k = trim($_POST['carto_key'] ?? '');
        cfg_salvar('carto_key', preg_match('/^[A-Za-z0-9_\-]{8,120}$/', $k) ? $k : null);
        flash($k === '' ? 'Chave do mapa da TV removida.' : (cfg('carto_key') ? 'Chave do mapa da TV salva.' : 'Chave inválida.'), cfg('carto_key') || $k === '' ? 'ok' : 'erro');
    }
    if ($acao === 'firebase') {
        $txt = trim($_POST['fcm_json'] ?? '');
        if (!empty($_FILES['fcm_arquivo']['tmp_name']) && is_uploaded_file($_FILES['fcm_arquivo']['tmp_name'])) $txt = file_get_contents($_FILES['fcm_arquivo']['tmp_name']);
        $j = json_decode($txt, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['private_key']) || empty($j['project_id'])) {
            flash('Arquivo inválido. Use a "chave da conta de serviço" (.json) do Firebase: Configurações do projeto → Contas de serviço → Gerar nova chave privada.', 'erro');
        } else {
            cfg_salvar('fcm_conta', json_encode($j));
            cfg_salvar('fcm_acesso', null);
            flash(fcm_token_acesso() ? 'Firebase ligado: projeto ' . $j['project_id'] . '.' : 'Chave salva, mas o Google não aceitou. Confira se a API "Firebase Cloud Messaging" está ativa no projeto.', fcm_token_acesso() ? 'ok' : 'alerta');
        }
    }
    if ($acao === 'firebase_remover') { cfg_salvar('fcm_conta', null); cfg_salvar('fcm_acesso', null); flash('Firebase desligado.'); }
    if ($acao === 'firebase_teste') {
        $mid = (int)($_POST['motoboy_id'] ?? 0);
        $n = fcm_enviar('motoboy', $mid, ['id' => 0, 'tipo' => 'teste', 'titulo' => '🔔 Teste do NetPoint Rotas', 'texto' => 'Se você está vendo isto, as notificações estão funcionando.', 'link' => 'motoboy.php', 'prioridade' => 'alta']);
        flash($n ? "Teste enviado para $n celular(es)." : 'Nenhum celular recebeu. O motoboy precisa abrir o app novo (1.0.4) uma vez, logado.', $n ? 'ok' : 'alerta');
    }
    if ($acao === 'google') {
        cfg_salvar('google_key', trim($_POST['google_key'] ?? '') ?: null);
        flash('Chave salva.');
    }
    redirecionar('cd.php');
}
$cd = cd_posicao();
topo('Centro de distribuição', 'rotas', true);
?>
<a href="rotas.php" class="voltar">← Rotas</a>
<h1>Centro de distribuição (CD)</h1>
<div class="duas-colunas">
  <div>
    <form method="post" class="form cartao">
      <?= csrf_field() ?><input type="hidden" name="acao" value="buscar">
      <p>As rotas começam aqui. Digite o endereço ou clique no mapa no lugar exato do CD.</p>
      <label>Endereço do CD<input name="endereco" value="<?= e(cfg('cd_endereco', '')) ?>" placeholder="Rua Exemplo, 100"></label>
      <button class="btn primario">Localizar</button>
    </form>
    <form method="post" class="form cartao" style="margin-top:1rem">
      <?= csrf_field() ?><input type="hidden" name="acao" value="google">
      <p><b>Chave do Google Maps</b> <small>(opcional)</small><br>Com ela os endereços são achados em segundos e com mais precisão. Sem ela o sistema usa o OpenStreetMap, grátis, mas leva cerca de 1 segundo por endereço novo.</p>
      <label>Chave da Geocoding API<input name="google_key" value="<?= e(cfg('google_key', '')) ?>" autocomplete="off"></label>
      <button class="btn">Salvar chave</button>
    </form>
    <?php $conta = fcm_conta();
          $nDisp = (int)db()->query("SELECT COUNT(DISTINCT usuario_id) FROM dispositivos")->fetchColumn();
          $motosCel = db()->query("SELECT DISTINCT u.id, u.nome FROM dispositivos d JOIN usuarios u ON u.id = d.usuario_id WHERE u.tipo = 'motoboy' ORDER BY u.nome")->fetchAll(); ?>
    <form method="post" enctype="multipart/form-data" class="form cartao" style="margin-top:1rem">
      <?= csrf_field() ?><input type="hidden" name="acao" value="firebase">
      <p><b>Notificações pelo Firebase</b> <?= $conta ? '<span class="selo finalizada">ligado · ' . e($conta['project_id']) . '</span>' : '<span class="selo">desligado</span>' ?><br>
        Com o Firebase, o aviso chega na hora no celular, mesmo bloqueado. Sem ele, o app confere a cada ~45 s.
        <?= $nDisp ? "<br>$nDisp celular(es) já registrados." : '' ?></p>
      <label>Chave da conta de serviço (.json)<input type="file" name="fcm_arquivo" accept=".json,application/json"></label>
      <details><summary>ou colar o conteúdo</summary><textarea name="fcm_json" rows="4" placeholder='{"type": "service_account", ...}'></textarea></details>
      <button class="btn"><?= $conta ? 'Trocar chave' : 'Ligar Firebase' ?></button>
    </form>
    <?php if ($conta): ?>
    <form method="post" class="form cartao" style="margin-top:.5rem">
      <?= csrf_field() ?>
      <label>Enviar notificação de teste para
        <select name="motoboy_id"><?php foreach ($motosCel as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nome']) ?></option><?php endforeach; ?></select></label>
      <div class="acoes" style="justify-content:flex-start">
        <button class="btn" name="acao" value="firebase_teste" <?= $motosCel ? '' : 'disabled' ?>>Enviar teste</button>
        <button class="btn perigo" name="acao" value="firebase_remover" onclick="return confirm('Desligar o Firebase?')">Desligar Firebase</button>
      </div>
    </form>
    <?php endif; ?>
    <form method="post" class="form cartao" style="margin-top:1rem">
      <?= csrf_field() ?><input type="hidden" name="acao" value="carto">
      <p><b>Mapa escuro da TV (CARTO)</b><br>Sem a chave, a TV usa o mapa comum escurecido. Chave grátis em carto.com/basemaps/apikey.</p>
      <label>Chave CARTO<input name="carto_key" value="<?= e(cfg('carto_key', '')) ?>" autocomplete="off" placeholder="cb1_..."></label>
      <button class="btn">Salvar chave do mapa</button>
    </form>
  </div>
  <div>
    <div id="mapa" class="mapa-rota"></div>
    <p class="dica"><?= $cd ? 'Arraste o marcador para ajustar.' : 'Clique no mapa para marcar o CD.' ?></p>
    <form method="post" id="f-pos"><?= csrf_field() ?><input type="hidden" name="acao" value="posicao"><input type="hidden" name="lat"><input type="hidden" name="lng"></form>
  </div>
</div>
<script>
const cd = <?= json_encode($cd) ?>;
const mapa = L.map('mapa').setView(cd || [-25.47, -49.23], cd ? 16 : 11);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);
const f = document.getElementById('f-pos');
function salvar(ll) { f.lat.value = ll.lat; f.lng.value = ll.lng; f.submit(); }
if (cd) L.marker(cd, { draggable: true }).addTo(mapa).bindPopup('CD').on('dragend', e => salvar(e.target.getLatLng()));
mapa.on('click', e => { if (confirm('Marcar o CD aqui?')) salvar(e.latlng); });
</script>
<?php rodape();
