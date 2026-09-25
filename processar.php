<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');
$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
topo('Montando rotas', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=9">
<h1>Localizando as entregas de <?= data_br($data) ?></h1>
<div class="cartao processo">
  <p id="etapa">Localizando endereços no mapa…</p>
  <div class="progresso grosso"><span id="barra" style="width:0%"></span></div>
  <p class="dica" id="detalhe">Deixe esta página aberta. Endereços que já apareceram em outros dias são instantâneos.</p>
  <div id="fim" hidden>
    <p id="resumo"></p>
    <a class="btn primario grande" href="distribuir.php?data=<?= e($data) ?>">Distribuir para os motoboys</a>
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
  const inicio = Date.now(); let base = null, j;
  while (true) {
    try { j = await chamar({ acao: 'geocodificar_lote', data: DATA }); }
    catch (e) { document.getElementById('detalhe').textContent = 'Falhou (' + e.message + '). Tentando de novo…'; await new Promise(r => setTimeout(r, 4000)); continue; }
    const feitos = j.total - j.pendentes;
    if (base === null) base = feitos;
    document.getElementById('barra').style.width = (j.total ? Math.round(feitos / j.total * 100) : 100) + '%';
    const vel = (feitos - base) / ((Date.now() - inicio) / 1000);
    const falta = vel > 0 ? Math.ceil(j.pendentes / vel / 60) : null;
    document.getElementById('detalhe').textContent = `${feitos} de ${j.total} endereços` + (falta ? ` · cerca de ${falta} min restantes` : '') + (j.sem_local ? ` · ${j.sem_local} não encontrados` : '');
    if (!j.pendentes) break;
  }
  document.getElementById('etapa').textContent = 'Endereços localizados.';
  document.getElementById('resumo').textContent = j.sem_local ? `${j.sem_local} endereços não foram achados no mapa; eles vão junto com as entregas de número vizinho.` : 'Todos os endereços foram achados.';
  document.getElementById('fim').hidden = false;
}
rodar();
</script>
<?php rodape();
