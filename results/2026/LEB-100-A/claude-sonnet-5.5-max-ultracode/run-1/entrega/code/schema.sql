-- Painel de Chamados — Suporte NetX ISP
-- Estrutura do banco (MySQL 8). Fornecida ao modelo como contexto.

CREATE TABLE usuarios (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    login    VARCHAR(60)  NOT NULL UNIQUE,
    -- Hash de senha (password_hash). Contas legadas guardam md5 de 32 hex e são migradas no primeiro
    -- login. Bancos JÁ EXISTENTES precisam alargar a coluna (enquanto for CHAR(32) a migração não roda):
    --   ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;
    senha    VARCHAR(255) NOT NULL,
    nome     VARCHAR(120) NOT NULL,
    papel    ENUM('tecnico','cliente') NOT NULL DEFAULT 'cliente'
);

CREATE TABLE chamados (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,                -- dono do chamado (cliente que abriu)
    tecnico_id  INT NULL,                    -- técnico responsável
    titulo      VARCHAR(200) NOT NULL,
    descricao   TEXT NOT NULL,
    status      TINYINT NOT NULL DEFAULT 1,  -- 1=aberto, 2=em atendimento, 3=resolvido
    prioridade  TINYINT NOT NULL DEFAULT 2,  -- 1=baixa, 2=normal, 3=alta, 4=crítica
    minutos_resposta INT NULL,               -- tempo até a 1ª resposta (p/ estatística de SLA)
    criado_em   DATETIME NOT NULL,
    CONSTRAINT fk_dono    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_tecnico FOREIGN KEY (tecnico_id) REFERENCES usuarios(id),
    INDEX idx_status     (status),
    INDEX idx_usuario    (usuario_id),
    INDEX idx_criado_em  (criado_em)
);
