# RELATORIO — Painel de Chamados (NetX ISP) · LEB-100-A

| | |
| --- | --- |
| Instância | LEB-100-A (nível 100, versão 1.1) |
| Padrão | LEB spec 1.1.0 · tarefa 1.0.0 |
| Matriz de avaliação (SHA-256) | `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625` |
| Arquivos alterados | `code/lib.php`, `code/index.php`, `code/config.php`, `code/schema.sql` |
| Achados | 16 (13 corrigidos · 3 reportados e não corrigidos) |

---

## 1. Resumo

O painel é um CRUD de chamados de suporte em PHP procedural sobre MySQL, com três
superfícies públicas (`lib.php`), quatro rotas GET (`index.php`) e uma exportação em CSV.
O código funciona e cumpre o contrato do manifesto — mas a superfície web **não
implementava a regra de negócio de visibilidade** que o próprio manifesto declara, e a
busca por título era **SQL injection** com exfiltração de hashes de senha comprovada
neste ambiente.

O que mudou: a busca virou consulta parametrizada; a visibilidade cliente/técnico foi
implementada **sem tocar nas assinaturas públicas** (contexto de usuário em contexto);
o XSS refletido da busca foi eliminado; as senhas migraram de md5 sem salt para
`password_hash()` (com migração transparente e sem risco de truncar o hash); o CSV deixou
de ser gravado em arquivo fixo dentro do document root e deixou de sair vazio quando o
diretório de exportação não existe; a média de SLA deixou de derrubar a página com
divisão por zero; e o N+1 de consultas foi eliminado (6 → 2 consultas na listagem).

O que **não** mudou: as sete assinaturas públicas, os nomes de rota (`busca`, `ver`,
`export`), o cabeçalho e a ordem do CSV, a estrutura HTML da tabela e os rótulos de
status — ver §4 (Decisões).

**Estado final do sistema:** painel utilizável, sem vazamento entre clientes e sem
injeção; o que resta são decisões de produto/operação que exigem informação de fora
deste pacote (rotação de segredo, contrato do relatório gerencial, índice de banco).

---

## 2. Método de verificação

O pacote foi exercitado contra um MariaDB 11.8 local (schema + seed do próprio
repositório) e um servidor `php -S`, antes e depois das correções:

| Verificação | Resultado |
| --- | --- |
| Caracterização antes/depois (7 funções públicas + `tecnicoNome`, todos os campos de retorno) | **Idêntica**, exceto a busca por `%` (F14, comportamento corrigido de propósito) |
| CSV byte a byte contra a versão original (com diretório gravável) | **Idêntico** (`ID,Titulo,Status,Tecnico,"Aberto em"` + 5 linhas em ordem de `id`) |
| Login dos 4 usuários do seed, senha errada, login inexistente | Idêntico ao original |
| Rótulos de `formatarStatus` (1, 2, 3, 0, 4, −1) | Idêntico ao original |
| Rótulos de `rotuloPrioridade` (10 combinações) | Idêntico ao original (nenhuma mudança de texto) |
| Visibilidade: cliente `ana` vê 101/102/105; técnica `carla` vê os 5 | Regra do manifesto passa a valer (antes `ana` via os 5) |
| Detalhe `index.php?ver=103` como `ana` | “Chamado nao encontrado” (antes exibia o chamado do `bruno`) |
| Export CSV como cliente / como técnica | 3 linhas (só as próprias) / 5 linhas |
| Payload UNION de exfiltração de `usuarios` | Original: **4 linhas injetadas com hashes de senha**; corrigido: 0 linhas |
| `?busca=<script>alert(1)</script>` | Original: `<script>` puro no HTML; corrigido: escapado |
| Tabela sem amostra de SLA | Original: erro fatal (divisão por zero); corrigido: “0 min”, HTTP 200 |
| Export com `EXPORT_DIR` inexistente | Original: resposta 200 com **0 bytes**; corrigido: CSV completo |
| Consultas por listagem (5 chamados) | Original: 6; corrigido: 2 (general log do MySQL) |
| Schema legado `CHAR(32)` (banco já implantado) | Login continua funcionando; hash **não** é reescrito (sem truncamento) |
| `login[]=x`, `busca[]=x`, `ver=abc`, `ver=-1` | HTTP 200, sem erro fatal (original: TypeError/500) |

---

## 3. Achados

Ordenados por prioridade de correção. Cada bloco traz **mecanismo** (o caminho concreto
deste código), **impacto/severidade**, **confiança** (0–100, probabilidade de o problema
ser real) e **o que foi feito**.

