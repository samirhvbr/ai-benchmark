-- Dados de exemplo p/ caracterização e verificação.
-- Senhas de teste: senha123 (clientes) e tecmaster (técnicos).

INSERT INTO usuarios (id, login, senha, nome, papel) VALUES
  (1, 'ana',    '$argon2id$v=19$m=65536,t=4,p=1$Q012RGRWd0hCYTRUT0oyag$PexPsPLmAbR/uHhwkacLnJc4UnoqNF7votFGXEsng50', 'Ana Souza',     'cliente'),
  (2, 'bruno',  '$argon2id$v=19$m=65536,t=4,p=1$Qmowc1ZLd2hKZlh1YjV2OA$uW7ezNvuu9BoVI4G9ptN8p+SuhiZq+0DnlOOQpHhXrQ', 'Bruno Lima',    'cliente'),
  (3, 'carla',  '$argon2id$v=19$m=65536,t=4,p=1$a3RmS2VtNjRTY0YvSHRScA$mT0DLbFZSkDErmNvXFp9mflp/28afakfP2WyjGpPvAs', 'Carla Tecnica', 'tecnico'),
  (4, 'diego',  '$argon2id$v=19$m=65536,t=4,p=1$MFpqYk55b2tOcmJncExITw$z9IIQdnPcjuOSmo8/GiStnq84HTNzE7MbIdEZtY9DIk', 'Diego Suporte', 'tecnico');

INSERT INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES
  (101, 1, 3, 'Sem conexao no bairro Centro', 'Cliente relata queda total.', 3, 3, 12,  '2026-06-01 09:15:00'),
  (102, 1, 4, 'Lentidao apos as 20h',         'Velocidade cai a noite.',      2, 2, 40,  '2026-06-02 20:30:00'),
  (103, 2, 3, 'Troca de plano',               'Deseja upgrade para 500MB.',   1, 1, NULL, '2026-06-03 11:00:00'),
  (104, 2, NULL, 'Fatura em duplicidade',     'Cobranca repetida no cartao.', 1, 4, NULL, '2026-06-04 08:05:00'),
  (105, 1, 3, 'Roteador nao liga',            'Equipamento sem energia.',     3, 2, 25,  '2026-06-05 14:20:00');
