<?php
// Sacas (coleta no depósito) + leitura da planilha de controle (.xlsx)
// Incluído depois do config.php. Cria as tabelas novas sozinho na primeira vez.

function garantir_schema_sacas(): void {
    $flag = __DIR__ . '/.schema_sacas_v1';
    if (file_exists($flag)) return;
    db()->exec("CREATE TABLE IF NOT EXISTS sacas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rota_id INT NOT NULL,
        caixa INT NOT NULL,
        quantidade INT NOT NULL DEFAULT 0,
        coletada TINYINT(1) NOT NULL DEFAULT 0,
        coletada_em DATETIME NULL,
        INDEX (rota_id, caixa),
        FOREIGN KEY (rota_id) REFERENCES rotas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!db()->query("SHOW COLUMNS FROM rotas LIKE 'cor'")->fetch()) {
        db()->exec("ALTER TABLE rotas ADD COLUMN cor VARCHAR(7) NULL");
    }
    @touch($flag);
}
garantir_schema_sacas();

// Cor do texto (preto ou branco) que dá leitura em cima de uma cor de fundo
function texto_sobre(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#1B2B34';
    [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#1B2B34' : '#FFFFFF';
}

function sem_acento(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
}

// "105 cj1 tay ursão lindao" -> total 105, rota CJ1, nome "Tay Ursão Lindao"
function interpretar_rotulo(string $rotulo): array {
    $total = null; $rotas = []; $nome = [];
    foreach (preg_split('/\s+/', trim(mb_strtolower($rotulo, 'UTF-8'))) as $t) {
        if ($t === '') continue;
        if (ctype_digit($t)) $total = (int)$t;
        elseif (preg_match('/^[a-z]{1,4}\d+$/', $t) || str_contains($t, '+')) $rotas[] = mb_strtoupper($t, 'UTF-8');
        else $nome[] = $t;
    }
    return ['total' => $total, 'rota' => implode(' ', $rotas), 'nome' => mb_convert_case(implode(' ', $nome), MB_CASE_TITLE, 'UTF-8')];
}

// Aplica o "tint" do Excel (clarear/escurecer) em uma cor
function aplicar_tint(string $hex, float $tint): string {
    [$r, $g, $b] = array_map(fn($x) => hexdec($x) / 255, str_split($hex, 2));
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $l = ($max + $min) / 2; $h = $s = 0;
    if ($max !== $min) {
        $d = $max - $min;
        $s = $l > .5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r) $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
        elseif ($max === $g) $h = ($b - $r) / $d + 2;
        else $h = ($r - $g) / $d + 4;
        $h /= 6;
    }
    $l = $tint < 0 ? $l * (1 + $tint) : $l * (1 - $tint) + $tint;
    $hue = function ($p, $q, $t) {
        if ($t < 0) $t += 1; if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    };
    if ($s == 0) { $r = $g = $b = $l; }
    else {
        $q = $l < .5 ? $l * (1 + $s) : $l + $s - $l * $s; $p = 2 * $l - $q;
        $r = $hue($p, $q, $h + 1/3); $g = $hue($p, $q, $h); $b = $hue($p, $q, $h - 1/3);
    }
    return sprintf('%02X%02X%02X', round($r * 255), round($g * 255), round($b * 255));
}

/**
 * Lê a planilha de controle: coluna A = caixa (saca), B = quantidade, C = nome/rota.
 * Agrupa as sacas pela cor de fundo da linha (cada cor = um motoboy).
 */
