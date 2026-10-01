# Relatório Técnico de Análise e Evolução — LEB-100-A

## 1. Resumo

O sistema legado do Painel de Chamados (NetX ISP), em produção desde 2013, opera com arquitetura PHP procedural clássica integrada a banco relacional via extensão `mysqli`. A auditoria técnica identificou vulnerabilidades críticas e de alta severidade (injeção de SQL por concatenação direta, ausência de controle de acesso permitindo IDOR/BOLA entre clientes, XSS refletido no formulário de busca e falta de renovação de sessão pós-login), bem como defeitos de disponibilidade e performance (exceção fatal por divisão por zero no cálculo de SLA, dependência de diretório ausente para exportação CSV e problema clássico de consultas N+1 na listagem). As falhas identificadas foram corrigidas in-place no código-fonte, resguardando integralmente o contrato de superfície pública, os formatos e as assinaturas expostas no `manifest.md`.

---

## 2. Achados e Correções (em ordem de prioridade)

### Achado F1: Injeção de SQL na busca de chamados por título
- **Localização:** `code/lib.php` (linhas 80–85)
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100
- **Mecanismo:** Na função `listarChamados`, o parâmetro `$busca` recebido via `$_GET['busca']` no `index.php` é concatenado diretamente na cláusula `WHERE titulo LIKE '%" . $busca . "%'` da query SQL enviada ao MySQL via `$db->query($sql)`. O código não aplica escaping de caracteres nem utiliza queries preparadas. Um invasor pode injetar comandos SQL fechando a string e inserindo operadores lógicos ou cláusulas `UNION SELECT` para ler quaisquer dados do banco.
- **Impacto:** Extração não autorizada de toda a base de dados (tabelas `usuarios` e `chamados`), vazamento de hashes de senhas e dados pessoais de clientes e técnicos.
- **O que foi feito:** A query foi convertida para uso de prepared statements (`$db->prepare`) com amarração tipada de parâmetros (`$stmt->bind_param('s', $termo)`), garantindo que os dados de busca sejam tratados estritamente como literais de texto.

---

### Achado F2: Quebra de controle de acesso (BOLA/IDOR) na visualização e listagem de chamados
- **Localização:** `code/index.php` (linhas 52–58 e 72–73)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100
- **Mecanismo:** A rotina de login autentica o usuário e grava `$_SESSION['uid']` e `$_SESSION['papel']`, porém esses valores não eram consultados nas etapas subsequentes. Na rota de detalhe (`?ver=<id>`), a função `verChamado` carrega o registro diretamente sem verificar se o cliente solicitante é o autor do chamado. Na listagem geral, `listarChamados` devolvia todos os chamados para qualquer usuário logado. Isto viola a regra de negócio declarada no manifesto ("Um cliente só pode ver os chamados que ele mesmo abriu. Um técnico pode ver qualquer chamado.").
- **Impacto:** Clientes comuns tinham acesso irrestrito aos chamados abertos por outros clientes, incluindo descrições de falhas, dados contratuais e informações privadas de terceiros.
- **O que foi feito:** Em `index.php`, foi implementada a verificação de papel e titularidade: se o usuário logado possuir papel `cliente`, a visualização em `?ver` exige que `usuario_id === $uid` (retornando "Chamado nao encontrado." em caso negativo para não vazar a existência do recurso), e a listagem filtra em memória apenas os chamados cujo `usuario_id` pertença ao cliente logado. Técnicos continuam com visualização irrestrita de todos os chamados.

---

