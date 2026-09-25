<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');
$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
topo('Montando rotas', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=2">
<h1>Montando as rotas de <?= data_br($data) ?></h1>
<div class="cartao processo">
  <?php if (!cd_posicao()): ?>
    <div class="aviso alerta">A posição do CD ainda não foi marcada, então as rotas começam pela primeira entrega. <a href="cd.php">Marcar o CD</a></div>
  <?php endif; ?>
  <p id="etapa">Localizando endereços no mapa…</p>
  <div class="progresso grosso"><span id="barra" style="width:0%"></span></div>
  <p class="dica" id="detalhe">Os motoboys já conseguem ver as caixas enquanto isso roda. Deixe esta página aberta até terminar.</p>
  <div id="fim" hidden>
    <p id="resumo"></p>
    <a class="btn primario" href="admin.php?data=<?= e($data) ?>">Abrir painel</a>
    <a class="btn" href="rotas.php?data=<?= e($data) ?>">Ver rotas</a>
  </div>
</div>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>, DATA = <?= json_encode($data) ?>;
async function chamar(dados) {
  const fd = new FormData(); Object.entries(dados).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } });
  const j = await r.json(); if (!r.ok) throw new Error(j.erro || 'erro'); return j;
}
async function rodar() {
  let inicio = Date.now(), feitosInicio = null;
  while (true) {
    let j;
    try { j = await chamar({ acao: 'geocodificar_lote', data: DATA }); }
    catch (e) { document.getElementById('detalhe').textContent = 'Falhou (' + e.message + '). Tentando de novo…'; await new Promise(r => setTimeout(r, 4000)); continue; }
    const pct = j.total ? Math.round((j.total - j.pendentes) / j.total * 100) : 100;
    document.getElementById('barra').style.width = pct + '%';
    if (feitosInicio === null) feitosInicio = j.total - j.pendentes;
    const feitos = j.total - j.pendentes - feitosInicio, seg = (Date.now() - inicio) / 1000;
    const falta = feitos > 0 ? Math.ceil(j.pendentes * seg / feitos / 60) : null;
    document.getElementById('detalhe').textContent = `${j.total - j.pendentes} de ${j.total} endereços` + (falta ? ` · cerca de ${falta} min restantes` : '') + (j.sem_local ? ` · ${j.sem_local} sem localização` : '');
    if (!j.pendentes) break;
  }
  document.getElementById('etapa').textContent = 'Montando a ordem de cada rota saindo do CD…';
  const o = await chamar({ acao: 'otimizar_dia', data: DATA });
  document.getElementById('etapa').textContent = 'Rotas prontas.';
  document.getElementById('detalhe').textContent = '';
  document.getElementById('resumo').innerHTML = `${o.rotas} rotas montadas com ${o.paradas} paradas.` + (o.sem_local ? ` ${o.sem_local} endereços não foram achados no mapa e entraram perto das entregas de número vizinho — dá para arrastar o ponto na tela da rota.` : '');
  document.getElementById('fim').hidden = false;
}
rodar();
</script>
<?php rodape();
