# Clube de Vantagens - Drogaria Sao Bento v2

Sistema de cashback e fidelidade para farmacia.

## Stack
- **Frontend:** HTML/CSS/JS (single file)
- **Backend:** PHP 8+
- **Banco:** PostgreSQL
- **Deploy:** Railway

## Deploy no Railway

1. Crie um projeto no Railway
2. Adicione um banco PostgreSQL (Add Plugin > PostgreSQL)
3. Conecte este repositorio GitHub ao projeto
4. O banco sera criado automaticamente no primeiro acesso
5. Senha padrao: `saobento2026`

## Desenvolvimento Local

```bash
# Instalar PostgreSQL e criar o banco
createdb clube_saobento
psql clube_saobento < database.sql

# Rodar servidor
php -S localhost:8080
```

## Variaveis de Ambiente (Railway configura automaticamente)
- `DATABASE_URL` - URL de conexao PostgreSQL (fornecida pelo Railway)
