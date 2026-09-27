<?php
// Planilha para importar a rota no Spoke (antigo Circuit): entregas pendentes, na ordem.
// Colunas no formato do Spoke: Address Line 1 (obrigatória), Address Line 2, City, State, Zip, Country e Notes.
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
$u = usuario();
if (!$u) { http_response_code(401); exit('Entre no sistema.'); }

$rid = (int)($_GET['rota'] ?? 0);
$s = db()->prepare("SELECT r.*, u.nome FROM rotas r JOIN usuarios u ON u.id = r.motoboy_id WHERE r.id = ?");
$s->execute([$rid]);
$rota = $s->fetch();
if (!$rota || ($u['tipo'] !== 'admin' && (int)$rota['motoboy_id'] !== (int)$u['id'])) { http_response_code(404); exit('Rota não encontrada.'); }

$s = db()->prepare("SELECT * FROM paradas WHERE rota_id = ? AND status = 'pendente' ORDER BY numero, id");
$s->execute([$rid]);
$paradas = $s->fetchAll();

// cidade pelo bairro (lista de bairros atendidos)
function cidade_do_bairro(?string $bairro): string {
    if (!$bairro) return '';
    foreach (bairros_atendidos() as $cid => $bs) foreach ($bs as $b) if (_mesmo_lugar($b, $bairro)) return $cid;
    return '';
}

$nome = 'spoke-' . $rota['data'] . '-' . preg_replace('/[^a-z0-9]+/', '-', sem_acento($rota['nome'])) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nome . '"');
$o = fopen('php://output', 'w');
fwrite($o, "\xEF\xBB\xBF");
fputcsv($o, ['Address Line 1', 'Address Line 2', 'City', 'State', 'Zip', 'Country', 'Notes']);
foreach ($paradas as $p) {
    $num = $p['entrega'] ?: $p['numero'];
    $nota = "Entrega $num · " . (int)$p['pacotes'] . ((int)$p['pacotes'] > 1 ? ' pacotes' : ' pacote')
          . ($p['entrega'] !== null ? ' · caixa ' . intdiv((int)$p['entrega'], 10) * 10 : '')
          . ($p['observacao'] ? ' · ' . $p['observacao'] : '');
    fputcsv($o, [trim($p['endereco'] . ', ' . $p['numero_casa'], ', '), (string)$p['bairro'],
                 $p['cidade'] ?: cidade_do_bairro($p['bairro']), 'PR', '', 'Brasil', $nota]);
}