### F1 — SQL injection na busca por título · `code/lib.php:80-85` · segurança · **crítica** · confiança 98 · **corrigido**

**Mecanismo.** `listarChamados()` montava a SQL por concatenação:

```php
$sql = 'SELECT * FROM chamados';
if ($busca !== '') {
    $sql .= " WHERE titulo LIKE '%" . $busca . "%'";   // ← lib.php:82 (original)
}
$res = $db->query($sql);
```

`$busca` é `$_GET['busca']` cru (via `index.php:72`). Uma aspa simples fecha o `LIKE` e o
resto da string vira SQL. Reproduzido neste ambiente contra o código original:
`index.php?busca=' UNION SELECT id,login,0,senha,'x',1,1,0,NOW() FROM usuarios --`
devolveu **4 linhas injetadas**, com os hashes md5/bcrypt da tabela `usuarios` dentro da
coluna “Titulo” da listagem (visíveis na tela de qualquer cliente logado).
`?busca=' OR '1'='1` ignorava o filtro e trazia a base inteira.

**Impacto.** Leitura de qualquer tabela do banco (hashes de senha, dados de outros
clientes) por qualquer usuário autenticado — e, conforme o privilégio do usuário MySQL,
alteração de dados. Combinado com F2, a rota de listagem já expunha a base a qualquer
cliente.

**Correção.** Consulta parametrizada (`mysqli prepare` + `bind_param`), com o termo de
busca em parâmetro; `verChamado()` e `tecnicoNome()` (que também concatenavam) foram
parametrizados. O payload de UNION passa a ser tratado como texto literal e retorna
zero linhas.

---

### F2 — Regra de visibilidade ignorada (cliente vê, abre e exporta chamados de outros) · `code/lib.php:78-93` (também `98-102` e `123-151`) · `code/index.php:52-96` · segurança · **alta** · confiança 96 · **corrigido**

**Mecanismo.** As três funções de leitura executam `SELECT * FROM chamados` **sem
qualquer condição de dono**: `listarChamados()` (linha 80), `verChamado()` (linha 100) e
`exportarCsv()` (linha 132). O papel do usuário existe na sessão (`$_SESSION['papel']`,
`index.php:25`) mas **nunca é lido** para filtrar. Como o manifesto proíbe alterar as
assinaturas, não havia parâmetro de usuário disponível.

**Impacto.** Reproduzido: logado como `ana` (cliente), a listagem mostrava os 5 chamados
do seed, inclusive 103 e 104 abertos por `bruno`; `index.php?ver=103` exibia a descrição
do chamado de outro cliente; `index.php?export=csv` baixava a base inteira. Além do
vazamento, isso contraria a regra de negócio declarada no manifesto.

**Correção.** Introduzido um **contexto de visibilidade fora da assinatura pública**
(`definirVisor()`/`visorAtual()` em `lib.php`, preenchido por `index.php` após o login):

- `listarChamados()` e `verChamado()` acrescentam `usuario_id = ?` quando o papel em
  contexto não é `tecnico` (papel desconhecido → falha fechada);
- `exportarCsv()` restringe o export do cliente aos próprios chamados;
- **sem sessão** (rotina noturna de exportação, relatório gerencial) o comportamento
  histórico — sem filtro — é preservado, porque o contexto não é definido.

Verificado: `ana` → 101/102/105; `carla` → os 5; `ana` em `?ver=103` → “Chamado nao
encontrado”; CSV da cliente → 3 linhas, do técnico → 5 linhas.

---

### F3 — XSS refletido no parâmetro de busca · `code/index.php:79` e `:82` · segurança · **alta** · confiança 95 · **corrigido**

**Mecanismo.** `$busca` era impresso cru no atributo `value` do input (linha 79) e no
texto “Resultados para: ” (linha 82), enquanto título, descrição e técnico da mesma
tela usavam `htmlspecialchars` — o esquecimento era local, não uma escolha de estilo.

**Impacto.** JavaScript no domínio do painel: roubo do cookie de sessão (combinado com
F12, que não usava `HttpOnly`), leitura da tela e ações em nome do usuário logado.

