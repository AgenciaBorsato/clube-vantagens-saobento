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
CREATE INDEX IF NOT EXISTS idx_compras_cliente_data ON compras(cliente_id, data_compra);

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

-- Tabela de auditoria
CREATE TABLE IF NOT EXISTS auditoria (
  id SERIAL PRIMARY KEY,
  acao VARCHAR(100) NOT NULL,
  detalhes TEXT,
  entidade_tipo VARCHAR(50),
  entidade_id INT,
  ip VARCHAR(45),
  criado_em TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_auditoria_acao ON auditoria(acao);
CREATE INDEX IF NOT EXISTS idx_auditoria_entidade ON auditoria(entidade_tipo, entidade_id);
CREATE INDEX IF NOT EXISTS idx_auditoria_data ON auditoria(criado_em);

-- ===== FEATURE 4: Multi-usuario =====
CREATE TABLE IF NOT EXISTS usuarios (
  id SERIAL PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nome VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'operador' CHECK (role IN ('operador', 'gerente')),
  ativo BOOLEAN DEFAULT TRUE,
  criado_em TIMESTAMP DEFAULT NOW(),
  ultimo_login TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS idx_usuarios_username ON usuarios(username);

-- Adicionar usuario_id na auditoria (idempotente)
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='auditoria' AND column_name='usuario_id') THEN
    ALTER TABLE auditoria ADD COLUMN usuario_id INT NULL;
  END IF;
END $$;

-- ===== FEATURE 2: Aniversariantes =====
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='clientes' AND column_name='data_nascimento') THEN
    ALTER TABLE clientes ADD COLUMN data_nascimento DATE NULL;
  END IF;
END $$;

-- ===== FEATURE 3: Campanhas promocionais =====
CREATE TABLE IF NOT EXISTS campanhas (
  id SERIAL PRIMARY KEY,
  nome VARCHAR(255) NOT NULL,
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  bonus_percentual DECIMAL(5,2) NOT NULL DEFAULT 0,
  ativa BOOLEAN DEFAULT TRUE,
  descricao TEXT NULL,
  criado_em TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_campanhas_datas ON campanhas(data_inicio, data_fim);
CREATE INDEX IF NOT EXISTS idx_campanhas_ativa ON campanhas(ativa);

DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='compras' AND column_name='campanha_id') THEN
    ALTER TABLE compras ADD COLUMN campanha_id INT NULL REFERENCES campanhas(id);
  END IF;
END $$;
