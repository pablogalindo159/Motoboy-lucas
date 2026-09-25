<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';

// Acesso: admin logado ou link da TV (?chave=...), somente leitura
$chave = (string)($_GET['chave'] ?? '');
$chaveTv = (string)cfg('tv_chave', '');
$porLink = $chaveTv !== '' && hash_equals($chaveTv, $chave);
if (!$porLink) exigir('admin');
$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TV · <?= APP_NOME ?></title>
<link rel="icon" href="assets/icone.svg" type="image/svg+xml">
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@500;600&family=Barlow+Semi+Condensed:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
  :root { --marca: #8CF20A; --fundo: #000; --painel: #111418; --linha: #262B31; --texto: #F2F4F5; --suave: #8C979E;
          --ok: #2FBF6A; --alerta: #FFC000; --erro: #FF5A4E;
          --fonte: 'Barlow', system-ui, sans-serif; --num: 'Barlow Semi Condensed', 'Arial Narrow', sans-serif; }
  * { box-sizing: border-box; }
  html, body { margin: 0; height: 100%; background: var(--fundo); color: var(--texto); font-family: var(--fonte); overflow: hidden; }
  .tv { display: grid; grid-template-rows: auto 1fr; height: 100vh; }
  header { display: flex; align-items: center; gap: 2.5vw; padding: 1.2vh 1.6vw; border-bottom: 2px solid var(--marca); }
  header img { height: 5.2vh; width: auto; }
  .totais { display: flex; gap: 2.4vw; flex: 1; justify-content: center; }
  .totais div { text-align: center; line-height: 1; }
  .totais b { display: block; font-family: var(--num); font-weight: 800; font-size: 4.6vh; color: var(--marca); }
  .totais span { font-size: 1.5vh; color: var(--suave); }
  .relogio { text-align: right; line-height: 1.05; }
  .relogio b { display: block; font-family: var(--num); font-size: 4.6vh; font-weight: 700; }
  .relogio span { font-size: 1.5vh; color: var(--suave); }
  .corpo { display: grid; grid-template-columns: 1fr 27vw; min-height: 0; }
  #mapa { height: 100%; background: #0b0d10; }
  aside { border-left: 1px solid var(--linha); background: var(--painel); display: flex; flex-direction: column; min-height: 0; }
  #lista { flex: 1; display: flex; flex-direction: column; min-height: 0; }
  .moto { flex: 1 1 0; min-height: 0; max-height: 13vh; display: grid; grid-template-columns: .55vw 1fr auto; column-gap: .9vw; align-items: center;
          padding: .6vh 1vw .6vh 0; border-bottom: 1px solid var(--linha); }
  .moto .cor { align-self: stretch; background: var(--cor); }
  .moto .nome { font-weight: 600; font-size: 2.1vh; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .moto .etapa { font-size: 1.45vh; color: var(--suave); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: .2vh; }
  .moto .etapa.alerta { color: var(--alerta); } .moto .etapa.erro { color: var(--erro); } .moto .etapa.ok { color: var(--ok); }
  .moto .barra { grid-column: 2 / 4; height: .7vh; background: #2A3036; border-radius: 99px; overflow: hidden; margin-top: .5vh; }
  .moto .barra span { display: block; height: 100%; background: var(--marca); }
  .moto .num { text-align: right; font-family: var(--num); line-height: 1; }
  .moto .num b { font-size: 3.6vh; font-weight: 800; }
  .moto .num small { font-size: 1.9vh; color: var(--suave); font-weight: 600; }
  .vazio { margin: auto; text-align: center; color: var(--suave); font-size: 2.4vh; padding: 2vh; }
  .rodape { display: flex; justify-content: space-between; padding: .8vh 1vw; font-size: 1.3vh; color: var(--suave); border-top: 1px solid var(--linha); }
  .rodape .off { color: var(--erro); font-weight: 600; }
  /* marcadores */
  .pin-moto { display: inline-flex; align-items: center; gap: .35em; padding: .25em .6em; border-radius: 99px; white-space: nowrap;
              font: 700 15px var(--fonte); background: var(--cor); color: var(--txt); border: 2px solid #000; box-shadow: 0 0 0 2px var(--cor), 0 4px 14px rgba(0,0,0,.6); transform: translate(-50%, -50%); }
  .pin-moto.parado { opacity: .55; }
  .pin-cd { display: grid; place-items: center; width: 42px; height: 28px; background: #000; color: var(--marca); border: 2px solid var(--marca); border-radius: 6px; font: 800 14px var(--num); transform: translate(-50%, -50%); }
  .leaflet-container { font-family: var(--fonte); }
  .leaflet-control-attribution { background: rgba(0,0,0,.6) !important; color: #777 !important; font-size: 10px; }
  .leaflet-control-attribution a { color: #999 !important; }
  .tela-cheia { position: fixed; left: 1vw; bottom: 1.5vh; z-index: 999; background: var(--marca); color: #000; border: 0; border-radius: 99px;
                padding: .8em 1.2em; font: 700 15px var(--fonte); cursor: pointer; }
</style>
</head>
<body>
<div class="tv">
  <header>
    <img src="assets/logo-horizontal.svg" alt="<?= APP_NOME ?>">
    <div class="totais">
      <div><b id="t-entregues">–</b><span>entregas feitas</span></div>
      <div><b id="t-faltam">–</b><span>faltam</span></div>
      <div><b id="t-pacotes">–</b><span>pacotes entregues</span></div>
      <div><b id="t-rua">–</b><span>motoboys na rua</span></div>
    </div>
    <div class="relogio"><b id="hora">--:--</b><span><?= data_br($data) ?></span></div>
  </header>
  <div class="corpo">
    <div id="mapa"></div>
    <aside>
      <div id="lista"><p class="vazio">Carregando…</p></div>
      <div class="rodape"><span id="atualizado"></span><span>atualiza a cada 15 s</span></div>
    </aside>
  </div>
</div>
<button class="tela-cheia" id="btn-cheia" onclick="document.documentElement.requestFullscreen?.(); this.remove()">Tela cheia</button>

<script>
const URL_DADOS = 'api.php?acao=painel&data=<?= e($data) ?>' + <?= json_encode($porLink ? '&chave=' . $chave : '') ?>;
const mapa = L.map('mapa', { zoomControl: false, preferCanvas: true, attributionControl: true }).setView([<?= MAPA_LAT ?>, <?= MAPA_LNG ?>], 12);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19, subdomains: 'abcd', attribution: '© OpenStreetMap © CARTO' }).addTo(mapa);
const camadas = L.layerGroup().addTo(mapa);
const reserva = ['#8CF20A', '#00B0F0', '#FF0066', '#FFC000', '#9B59FF', '#00C49A', '#FF6A00', '#1F5FA8'];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const txtSobre = hex => { const h = hex.replace('#', ''); const [r, g, b] = [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16)); return (0.299*r + 0.587*g + 0.114*b) > 150 ? '#000' : '#fff'; };
const hora = d => d ? d.substr(11, 5) : '';
let ultimoEnquadre = 0;

function etapa(m, faltam, total) {
  if (!total) return ['Sem entregas', ''];
  if (!faltam) return ['Rota concluída', 'ok'];
  const sinal = m.minutos_sem_sinal;
  if (m.sacas && !m.chegada_cd) return ['Ainda não chegou no CD', ''];
  if (m.sacas && !m.saida_cd) return [`No CD · caixas ${m.sacas_coletadas}/${m.sacas}`, 'alerta'];
  if (sinal === null) return ['Sem localização', 'erro'];
  if (sinal > 10) return [`Sem sinal há ${sinal} min`, 'erro'];
  return [null, ''];
}

async function carregar() {
  let d;
  try {
    const r = await fetch(URL_DADOS, { cache: 'no-store' });
    if (!r.ok) throw new Error(r.status);
    d = await r.json();
  } catch (e) {
    document.getElementById('atualizado').innerHTML = '<span class="off">Sem conexão · tentando de novo</span>';
    return;
  }
  camadas.clearLayers();
  const lim = [];
  let tEnt = 0, tFalta = 0, tPac = 0, tRua = 0;
  const lista = document.getElementById('lista');
  lista.innerHTML = '';

  if (d.cd) { L.marker(d.cd, { interactive: false, icon: L.divIcon({ className: '', html: '<div class="pin-cd">CD</div>', iconSize: [0, 0] }) }).addTo(camadas); lim.push(d.cd); }

  d.motoboys.forEach((m, i) => {
    const cor = m.cor || reserva[i % reserva.length];
    const total = m.paradas.length;
    const ent = m.paradas.filter(p => p.status === 'entregue').length;
    const falhou = m.paradas.filter(p => p.status === 'falhou').length;
    const faltam = total - ent - falhou;
    const prox = m.paradas.find(p => p.status === 'pendente');
    tEnt += ent; tFalta += faltam;
    tPac += m.paradas.filter(p => p.status === 'entregue').reduce((s, p) => s + +p.pacotes, 0);
    const naRua = m.saida_cd || (!m.sacas && ent + falhou > 0);
    if (naRua && faltam) tRua++;

    // entregas no mapa: pendentes na cor do motoboy, feitas apagadas
    m.paradas.forEach(p => {
      if (!p.lat) return;
      const ll = [+p.lat, +p.lng]; lim.push(ll);
      const feito = p.status !== 'pendente';
      L.circleMarker(ll, { radius: p === prox ? 7 : 3.5, weight: p === prox ? 3 : 0, color: '#fff',
        fillColor: feito ? (p.status === 'entregue' ? '#3a4a3a' : '#5a2a2a') : cor, fillOpacity: feito ? .7 : .95, interactive: false }).addTo(camadas);
    });
    if (m.trajeto.length > 1) L.polyline(m.trajeto, { color: cor, weight: 3, opacity: .55, interactive: false }).addTo(camadas);
    if (m.lat) {
      const ll = [+m.lat, +m.lng]; lim.push(ll);
      const primeiro = esc(m.nome.split(' ')[0]);
      L.marker(ll, { zIndexOffset: 1000, interactive: false, icon: L.divIcon({ className: '', iconSize: [0, 0],
        html: `<div class="pin-moto ${m.minutos_sem_sinal > 10 ? 'parado' : ''}" style="--cor:${cor};--txt:${txtSobre(cor)}">🛵 ${primeiro} · ${ent}/${total}</div>` }) }).addTo(camadas);
    }

    // placar lateral
    const [msg, cls] = etapa(m, faltam, total);
    const linha = msg ?? (prox ? `Próxima: ${prox.entrega ?? prox.numero} · ${prox.endereco}, ${prox.numero_casa ?? ''}` : '');
    const pct = total ? Math.round((ent + falhou) / total * 100) : 0;
    const el = document.createElement('div');
    el.className = 'moto'; el.style.setProperty('--cor', cor);
    el.innerHTML = `<div class="cor"></div>
      <div style="min-width:0"><div class="nome">${esc(m.nome)}</div><div class="etapa ${cls}">${esc(linha)}</div></div>
      <div class="num"><b>${ent}</b><small>/${total}</small></div>
      <div></div><div class="barra"><span style="width:${pct}%"></span></div>`;
    lista.appendChild(el);
  });
  if (!d.motoboys.length) lista.innerHTML = '<p class="vazio">Nenhuma rota neste dia ainda.</p>';

  document.getElementById('t-entregues').textContent = tEnt;
  document.getElementById('t-faltam').textContent = tFalta;
  document.getElementById('t-pacotes').textContent = tPac;
  document.getElementById('t-rua').textContent = tRua;
  document.getElementById('atualizado').textContent = 'Atualizado às ' + d.atualizado;
  // reenquadra no começo e a cada 10 minutos
  if (lim.length && Date.now() - ultimoEnquadre > 600000) { mapa.fitBounds(lim, { padding: [40, 40], maxZoom: 15 }); ultimoEnquadre = Date.now(); }
}

function relogio() { document.getElementById('hora').textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }); }
relogio(); setInterval(relogio, 10000);
carregar(); setInterval(carregar, 15000);
// mantém a tela ligada e recarrega a página de madrugada para não acumular memória
(async () => { try { await navigator.wakeLock?.request('screen'); } catch (e) {} })();
document.addEventListener('visibilitychange', async () => { if (document.visibilityState === 'visible') try { await navigator.wakeLock?.request('screen'); } catch (e) {} });
setInterval(() => { if (new Date().getHours() === 4) location.reload(); }, 3600000);
<?php if (!isset($_GET['data'])): ?>
// virou o dia: passa a mostrar o dia novo
const hoje = <?= json_encode(date('Y-m-d')) ?>;
setInterval(() => { const d = new Date(); const s = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); if (s !== hoje) location.reload(); }, 60000);
<?php endif; ?>
</script>
</body>
</html>
