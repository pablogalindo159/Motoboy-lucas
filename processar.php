<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');
$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
$s = db()->prepare("SELECT JSON_LENGTH(dados) FROM importacoes_backup WHERE data = ?");
$s->execute([$data]);
$anteriores = (int)$s->fetchColumn();
topo('Montando rotas', 'rotas');
?>
<link rel="stylesheet" href="assets/sacas.css?v=21">
<h1>Localizando as entregas de <?= data_br($data) ?></h1>
<div class="cartao processo">
  <p id="etapa">Localizando endereços no mapa…</p>
  <div class="progresso grosso"><span id="barra" style="width:0%"></span></div>
  <p class="dica" id="detalhe">Deixe esta página aberta. Endereços que já apareceram em outros dias são instantâneos.</p>
  <div class="controles-envio" id="controles">
    <button type="button" class="btn" id="btn-pausar" onclick="pausar()">Pausar</button>
    <button type="button" class="btn perigo" id="btn-cancelar" onclick="cancelarEnvio()">Cancelar envio</button>
  </div>
  <div id="fim" hidden>
    <p id="resumo"></p>
    <a class="btn primario grande" href="distribuir.php?data=<?= e($data) ?>">Distribuir para os motoboys</a>
  </div>
</div>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>, DATA = <?= json_encode($data) ?>, ANTERIORES = <?= $anteriores ?>;
let pausado = false, cancelado = false, controle = null, rodando = false;

async function chamar(dados) {
  controle = new AbortController();
  const fd = new FormData(); Object.entries(dados).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('api.php', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF }, signal: controle.signal });
  const j = await r.json(); if (!r.ok) throw new Error(j.erro || 'erro'); return j;
}

function pausar() {
  const b = document.getElementById('btn-pausar');
  if (!pausado) {
    pausado = true; if (controle) controle.abort();
    b.textContent = 'Continuar'; b.classList.add('primario');
    document.getElementById('etapa').textContent = 'Pausado. Toque em Continuar para seguir de onde parou.';
  } else {
    pausado = false; b.textContent = 'Pausar'; b.classList.remove('primario');
    document.getElementById('etapa').textContent = 'Localizando endereços no mapa…';
    rodar();
  }
}

async function cancelarEnvio() {
  const msg = ANTERIORES
    ? `Cancelar este envio? A lista anterior deste dia (${ANTERIORES} entregas) volta a valer.`
    : 'Cancelar este envio? As entregas enviadas agora serão apagadas e o dia fica sem lista.';
  if (!confirm(msg + '\n\nAs rotas já criadas não mudam.')) return;
  cancelado = true; if (controle) controle.abort();
  document.querySelectorAll('#controles button').forEach(b => b.disabled = true);
  document.getElementById('etapa').textContent = 'Cancelando…';
  try { await chamar({ acao: 'cancelar_importacao', data: DATA }); location.href = 'importar_entregas.php?data=' + DATA; }
  catch (e) { alert('Não foi possível cancelar. Tente de novo.'); cancelado = false; document.querySelectorAll('#controles button').forEach(b => b.disabled = false); }
}

async function rodar() {
  if (rodando) return; rodando = true;
  const inicio = Date.now(); let base = null, j = null;
  while (!pausado && !cancelado) {
    try { j = await chamar({ acao: 'geocodificar_lote', data: DATA }); }
    catch (e) {
      if (pausado || cancelado) break;
      document.getElementById('detalhe').textContent = 'Falhou (' + e.message + '). Tentando de novo…';
      await new Promise(r => setTimeout(r, 4000)); continue;
    }
    const feitos = j.total - j.pendentes;
    if (base === null) base = feitos;
    document.getElementById('barra').style.width = (j.total ? Math.round(feitos / j.total * 100) : 100) + '%';
    const vel = (feitos - base) / ((Date.now() - inicio) / 1000);
    const falta = vel > 0 ? Math.ceil(j.pendentes / vel / 60) : null;
    document.getElementById('detalhe').textContent = `${feitos} de ${j.total} endereços` + (falta ? ` · cerca de ${falta} min restantes` : '') + (j.fora_bairro ? ` · ${j.fora_bairro} fora dos bairros` : '') + (j.sem_local ? ` · ${j.sem_local} não encontrados` : '');
    if (!j.pendentes) break;
  }
  rodando = false;
  if (pausado || cancelado || !j || j.pendentes) return;
  document.getElementById('etapa').textContent = 'Endereços localizados.';
  document.getElementById('btn-pausar').hidden = true;
  const partes = [];
  if (j.fora_bairro) partes.push(`⚠ ${j.fora_bairro} entregas ficaram FORA dos bairros atendidos (a rua só existe em outro bairro). Elas não serão distribuídas; veja quais são na tela de distribuição.`);
  if (j.sem_local) partes.push(`${j.sem_local} endereços não foram achados no mapa; eles vão junto com as entregas de número vizinho.`);
  const resumo = document.getElementById('resumo');
  resumo.textContent = partes.join(' ') || 'Todos os endereços foram achados nos bairros atendidos.';
  if (j.fora_bairro) resumo.className = 'txt-erro';
  document.getElementById('fim').hidden = false;
}
rodar();
</script>
<?php rodape();
