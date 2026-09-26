<?php
// Histórico de notificações do admin (e o que foi enviado aos motoboys)
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
$u = exigir('admin');

$aba = ($_GET['aba'] ?? '') === 'motoboys' ? 'motoboys' : 'minhas';
$tipos = [
    'socorro'    => ['🆘 Socorro', ['pedido_socorro']],
    'ambulancia' => ['🚑 Ambulância', ['ambulancia', 'ambulancia_origem', 'ambulancia_cancelada', 'ambulancia_recusada', 'socorro_coletado']],
    'alertas'    => ['⚠️ Alertas', ['sem_sinal', 'atraso_cd', 'falhas_seguidas', 'entregas_rapidas']],
    'rotas'      => ['📦 Rotas', ['rota_disponivel', 'entregas_adicionadas', 'rota_trocada', 'rota_concluida']],
    'voador'     => ['📷 Pacote voador', ['pacote_voador']],
    'pagamento'  => ['💰 Pagamentos', ['pagamento']],
];
$tipo = isset($tipos[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
$dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia'] ?? '') ? $_GET['dia'] : '';
$busca = trim($_GET['q'] ?? '');
$pag = max(1, (int)($_GET['p'] ?? 1)); $porPag = 50;

$onde = $aba === 'minhas' ? ["a.para_tipo = 'admin'"] : ["a.para_tipo = 'motoboy'"];
$par = [];
if ($tipo) { $onde[] = "a.tipo IN (" . implode(',', array_fill(0, count($tipos[$tipo][1]), '?')) . ")"; array_push($par, ...$tipos[$tipo][1]); }
if ($dia) { $onde[] = "DATE(a.criado_em) = ?"; $par[] = $dia; }
if ($busca !== '') { $onde[] = "(a.titulo LIKE ? OR a.texto LIKE ? OR u.nome LIKE ?)"; array_push($par, "%$busca%", "%$busca%", "%$busca%"); }
$where = implode(' AND ', $onde);

$s = db()->prepare("SELECT COUNT(*) FROM avisos a LEFT JOIN usuarios u ON u.id = a.para_id WHERE $where");
$s->execute($par);
$total = (int)$s->fetchColumn();
$s = db()->prepare("SELECT a.*, u.nome para_nome FROM avisos a LEFT JOIN usuarios u ON u.id = a.para_id WHERE $where ORDER BY a.id DESC LIMIT $porPag OFFSET " . (($pag - 1) * $porPag));
$s->execute($par);
$lista = $s->fetchAll();

// ao abrir "Para mim", tudo fica como lido (as novas continuam destacadas nesta visita)
$lidoAntes = (int)cfg('avisos_lidos_' . $u['id'], 0);
if ($aba === 'minhas') {
    $max = (int)db()->query("SELECT COALESCE(MAX(id), 0) FROM avisos WHERE para_tipo = 'admin'")->fetchColumn();
    if ($max > $lidoAntes) cfg_salvar('avisos_lidos_' . $u['id'], (string)$max);
}

function quando(string $d): string {
    $min = (int)round((time() - strtotime($d)) / 60);
    if ($min < 1) return 'agora';
    if ($min < 60) return "há $min min";
    if (date('Y-m-d', strtotime($d)) === date('Y-m-d')) return 'hoje ' . date('H:i', strtotime($d));
    if (date('Y-m-d', strtotime($d)) === date('Y-m-d', strtotime('-1 day'))) return 'ontem ' . date('H:i', strtotime($d));
    return date('d/m H:i', strtotime($d));
}
$url = fn($extra) => 'notificacoes.php?' . http_build_query(array_filter(array_merge(['aba' => $aba, 'tipo' => $tipo, 'dia' => $dia, 'q' => $busca], $extra), fn($v) => $v !== '' && $v !== null));

topo('Notificações', 'notificacoes');
?>
<link rel="stylesheet" href="assets/sacas.css?v=25">
<div class="cabecalho-rota">
  <div>
    <h1>Notificações</h1>
    <p class="numeros"><b><?= $total ?></b> <?= $aba === 'minhas' ? 'para você' : 'enviadas aos motoboys' ?><?= $tipo || $dia || $busca ? ' (com filtro)' : '' ?></p>
  </div>
  <div class="cartao aviso-windows">
    <b>🔔 No Windows:</b> <span id="estado-windows">…</span>
    <button type="button" class="btn pequeno" onclick="pedirNotificacao()">Ligar</button>
  </div>
</div>

<div class="abas-modo">
  <a class="<?= $aba === 'minhas' ? 'ativo' : '' ?>" href="notificacoes.php">Para mim</a>
  <a class="<?= $aba === 'motoboys' ? 'ativo' : '' ?>" href="notificacoes.php?aba=motoboys">Enviadas aos motoboys</a>
</div>

<form class="filtro-notif">
  <input type="hidden" name="aba" value="<?= e($aba) ?>">
  <select name="tipo" onchange="this.form.submit()">
    <option value="">Todos os tipos</option>
    <?php foreach ($tipos as $k => [$rot]): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?>
  </select>
  <input type="date" name="dia" value="<?= e($dia) ?>" onchange="this.form.submit()">
  <input type="search" name="q" value="<?= e($busca) ?>" placeholder="<?= $aba === 'motoboys' ? 'Buscar texto ou motoboy' : 'Buscar texto (ex.: nome do motoboy)' ?>">
  <button class="btn">Filtrar</button>
  <?php if ($tipo || $dia || $busca): ?><a class="btn" href="notificacoes.php<?= $aba === 'motoboys' ? '?aba=motoboys' : '' ?>">Limpar</a><?php endif; ?>
</form>

<div class="lista-notif">
<?php foreach ($lista as $a): $nova = $aba === 'minhas' && (int)$a['id'] > $lidoAntes; ?>
  <div class="notif<?= $nova ? ' nova' : '' ?><?= $a['prioridade'] === 'alta' ? ' alta' : '' ?>">
    <div class="notif-corpo">
      <b><?= e($a['titulo']) ?></b>
      <?php if ($a['texto']): ?><span><?= e($a['texto']) ?></span><?php endif; ?>
      <small><?= quando($a['criado_em']) ?><?= $aba === 'motoboys' ? ' · para ' . e($a['para_nome'] ?? '?') : '' ?><?= $nova ? ' · <b class="tag-nova">nova</b>' : '' ?></small>
    </div>
    <?php if ($a['link'] && $aba === 'minhas'): ?><a class="btn pequeno" href="<?= e($a['link']) ?>">Abrir</a><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$lista): ?><p class="vazio">Nenhuma notificação<?= $tipo || $dia || $busca ? ' com esse filtro' : '' ?>.</p><?php endif; ?>
</div>

<?php if ($total > $porPag): $paginas = (int)ceil($total / $porPag); ?>
<div class="paginas">
  <?php if ($pag > 1): ?><a class="btn pequeno" href="<?= e($url(['p' => $pag - 1])) ?>">‹ Mais novas</a><?php endif; ?>
  <span>Página <?= $pag ?> de <?= $paginas ?></span>
  <?php if ($pag < $paginas): ?><a class="btn pequeno" href="<?= e($url(['p' => $pag + 1])) ?>">Mais antigas ›</a><?php endif; ?>
</div>
<?php endif; ?>
<?php rodape();
