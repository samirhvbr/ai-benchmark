-- Dados de exemplo p/ caracterização e verificação.
-- Senhas: senha123 (clientes) e tecmaster (técnicos), com password_hash/bcrypt.

INSERT INTO usuarios (id, login, senha, nome, papel) VALUES
  (1, 'ana',    '$2y$12$dWQhg1fEgR/SYscsZQo/aeybxyco6bWqm1JobxGqRQSoM.ypEAXMG', 'Ana Souza',     'cliente'),
  (2, 'bruno',  '$2y$12$JbmSNwqqQwyAkyr6MhIEK.53VGxhJUkPUlnNWcHRWmFUkfbsgjYfK', 'Bruno Lima',    'cliente'),
  (3, 'carla',  '$2y$12$Znj20yr117O4ZcnFNU0arO/I67cpf1pnSOYYC964hHrS9C3Qy9/fO', 'Carla Tecnica', 'tecnico'),
  (4, 'diego',  '$2y$12$8SI5gUYXliMgF2w6MJXj.uKjR6Wz/u2DSEOzZlYfATh.p4Submrze', 'Diego Suporte', 'tecnico');

INSERT INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (101, 1, 3, 'Sem conexao no bairro Centro', 'Cliente relata queda total.', 3, 3, 12,  '2026-06-01 09:15:00'),
  (102, 1, 4, 'Lentidao apos as 20h',         'Velocidade cai a noite.',      2, 2, 40,  '2026-06-02 20:30:00'),
  (103, 2, 3, 'Troca de plano',               'Deseja upgrade para 500MB.',   1, 1, NULL, '2026-06-03 11:00:00'),
  (104, 2, NULL, 'Fatura em duplicidade',     'Cobranca repetida no cartao.', 1, 4, NULL, '2026-06-04 08:05:00'),
  (105, 1, 3, 'Roteador nao liga',            'Equipamento sem energia.',     3, 2, 25,  '2026-06-05 14:20:00');
