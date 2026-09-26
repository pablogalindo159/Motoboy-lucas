# NetPoint Rotas Motoboy

Sistema PHP + MySQL para lançar rotas de entrega e acompanhar os motoboys no mapa.

## Instalação
1. Suba a pasta para o servidor (PHP 7.4+ com PDO MySQL e `allow_url_fopen` ligado).
2. Crie o banco: `CREATE DATABASE rotas_motoboy CHARACTER SET utf8mb4;`
3. Ajuste usuário/senha do banco e o e-mail de contato em `config.php`.
4. Abra `https://seusite/install.php` uma vez. Login inicial: **admin / admin123**.
5. **Apague o `install.php`** e troque a senha em *Minha senha*.

## Uso
- **Motoboys**: cadastre nome, telefone, placa, login e senha.
- **Rotas**: escolha motoboy e dia, depois lance as paradas (nº, rua, número, bairro, pacotes) uma a uma ou colando uma lista `rua; número; bairro; pacotes`.
- **Painel**: mapa com a posição de cada motoboy (atualiza a cada 15 s), trajeto do dia, paradas coloridas (amarelo pendente, verde entregue, vermelho não entregue) e contagem de feitas/faltam.
- **Motoboy** (no celular): entra com o login dele e vê a próxima entrega, com botões para abrir no Google Maps ou Waze, rota completa das próximas 10 paradas e marcar Entregue / Não entregue.

## Importante
- **HTTPS é obrigatório** para o navegador liberar o GPS do celular.
- A localização é enviada enquanto a página do motoboy está aberta; o sistema pede para manter a tela acesa.
- Endereços viram coordenadas pelo Nominatim (OpenStreetMap, grátis). Se um ponto cair errado, arraste o marcador na tela da rota.

## Rotina do dia (automática)
1. **Posição do CD** (uma vez): marque o CD no mapa. As rotas começam dele.
2. **Quadrantes**: as 19 zonas fixas do Mercado Livre (Cajuru 01–09, Capão da Imbuia 01–02, Cristo Rei 01–03, Vargem Grande 01–02, Weissópolis 01–03) já vêm no sistema (`quadrantes_fixos.json`). Escolha o motoboy padrão de cada zona uma vez.
3. **Lista de entregas do dia**: envie o `.txt` (número, endereço, "N unidades"). O sistema localiza os endereços.
4. **Distribuir**: pelos quadrantes, ou "Dividir automático" (mesma quantidade de pacotes para quem trabalha no dia). Confira e toque em *Criar rotas*. A sequência de entrega de cada motoboy é sempre a do número da lista (.txt).
5. No celular, o motoboy toca **Cheguei no CD**, vê a primeira entrega e as caixas (caixa 0 = entregas 0–9, caixa 10 = 10–19…), marca cada uma e toca **Sair para as entregas**. Se uma caixa for dividida com outro motoboy, aparece quais entregas são dele.

**Tela da TV**: no Painel, abra "Tela da TV" e use o link no navegador da TV. Mostra o mapa ao vivo com todos os motoboys, entregas feitas e que faltam, sem precisar de login (somente leitura).

Endereços já localizados ficam guardados. Com uma chave do Google (Geocoding API) em *Posição do CD*, a localização fica mais rápida e precisa.

Na VPS: `apt install -y php-zip php-xml php-mbstring`. Dados do banco ficam em `config.local.php` (fora do Git).

## App Android
O GitHub gera o APK sozinho a cada mudança na pasta `android/` (Actions → "Gerar APK Android").
Link fixo da versão mais nova: https://github.com/pablogalindo159/Motoboy-lucas/releases/latest/download/NetPoint-Rotas.apk

O app abre o sistema em tela cheia e, enquanto o motoboy tem entregas pendentes, envia a localização mesmo com o Waze/Google Maps na frente (notificação fixa "Enviando sua localização").
Endereço do servidor: `android/app/src/main/res/values/strings.xml` (`url_servidor`).

## Financeiro
Menu **Financeiro** (só admin): entregas feitas por motoboy na quinzena (1–15 e 16–fim do mês), valor a pagar, ajuste (bônus/vale), marcar como pago, planilha e impressão.
O valor por entrega fica no cadastro do motoboy e é guardado em cada rota no dia em que ela é criada. Só conta o que foi marcado como Entregue.
