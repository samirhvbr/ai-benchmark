-- Dados de exemplo p/ caracterização e verificação.
-- Senhas: senha123 e tecmaster. Hashes password_hash; o código aceita MD5 legado durante a migração.

START TRANSACTION;

INSERT IGNORE INTO usuarios (id, login, senha, nome, papel) VALUES
  (1, 'ana', '$2y$12$SqpLiAP/I5vd109hsGgp7etDil5xcwyykaHnyrdzRFZ1we.6eIM9u', 'Ana Souza', 'cliente');

INSERT IGNORE INTO usuarios (id, login, senha, nome, papel) VALUES
  (2, 'bruno', '$2y$12$pvqKdaUwuPd3Dg3bHKBK4OFx57UZIx30TpK3RqP4KeRxQc/FgINcu', 'Bruno Lima', 'cliente');

INSERT IGNORE INTO usuarios (id, login, senha, nome, papel) VALUES
  (3, 'carla', '$2y$12$TT9Xzrc8oWjP5KM0vmEsOeWLIrjBWuwzirnvKMNKiY2v/wU/n/BAO', 'Carla Tecnica', 'tecnico');

INSERT IGNORE INTO usuarios (id, login, senha, nome, papel) VALUES
  (4, 'diego', '$2y$12$1i.vbbr6nkta8.5k4nLkHuxpP/Chcm4ZicjTpJpGhpYlMMxM4dZ4O', 'Diego Suporte', 'tecnico');

INSERT IGNORE INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (101, 1, 3, 'Sem conexao no bairro Centro', 'Cliente relata queda total.', 3, 3, 12, '2026-06-01 09:15:00');

INSERT IGNORE INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (102, 1, 4, 'Lentidao apos as 20h', 'Velocidade cai a noite.', 2, 2, 40, '2026-06-02 20:30:00');

INSERT IGNORE INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (103, 2, 3, 'Troca de plano', 'Deseja upgrade para 500MB.', 1, 1, NULL, '2026-06-03 11:00:00');

INSERT IGNORE INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (104, 2, NULL, 'Fatura em duplicidade', 'Cobranca repetida no cartao.', 1, 4, NULL, '2026-06-04 08:05:00');

INSERT IGNORE INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (105, 1, 3, 'Roteador nao liga', 'Equipamento sem energia.', 3, 2, 25, '2026-06-05 14:20:00');

COMMIT;
