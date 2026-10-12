-- NetX ISP · Chamados e Ordens de Serviço. PostgreSQL 16.
-- Tabelas de negócio primeiro; as três últimas são estado interno do sistema.

create table empresa (
    id                 bigserial primary key,
    nome               text      not null,
    fuso_horario       text      not null default 'America/Sao_Paulo',
    expediente_inicio  time      not null default '08:00',
    expediente_fim     time      not null default '18:00',
    ativa              boolean   not null default true
);

create table usuario (
    id          bigserial primary key,
    empresa_id  bigint references empresa (id),
    email       text    not null,
    nome        text    not null,
    papel       text    not null,
    senha_hash  text    not null,
    ativo       boolean not null default true
);

create table cliente (
    id          bigserial primary key,
    empresa_id  bigint  not null references empresa (id),
    nome        text    not null,
    documento   text,
    email       text,
    telefone    text,
    endereco    text,
    ativo       boolean not null default true
);

create table tecnico (
    id          bigserial primary key,
    empresa_id  bigint  not null references empresa (id),
    usuario_id  bigint  references usuario (id),
    nome        text    not null,
    ativo       boolean not null default true
);

create table sla_politica (
    id                  bigserial primary key,
    empresa_id          bigint  not null references empresa (id),
    prioridade          text    not null,
    horas_resposta      integer not null,
    horas_resolucao     integer not null,
    somente_expediente  boolean not null
);

create table chamado (
    id                    bigserial primary key,
    empresa_id            bigint      not null references empresa (id),
    cliente_id            bigint      not null references cliente (id),
    tecnico_id            bigint      references tecnico (id),
    titulo                text        not null,
    descricao             text        not null default '',
    prioridade            text        not null,
    status                text        not null,
    aberto_em             timestamptz not null,
    prazo_resposta        timestamptz not null,
    prazo_resolucao       timestamptz not null,
    primeira_resposta_em  timestamptz,
    resolvido_em          timestamptz,
    criado_por            bigint      not null references usuario (id)
);

create table ordem_servico (
    id             bigserial primary key,
    empresa_id     bigint      not null references empresa (id),
    chamado_id     bigint      not null references chamado (id),
    tecnico_id     bigint      not null references tecnico (id),
    tipo           text        not null,
    status         text        not null,
    agendada_para  timestamptz not null,
    iniciada_em    timestamptz,
    concluida_em   timestamptz,
    valor_cents    bigint,
    observacoes    text
);

create table evento_chamado (
    id          bigserial primary key,
    chamado_id  bigint      not null references chamado (id),
    tipo        text        not null,
    usuario_id  bigint      references usuario (id),
    detalhe     text        not null default '',
    ocorrido_em timestamptz not null
);

create table notificacao (
    id                    bigserial primary key,
    empresa_id            bigint      not null references empresa (id),
    chave_evento          text        not null,
    destinatario          text        not null,
    assunto               text        not null,
    corpo                 text        not null,
    estado                text        not null,
    tentativas            integer     not null default 0,
    proxima_tentativa_em  timestamptz not null,
    enviada_em            timestamptz
);

create table lancamento (
    id                bigserial primary key,
    empresa_id        bigint      not null references empresa (id),
    ordem_servico_id  bigint      not null references ordem_servico (id),
    valor_cents       bigint      not null,
    competencia       text        not null,
    criado_em         timestamptz not null
);

-- estado interno: não é lido por ferramentas externas
create table sessao (
    token       text primary key,
    usuario_id  bigint      not null references usuario (id),
    expira_em   timestamptz not null,
    ativa       boolean     not null default true
);

create table job_checkpoint (
    nome      text   primary key,
    ultimo_id bigint not null
);

create table chave_idempotencia (
    id          bigserial primary key,
    empresa_id  bigint      not null,
    rota        text        not null,
    chave       text        not null,
    corpo_hash  text        not null,
    status      integer,
    resposta    text,
    criado_em   timestamptz not null
);