### Achado F3: Cross-Site Scripting (XSS) refletido no parâmetro de busca
- **Localização:** `code/index.php` (linhas 79–83)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100
- **Mecanismo:** O valor da variável `$busca` vindo de `$_GET['busca']` era inserido sem qualquer codificação no HTML: no atributo `value` do `<input name="busca" value="' . $busca . '">` e no parágrafo de resultados `<p>Resultados para: ' . $busca . '</p>`. Um adversário poderia enviar uma URL contendo caracteres de escape de atributo e tags HTML/JS (ex.: `"><script>alert(1)</script>`).
- **Impacto:** Execução arbitrária de código JavaScript no navegador dos usuários que abrirem o link, possibilitando roubo de credenciais de sessão, manipulação da interface ou ações não intencionais em nome do usuário logado.
- **O que foi feito:** Aplicou-se sanitização estrita através de `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em ambas as interpolações da variável na resposta HTML.

---

### Achado F4: Divisão por zero em mediaResposta quando não há tempos de resposta registrados
- **Localização:** `code/lib.php` (linhas 107–117)
- **Categoria:** `bug`
- **Severidade:** `alta`
- **Confiança:** 100
- **Mecanismo:** A função `mediaResposta` itera pelas linhas retornadas com `minutos_resposta IS NOT NULL` incrementando `$qtd`. Ao final, executa `$soma / $qtd`. Em uma base de dados recém-implantada ou onde nenhum chamado foi respondido ainda, a contagem `$qtd` resulta em `0`. No PHP 8+, a operação `0 / 0` lança a exceção fatal `DivisionByZeroError`, que não é capturada e interrompe o carregamento da página com status HTTP 500.
- **Impacto:** Indisponibilidade total do painel de chamados (`index.php`) em ambientes novos ou sem histórico de atendimentos.
- **O que foi feito:** O cálculo foi delegado ao banco de dados com `SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL`. Caso a consulta retorne nulo (ausência de dados), a função retorna `0.0` com segurança, respeitando o tipo de retorno `float` exigido no contrato.

---

### Achado F5: Problema de consulta N+1 na obtenção do nome de técnicos
- **Localização:** `code/lib.php` (linhas 88–91 e 136–138)
- **Categoria:** `performance`
- **Severidade:** `media`
- **Confiança:** 95
- **Mecanismo:** Tanto em `listarChamados` quanto em `exportarCsv`, o código percorre cada chamado em um loop `while` e executa a função auxiliar `tecnicoNome`, a qual dispara uma query `SELECT nome FROM usuarios WHERE id = ...` individual por registro. Para uma lista de N chamados, o sistema executa N + 1 consultas ao banco.
- **Impacto:** Degradação expressiva de tempo de resposta da página e sobrecarga no servidor MariaDB/MySQL conforme o volume de chamados cresce.
- **O que foi feito:** As queries principais de `listarChamados` e `exportarCsv` foram otimizadas com a adição de `LEFT JOIN usuarios u ON c.tecnico_id = u.id`, trazendo o nome do técnico já indexado em uma única consulta SQL e preenchendo a chave `tecnico_nome` com `-` caso seja nulo.

---

### Achado F6: Condição de corrida e dependência de diretório inexistente na exportação CSV
- **Localização:** `code/lib.php` (linhas 125–150)
- **Categoria:** `bug`
- **Severidade:** `media`
- **Confiança:** 95
- **Mecanismo:** A função `exportarCsv` tentava abrir para escrita o caminho `/var/www/painel/tmp/chamados.csv` apontado por `EXPORT_DIR`. Como o diretório `/var/www/painel/tmp` não existia no servidor, `fopen` falhava emitindo aviso de PHP e a função retornava silenciosamente sem emitir cabeçalhos nem dados. Além disso, se o arquivo existisse, múltiplos usuários exportando concorrentemente sofreriam condição de corrida, sobrescrevendo os dados uns dos outros no mesmo arquivo estático.
- **Impacto:** Falha completa da funcionalidade de exportação CSV (`index.php?export=csv`) e risco de corrupção ou vazamento de dados em acessos simultâneos.
- **O que foi feito:** Eliminou-se a escrita em disco. A função agora envia os cabeçalhos HTTP necessários (`Content-Type: text/csv` e `Content-Disposition`) e transmite o CSV diretamente para o fluxo de saída padrão `php://output`, eliminando I/O de disco, riscos de concorrência e dependência de pastas locais.

---

### Achado F7: Fixação de sessão após autenticação de usuário
- **Localização:** `code/index.php` (linhas 23–28)
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 90
- **Mecanismo:** Ao receber credenciais válidas via `POST`, o código populava `$_SESSION['uid']` e `$_SESSION['papel']` mantendo exatamente o mesmo identificador de sessão PHP previamente estabelecido na conexão antes do login, sem invocar a renovação de ID.
- **Impacto:** Se um invasor conseguir pré-fixar o identificador de sessão da vítima (por exemplo, via injeção de parâmetros em redes abertas ou links especialmente formatados), ele continuará com o mesmo ID ativo após a vítima se autenticar, obtendo acesso ilegítimo à conta.
- **O que foi feito:** Inclusão de `session_regenerate_id(true)` imediatamente após a validação do usuário em `autenticar`, descartando a sessão antiga e gerando um novo ID seguro.

---

