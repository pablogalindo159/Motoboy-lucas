<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['data'] ?? '') ? $_REQUEST['data'] : date('Y-m-d');
$motoboys = db()->query("SELECT id, nome FROM usuarios WHERE tipo='motoboy' AND ativo=1 ORDER BY nome")->fetchAll();
$temQuadrantes = (bool)quadrantes_ativos();
$modo = ($_REQUEST['modo'] ?? '') ?: ($temQuadrantes ? 'quadrantes' : 'setores');
$escolhidos = array_values(array_map('intval', (array)($_REQUEST['moto'] ?? array_column($motoboys, 'id'))));

$grupos = []; $avisos = [];
// entregas de fora que você aceitou mandar para o quadrante mais próximo
$aceitas = array_values(array_filter(array_map('intval', (array)($_REQUEST['aceitar'] ?? []))));
if ($modo === 'quadrantes') [$grupos, $avisos] = grupos_por_quadrante($data, $aceitas);
elseif ($escolhidos) [$grupos, $avisos] = grupos_por_setor($data, $escolhidos, $aceitas);

// motoboys escolhidos em cada quadrante/setor (um ou mais; vem do formulário ao recalcular ou confirmar)
$sel = isset($_REQUEST['motoboy']) && is_array($_REQUEST['motoboy']) ? $_REQUEST['motoboy'] : null;
foreach ($grupos as &$g) {
    $lista = $g['motoboy_id'] ? [(int)$g['motoboy_id']] : [];
    if ($sel !== null && array_key_exists($g['chave'], $sel)) {
        $v = is_array($sel[$g['chave']]) ? $sel[$g['chave']] : [$sel[$g['chave']]];
        $lista = array_values(array_unique(array_filter(array_map('intval', $v))));
    }
    $g['motoboys'] = $lista;
}
unset($g);
$nomeMoto = array_column($motoboys, 'nome', 'id');

// quadrante com mais de um motoboy: divide pela numeração (caixas inteiras, pacotes iguais)
$gruposDist = []; $divisao = [];
$partirCaixa = !empty($_REQUEST['partir_caixa']);
foreach ($grupos as $g) {
    $n = count($g['motoboys']);
    if ($n <= 1) { $g['motoboy_id'] = $g['motoboys'][0] ?? null; $gruposDist[] = $g; continue; }
    foreach (dividir_por_numero($g['entregas'], $n, !$partirCaixa) as $i => $parte) {
        $p = $g;
        $p['entregas'] = $parte;
        $p['motoboy_id'] = $g['motoboys'][$i];
        $p['nome'] = $g['nome'] . ' (' . ($i + 1) . '/' . $n . ')';
        $p['cor'] = cor_da_parte($g['cor'], $i);
        $gruposDist[] = $p;
        $nums = array_column($parte, 'entrega');
        $divisao[$g['chave']][] = ['mid' => $g['motoboys'][$i], 'pac' => array_sum(array_column($parte, 'pacotes')),
                                   'de' => $nums ? min($nums) : null, 'ate' => $nums ? max($nums) : null, 'cor' => $p['cor']];
    }
}
$pm = montar_por_motoboy($gruposDist);

// mínimo e máximo de hoje: o que foi digitado agora; se não, o padrão do cadastro
$padroes = db()->query("SELECT id, pacotes_min, pacotes_max FROM usuarios WHERE tipo = 'motoboy'")->fetchAll(PDO::FETCH_UNIQUE);
$lim = [];
foreach (array_keys($pm) as $mid) {
    $f = $_REQUEST['lim'][$mid] ?? null;
    $val = fn($k, $pad) => $f !== null ? (($f[$k] ?? '') !== '' ? max(0, (int)$f[$k]) : null) : ($pad !== null ? (int)$pad : null);
    $lim[$mid] = ['min' => $val('min', $padroes[$mid]['pacotes_min'] ?? null), 'max' => $val('max', $padroes[$mid]['pacotes_max'] ?? null)];
}
$equilibrar = !isset($_REQUEST['equilibrar']) || $_REQUEST['equilibrar'] === '1';

