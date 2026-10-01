# CLAUDE.md — NetPoint Rotas Motoboy

Leia este arquivo inteiro antes de mexer em qualquer coisa. Ele resume o projeto, como o dono gosta de trabalhar,
as regras de negócio, onde fica cada coisa e como testar e publicar.

> ⚠️ Este repositório é **público**. Nunca coloque aqui (nem em commit) senha, token, chave de API, chave da conta
> de serviço do Firebase, `config.local.php` ou o keystore do app. Peça ao dono quando precisar de algum deles.

---

## 1. Como trabalhar com o dono (Pablo)

- Fala **português do Brasil**. Responder em pt-BR, **direto e prático**, em tópicos, sem repetir informação.
- **Só alterar o sistema quando ele pedir.** Não mudar nada por iniciativa própria. Se achar um problema ou uma
  melhoria, **avise e pergunte**; não implemente sem ele pedir. (Já aconteceu de mudar algo sem pedido e ele pedir para desfazer.)
- Quando o pedido for ambíguo, diga como entendeu e siga; ele corrige se for outra coisa.
- Ele manda muitos **prints da tela** (foto do monitor ou do celular) para mostrar o problema.
- Ele usa o sistema em produção com motoboys reais: cuidado com mudanças que afetem rotas em andamento.
- Depois de cada entrega, sempre passar o comando de atualização da VPS (seção 6).

## 2. O que é o sistema

Sistema web em **PHP + MySQL/MariaDB** (sem framework) para entregas de motoboys de um CD do Mercado Livre
(região Cajuru/Curitiba e Pinhais-PR):

1. Admin importa a lista do dia (.txt) → o sistema acha cada endereço no mapa (só nos bairros atendidos).
2. **Distribuição** por **quadrantes** (19 zonas fixas + as criadas) → cria as rotas e as **caixas** de coleta.
3. Motoboy usa o **app Android** (WebView + GPS em segundo plano + Firebase): chega no CD, pega as caixas, entrega,
   tira foto (**pacote voador**), pede socorro.
4. Admin acompanha **ao vivo** (painel e TV), resolve imprevistos (**ambulância**), recebe **alertas** e paga a
   **quinzena por pacote entregue**.

Existe um manual completo para o usuário final (PDF "NetPoint-Rotas-Manual-do-Sistema", gerado fora do repo).

## 3. Infraestrutura

