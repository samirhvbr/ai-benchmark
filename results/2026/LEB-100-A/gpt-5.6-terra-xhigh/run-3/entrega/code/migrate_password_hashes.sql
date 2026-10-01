-- Execute uma vez antes de implantar a versão que migra hashes MD5 no login.
-- A alteração preserva os hashes existentes; cada um é substituído por password_hash
-- quando o respectivo usuário autenticar com sucesso.
ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;