// quadrantes que passam do máximo: pergunta se fica tudo com o motoboy ou se o excesso vai para outro
$acimaMax = [];
foreach ($pm as $mid => $m) if ($lim[$mid]['max'] !== null && $m['pac_quadrante'] > $lim[$mid]['max']) $acimaMax[$mid] = $m['pac_quadrante'] - $lim[$mid]['max'];
$decisao = [];
foreach ($acimaMax as $mid => $x) {
    $d = $_REQUEST['excesso'][$mid] ?? '';
    $decisao[$mid] = in_array($d, ['manter', 'passar'], true) ? $d : '';
}
$pendentes = array_keys(array_filter($decisao, fn($d) => $d === ''));
// "manter": fica exatamente com tudo do quadrante (não cede nem recebe); sem resposta, a prévia mostra o excesso sendo passado
$limEq = $lim;
foreach ($decisao as $mid => $d) if ($d === 'manter') $limEq[$mid]['min'] = $limEq[$mid]['max'] = $pm[$mid]['pac_quadrante'];
$eq = $equilibrar ? equilibrar_pacotes($pm, $limEq) : ['movidas' => [], 'recebeu' => [], 'cedeu' => []];
$erroDecisao = false;

if (($_POST['acao'] ?? '') === 'confirmar' && csrf_ok() && $equilibrar && $pendentes) {
    $erroDecisao = true; // não cria: falta responder o que fazer com quem passou do máximo
} elseif (($_POST['acao'] ?? '') === 'confirmar' && csrf_ok()) {
    if (!$pm) { flash('Escolha pelo menos um motoboy.', 'erro'); redirecionar("distribuir.php?data=$data&modo=$modo"); }
    // quadrante: guarda o motoboy escolhido como padrão para os próximos dias
    if ($modo === 'quadrantes' && !empty($_POST['lembrar'])) {
        $up = db()->prepare("UPDATE quadrantes SET motoboy_id = ? WHERE id = ?");
        foreach ($grupos as $g) if ($g['chave'] !== 'fora') $up->execute([$g['motoboys'][0] ?? null, (int)substr($g['chave'], 1)]);
    }
    if (!empty($_POST['salvar_padrao'])) {
        $up = db()->prepare("UPDATE usuarios SET pacotes_min = ?, pacotes_max = ? WHERE id = ?");
        foreach ($lim as $mid => $l) $up->execute([$l['min'], $l['max'], $mid]);
    }
    set_time_limit(180);
    try {
        $rotas = criar_rotas_por_motoboy($data, $pm);
    } catch (Throwable $ex) {
        flash('Erro ao criar as rotas: ' . $ex->getMessage(), 'erro'); redirecionar("distribuir.php?data=$data&modo=$modo");
    }
    $semMoto = array_sum(array_map(fn($g) => $g['motoboys'] ? 0 : count($g['entregas']), $grupos));
    $movidos = array_sum(array_column($eq['movidas'], 'pacotes'));
    flash(count($rotas) . ' rotas criadas na ordem da lista.' . ($movidos ? " $movidos pacotes remanejados para respeitar mínimo e máximo." : '') . ($semMoto ? " $semMoto entregas ficaram sem motoboy." : ''), $semMoto ? 'alerta' : 'ok');
    redirecionar('admin.php?data=' . urlencode($data));
}

$entregas = entregas_do_dia($data);
$s = db()->prepare("SELECT COUNT(*) FROM rotas r WHERE r.data = ? AND (r.chegada_cd IS NOT NULL OR EXISTS (SELECT 1 FROM paradas p WHERE p.rota_id = r.id AND p.status <> 'pendente'))");
$s->execute([$data]);
$iniciadas = (int)$s->fetchColumn();

topo('Distribuir entregas', 'rotas', true);
?>
<link rel="stylesheet" href="assets/sacas.css?v=18">
<a href="rotas.php?data=<?= e($data) ?>" class="voltar">← Rotas</a>
<h1>Distribuir entregas de <?= data_br($data) ?></h1>

<?php if (!$entregas): ?>
  <div class="cartao estreito"><p>Nenhuma lista carregada para este dia.</p><a class="btn primario" href="importar_entregas.php?data=<?= e($data) ?>">Carregar lista de entregas</a></div>
<?php rodape(); exit; endif; ?>