**Correção.** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` antes da
impressão; os demais `htmlspecialchars` foram padronizados na mesma forma.

---

### F4 — Senhas com md5 sem salt · `code/lib.php:15-20` (e `code/schema.sql:7`) · segurança · **alta** · confiança 95 · **corrigido**

**Mecanismo.** `autenticar()` fazia `$hash = md5($senha)` e comparava com
`WHERE login = ? AND senha = ?`. md5 **sem salt**: hash idêntico para a mesma senha (no
seed, `ana` e `bruno` compartilham `e7d80ffeefa212b7c5c55700e4f7193e`) e quebrável por
dicionário em segundos. A coluna era `CHAR(32)` — exatamente o tamanho do md5, sem espaço
para hash forte.

**Impacto.** Qualquer vazamento do banco (backup, dump, o próprio F1/F2) entrega as
senhas de forma reversível; com senhas fracas e reutilizadas (`senha123`, `tecmaster`),
a conta de qualquer cliente/técnico cai imediatamente.

**Correção.** `autenticar()` aceita os dois formatos — `password_hash()`/`password_verify()`
(bcrypt/argon2) e o md5 legado, agora comparado com `hash_equals` (tempo constante). No
primeiro login de cada usuário, o md5 é reescrito com `password_hash(PASSWORD_DEFAULT)`.
**A reescrita só ocorre se a coluna aceitar ≥ 60 caracteres** (verificado em
`information_schema`): com o schema antigo (`CHAR(32)`) o hash seria truncado e o
usuário ficaria sem login — nesse caso nada é reescrito e o md5 segue válido.
`schema.sql` passou de `CHAR(32)` para `VARCHAR(255)`. Verificado nos dois cenários:
com `VARCHAR(255)` o hash migra para `$2y$12$…` (60) e o login continua funcionando
depois da migração; com `CHAR(32)` o login continua funcionando e o hash **não** muda.

> **Nota de deployment:** código e schema precisam subir juntos — a versão antiga do
> `autenticar()` (compara md5) não reconhece hash bcrypt. O rollout deve ser feito
> código + `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` na mesma janela.

---

### F5 — Chave de API do SMTP embutida no código · `code/config.php:15` · segurança · **alta** · confiança 92 · **corrigido**

**Mecanismo.** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')` fixava no
arquivo-fonte a credencial da central de e-mail transacional.

**Impacto.** Quem lê o repositório (ou um backup do `.php` no document root) usa a chave
para enviar e-mail em nome do ISP (phishing com remetente corporativo, cobrança falsa).

**Correção.** A constante continua **definida** (para não quebrar os scripts do ISP que a
referenciam), mas o valor vem do ambiente:
`define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');`. A chave literal saiu do código e
deve ser rotacionada na central.

---

### F6 — Senha do banco com fallback embutido · `code/config.php:12` · segurança · **alta** · confiança 90 · **reportado, não corrigido**

**Mecanismo.** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod')` mantém a senha
do MySQL de produção escrita no arquivo e a usa sempre que `DB_PASS` não estiver
definida — o caso de qualquer deploy novo ou restauração de backup.

**Impacto.** Credencial de base exposta no código e no histórico do repositório.

**Por que não corrigi.** Remover o fallback derrubaria **todo** ambiente que não defina
`DB_PASS` (deploy novo, restore de backup, ambiente de avaliação) — é exatamente o risco
de indisponibilidade que não posso assumir num sistema em produção com consumidores
externos. O caminho seguro é sequencial e Known: (1) definir `DB_PASS` no
ambiente/segredo do orquestrador; (2) rotacionar a senha no MySQL; (3) remover o fallback
— uma linha, isolada, com risco zero. Enquanto isso, registrei a decisão no próprio
arquivo, para que a remoção não passe despercebida.

---

### F7 — CSV com dados de todos os chamados em arquivo de nome fixo dentro de diretório web · `code/lib.php:125-126` e `:148-150` (e `config.php:18`) · segurança · **média** · confiança 88 · **corrigido**

**Mecanismo.** `exportarCsv()` escrevia sempre em `EXPORT_DIR . '/chamados.csv'`
(`/var/www/painel/tmp`, tipicamente **dentro** do document root) e servia o arquivo com
`readfile()`. Nome fixo e previsível, arquivo permanence no disco após o download, e
`fopen(..., 'w')` segue symlink — um usuário local com escrita no diretório poderia
apontar o caminho para outro arquivo e sobrescrevê-lo. Requisições simultâneas disputavam
o mesmo arquivo.

**Impacto.** Download do arquivo por qualquer visitante (sem login) com a base de
chamados; sobrescrita arbitrária de arquivo via symlink.

