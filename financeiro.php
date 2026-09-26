<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');

$q = quinzena($_REQUEST['q'] ?? null);
$motoboys = db()->query("SELECT id, nome, ativo, valor_entrega FROM usuarios WHERE tipo = 'motoboy' ORDER BY nome")->fetchAll(PDO::FETCH_UNIQUE);
$ap = apuracao($q['ini'], $q['fim']);

$s = db()->prepare("SELECT * FROM pagamentos WHERE periodo_inicio = ?");
$s->execute([$q['ini']]);
$pagos = [];
foreach ($s->fetchAll() as $p) $pagos[(int)$p['motoboy_id']] = $p;

function valor_digitado($txt): float {
    $t = str_replace(['R$', ' '], '', trim((string)$txt));
    if (substr_count($t, ',') === 1) $t = str_replace(['.', ','], ['', '.'], $t);
    return is_numeric($t) ? round((float)$t, 2) : 0.0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $mid = (int)($_POST['motoboy_id'] ?? 0);
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'pagar' && isset($ap[$mid]) && $ap[$mid]['sem_valor']) {
        flash('Defina o valor por entrega de ' . ($motoboys[$mid]['nome'] ?? '') . ' antes de pagar.', 'erro');
    } elseif ($acao === 'pagar' && isset($ap[$mid]) && !isset($pagos[$mid])) {
        $ajuste = valor_digitado($_POST['ajuste'] ?? '0');
        $valor = round($ap[$mid]['valor'], 2);
        db()->prepare("INSERT INTO pagamentos (motoboy_id, periodo_inicio, periodo_fim, entregas, valor_entregas, ajuste, valor_total, observacao) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$mid, $q['ini'], $q['fim'], $ap[$mid]['entregues'], $valor, $ajuste, $valor + $ajuste, trim($_POST['observacao'] ?? '') ?: null]);
        flash('Pagamento de ' . ($motoboys[$mid]['nome'] ?? '') . ' registrado: ' . dinheiro($valor + $ajuste) . '.');
    }
    if ($acao === 'desfazer') {
        db()->prepare("DELETE FROM pagamentos WHERE motoboy_id = ? AND periodo_inicio = ?")->execute([$mid, $q['ini']]);
        flash('Pagamento desfeito. Ele volta para "a pagar".');
    }
    redirecionar('financeiro.php?q=' . $q['chave']);
}

// linhas: todos que trabalharam na quinzena ou já foram pagos nela
$ids = array_unique(array_merge(array_keys($ap), array_keys($pagos)));
usort($ids, fn($a, $b) => strcmp($motoboys[$a]['nome'] ?? '', $motoboys[$b]['nome'] ?? ''));
$tot = ['entregas' => 0, 'apagar' => 0.0, 'pago' => 0.0, 'pendente' => 0.0];
foreach ($ids as $mid) {
    $a = $ap[$mid] ?? ['entregues' => 0, 'valor' => 0];
    $tot['entregas'] += $a['entregues'];
    if (isset($pagos[$mid])) $tot['pago'] += (float)$pagos[$mid]['valor_total'];
    else $tot['pendente'] += $a['valor'];
}
$tot['apagar'] = $tot['pago'] + $tot['pendente'];

// exportar para planilha (CSV que abre no Excel)
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="financeiro-' . $q['chave'] . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Quinzena', $q['rotulo'] . ' (' . $q['dias'] . ')'], ';');
    fputcsv($o, ['Motoboy', 'Entregas feitas', 'Não entregues', 'Valor por entrega', 'Valor das entregas', 'Ajuste', 'Total', 'Situação', 'Pago em', 'Observação'], ';');
    $n = fn($v) => number_format((float)$v, 2, ',', '');
    foreach ($ids as $mid) {
        $a = $ap[$mid] ?? ['entregues' => 0, 'falhas' => 0, 'valor' => 0, 'valores' => []];
        $p = $pagos[$mid] ?? null;
        $vals = array_keys($a['valores'] ?? []);
        fputcsv($o, [$motoboys[$mid]['nome'] ?? '?', $p ? $p['entregas'] : $a['entregues'], $a['falhas'] ?? 0,
            count($vals) === 1 ? $n($vals[0]) : (count($vals) > 1 ? 'variado' : ''), $n($p ? $p['valor_entregas'] : $a['valor']), $n($p['ajuste'] ?? 0),
            $n($p ? $p['valor_total'] : $a['valor']), $p ? 'Pago' : 'A pagar', $p ? date('d/m/Y H:i', strtotime($p['pago_em'])) : '', $p['observacao'] ?? ''], ';');
    }
    fputcsv($o, ['TOTAL', $tot['entregas'], '', '', '', '', $n($tot['apagar'])], ';');
    exit;
}

topo('Financeiro', 'financeiro');
?>
<link rel="stylesheet" href="assets/sacas.css?v=16">
<div class="cabecalho-rota financeiro-topo">
  <div>
    <h1>Financeiro</h1>
    <div class="quinzena">
      <a class="btn pequeno" href="?q=<?= e($q['ant']) ?>" aria-label="Quinzena anterior">‹</a>
      <b><?= e($q['rotulo']) ?></b> <span>(<?= e($q['dias']) ?>)</span>
      <a class="btn pequeno" href="?q=<?= e($q['prox']) ?>" aria-label="Próxima quinzena">›</a>
    </div>
  </div>
  <div class="acoes nao-imprimir">
    <a class="btn" href="?q=<?= e($q['chave']) ?>&csv=1">Baixar planilha</a>
    <button class="btn" onclick="window.print()">Imprimir</button>
  </div>
</div>

