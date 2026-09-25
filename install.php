<?php
// Rode UMA vez: http://seusite/install.php  — depois APAGUE este arquivo.
require __DIR__ . '/config.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  login VARCHAR(50) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  tipo ENUM('admin','motoboy') NOT NULL DEFAULT 'motoboy',
  telefone VARCHAR(20) NULL,
  placa VARCHAR(10) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  ultima_localizacao DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rotas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  motoboy_id INT NOT NULL,
  data DATE NOT NULL,
  descricao VARCHAR(150) NULL,
  status ENUM('aberta','em_andamento','finalizada') NOT NULL DEFAULT 'aberta',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (data), INDEX (motoboy_id),
  FOREIGN KEY (motoboy_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paradas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rota_id INT NOT NULL,
  numero INT NOT NULL,
  endereco VARCHAR(200) NOT NULL,
  numero_casa VARCHAR(20) NULL,
  bairro VARCHAR(100) NULL,
  cidade VARCHAR(100) NULL,
  pacotes INT NOT NULL DEFAULT 1,
  observacao VARCHAR(255) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  status ENUM('pendente','entregue','falhou') NOT NULL DEFAULT 'pendente',
  motivo VARCHAR(255) NULL,
  finalizado_em DATETIME NULL,
  INDEX (rota_id, numero),
  FOREIGN KEY (rota_id) REFERENCES rotas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS localizacoes (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  motoboy_id INT NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (motoboy_id, criado_em),
  FOREIGN KEY (motoboy_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

try {
    db()->exec($sql);
    $existe = db()->query("SELECT COUNT(*) FROM usuarios WHERE tipo = 'admin'")->fetchColumn();
    if (!$existe) {
        db()->prepare("INSERT INTO usuarios (nome, login, senha_hash, tipo) VALUES (?, ?, ?, 'admin')")
            ->execute(['Administrador', 'admin', password_hash('admin123', PASSWORD_DEFAULT)]);
        echo "<p>Tabelas criadas. Admin: <b>admin</b> / senha <b>admin123</b> — troque em <i>Minha senha</i> depois de entrar.</p>";
    } else {
        echo "<p>Tabelas verificadas. Já existe administrador.</p>";
    }
    echo "<p><b>Apague o arquivo install.php agora.</b> <a href='index.php'>Ir para o login</a></p>";
} catch (Throwable $ex) {
    echo "<p>Erro: " . e($ex->getMessage()) . "</p><p>Confira os dados do banco em config.php.</p>";
}