**Correção.** CSV montado em **arquivo temporário de nome único** (`tempnam`) e removido
logo após o envio (`@unlink`): acaba a exposição permanente, a corrida entre requisições
e o ataque de symlink sobre nome previsível. Se `EXPORT_DIR` não existir ou não for
gravável, usa-se o diretório temporário do sistema. Verificado: 0 arquivos residuais
após o download.

---

### F8 — Export CSV devolvia arquivo vazio, em silêncio · `code/lib.php:125-129` · bug · **média** · confiança 92 · **corrigido**

**Mecanismo.** `fopen(EXPORT_DIR . '/chamados.csv', 'w')` devolvia `false` quando o
diretório não existia e a função retornava **em silêncio**, sem cabeçalho de erro nem
log. Reproduzido neste ambiente: `index.php?export=csv` respondeu **HTTP 200 com 0 bytes**.

**Impacto.** O consumidor (rotina noturna, relatório gerencial, usuário) recebe um CSV
vazio que pode ser lido como “não havia chamados” — perda silenciosa de dados em vez de
erro visível.

**Correção.** Arquivo criado via `tempnam()` (escolhe diretório utilizável) e falha
registrada em `error_log()`. O conteúdo gerado é exatamente o esperado (verificado byte a
byte contra a versão original com diretório gravável).

---

### F9 — Divisão por zero em `mediaResposta` derrubava a página · `code/lib.php:107-117` · bug · **média** · confiança 88 · **corrigido**

**Mecanismo.** A função somava e contava em PHP e terminava em `return $soma / $qtd;`.
Com a tabela vazia (ou sem nenhum chamado respondido — o estado normal logo após a
instalação), `$qtd = 0` e a divisão por zero **lança `DivisionByError` no PHP 8**,
matando a requisição com erro fatal 500. Como o indicador fica no topo da listagem, o
painel inteiro cai.

**Impacto.** Indisponibilidade da página de listagem sempre que não houver amostra de SLA.

**Correção.** Soma e contagem passaram para o banco (`SUM`/`COUNT`) e o quociente só é
calculado com amostra; sem amostra, `mediaResposta()` devolve `0.0`. Reproduzido: com
todos os `minutos_resposta` em NULL, a página respondeu 200 com “Tempo medio de 1a
resposta: 0 min”. (O cálculo continua em PHP para preservar a precisão de float do
comportamento anterior — `AVG()` devolveria 4 casas decimais.)

---

### F10 — N+1 de consultas na listagem e no export · `code/lib.php:89` (e `:137`) · performance · **média** · confiança 95 · **corrigido**

**Mecanismo.** `listarChamados()` e `exportarCsv()` chamavam `tecnicoNome()` **dentro do
laço**, e `tecnicoNome()` executava `SELECT nome FROM usuarios WHERE id = …` a cada
chamado. Medido no *general log* do MySQL: **6 consultas** para listar 5 chamados.

**Impacto.** Latência e carga de banco proporcionais ao histórico, com efeito maior em
relatórios que percorrem a base inteira.

**Correção.** Os ids de técnico da página são resolvidos em **uma** consulta
(`WHERE id IN (…)`) e o resultado fica em cache por requisição, reutilizado por
`tecnicoNome()`. Medido novamente: **2 consultas** para os mesmos 5 chamados. Nenhum
formato de saída mudou.

---

### F11 — CSV injection (fórmula) pelo título do chamado · `code/lib.php:138-144` · segurança · **média** · confiança 78 · **corrigido**

**Mecanismo.** O título — texto digitado pelo cliente final — era gravado direto no CSV.
`fputcsv()` protege separador e aspas, mas **não** o que a planilha faz com o conteúdo:
Excel/Sheets/LibreOffice executam células iniciadas por `=`, `+`, `-` ou `@` como
fórmula. Um título `=HYPERLINK("http://x","clic")` chega executável a quem abre o
arquivo.

**Impacto.** Execução de ação pela planilha no computador de quem abre o export
(relatório gerencial, rotina interna) — exfiltração via fórmula externa ou ação
destrutiva.

**Correção.** `csvValorSeguro()` prefixa apóstrofo quando o valor começa por `=`, `+`,
`-`, `@`, tab, CR ou LF. Aplicado **apenas ao título** (dado do cliente); os demais
campos vêm de cadastro interno e mantêm o valor original — inclusive o placeholder `-`
de chamado sem técnico, que não pode ser alterado.

---