function ler_planilha_sacas(string $arquivo): array {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        throw new RuntimeException('Falta extensão no PHP. Na VPS rode: apt install -y php-zip php-xml && systemctl restart php8.3-fpm');
    }
    $zip = new ZipArchive();
    if ($zip->open($arquivo) !== true) throw new RuntimeException('Não foi possível abrir o arquivo. Envie a planilha no formato .xlsx.');

    // textos
    $textos = [];
    if ($x = $zip->getFromName('xl/sharedStrings.xml')) {
        foreach (simplexml_load_string($x)->si as $si) {
            if (isset($si->t)) { $textos[] = (string)$si->t; continue; }
            $t = ''; foreach ($si->r as $r) $t .= (string)$r->t; $textos[] = $t;
        }
    }

    // cores do tema (ordem que o Excel usa: lt1, dk1, lt2, dk2, accent1..6)
    $tema = ['FFFFFF', '000000', 'E7E6E6', '44546A', '4472C4', 'ED7D31', 'A5A5A5', 'FFC000', '5B9BD5', '70AD47'];
    if ($x = $zip->getFromName('xl/theme/theme1.xml')) {
        $pos = ['lt1' => 0, 'dk1' => 1, 'lt2' => 2, 'dk2' => 3, 'accent1' => 4, 'accent2' => 5, 'accent3' => 6, 'accent4' => 7, 'accent5' => 8, 'accent6' => 9];
        foreach ($pos as $nome => $i) {
            if (preg_match('/<a:' . $nome . '>.*?(?:srgbClr val="([0-9A-Fa-f]{6})"|lastClr="([0-9A-Fa-f]{6})")/s', $x, $m)) {
                $tema[$i] = strtoupper($m[1] ?: $m[2]);
            }
        }
    }

    // estilos -> cor de preenchimento
    $corEstilo = [];
    if ($x = $zip->getFromName('xl/styles.xml')) {
        $st = simplexml_load_string($x);
        $cores = [];
        foreach ($st->fills->fill as $f) {
            $fg = $f->patternFill->fgColor ?? null;
            $tipo = (string)($f->patternFill['patternType'] ?? '');
            $hex = null;
            if ($fg && $tipo === 'solid') {
                if (isset($fg['rgb'])) $hex = substr((string)$fg['rgb'], -6);
                elseif (isset($fg['theme'])) $hex = aplicar_tint($tema[(int)$fg['theme']] ?? 'FFFFFF', (float)($fg['tint'] ?? 0));
            }
            $cores[] = $hex ? '#' . strtoupper($hex) : null;
        }
        foreach ($st->cellXfs->xf as $xf) $corEstilo[] = $cores[(int)$xf['fillId']] ?? null;
    }

    $x = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$x) throw new RuntimeException('A planilha está vazia ou em um formato diferente do esperado.');

    $linhas = []; $data = null;
    foreach (simplexml_load_string($x)->sheetData->row as $row) {
        $l = [];
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+/', '', (string)$c['r']);
            $t = (string)$c['t'];
            if ($t === 's') $v = $textos[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)$c->is->t;
            else $v = (string)$c->v;
            $l[$col] = ['v' => trim($v), 'cor' => $corEstilo[(int)$c['s']] ?? null];
        }
        // data no cabeçalho, ex.: "NOME   DATA: 23/09/2026"
        if (!$data && isset($l['C']) && preg_match('#(\d{2})/(\d{2})/(\d{4})#', $l['C']['v'], $m)) $data = "$m[3]-$m[2]-$m[1]";
        $linhas[] = $l;
    }

    $grupos = [];
    foreach ($linhas as $l) {
        $caixa = $l['A']['v'] ?? ''; $qtd = $l['B']['v'] ?? '';
        if (!is_numeric($caixa) || !is_numeric($qtd)) continue;
        $cor = $l['A']['cor'] ?? $l['B']['cor'] ?? null;
        $cor = $cor ?: '#FFFFFF';
        $g = &$grupos[$cor];
        $g['cor'] ??= $cor;
        $g['sacas'][] = ['caixa' => (int)$caixa, 'quantidade' => (int)$qtd];
        $rot = $l['C']['v'] ?? '';
        if ($rot !== '') $g['rotulos'][] = $rot;
        unset($g);
    }

    $saida = [];
    foreach ($grupos as $g) {
        $rotulo = implode(' / ', $g['rotulos'] ?? []);
        $info = interpretar_rotulo($rotulo);
        $soma = array_sum(array_column($g['sacas'], 'quantidade'));
        $saida[] = [
            'cor' => $g['cor'], 'rotulo' => $rotulo ?: 'Sem nome na planilha',
            'nome' => $info['nome'], 'rota' => $info['rota'], 'total_planilha' => $info['total'],
            'sacas' => $g['sacas'], 'pacotes' => $soma,
        ];
    }
    return ['data' => $data, 'grupos' => $saida];
}
