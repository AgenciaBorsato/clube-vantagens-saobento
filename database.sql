-- ============================================================
-- DROGARIA SAO BENTO - CLUBE DE VANTAGENS v2
-- Script de criacao do banco de dados (PostgreSQL)
-- ============================================================

CREATE TABLE IF NOT EXISTS configuracoes (
  id SERIAL PRIMARY KEY,
  chave VARCHAR(100) NOT NULL UNIQUE,
  valor TEXT NOT NULL,
  atualizado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS cashback_mensal (
  id SERIAL PRIMARY KEY,
  ano INT NOT NULL,
  mes INT NOT NULL,
  percentual DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  criado_em TIMESTAMP DEFAULT NOW(),
  UNIQUE (ano, mes)
);

CREATE TABLE IF NOT EXISTS clientes (
  id SERIAL PRIMARY KEY,
  nome VARCHAR(255) NOT NULL,
  cpf VARCHAR(11) NOT NULL UNIQUE,
  telefone VARCHAR(11) NOT NULL UNIQUE,
  data_cadastro TIMESTAMP DEFAULT NOW(),
  ativo BOOLEAN DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_clientes_telefone ON clientes(telefone);
CREATE INDEX IF NOT EXISTS idx_clientes_cpf ON clientes(cpf);

CREATE TABLE IF NOT EXISTS compras (
  id SERIAL PRIMARY KEY,
  cliente_id INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
  valor DECIMAL(10,2) NOT NULL,
  cashback_percentual DECIMAL(5,2) NOT NULL,
  cashback_valor DECIMAL(10,2) NOT NULL,
  data_compra TIMESTAMP DEFAULT NOW(),
  estornada BOOLEAN DEFAULT FALSE,
  data_estorno TIMESTAMP NULL DEFAULT NULL,
  motivo_estorno VARCHAR(255) NULL DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_compras_cliente ON compras(cliente_id);
CREATE INDEX IF NOT EXISTS idx_compras_data ON compras(data_compra);

CREATE TABLE IF NOT EXISTS resgates (
  id SERIAL PRIMARY KEY,
  cliente_id INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
  valor DECIMAL(10,2) NOT NULL,
  data_resgate TIMESTAMP DEFAULT NOW(),
  estornado BOOLEAN DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_resgates_cliente ON resgates(cliente_id);

CREATE TABLE IF NOT EXISTS login_tentativas (
  id SERIAL PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  tentativa_em TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_ip ON login_tentativas(ip);
CREATE INDEX IF NOT EXISTS idx_login_tempo ON login_tentativas(tentativa_em);