### F12 — Fixação de sessão e cookie sem `HttpOnly`/`SameSite` · `code/index.php:15` e `:24-27` · segurança · **média** · confiança 85 · **corrigido**

**Mecanismo.** `session_start()` sem configurar o cookie (PHP emite o `PHPSESSID`
padrão, sem `HttpOnly` e sem `SameSite`) e, no login, apenas `$_SESSION['uid']`/`['papel']`
eram preenchidos — **sem regenerar o id de sessão**. Um `PHPSESSID` fixado na vítima
continua válido após o login dela.

**Impacto.** Sequestro de sessão via id fixado; e, somado ao XSS de F3, o cookie
facilmente roubável por JavaScript.

**Correção.** Cookie emitido com `HttpOnly` e `SameSite=Lax` (`Secure` quando há HTTPS) e
`session_regenerate_id(true)` no login. Verificado: o id do cookie após o login é
diferente do id fixado antes do login.

---

### F13 — Parâmetros como array causavam erro fatal · `code/index.php:22` (e `:53`, `:72`) · bug · **média** · confiança 82 · **corrigido**

**Mecanismo.** `$_POST['login']`, `$_GET['busca']` e `$_GET['ver']` iam direto para
funções tipadas (`autenticar(..., string $usuario)`, `listarChamados(..., string $busca)`).
O PHP aceita `?login[]=x`, que transforma o valor em **array**; a coerção para
`string`/`int` lança `TypeError` e a requisição morre com **500**.

**Impacto.** Qualquer visitante derruba a página com uma URL malformada (DoS trivial),
com risco de expor caminho do arquivo se `display_errors` estiver ligado.

**Correção.** Entradas validadas antes do uso (`is_string()` para login/senha/busca, com
valor neutro; `(int)` apenas sobre string). Verificado com `login[]=x`, `busca[]=x`,
`ver=abc`, `ver=-1`: todas respondem 200 com o comportamento esperado.

---

### F14 — Curingas do `LIKE` não escapados · `code/lib.php:82` · bug · **baixa** · confiança 78 · **corrigido**

**Mecanismo.** A busca montava `LIKE '%<busca>%'` sem escapar os curingas: `%` e `_`
digitados pelo usuário eram interpretados pelo `LIKE`. Buscar por `%` devolvia **todos**
os chamados (5 de 5) em vez de zero.

**Impacto.** Filtro que aparenta funcionar e não filtra; facilitador para F1.