<div class="abas-modo">
  <a class="<?= $modo === 'quadrantes' ? 'ativo' : '' ?>" href="?data=<?= e($data) ?>&modo=quadrantes">Pelos quadrantes do mapa</a>
  <a class="<?= $modo === 'setores' ? 'ativo' : '' ?>" href="?data=<?= e($data) ?>&modo=setores">Dividir automático</a>
</div>

<?php if ($modo === 'quadrantes' && !$temQuadrantes): ?>
  <div class="aviso alerta">Nenhum quadrante desenhado ainda. <a href="quadrantes.php">Desenhar ou importar quadrantes</a>, ou use "Dividir automático".</div>
<?php endif; ?>

<?php if ($modo === 'setores'): ?>
  <form class="cartao motos-dia">
    <input type="hidden" name="data" value="<?= e($data) ?>"><input type="hidden" name="modo" value="setores">
    <p><b>Quem trabalha hoje?</b> O sistema divide a cidade em fatias saindo do CD, com a mesma quantidade de pacotes para cada um.</p>
    <div class="chips-motos">
      <?php foreach ($motoboys as $m): ?>
        <label><input type="checkbox" name="moto[]" value="<?= $m['id'] ?>" <?= in_array((int)$m['id'], $escolhidos, true) ? 'checked' : '' ?>> <?= e($m['nome']) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn">Refazer divisão</button>
  </form>
<?php endif; ?>

<?php if (!empty($avisos['fora']) || !empty($avisos['aceitas'])):
    $foraLista = $avisos['fora_lista'] ?? []; $aceitasLista = $avisos['aceitas'] ?? [];
    $foraPac = array_sum(array_column($foraLista, 'pacotes'));
    $foraGrupo = null; foreach ($grupos as $g) if ($g['chave'] === 'fora') $foraGrupo = $g;
    $dist = fn($m) => $m === null ? '' : ($m >= 1000 ? number_format($m / 1000, 1, ',', '') . ' km' : $m . ' m'); ?>
  <div class="aviso <?= $foraLista ? 'erro' : 'ok' ?> fora-quad">
    <?php if ($foraLista): ?>
      <b>⚠ <?= count($foraLista) ?> entregas fora dos bairros / quadrantes atendidos (<?= $foraPac ?> pacotes)</b>
      <?php if ($foraGrupo && $foraGrupo['motoboys']): ?>
        — vão para <?= e(implode(', ', array_map(fn($m) => $nomeMoto[$m] ?? '?', $foraGrupo['motoboys']))) ?>, como você escolheu no card de fora.
      <?php else: ?>
        — <b>não serão distribuídas</b>, a não ser que você aceite mandar para o quadrante mais próximo.
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($aceitasLista): ?><p><b>✓ <?= count($aceitasLista) ?> aceitas</b> foram para o quadrante mais próximo (<?= array_sum(array_column($aceitasLista, 'pacotes')) ?> pacotes).</p><?php endif; ?>
    <details <?= $aceitasLista || count($foraLista) <= 10 ? 'open' : '' ?>><summary>Ver quais são</summary>
      <div class="tabela-wrap"><table class="tabela">
        <thead><tr><th>Nº</th><th>Endereço</th><th>Pacotes</th><th>Por quê</th><th>Mandar para o quadrante mais próximo</th></tr></thead>
        <tbody><?php foreach (array_merge($aceitasLista, $foraLista) as $f): $ok = in_array((int)$f['id'], $aceitas, true) && $f['zona_id'] !== null; ?>
          <tr class="<?= $ok ? 'aceita' : '' ?>"><td><b><?= (int)$f['entrega'] ?></b></td><td><?= e($f['rua']) ?>, <?= e($f['numero_casa']) ?></td><td><?= (int)$f['pacotes'] ?></td>
            <td><small class="<?= $ok ? '' : 'txt-erro' ?>"><?= e($f['motivo'] ?: 'Fora dos quadrantes') ?></small>
              <?php if ($f['lat'] !== null): ?> <a href="https://www.google.com/maps?q=<?= e($f['lat']) ?>,<?= e($f['lng']) ?>" target="_blank">mapa</a><?php endif; ?></td>
            <td><?php if ($f['zona_id'] !== null): ?>
                <label class="aceitar"><input type="checkbox" class="chk-aceitar" form="f-dist" name="aceitar[]" value="<?= (int)$f['id'] ?>" <?= $ok ? 'checked' : '' ?> onchange="document.querySelector('#f-dist [value=previa]').click()">
                  <?= e($f['zona_perto']) ?> <small>(<?= $dist($f['dist_m']) ?>)</small></label>
              <?php else: ?><small>sem localização</small><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php if ($foraLista): ?>
        <button type="button" class="btn pequeno" onclick="document.querySelectorAll('.chk-aceitar').forEach(c => c.checked = true); document.querySelector('#f-dist [value=previa]').click()">Aceitar todas: mandar para o quadrante mais próximo</button>
      <?php endif; ?>
      <p class="dica">O endereço pode ter sido achado no lugar errado. Confira no mapa antes de aceitar.</p>
    </details>
  </div>
