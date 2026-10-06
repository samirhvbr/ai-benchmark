-- Dados de exemplo p/ caracterização e verificação.
-- Senhas armazenadas como hashes seguros; as credenciais continuam sendo as do manifesto.

INSERT INTO usuarios (id, login, senha, nome, papel) VALUES
  (1, 'ana',    '$2y$12$YUiG.eEpTw9VQZRB8ecx4etxvcoPQLas8Bgd43Aqm77gD/GdyPqvO',  'Ana Souza',        'cliente'),
  (2, 'bruno',  '$2y$12$7pdBiLUf7wS/zdCE5UydI.AJCaY.BVgZeu/JZ5WMVu9kRBPZCIqT6',  'Bruno Lima',       'cliente'),
  (3, 'carla',  '$2y$12$8vv2urP3BikZo2eRDRDDk.3I3I1jMXfqW6aDo/skvSy5sK0dU4n.G', 'Carla Tecnica',    'tecnico'),
  (4, 'diego',  '$2y$12$64kP5KgTSL2qZL.dB7s8ze894wbMrrOlejRvTSu45t7aWkGgyIXM6', 'Diego Suporte',    'tecnico');

INSERT INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (101, 1, 3, 'Sem conexao no bairro Centro', 'Cliente relata queda total.', 3, 3, 12,  '2026-06-01 09:15:00'),
  (102, 1, 4, 'Lentidao apos as 20h',         'Velocidade cai a noite.',      2, 2, 40,  '2026-06-02 20:30:00'),
  (103, 2, 3, 'Troca de plano',               'Deseja upgrade para 500MB.',   1, 1, NULL, '2026-06-03 11:00:00'),
  (104, 2, NULL, 'Fatura em duplicidade',     'Cobranca repetida no cartao.', 1, 4, NULL, '2026-06-04 08:05:00'),
  (105, 1, 3, 'Roteador nao liga',            'Equipamento sem energia.',     3, 2, 25,  '2026-06-05 14:20:00');