**Correção.** `escaparLike()` neutraliza `\`, `%` e `_` e a cláusula passa a usar
`ESCAPE`. Verificado: `%` e `_` → 0 linhas; `Lentidao` → exatamente o chamado 102.

---

### F15 — Chamado de prioridade crítica dentro do SLA rotulado como “Alto” · `code/lib.php:49-51` · qualidade · **baixa** · confiança 65 · **reportado, não corrigido**

**Mecanismo.** `rotuloPrioridade()` só distingue a criticidade (prioridade 4) no ramo de
SLA estourado (`CRITICO - SLA estourado`); dentro do prazo, **qualquer** prioridade ≥ 3
volta `Alto - dentro do SLA` — inclusive a prioridade 4, que o schema define como
“crítica” (`schema.sql:19`). O seed não cobre esse caso (a única linha com prioridade 4
tem `minutos_resposta` NULL), o que impede a detecção automática.

**Impacto.** Chamado crítico aparece como “Alto”, induzindo o técnico a tratá-lo com
prioridade menor que a declarada.

**Por que não corrigi.** Os rótulos de **status** são contrato de valor declarado no
manifesto; os de **prioridade** não são. Mas o relatório gerencial (que não está neste
pacote) pode consumir os textos atuais de `Alto - dentro do SLA`, e trocar esse texto
pode quebrar essa correspondência sem qualquer ganho observável para o cliente do painel.
Fica como recomendação para o próximo ciclo, junto com a definição de um SLA por
prioridade. **Decisão consciente, não esquecimento.**

---

### F16 — `mediaResposta` é um indicador global, visível também ao cliente · `code/lib.php:107-117` (e `code/index.php:74`, `:78`) · segurança · **baixa** · confiança 55 · **reportado, não corrigido**

**Mecanismo.** A média é calculada sobre todos os chamados, sem o filtro de dono aplicado
em F2, e o `index.php` a exibe no topo da listagem para qualquer usuário logado,
inclusive cliente.

**Impacto.** Um cliente infere um indicador agregado que inclui os chamados dos demais
clientes — valor agregado, sem identificar chamado nem cliente.

**Por que não corrigi.** `mediaResposta(mysqli $db): float` é assinatura pública consumida
pelo relatório gerencial; filtrar exigiria conhecer o usuário em contexto (inexistente
em script interno) e mudaria o valor do indicador no painel e no relatório sem que o
manifesto o peça. Risco de compatibilidade maior que o benefício — registrado como
decisão de produto.

---

## 4. Decisões — o que deliberadamente **não** mudei

| # | O que ficou como está | Por quê |
| --- | --- | --- |
| D1 | **Fallback da senha do banco** (`config.php:12`) | Segredo real, mas removê-lo derruba qualquer deploy/restore que não defina `DB_PASS` — risco de indisponibilidade inaceitável. Rotacionar + definir no ambiente + remover o fallback é o caminho seguro (F6). |
| D2 | **Assinaturas públicas** (7 funções + `tecnicoNome`) | O manifesto proíbe mudar a superfície pública. Por isso a regra de visibilidade foi implementada por contexto externo à assinatura, e **não** por um novo parâmetro em `listarChamados()`/`verChamado()`/`exportarCsv()` — acrescentar parâmetros quebraria a assinatura declarada. |
| D3 | **CSV do cliente restrito aos próprios chamados** | O manifesto descreve o CSV como “uma linha por chamado”, sem dizer de quem. Como a regra de visibilidade é declarada como obrigatória, o export do cliente respeita a mesma regra; o export do técnico e da rotina noturna segue sem filtro (comportamento histórico, byte a byte idêntico). |
| D4 | **Fallback de `formatarStatus` para “Resolvido”** em status fora de 1/2/3 | Comportamento histórico; o manifesto não define o caso inválido (o schema só permite 1/2/3). Alterar o texto pode quebrar o relatorio gerencial. O risco real (status inválido) é de qualidade de dados e deve ser tratado na entrada. |
| D5 | **Rótulo de prioridade para prioridade 4 dentro do SLA** | Texto possivelmente consumido por consumidor externo não presente no pacote (F15). |
| D6 | **`mediaResposta` sem filtro de dono** | Função pública usada pelo relatório gerencial; filtrar mudaria o indicador do painel sem pedido do manifesto (F16). |
| D7 | **`SELECT *` na listagem/export** | Reduziria tráfego, mas mudaria o conjunto de chaves devolvido por `listarChamados()`, que é parte do formato de retorno descrito no manifesto e pode ser usado pelo relatório gerencial. |
| D8 | **Paginação e índice para a busca por título** | Caminho natural de evolução, porém altera a forma do resultado (página, contagem) e o consumo de memória do painel — fora do escopo de uma manutenção corretiva. Registrado como recomendação. |
| D9 | **Rate limiting de login e CSRF no formulário** | Exige decidir onde guardar o contador (IP ou usuário) e qual o contrato de resposta de login falho, o que afeta consumidores do painel; não há evidência de abuso que justificasse o risco de compatibilidade. |
| D10 | **Stack e camada de acesso a dados** | O manifesto proíbe trocar a stack (as assinaturas recebem `mysqli $db`). Toda a correção foi feita dentro do que já existe (`prepare`/`bind_param`), sem dependência nova. |
| D11 | **`EXPORT_DIR` continua sendo o diretório preferido** | Respeita a configuração de operações; o risco foi eliminado pelo nome único + remoção após o envio. |

---

## 5. O que continua aberto (recomendações, fora do escopo desta entrega)

1. **Rotacionar** a senha do MySQL e a chave SMTP; definir `SMTP_API_KEY` e `DB_PASS` no
   ambiente; depois remover o fallback de `DB_PASS` (D1/F6).
2. **Rollout do hash**: código + `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`
   na mesma janela — a versão anterior do `autenticar()` não reconhece bcrypt (F4).
3. Definir **SLA por prioridade** e revisar os rótulos de prioridade com o dono do
   relatório gerencial (F15).
4. **Paginação + índice** da listagem (D8).
5. Política de retenção/expurgo do `EXPORT_DIR` e revisão do que mais vive no
   document root (F7).

---

## 6. Rastreabilidade

Cada achado deste relatório tem o mesmo identificador em `achados.json` (`F1`…`F16`), com
o mesmo arquivo, linha **da numeração original** do arquivo recebido, mecanismo,
severidade, confiança e a marca de “corrigido”. A lista `nao_alterei` do JSON espelha a
seção 4 acima (D1…D11).