| Item | Valor |
|---|---|
| Sistema | `https://165.227.199.201` (HTTPS Let's Encrypt para IP, renovação automática) |
| VPS | DigitalOcean, Ubuntu 24.04, 512 MB RAM + swap, PHP 8.3-FPM, MariaDB, Nginx |
| Pasta | `/var/www/motoboy` (dono `www-data`) |
| Banco | `rotas_motoboy` — credenciais em `config.local.php` (fora do Git) |
| Fotos | `dados/comprovantes/` (fora do Git) |
| App Android | `releases/latest/download/NetPoint-Rotas.apk` deste repositório |
| Cron | `* * * * * php /var/www/motoboy/cron.php` (alertas por horário) |

`config.local.php` (modelo, sem a senha real):
```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'rotas_motoboy');
define('DB_USER', 'motoboy');
define('DB_PASS', '***');
```

## 4. Mapa dos arquivos

| Arquivo | Função |
|---|---|
| `config.php` | Conexão, sessão (14 h), helpers (`e()`, `cfg()`, `cfg_salvar()`, `flash()`, `csrf_*`, `data_br`, `hora_br`, `dinheiro`), `topo()`/`rodape()` (menu, sino, faixa de SOS, balões de aviso do admin) |
| `sacas.php` | Leitura de planilha/lista, caixas, `PALETA` de cores |
| `logistica.php` | **Núcleo**: geocodificação, bairros atendidos, quadrantes, distribuição, divisão, máximo/equilíbrio, vizinhos, criar rotas, ambulância, avisos/Firebase, alertas, aprendizado de locais, migrações do banco |
| `api.php` | Endpoints JSON (`acao=`): localização, status de parada, coleta de caixa, CD, pacote voador, socorro, avisos, Firebase, CEP, mover entrega, salvar locais etc. |
| `index.php` / `logout.php` / `senha.php` | Entrada, saída, troca de senha |
| `admin.php` | Painel ao vivo (mapa, cartões, totais) |
| `rotas.php` | Rotas do dia + lista "Entregas do dia" (busca, filtros, passar sem motoboy, Corrigir) |
| `rota.php` | Tela da rota (paradas, mapa arrastável, ambulância com sugestão, fotos) |
| `importar_entregas.php` / `processar.php` | Envio do .txt e tela "Localizando" (pausar/cancelar envio) |
| `distribuir.php` | **Distribuição** (cards, acima do máximo, mapa com seleção, pendentes/salvar) |
| `caixas.php` | Caixas do dia (impressão) |
| `quadrantes.php` | Zonas (criar, desenhar, KML/ZIP, bairros atendidos) |
| `corrigir_local.php` | Corrigir local por CEP + mapa (aprende) |
| `motoboys.php` | Cadastro (valor por pacote, máximo, mínimo, telefone) |
| `financeiro.php` | Quinzena por pacote, pagar com ajuste, planilha |
| `notificacoes.php` | Histórico de notificações |
| `cd.php` | Posição do CD + configurações (Google key, horário do CD, Firebase, CARTO) |
| `tv.php` | TV sem login (chave na URL) |
| `motoboy.php` | **App do motoboy** (fases, entrega, pacote voador, quinzena, SOS, ambulância, avisos ao vivo, GPS) |
| `exportar_spoke.php` | Planilha CSV para o Spoke (Circuit) |
| `foto.php` | Serve as fotos (autenticado) |
| `cron.php` | Alertas por horário (CLI) |
| `install.php` | Cria as tabelas iniciais (apagar depois de usar) |
| `quadrantes_fixos.json` | As 19 zonas fixas |
| `assets/style.css` · `sacas.css` · `mapa.css` · `mapa.js` | Estilos e peças comuns dos mapas (`NP.quadrantes`, `NP.pino`) |
| `android/` | App Android (Java): `MainActivity`, `NetPointService` (GPS + avisos), `NetPointFcmService` (Firebase) |
| `.github/workflows/apk.yml` | Compila, assina e publica o APK a cada push em `android/**` |

## 5. Convenções do código (importante)

- **Migrações do banco**: funções `garantir_schema_vN()` no fim de `logistica.php`, cada uma com um arquivo-flag
  `.schema_vN` na raiz. A última é **v17** → a próxima é **v18**. Nunca alterar uma migração antiga; criar outra.
  Use `IF NOT EXISTS` / `SHOW COLUMNS` para ser idempotente.
- **Cache de CSS/JS**: ao mudar um CSS/JS, subir a versão no `?v=` das páginas que o carregam
  (atual: `sacas.css?v=40`, `style.css?v=8`, `mapa.css?v=2`, `mapa.js?v=2`).
- Todo POST/ação da API exige **CSRF** (`X-CSRF` ou campo `csrf`). Páginas do admin começam com `exigir('admin')`.
- Saída HTML sempre com `e()`. SQL sempre com prepared statements.
- Fuso: `America/Sao_Paulo` no PHP e `-03:00` na sessão do MySQL.
- `avisar($paraTipo, $paraId, $tipo, $titulo, $texto, $link, $prioridade)` cria a notificação e envia pelo Firebase.
- Mapas: Leaflet 1.9.4 via cdnjs; bolinhas com `NP.pino()`, quadrantes com `NP.quadrantes()`.
- O app Android carrega o sistema numa WebView e expõe `window.NetPointApp` (`iniciarRastreio`, `pararRastreio`,
  `ligarAvisos`, `desligarAvisos`, `temFcm`, `tokenFcm`). O `User-Agent` contém `NetPointApp/1.0.N`.
- **Nunca** acordar o serviço Android com `startForegroundService` só para pará-lo (o Android fecha o app);
  para parar, usar `stopService` (já corrigido no 1.0.6).

## 6. Publicar e atualizar

1. Commit e push na `main`.
2. **VPS** (o dono roda):
   ```bash
   cd /var/www/motoboy && git pull && chown -R www-data:www-data /var/www/motoboy
   ```
   O banco se atualiza sozinho na primeira página aberta depois do pull. No navegador, Ctrl+F5.
3. **App**: qualquer mudança em `android/**` dispara o GitHub Actions, que gera `NetPoint Rotas 1.0.<run>`
   e publica na release `latest`. Instalar por cima no celular. Segredos do Actions (não estão no código):
   `ANDROID_KEYSTORE_B64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`, `GOOGLE_SERVICES_JSON`.
4. Para o Claude dar push, o dono gera um **token fine-grained só deste repositório** e passa na conversa.
   Nunca grave o token em arquivo nem no histórico do Git.

## 7. Como testar antes de publicar

Não há testes automatizados. O jeito usado até aqui:
- `php -l` em todo arquivo alterado.
- Subir um ambiente local: MariaDB + `php -S 127.0.0.1:8099`, rodar `install.php`, criar motoboys e importar uma
  lista de exemplo; testar fluxos com `curl` (login com cookie, CSRF tirado da página) e consultas no banco.
- Telas: Playwright (Chromium) com o Leaflet servido localmente (sem internet no ambiente de teste).
- Serviços externos (Nominatim, ViaCEP, Google, Firebase) **simulados** sobrescrevendo `http_json`/`http_post`
  numa cópia de teste do `logistica.php`.
- Java do app: conferir chaves balanceadas e deixar o GitHub Actions compilar; olhar o resultado do workflow.

## 8. Regras de negócio (resumo)

### Endereços
- Busca só numa área em volta dos quadrantes (~1 km) e só aceita resultado em **bairro atendido** + **cidade exata**.
  Lista padrão (editável em Quadrantes → Bairros atendidos, guardada em `cfg('bairros_atendidos')`):
  Curitiba: Cajuru, Capão da Imbuia, Tarumã, Cristo Rei, Jardim Botânico, Alto da Rua XV ·
  Pinhais: Weissópolis, Vargem Grande, Guarituba, Maria Antonieta, Planta Guilherme Weiss ·
  Piraquara: Guarituba, Maria Antonieta, Vargem Grande.
- Status: `ok` · `fora_bairro` (achado só em outro bairro; guarda o ponto) · `nao_achado`.
- Tudo fica no `geocache` (chave rua+número sem acento). Correções manuais (`fonte='manual'`) valem para sempre
  (`aprender_local()`). Mudar os bairros atendidos **apaga o cache inteiro**.
- Google Geocoding opcional (`cfg('google_key')`); senão Nominatim (1 req/s).

### Distribuição (`distribuir.php` + `logistica.php`)
1. Entrega → quadrante: dentro; sobreposição = mais "para dentro"; fresta ≤150 m = o mais perto; senão "fora".
   Não achada segue as de número vizinho.
2. Cards começam **todos em "Não distribuir"**. Vários quadrantes de um motoboy somam; o maior é o principal.
3. Vários motoboys no mesmo quadrante: partes seguidas pelo número, pacotes iguais, cortando nas caixas
   (opção "partir caixa").
4. **Acima do máximo**: pergunta obrigatória — manter, ou passar o excesso para um **quadrante vizinho**
   (divisa ≤200 m; se sem motoboy, escolhe ali). Passa as entregas mais perto da divisa. "Confirmar escolha".
5. Equilíbrio **só pelo máximo** (a regra do mínimo foi **removida** a pedido; o mínimo só aparece como aviso).
6. Escolhas no mapa por cima de tudo: "Passar para" numa bolinha, **Selecionar várias** (toque/retângulo, com aviso
   de mínimo/máximo). Arrastar, CEP, passar e aceitar ficam **pendentes** até "Salvar e recalcular".
7. Criar rotas: ordem pelo número; caixa = dezena; guarda o valor por pacote; notifica os motoboys.

### Motoboy / app
- GPS **só** entre "Cheguei no CD" e o fim da rota (mais ambulância).
- Pode escolher a entrega (`motoboy.php?p=ID`). Entregue com >1 pacote pergunta quantos (resto = recusado; 0 = não entregue).
- Pacote voador com várias fotos (1ª marca entregue e avisa o admin).

### Financeiro
- Paga **por pacote entregue** (`COALESCE(pacotes_entregues, pacotes)`), valor por pacote do dia da rota.
- Quinzenas 1–15 e 16–fim; marcar pago avisa o motoboy e zera o card da quinzena no app.

### Ambulância e alertas
- Ambulância passa as pendentes ao socorrista (sugestão por proximidade do fim da rota/GPS e pendências); recusável com motivo.
- Alertas: sem GPS >15 min em rota · não chegou no CD até o horário · 3 "não entregue" seguidas · 5 entregas em 3 min ·
  pacote voador · rota concluída · SOS. Sem repetir (`alertas_enviados`).

## 9. Banco (principais tabelas)

`usuarios` (admin/motoboy, lat/lng, `pacotes_min`, `pacotes_max`, `valor_entrega` = valor por pacote) ·
`rotas` (data, motoboy, cor, `chegada_cd`, `saida_cd`, `valor_entrega`) ·
`paradas` (rota, numero, entrega, endereço, pacotes, lat/lng, status, `pacotes_entregues`, `pacotes_recusados`, `socorro_id`) ·
`sacas` (caixas por rota) · `entregas` (lista do dia, `geo_status`, bairro) · `quadrantes` · `geocache` ·
`configuracoes` · `comprovantes` (fotos) · `pagamentos` · `socorros` · `pedidos_socorro` · `avisos` ·
`dispositivos` (tokens Firebase) · `alertas_enviados` · `importacoes_backup` · `localizacoes`.

## 10. Pendências e ideias já conversadas (só fazer se ele pedir)

- Botão **Imprimir** na lista "Entregas do dia" (o que estiver filtrado + totais).
- Página só de **fotos** dos pacotes voadores (grade com filtros).
- Mudar bairros atendidos **sem apagar** as correções manuais do cache.
- **Nova parada** manual com número de entrega e a busca de endereço nova (hoje usa a antiga, com SJP como padrão).
- Tirar o campo "motoboy padrão" dos quadrantes e o "mínimo" do cadastro (não são mais usados na distribuição).
- "Corrigir" avisar quando a entrega muda de quadrante depois das rotas criadas.
- Atualizar o link de download do app se o repositório mudar de dono.
- Observação registrada (sem mudar): Guarituba é bairro de **Piraquara**; ele preferiu manter também em Pinhais.
