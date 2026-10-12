-- Unicidade exigida pelas regras de negócio: uma chave de idempotência por empresa e rota, uma notificação por evento e destinatário,
-- um lançamento por ordem de serviço, um e-mail por usuário.
create unique index uq_chave_idempotencia on chave_idempotencia (empresa_id, rota, chave);
create unique index uq_notificacao_evento on notificacao (chave_evento, destinatario);
create unique index uq_lancamento_ordem on lancamento (ordem_servico_id);
create unique index uq_usuario_email on usuario (lower(email));
