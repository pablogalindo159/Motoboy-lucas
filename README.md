# Rotas Motoboy

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
