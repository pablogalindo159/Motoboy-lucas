<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

$id = (int)($_GET['id'] ?? 0);
$s = db()->prepare("SELECT r.*, u.nome motoboy FROM rotas r JOIN usuarios u ON u.id = r.motoboy_id WHERE r.id = ?");
$s->execute([$id]);
$rota = $s->fetch();
if (!$rota) { flash('Rota não encontrada.', 'erro'); redirecionar('rotas.php'); }

function proximo_numero(int $rotaId): int {
    $s = db()->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM paradas WHERE rota_id = ?");
    $s->execute([$rotaId]);
    return (int)$s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $acao = $_POST['acao'] ?? '';
    set_time_limit(0);

    if ($acao === 'salvar_parada') {
        $pid = (int)($_POST['parada_id'] ?? 0);
        $end = trim($_POST['endereco'] ?? '');
        $num = trim($_POST['numero_casa'] ?? '');
        $bairro = trim($_POST['bairro'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '') ?: CIDADE_PADRAO;
        $numero = (int)($_POST['numero'] ?? 0) ?: proximo_numero($id);
        $pac = max(1, (int)($_POST['pacotes'] ?? 1));
        $obs = trim($_POST['observacao'] ?? '');
        if ($end === '') { flash('Informe o endereço.', 'erro'); redirecionar("rota.php?id=$id"); }
        [$lat, $lng] = geocodificar($end, $num, $bairro, $cidade);

        if ($pid) {
            db()->prepare("UPDATE paradas SET numero=?, endereco=?, numero_casa=?, bairro=?, cidade=?, pacotes=?, observacao=?, lat=COALESCE(?,lat), lng=COALESCE(?,lng) WHERE id=? AND rota_id=?")
                ->execute([$numero, $end, $num, $bairro, $cidade, $pac, $obs, $lat, $lng, $pid, $id]);
            flash('Parada atualizada.');
        } else {
            db()->prepare("INSERT INTO paradas (rota_id, numero, endereco, numero_casa, bairro, cidade, pacotes, observacao, lat, lng) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$id, $numero, $end, $num, $bairro, $cidade, $pac, $obs, $lat, $lng]);
            flash($lat ? "Parada $numero adicionada." : "Parada $numero adicionada, mas o endereço não foi achado no mapa. Arraste o ponto no mapa ou corrija o endereço.", $lat ? 'ok' : 'alerta');
        }
    }

    if ($acao === 'importar') {
        $linhas = preg_split('/\r?\n/', trim($_POST['lista'] ?? ''));
        $numero = proximo_numero($id);
        $ok = 0; $semMapa = 0;
        foreach ($linhas as $l) {
            if (trim($l) === '') continue;
            $c = array_map('trim', preg_split('/\t|;/', $l));
            $end = $c[0] ?? '';
            if ($end === '') continue;
            $num = $c[1] ?? '';
            $bairro = $c[2] ?? '';
            $pac = max(1, (int)($c[3] ?? 1));
            [$lat, $lng] = geocodificar($end, $num, $bairro, CIDADE_PADRAO);
            if (!$lat) $semMapa++;
            db()->prepare("INSERT INTO paradas (rota_id, numero, endereco, numero_casa, bairro, cidade, pacotes, lat, lng) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$id, $numero++, $end, $num, $bairro, CIDADE_PADRAO, $pac, $lat, $lng]);
            $ok++;
            usleep(1100000); // limite do Nominatim: 1 consulta por segundo
        }
        flash("$ok paradas importadas." . ($semMapa ? " $semMapa não foram achadas no mapa; ajuste arrastando o ponto." : ''), $semMapa ? 'alerta' : 'ok');
    }

    if ($acao === 'excluir_parada') {
        db()->prepare("DELETE FROM paradas WHERE id = ? AND rota_id = ?")->execute([(int)$_POST['parada_id'], $id]);
        flash('Parada excluída.');
    }

    if ($acao === 'reabrir_parada') {
        db()->prepare("UPDATE paradas SET status='pendente', motivo=NULL, finalizado_em=NULL WHERE id = ? AND rota_id = ?")->execute([(int)$_POST['parada_id'], $id]);
        flash('Parada voltou para pendente.');
    }

    if ($acao === 'ordem_lista') {
        $s = db()->prepare("SELECT id FROM paradas WHERE rota_id = ? ORDER BY status = 'pendente', entrega IS NULL, entrega, numero");
        $s->execute([$id]);
        $up = db()->prepare("UPDATE paradas SET numero = ? WHERE id = ?");
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $i => $pid) $up->execute([$i + 1, $pid]);
        flash('Paradas na ordem do número da entrega.');
    }

    if ($acao === 'trocar_motoboy') {
        db()->prepare("UPDATE rotas SET motoboy_id = ? WHERE id = ?")->execute([(int)$_POST['motoboy_id'], $id]);
        flash('Motoboy da rota alterado.');
    }

    atualizar_status_rota($id);
    redirecionar("rota.php?id=$id");
}

$s = db()->prepare("SELECT * FROM paradas WHERE rota_id = ? ORDER BY numero, id");
$s->execute([$id]);
$paradas = $s->fetchAll();

$fotos = [];
if ($paradas) {
    $s = db()->prepare("SELECT c.id, c.parada_id, c.lat, c.lng, c.precisao_m, c.endereco_gps, c.criado_em FROM comprovantes c JOIN paradas p ON p.id = c.parada_id WHERE p.rota_id = ? ORDER BY c.id");
    $s->execute([$id]);
    foreach ($s->fetchAll() as $c) $fotos[$c['parada_id']] = $c;
}

$s = db()->prepare("SELECT * FROM sacas WHERE rota_id = ? ORDER BY caixa");
$s->execute([$id]);
$sacas = $s->fetchAll();

$editar = null;
if (isset($_GET['editar'])) foreach ($paradas as $p) if ($p['id'] == $_GET['editar']) $editar = $p;

$total = count($paradas);
$entregues = count(array_filter($paradas, fn($p) => $p['status'] === 'entregue'));
$falhas = count(array_filter($paradas, fn($p) => $p['status'] === 'falhou'));
$pacotes = array_sum(array_column($paradas, 'pacotes'));
$motoboys = db()->query("SELECT id, nome FROM usuarios WHERE tipo='motoboy' AND ativo=1 ORDER BY nome")->fetchAll();
$rotuloParada = ['pendente' => 'Pendente', 'entregue' => 'Entregue', 'falhou' => 'Não entregue'];

topo('Rota ' . $rota['motoboy'], 'rotas', true);
?>
<div class="cabecalho-rota">
  <div>
    <a href="rotas.php?data=<?= e($rota['data']) ?>" class="voltar">← Rotas de <?= data_br($rota['data']) ?></a>
    <h1><?= e($rota['motoboy']) ?><?= $rota['descricao'] ? ' · ' . e($rota['descricao']) : '' ?></h1>
    <p class="numeros"><b><?= $total ?></b> paradas <b><?= $pacotes ?></b> pacotes <b><?= $entregues ?></b> entregues <b><?= $total - $entregues - $falhas ?></b> faltam</p>
  </div>
  <form method="post" class="trocar">
    <?= csrf_field() ?><input type="hidden" name="acao" value="ordem_lista">
    <button class="btn">Ordenar pelo número da entrega</button>
  </form>
  <form method="post" class="trocar">
    <?= csrf_field() ?><input type="hidden" name="acao" value="trocar_motoboy">
    <select name="motoboy_id" onchange="this.form.submit()" aria-label="Trocar motoboy">
      <?php foreach ($motoboys as $m): ?><option value="<?= $m['id'] ?>" <?= $m['id'] == $rota['motoboy_id'] ? 'selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<link rel="stylesheet" href="assets/sacas.css?v=12">
<?php if ($sacas): $col = count(array_filter($sacas, fn($x) => $x['coletada'])); ?>
<div class="sacas-admin" style="--cor-rota:<?= e($rota['cor'] ?: '#8CF20A') ?>;--texto-rota:<?= texto_sobre($rota['cor'] ?: '#8CF20A') ?>">
  <div class="faixa">Sacas: <?= $col ?> de <?= count($sacas) ?> coletadas · <?= array_sum(array_column($sacas, 'quantidade')) ?> pacotes</div>
  <div class="chips">
    <?php foreach ($sacas as $sc): ?><span class="chip <?= $sc['coletada'] ? 'ok' : '' ?>" title="<?= $sc['coletada'] ? 'Coletada às ' . hora_br($sc['coletada_em']) : 'Aguardando coleta' ?>"><b><?= (int)$sc['caixa'] ?></b> <?= (int)$sc['quantidade'] ?></span><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="duas-colunas">
  <div>
    <form method="post" class="form cartao">
      <h2><?= $editar ? 'Editar parada ' . (int)$editar['numero'] : 'Nova parada' ?></h2>
      <?= csrf_field() ?><input type="hidden" name="acao" value="salvar_parada">
      <input type="hidden" name="parada_id" value="<?= (int)($editar['id'] ?? 0) ?>">
      <div class="linha">
        <label class="curto">Parada nº<input name="numero" type="number" min="1" value="<?= e($editar['numero'] ?? proximo_numero($id)) ?>"></label>
        <label class="curto">Pacotes<input name="pacotes" type="number" min="1" value="<?= e($editar['pacotes'] ?? 1) ?>"></label>
      </div>
      <label>Endereço (rua)<input name="endereco" value="<?= e($editar['endereco'] ?? '') ?>" placeholder="Rua Joaquim Nabuco" required></label>
      <div class="linha">
        <label class="curto">Número<input name="numero_casa" value="<?= e($editar['numero_casa'] ?? '') ?>"></label>
        <label>Bairro<input name="bairro" value="<?= e($editar['bairro'] ?? '') ?>"></label>
      </div>
      <label>Cidade<input name="cidade" value="<?= e($editar['cidade'] ?? CIDADE_PADRAO) ?>"></label>
      <label>Observação <small>(complemento, referência)</small><input name="observacao" value="<?= e($editar['observacao'] ?? '') ?>"></label>
      <button class="btn primario"><?= $editar ? 'Salvar parada' : 'Adicionar parada' ?></button>
      <?php if ($editar): ?><a class="btn" href="rota.php?id=<?= $id ?>">Cancelar</a><?php endif; ?>
    </form>

    <details class="cartao importar">
      <summary>Colar várias paradas de uma vez</summary>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="acao" value="importar">
        <p class="dica">Uma parada por linha, na ordem de entrega: <b>rua; número; bairro; pacotes</b>. Também aceita colunas copiadas do Excel. Leva cerca de 1 segundo por endereço para localizar no mapa.</p>
        <textarea name="lista" rows="7" placeholder="Rua Joaquim Nabuco; 1643; Centro; 2&#10;Rua Lilian Viana de Araújo; 481; ; 1"></textarea>
        <button class="btn primario">Importar paradas</button>
      </form>
    </details>
  </div>

  <div>
    <div id="mapa" class="mapa-rota"></div>
    <p class="dica">Ponto no lugar errado? Arraste o marcador até a casa certa — salva na hora.</p>
    <div class="tabela-wrap">
      <table class="tabela">
        <thead><tr><th>Ordem</th><th>Entrega</th><th>Endereço</th><th>Pacotes</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($paradas as $p): ?>
          <tr>
            <td><span class="num-parada <?= e($p['status']) ?>"><?= (int)$p['numero'] ?></span></td>
            <td><?= $p['entrega'] ? '<b>' . (int)$p['entrega'] . '</b><br><small>caixa ' . intdiv((int)$p['entrega'], 10) * 10 . '</small>' : '—' ?></td>
            <td><?= e($p['endereco']) ?>, <?= e($p['numero_casa']) ?><?= $p['bairro'] ? ' – ' . e($p['bairro']) : '' ?>
              <?php if (!$p['lat']): ?><br><small class="txt-alerta">Sem posição no mapa</small><?php endif; ?>
              <?php if ($p['motivo']): ?><br><small>Motivo: <?= e($p['motivo']) ?></small><?php endif; ?></td>
            <td><?= (int)$p['pacotes'] ?></td>
            <td><span class="selo <?= e($p['status']) ?>"><?= $rotuloParada[$p['status']] ?></span>
              <?php if ($p['finalizado_em']): ?><br><small><?= hora_br($p['finalizado_em']) ?></small><?php endif; ?>
              <?php if (isset($fotos[$p['id']])): $f = $fotos[$p['id']]; ?>
                <br><a class="link-foto" href="foto.php?id=<?= (int)$f['id'] ?>" target="_blank">📷 Pacote voador</a>
                <?php if ($f['lat']): ?><br><small><a href="https://www.google.com/maps?q=<?= e($f['lat']) ?>,<?= e($f['lng']) ?>" target="_blank">GPS ±<?= (int)$f['precisao_m'] ?> m</a></small><?php endif; ?>
                <?php if (!empty($f['endereco_gps'])): ?><br><small>📍 <?= e($f['endereco_gps']) ?></small><?php endif; ?>
              <?php endif; ?></td>
            <td class="acoes">
              <a class="btn pequeno" href="?id=<?= $id ?>&editar=<?= $p['id'] ?>">Editar</a>
              <?php if ($p['status'] !== 'pendente'): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="reabrir_parada"><input type="hidden" name="parada_id" value="<?= $p['id'] ?>"><button class="btn pequeno">Reabrir</button></form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Excluir a parada <?= (int)$p['numero'] ?>?')"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir_parada"><input type="hidden" name="parada_id" value="<?= $p['id'] ?>"><button class="btn pequeno perigo">Excluir</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$paradas): ?><tr><td colspan="6" class="vazio">Nenhuma parada ainda. Adicione pelo formulário ou cole a lista.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const paradas = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'numero' => (int)$p['numero'], 'lat' => $p['lat'] ? (float)$p['lat'] : null, 'lng' => $p['lng'] ? (float)$p['lng'] : null, 'status' => $p['status'], 'end' => $p['endereco'] . ', ' . $p['numero_casa']], $paradas)) ?>;
const mapa = L.map('mapa').setView([<?= MAPA_LAT ?>, <?= MAPA_LNG ?>], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);
const pontos = [];
paradas.forEach(p => {
  const pos = p.lat ? [p.lat, p.lng] : mapa.getCenter();
  const m = L.marker(pos, { draggable: true, icon: L.divIcon({ className: '', html: `<div class="pino ${p.status}${p.lat ? '' : ' sem-pos'}">${p.numero}</div>`, iconSize: [30, 30], iconAnchor: [15, 15] }) })
    .addTo(mapa).bindPopup(`<b>Parada ${p.numero}</b><br>${p.end.replace(/</g, '&lt;')}`);
  if (p.lat) pontos.push(pos);
  m.on('dragend', async () => {
    const ll = m.getLatLng();
    const fd = new FormData(); fd.append('acao', 'mover_parada'); fd.append('id', p.id); fd.append('lat', ll.lat); fd.append('lng', ll.lng);
    const r = await fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } });
    if (r.ok) m.getElement().querySelector('.pino').classList.remove('sem-pos');
    else alert('Não foi possível salvar a nova posição.');
  });
});
const cd = <?= json_encode(cd_posicao()) ?>;
if (cd) { L.marker(cd, { icon: L.divIcon({ className: '', html: '<div class="mapa-cd">CD</div>', iconSize: [34, 24], iconAnchor: [17, 12] }) }).addTo(mapa); pontos.push(cd); }
const linha = paradas.filter(p => p.lat && p.status === 'pendente').map(p => [p.lat, p.lng]);
if (linha.length) L.polyline(cd ? [cd, ...linha] : linha, { color: '#111111', weight: 2, opacity: .45, dashArray: '4 6' }).addTo(mapa);
if (pontos.length) mapa.fitBounds(pontos, { padding: [30, 30], maxZoom: 16 });
</script>
<?php rodape();