<div class="resumo-fin">
  <div><span>Entregas feitas</span><b><?= $tot['entregas'] ?></b></div>
  <div><span>Total da quinzena</span><b><?= dinheiro($tot['apagar']) ?></b></div>
  <div><span>Já pago</span><b class="pago"><?= dinheiro($tot['pago']) ?></b></div>
  <div><span>Falta pagar</span><b class="falta"><?= dinheiro($tot['pendente']) ?></b></div>
</div>

<?php if ($q['fim'] >= date('Y-m-d')): ?><p class="dica">Quinzena em andamento: os números ainda mudam conforme as entregas do dia.</p><?php endif; ?>

<div class="tabela-wrap">
  <table class="tabela fin">
    <thead><tr><th>Motoboy</th><th>Entregas feitas</th><th>R$ por entrega</th><th>Valor</th><th>Situação</th></tr></thead>
    <tbody>
    <?php foreach ($ids as $mid):
        $mb = $motoboys[$mid] ?? ['nome' => '?', 'valor_entrega' => null];
        $a = $ap[$mid] ?? ['entregues' => 0, 'falhas' => 0, 'pendentes' => 0, 'pacotes' => 0, 'valor' => 0, 'valores' => [], 'dias' => [], 'sem_valor' => false];
        $p = $pagos[$mid] ?? null;
        $vals = array_keys($a['valores']);
        $mudou = $p && ((int)$p['entregas'] !== (int)$a['entregues']); ?>
      <tr class="<?= $p ? 'linha-paga' : '' ?>">
        <td><b><?= e($mb['nome']) ?></b>
          <?php if ($a['dias']): ?>
          <details class="dias"><summary><?= count($a['dias']) ?> <?= count($a['dias']) === 1 ? 'dia trabalhado' : 'dias trabalhados' ?></summary>
            <table>
              <?php foreach ($a['dias'] as $d): ?>
                <tr><td><?= data_br($d['data']) ?></td><td><?= $d['entregues'] ?> entregas<?= $d['falhas'] ? " · {$d['falhas']} não entregues" : '' ?></td>
                    <td><?= $d['valor'] === null ? '—' : dinheiro($d['entregues'] * $d['valor']) ?></td></tr>
              <?php endforeach; ?>
            </table>
          </details>
          <?php endif; ?></td>
        <td><b><?= $a['entregues'] ?></b>
          <?php if ($a['falhas']): ?><br><small><?= $a['falhas'] ?> não entregues (não pagas)</small><?php endif; ?>
          <?php if ($a['pendentes']): ?><br><small class="txt-alerta"><?= $a['pendentes'] ?> ainda pendentes</small><?php endif; ?></td>
        <td><?php if ($a['sem_valor']): ?><a class="txt-alerta" href="motoboys.php?editar=<?= $mid ?>">Definir valor</a>
            <?php else: ?><?= count($vals) > 1 ? 'variado<br><small>' . e(implode(' / ', array_map('dinheiro', $vals))) . '</small>' : dinheiro($vals[0] ?? ($mb['valor_entrega'] ?? 0)) ?><?php endif; ?></td>
        <td>
          <?php if ($p): ?>
            <b><?= dinheiro($p['valor_total']) ?></b>
            <?php if ((float)$p['ajuste'] != 0): ?><br><small><?= dinheiro($p['valor_entregas']) ?> <?= (float)$p['ajuste'] > 0 ? '+' : '−' ?> <?= dinheiro(abs((float)$p['ajuste'])) ?> de ajuste</small><?php endif; ?>
            <?php if ($p['observacao']): ?><br><small><?= e($p['observacao']) ?></small><?php endif; ?>
          <?php else: ?>
            <b><?= dinheiro($a['valor']) ?></b>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($p): ?>
            <span class="selo finalizada">Pago em <?= date('d/m', strtotime($p['pago_em'])) ?></span>
            <?php if ($mudou): ?><br><small class="txt-alerta">Mudou depois do pagamento: agora são <?= $a['entregues'] ?> entregas (<?= dinheiro($a['valor']) ?>)</small><?php endif; ?>
            <form method="post" class="nao-imprimir" onsubmit="return confirm('Desfazer o pagamento de <?= e($mb['nome']) ?>?')">
              <?= csrf_field() ?><input type="hidden" name="q" value="<?= e($q['chave']) ?>"><input type="hidden" name="motoboy_id" value="<?= $mid ?>">
              <button class="btn pequeno" name="acao" value="desfazer">Desfazer</button>
            </form>
          <?php elseif ($a['entregues'] > 0 && $a['sem_valor']): ?>
            <small class="txt-alerta">Defina o valor por entrega para pagar</small>
          <?php elseif ($a['entregues'] > 0): ?>
            <details class="pagar nao-imprimir">
              <summary class="btn pequeno primario">Marcar como pago</summary>
              <form method="post" class="form">
                <?= csrf_field() ?><input type="hidden" name="q" value="<?= e($q['chave']) ?>"><input type="hidden" name="motoboy_id" value="<?= $mid ?>">
                <label>Ajuste (R$) <small>bônus positivo, vale/desconto negativo</small><input name="ajuste" inputmode="decimal" placeholder="0,00"></label>
                <label>Observação<input name="observacao" placeholder="ex.: Pix, vale de R$ 50 descontado"></label>
                <button class="btn primario" name="acao" value="pagar">Confirmar pagamento de <?= dinheiro($a['valor']) ?></button>
              </form>
            </details>
            <span class="so-imprimir">A pagar</span>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ids): ?><tr><td colspan="5" class="vazio">Nenhuma entrega nesta quinzena.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="dica">Conta como entrega feita tudo o que o motoboy marcou como Entregue (inclusive pacote voador). Não entregue não é pago. O valor por entrega de cada dia é o do cadastro do motoboy no dia em que a rota foi criada.</p>
<?php rodape();