<?php endif; ?>
<?php if (!empty($avisos['sem_local'])): ?><p class="dica"><?= (int)$avisos['sem_local'] ?> entregas não foram achadas no mapa e seguem com as entregas de número vizinho.</p><?php endif; ?>
<?php if ($iniciadas): ?><div class="aviso erro"><?= $iniciadas ?> rotas deste dia já começaram (chegada no CD ou entrega marcada). Distribuir de novo apaga as rotas atuais do dia.</div><?php endif; ?>

<?php if ($grupos): ?>
<div class="duas-colunas distribuir">
  <form method="post" id="f-dist">
    <?= csrf_field() ?>
    <input type="hidden" name="data" value="<?= e($data) ?>"><input type="hidden" name="modo" value="<?= e($modo) ?>">
    <?php foreach ($escolhidos as $id): ?><input type="hidden" name="moto[]" value="<?= $id ?>"><?php endforeach; ?>
    <?php if ($acimaMax && $equilibrar): ?>
    <div class="aviso alerta acima-max<?= $erroDecisao ? ' pendente' : '' ?>" id="acima-max">
      <b>⚠ Quadrante acima do máximo</b>
      <?php if ($erroDecisao): ?><p class="txt-alerta">Responda abaixo antes de criar as rotas.</p><?php endif; ?>
      <?php foreach ($acimaMax as $mid => $excesso): $m = $pm[$mid]; $nome = $nomeMoto[$mid] ?? '?'; ?>
        <div class="decisao">
          <p><span class="bolinha" style="background:<?= e($m['cor']) ?>"></span><b><?= e($nome) ?></b> · <?= e(implode(' + ', $m['nomes'])) ?>:
             <b><?= (int)$m['pac_quadrante'] ?></b> pacotes, máximo <b><?= (int)$lim[$mid]['max'] ?></b> (<b>+<?= (int)$excesso ?></b>)</p>
          <label><input type="radio" name="excesso[<?= $mid ?>]" value="manter" <?= $decisao[$mid] === 'manter' ? 'checked' : '' ?> onchange="this.form.querySelector('[value=previa]').click()">
            Adicionar mesmo assim para <?= e($nome) ?> (fica com <?= (int)$m['pac_quadrante'] ?>)</label>
          <label><input type="radio" name="excesso[<?= $mid ?>]" value="passar" <?= $decisao[$mid] === 'passar' ? 'checked' : '' ?> onchange="this.form.querySelector('[value=previa]').click()">
            Passar os <?= (int)$excesso ?> pacotes a mais para o próximo motoboy</label>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="lista-grupos">
    <?php foreach ($grupos as $g):
        $pac = array_sum(array_column($g['entregas'], 'pacotes'));
        $cx = count(array_unique(array_map(fn($e) => intdiv((int)$e['entrega'], 10) * 10, $g['entregas']))); ?>
      <div class="grupo" style="--cor-rota:<?= e($g['cor']) ?>;--texto-rota:<?= texto_sobre($g['cor']) ?>">
        <div class="faixa"><?= e($g['nome']) ?></div>
        <div class="corpo">
          <p><b><?= count($g['entregas']) ?></b> entregas · <b><?= $pac ?></b> pacotes · <?= $cx ?> caixas</p>
          <?php $lista = $g['motoboys'] ?: [0]; if ($g['motoboys']) $lista[] = 0; // + um campo vazio para adicionar outro
          foreach ($lista as $k => $escolhido): ?>
          <label><?= $k === 0 ? 'Motoboy' : '' ?>
            <select name="motoboy[<?= e($g['chave']) ?>][]" class="<?= $k > 0 && !$escolhido ? 'sel-extra' : '' ?>" onchange="this.form.querySelector('[value=previa]').click()">
              <option value=""><?= $k === 0 ? 'Não distribuir' : ($escolhido ? 'Tirar este motoboy' : '+ adicionar outro motoboy') ?></option>
              <?php foreach ($motoboys as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$escolhido === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?>
            </select>
          </label>
          <?php endforeach; ?>
          <?php if (!empty($divisao[$g['chave']])): ?>
            <ul class="divisao">
              <?php foreach ($divisao[$g['chave']] as $d): ?>
                <li><span class="bolinha" style="background:<?= e($d['cor']) ?>"></span><b><?= e($nomeMoto[$d['mid']] ?? '?') ?></b>: <?= $d['pac'] ?> pacotes<?= $d['de'] !== null ? " · entregas {$d['de']} a {$d['ate']}" : '' ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if ($divisao): ?>
      <label class="lembrar"><input type="checkbox" name="partir_caixa" value="1" <?= $partirCaixa ? 'checked' : '' ?> onchange="this.form.querySelector('[value=previa]').click()">
        Quadrante com mais de um motoboy: dividir igual, mesmo partindo caixa <small>(desmarcado = cada um fica com caixas inteiras)</small></label>
    <?php endif; ?>
    <?php if ($modo === 'quadrantes'): ?><label class="lembrar"><input type="checkbox" name="lembrar" value="1" checked> Lembrar estes motoboys nos quadrantes para os próximos dias</label><?php endif; ?>

    <?php if ($pm):
        // de quem cada um recebeu
        $origem = [];
        foreach ($eq['movidas'] as $mv) $origem[$mv['para']][$mv['de']] = ($origem[$mv['para']][$mv['de']] ?? 0) + $mv['pacotes'];
    ?>
    <div class="cartao limites">
      <h2>Pacotes de cada motoboy hoje</h2>
      <p class="dica">Ajuste o mínimo e o máximo do dia e toque em <b>Recalcular</b>. Quem ficar abaixo do mínimo recebe as entregas de outros quadrantes mais perto do quadrante principal dele; quem passar do máximo cede para quem tem espaço.</p>
      <div class="tabela-wrap">
        <table class="tabela">
          <thead><tr><th>Motoboy</th><th>No quadrante</th><th>Mínimo</th><th>Máximo</th><th>Fica com</th></tr></thead>
          <tbody>
          <?php foreach ($pm as $mid => $m):
              $final = array_sum(array_column($m['entregas'], 'pacotes'));
              $l = $lim[$mid];
              $abaixo = $l['min'] !== null && $final < $l['min'];
              $acima = $l['max'] !== null && $final > $l['max'] && ($decisao[$mid] ?? '') !== 'manter';
              $mantido = ($decisao[$mid] ?? '') === 'manter'; ?>
            <tr>
              <td><span class="bolinha" style="background:<?= e($m['cor']) ?>"></span><b><?= e($nomeMoto[$mid] ?? '?') ?></b><br><small><?= e(implode(' + ', $m['nomes'])) ?></small></td>
              <td><?= (int)$m['pac_quadrante'] ?></td>
              <td><input class="num-lim" type="number" min="0" inputmode="numeric" name="lim[<?= $mid ?>][min]" value="<?= e($l['min'] ?? '') ?>" placeholder="—"></td>
              <td><input class="num-lim" type="number" min="1" inputmode="numeric" name="lim[<?= $mid ?>][max]" value="<?= e($l['max'] ?? '') ?>" placeholder="—"></td>
              <td>
                <b class="<?= $abaixo || $acima ? 'txt-alerta' : '' ?>"><?= $final ?></b> pacotes
                <?php if (!empty($eq['recebeu'][$mid])): ?><br><small class="mais">+<?= (int)$eq['recebeu'][$mid] ?> de <?= e(implode(', ', array_map(fn($de, $q) => ($nomeMoto[$de] ?? '?') . " ($q)", array_keys($origem[$mid]), $origem[$mid]))) ?></small><?php endif; ?>
                <?php if (!empty($eq['cedeu'][$mid])): ?><br><small class="menos">−<?= (int)$eq['cedeu'][$mid] ?> para outros</small><?php endif; ?>
                <?php if ($mantido): ?><br><small class="txt-alerta">Acima do máximo: você escolheu manter</small>
                <?php elseif (isset($acimaMax[$mid]) && $decisao[$mid] === '' && $equilibrar): ?><br><small class="txt-alerta">Acima do máximo: falta decidir (aviso no topo)</small>
                <?php elseif ($abaixo): ?><br><small class="txt-alerta">Não deu para chegar ao mínimo</small>
                <?php elseif ($acima): ?><br><small class="txt-alerta">Ninguém com espaço para receber o excesso</small><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <input type="hidden" name="equilibrar" value="0">
      <label class="lembrar"><input type="checkbox" name="equilibrar" value="1" <?= $equilibrar ? 'checked' : '' ?>> Equilibrar pelo mínimo e máximo</label>
      <label class="lembrar"><input type="checkbox" name="salvar_padrao" value="1"> Salvar estes mínimos e máximos como padrão de cada motoboy</label>
    </div>
    <?php endif; ?>

    <div class="rodape-previa">
      <button class="btn grande" name="acao" value="previa">Recalcular</button>
      <button class="btn primario grande" name="acao" value="confirmar" <?= $pendentes && $equilibrar ? 'disabled title="Responda o aviso de quadrante acima do máximo"' : '' ?>>Criar rotas</button>
      <?php if ($pendentes && $equilibrar): ?><small class="txt-alerta">Responda o aviso de quadrante acima do máximo para liberar.</small><?php endif; ?>
    </div>
    <p class="dica">Cada motoboy entrega na ordem do número da lista. Um motoboy pode ficar com mais de um quadrante: vira uma rota só. As caixas de cada um saem das entregas dele.</p>
  </form>
  <div>
    <div id="mapa" class="mapa-rota mapa-dist"></div>
    <p class="dica"><?= count($entregas) ?> entregas · <?= array_sum(array_column($entregas, 'pacotes')) ?> pacotes<?= $eq['movidas'] ? ' · pontos com borda branca = remanejados' : '' ?></p>
  </div>
</div>

<script>
const grupos = <?= json_encode(array_values(array_map(fn($mid, $m) => ['nome' => ($nomeMoto[$mid] ?? '?') . ' · ' . implode(' + ', $m['nomes']), 'cor' => $m['cor'], 'pts' => array_values(array_filter(array_map(fn($e) => $e['lat'] ? [(float)$e['lat'], (float)$e['lng'], (int)$e['entrega'], isset($e['movida_de']) ? 1 : 0] : null, $m['entregas'])))], array_keys($pm), $pm))) ?>;
const quads = <?= json_encode($modo === 'quadrantes' ? array_map(fn($q) => ['nome' => $q['nome'], 'cor' => $q['cor'], 'pontos' => $q['pontos']], quadrantes_ativos()) : []) ?>;
const cd = <?= json_encode(cd_posicao()) ?>;
const mapa = L.map('mapa', { preferCanvas: true }).setView(cd || [<?= MAPA_LAT ?>, <?= MAPA_LNG ?>], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);
const lim = [];
quads.forEach(q => L.polygon(q.pontos, { color: q.cor, weight: 2, fillOpacity: .08 }).addTo(mapa).bindTooltip(q.nome));
grupos.forEach(g => g.pts.forEach(p => { lim.push([p[0], p[1]]);
  L.circleMarker([p[0], p[1]], { radius: p[3] ? 7 : 5, color: p[3] ? '#fff' : '#000', weight: p[3] ? 3 : 1, fillColor: g.cor, fillOpacity: .95 }).addTo(mapa).bindTooltip(`${g.nome} · entrega ${p[2]}${p[3] ? ' (remanejada)' : ''}`); }));
if (cd) { L.marker(cd, { icon: L.divIcon({ className: '', html: '<div class="mapa-cd">CD</div>', iconSize: [34, 24], iconAnchor: [17, 12] }) }).addTo(mapa); lim.push(cd); }
if (lim.length) mapa.fitBounds(lim, { padding: [20, 20] });
</script>
<?php endif; ?>
<?php rodape();