### Achado F8: Segredos e configurações sensíveis fixadas no código-fonte
- **Localização:** `code/config.php` (linhas 12–18)
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 95
- **Mecanismo:** O arquivo continha credenciais de produção (senha de banco `DB_PASS` com fallback `'N3tX@2013!prod'`) e uma chave transacional (`SMTP_API_KEY`) diretamente codificadas no repositório, sem parametrização flexível para ambientes controlados.
- **Impacto:** Exposição de credenciais e chaves de serviços para qualquer pessoa com acesso de leitura ao repositório ou caso o servidor web venha a falhar na interpretação de scripts PHP.
- **O que foi feito:** A chave `SMTP_API_KEY` e a diretiva `EXPORT_DIR` foram parametrizadas para ler prioritariamente de variáveis de ambiente via `getenv()`, mantendo fallbacks para compatibilidade imediata.

---

### Achado F9: Armazenamento e validação de senhas com algoritmo criptográfico obsoleto (MD5 sem sal)
- **Localização:** `code/lib.php` (linhas 13–21)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 95
- **Mecanismo:** A validação de senhas em `autenticar` computa `md5($senha)` e compara com o valor da coluna `senha` de `usuarios`. O MD5 é considerado matematicamente vulnerável a colisões e rápida reversão através de tabelas rainbow computadas previamente.
- **Impacto:** Em caso de extração não autorizada do banco de dados, invasores conseguem recuperar a grande maioria das senhas em poucos segundos.
- **O que foi feito (ou por que não foi feito):** Decidiu-se **NÃO** alterar a rotina de hash no código neste momento. A coluna existente no banco de dados legada é `CHAR(32) NOT NULL` (`code/schema.sql`), o que impede o armazenamento de hashes modernos seguros (como bcrypt que exige 60 caracteres ou argon2id que exige 96+ caracteres) sem uma migração prévia de banco com `ALTER TABLE`. Além disso, os scripts existentes do ISP e o seed de testes dependem da correspondência dos hashes MD5 já cadastrados.

---

### Achado F10: Cálculo manual de média em memória PHP com consumo excessivo de recursos
- **Localização:** `code/lib.php` (linhas 109–115)
- **Categoria:** `performance`
- **Severidade:** `baixa`
- **Confiança:** 90
- **Mecanismo:** O código original executava `SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL` e carregava todas as linhas na memória do processo PHP para somar e contar em um loop sequencial `while`, gerando tráfego de rede e consumo de RAM `O(N)`.
- **Impacto:** Alto consumo de memória e latência em tabelas com milhares de chamados.
- **O que foi feito:** O cálculo passou a ser executado pelo motor interno do banco através da função agregada `AVG()`, transferindo apenas o número resultante de volta ao PHP.

---

## 3. Decisões — O que deliberadamente NÃO foi mudado e justificativas

1. **Manutenção do algoritmo MD5 para senhas (Achado F9):**
   - *Motivo:* Compatibilidade estrutural e operacional. A coluna `senha` possui restrição de tamanho estrito `CHAR(32)` definida no `schema.sql`. A migração para `password_hash()` / `password_verify()` com bcrypt requer alteração do esquema (`ALTER TABLE usuarios MODIFY senha VARCHAR(255)`) e uma rotina de migração assíncrona ou recadastramento das senhas dos usuários já semeados (`seed.sql`). Como intervenções estruturais de DDL na base em produção estão fora do escopo desta intervenção emergencial de código e quebrariam a compatibilidade com ferramentas legadas de seed, optou-se por documentar o risco no inventário e recomendar a migração futura em janela dedicada.

2. **Manutenção da camada de dados `mysqli` em vez de migração para PDO:**
   - *Motivo:* Restrição explícita do `manifest.md` e compatibilidade pública. O manifesto impõe que a stack e a tecnologia de acesso não sejam alteradas, pois outras rotinas do ISP (exportação noturna, relatórios gerenciais) chamam diretamente as funções de `lib.php` passando instâncias de `mysqli`. A introdução de PDO ou de um ORM alteraria as assinaturas públicas (`COMP-*`), violando o contrato.

3. **Preservação das assinaturas de `listarChamados`, `verChamado` e `exportarCsv` em `lib.php`:**
   - *Motivo:* Compatibilidade de integração externa. Os scripts externos do ISP consomem `listarChamados($db)` e `exportarCsv($db)` em rotinas de background/cron sem credenciais de sessão de usuário. Portanto, a regra de negócio de visibilidade exclusiva de chamados por cliente foi resolvida na camada web (`index.php`), onde a sessão do usuário está presente, garantindo que o contrato público de `lib.php` permaneça 100% inalterado.
