-- Índices sobre as colunas que as listagens, os relatórios e os processamentos filtram.
create index ix_chamado_empresa_status on chamado (empresa_id, status, prioridade);
create index ix_chamado_empresa_aberto on chamado (empresa_id, aberto_em);
create index ix_chamado_tecnico on chamado (tecnico_id);
create index ix_ordem_empresa_status on ordem_servico (empresa_id, status, agendada_para);
create index ix_ordem_chamado on ordem_servico (chamado_id);
create index ix_ordem_tecnico on ordem_servico (tecnico_id);
create index ix_evento_chamado on evento_chamado (chamado_id);
create index ix_notificacao_pendente on notificacao (estado, proxima_tentativa_em);
create index ix_sessao_usuario on sessao (usuario_id);
