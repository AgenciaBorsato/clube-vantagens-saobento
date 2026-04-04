-- ============================================================
-- BIPCASH SaaS - Schema Multi-Tenant (PostgreSQL)
-- Cada farmacia e um tenant isolado
-- ============================================================

-- Dropar tabelas antigas se existem (sem farmacia_id)
-- Ordem reversa por causa das FKs
DROP TABLE IF EXISTS auditoria CASCADE;
DROP TABLE IF EXISTS login_tentativas CASCADE;
DROP TABLE IF EXISTS resgates CASCADE;
DROP TABLE IF EXISTS compras CASCADE;
DROP TABLE IF EXISTS campanhas CASCADE;
DROP TABLE IF EXISTS clientes CASCADE;
DROP TABLE IF EXISTS cashback_mensal CASCADE;
DROP TABLE IF EXISTS configuracoes CASCADE;
DROP TABLE IF EXISTS usuarios CASCADE;
DROP TABLE IF EXISTS super_admins CASCADE;
DROP TABLE IF EXISTS farmacias CASCADE;

-- Tabela principal: Farmacias (tenants)
CREATE TABLE IF NOT EXISTS farmacias (
  id SERIAL PRIMARY KEY,
  nome VARCHAR(255) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  logo_base64 TEXT NULL,
  cor_primaria VARCHAR(7) DEFAULT '#2196f3',
  cor_secundaria VARCHAR(7) DEFAULT '#0a2540',
  whatsapp_url VARCHAR(500) NULL,
  whatsapp_instance VARCHAR(100) NULL,
  whatsapp_token VARCHAR(500) NULL,
  whatsapp_enabled BOOLEAN DEFAULT FALSE,
  ativa BOOLEAN DEFAULT TRUE,
  plano VARCHAR(50) DEFAULT 'basico',
  criado_em TIMESTAMP DEFAULT NOW()
);

-- Super Admins (separado de usuarios de farmacia)
CREATE TABLE IF NOT EXISTS super_admins (
  id SERIAL PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nome VARCHAR(255) NOT NULL,
  ativo BOOLEAN DEFAULT TRUE,
  criado_em TIMESTAMP DEFAULT NOW(),
  ultimo_login TIMESTAMP NULL
);

-- Configuracoes POR FARMACIA
CREATE TABLE IF NOT EXISTS configuracoes (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NOT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  chave VARCHAR(100) NOT NULL,
  valor TEXT NOT NULL,
  atualizado_em TIMESTAMP DEFAULT NOW(),
  UNIQUE (farmacia_id, chave)
);

-- Cashback mensal POR FARMACIA
CREATE TABLE IF NOT EXISTS cashback_mensal (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NOT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  ano INT NOT NULL,
  mes INT NOT NULL,
  percentual DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  criado_em TIMESTAMP DEFAULT NOW(),
  UNIQUE (farmacia_id, ano, mes)
);

-- Clientes POR FARMACIA
CREATE TABLE IF NOT EXISTS clientes (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NOT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  nome VARCHAR(255) NOT NULL,
  cpf VARCHAR(11) NOT NULL,
  telefone VARCHAR(11) NOT NULL,
  data_nascimento DATE NULL,
  data_cadastro TIMESTAMP DEFAULT NOW(),
  ativo BOOLEAN DEFAULT TRUE,
  UNIQUE (farmacia_id, cpf),
  UNIQUE (farmacia_id, telefone)
);
CREATE INDEX IF NOT EXISTS idx_clientes_farmacia ON clientes(farmacia_id);
CREATE INDEX IF NOT EXISTS idx_clientes_telefone ON clientes(farmacia_id, telefone);
CREATE INDEX IF NOT EXISTS idx_clientes_cpf ON clientes(farmacia_id, cpf);

-- Campanhas POR FARMACIA (definida ANTES de compras por causa da FK)
CREATE TABLE IF NOT EXISTS campanhas (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NOT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  nome VARCHAR(255) NOT NULL,
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  bonus_percentual DECIMAL(5,2) NOT NULL DEFAULT 0,
  ativa BOOLEAN DEFAULT TRUE,
  descricao TEXT NULL,
  criado_em TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_campanhas_farmacia ON campanhas(farmacia_id);

-- Compras POR FARMACIA
CREATE TABLE IF NOT EXISTS compras (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NOT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  cliente_id INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
  valor DECIMAL(10,2) NOT NULL,
  cashback_percentual DECIMAL(5,2) NOT NULL,
  cashback_valor DECIMAL(10,2) NOT NULL,
  data_compra TIMESTAMP DEFAULT NOW(),
  estornada BOOLEAN DEFAULT FALSE,
  data_estorno TIMESTAMP NULL DEFAULT NULL,
  motivo_estorno VARCHAR(255) NULL DEFAULT NULL,
  campanha_id INT NULL REFERENCES campanhas(id)
);
CREATE INDEX IF NOT EXISTS idx_compras_farmacia ON compras(farmacia_id);
CREATE INDEX IF NOT EXISTS idx_compras_cliente ON compras(cliente_id);
CREATE INDEX IF NOT EXISTS idx_compras_data ON compras(data_compra);

-- Resgates POR FARMACIA
CREATE TABLE IF NOT EXISTS resgates (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NOT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  cliente_id INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
  valor DECIMAL(10,2) NOT NULL,
  data_resgate TIMESTAMP DEFAULT NOW(),
  estornado BOOLEAN DEFAULT FALSE
);
CREATE INDEX IF NOT EXISTS idx_resgates_farmacia ON resgates(farmacia_id);

-- Login tentativas (global)
CREATE TABLE IF NOT EXISTS login_tentativas (
  id SERIAL PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  tentativa_em TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_login_ip ON login_tentativas(ip);

-- Auditoria POR FARMACIA
CREATE TABLE IF NOT EXISTS auditoria (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  usuario_id INT NULL,
  acao VARCHAR(100) NOT NULL,
  detalhes TEXT,
  entidade_tipo VARCHAR(50),
  entidade_id INT,
  ip VARCHAR(45),
  criado_em TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_auditoria_farmacia ON auditoria(farmacia_id);

-- Usuarios POR FARMACIA
CREATE TABLE IF NOT EXISTS usuarios (
  id SERIAL PRIMARY KEY,
  farmacia_id INT NOT NULL REFERENCES farmacias(id) ON DELETE CASCADE,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nome VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'operador' CHECK (role IN ('operador', 'gerente')),
  ativo BOOLEAN DEFAULT TRUE,
  criado_em TIMESTAMP DEFAULT NOW(),
  ultimo_login TIMESTAMP NULL,
  UNIQUE (farmacia_id, username)
);
CREATE INDEX IF NOT EXISTS idx_usuarios_farmacia ON usuarios(farmacia_id);
