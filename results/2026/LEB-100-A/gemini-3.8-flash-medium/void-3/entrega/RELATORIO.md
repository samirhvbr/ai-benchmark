# Relatório Técnico de Avaliação e Manutenção — LEB-100-A

## 1. Resumo

O sistema legado do Painel de Chamados (NetX ISP) apresenta funcionamento básico para operações de suporte, porém acumulava vulnerabilidades críticas de segurança (injeção de SQL, Cross-Site Scripting refletido e quebra de controle de acesso/BOLA), gargalos severos de performance e escalabilidade (problema de N+1 queries na listagem e exportação, cálculo ineficiente em PHP da média de SLA e vazamento/acúmulo de arquivos temporários em disco no exportador CSV), além de divergências na regra de classificação de SLA para prioridade crítica e inconsistência na divisão por zero ao calcular métricas vazias.

Todas as correções necessárias foram implementadas preservando estritamente a superfície pública contratada no `manifest.md` (assinaturas de funções, tipos, estrutura da tabela HTML, parâmetros de rota e formato do CSV). Itens com risco de quebra de compatibilidade externa (algoritmo legado de hash de senhas MD5 e parâmetros de infraestrutura/credenciais herdadas) foram mantidos propositalmente e documentados.

---

## 2. Achados e Correções

### Achado F1: Injeção de SQL via concatenação direta do parâmetro de busca na listagem
- **Localização:** `code/lib.php`, linhas 80 a 85 (original)
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100
- **Mecanismo:** A função `listarChamados()` recebia o parâmetro `$busca` (originado diretamente de `$_GET['busca']`) e concatenava seu valor diretamente na string SQL (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`), executando via `$db->query($sql)`. Qualquer caractere de aspa simples `'` enviado pelo usuário permitia fechar o literal de string e injetar instruções SQL arbitrárias (SQL Injection baseado em erro ou booleano, ou UNION injection).
- **Impacto:** Um usuário autenticado (ou atacante com acesso à rota de listagem) podia extrair todo o banco de dados (tabela de usuários, hashes de senhas, chamados de outros clientes), burlar a autenticação ou comprometer a integridade dos dados no MySQL.
- **O que foi feito:** O fluxo de consulta foi migrado para prepared statements com parâmetro vinculado (`$stmt->prepare()`, `$stmt->bind_param('s', $param)` usando `%$busca%`), eliminando qualquer possibilidade de injeção de SQL na filtragem.
- **Status:** Corrigido (`corrigido: true`).

---

### Achado F2: Violação de controle de acesso (BOLA/IDOR) e vazamento de chamados entre clientes
- **Localização:** `code/index.php`, linhas 52 a 58 e linhas 72 a 73 (original)
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100
- **Mecanismo:** De acordo com o `manifest.md`, clientes só podem visualizar chamados abertos por eles mesmos, enquanto técnicos podem ver todos os chamados. No entanto, `index.php` repassava `listarChamados($db, $busca)` para a tabela sem filtrar por `usuario_id` para usuários com `papel === 'cliente'`. Além disso, na rota de visualização detalhada (`index.php?ver=<id>`), a aplicação apenas carregava `verChamado($db, (int)$_GET['ver'])` e exibia os dados sem verificar se o cliente autenticado (`$_SESSION['uid']`) era o proprietário (`usuario_id`) do chamado.
- **Impacto:** Qualquer cliente autenticado conseguia ler todos os chamados da empresa na listagem ou acessar o chamado de qualquer outro cliente simplesmente alterando o ID no parâmetro `?ver=103`, expondo dados confidenciais e quebrando a regra de negócio central.
- **O que foi feito:** No `index.php`, na rota de listagem, adicionou-se a filtragem dos chamados para que clientes vejam exclusivamente chamados com `usuario_id === $uid` (preservando o retorno irrestrito para técnicos e mantendo a assinatura pública de `listarChamados`). Na rota de detalhes (`?ver=`), adicionou-se verificação: se o usuário for cliente e `usuario_id !== $uid`, a visualização é bloqueada exibindo "Chamado nao encontrado." com código de saída padrão.
- **Status:** Corrigido (`corrigido: true`).

---

### Achado F3: Injeção de SQL na consulta individual por ID em verChamado e tecnicoNome
- **Localização:** `code/lib.php`, linhas 69 a 70 e linha 100 (original)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 95
- **Mecanismo:** Embora `tecnicoNome` e `verChamado` utilizassem type hint `int` em suas assinaturas, a montagem da SQL era feita por concatenação direta (`SELECT * FROM chamados WHERE id = ' . $id` e `WHERE id = ' . $tecnicoId`). Se chamadas internamente ou por scripts legados em contextos onde o tipo pudesse ser burlado ou caso o padrão fosse reutilizado com strings, ocorria injeção. O uso de interpolação não preparada fere as práticas de segurança e robustez de banco de dados.
- **Impacto:** Risco de exploração em chamadas diretas ou integração com componentes externos do ISP caso o valor fosse propagado sem validação estrita prévia.
- **O que foi feito:** Ambas as funções foram refatoradas para utilizar queries parametrizadas com `$db->prepare()` e `bind_param('i', ...)`.
- **Status:** Corrigido (`corrigido: true`).

---

### Achado F4: Cross-Site Scripting (XSS) refletido no parâmetro de busca
- **Localização:** `code/index.php`, linhas 79 e 82 (original)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100
- **Mecanismo:** Em `code/index.php`, o valor da variável `$busca` (obtida de `$_GET['busca']`) era interpolado diretamente no HTML sem sanitização nem escape: `<input name="busca" value="' . $busca . '"...>` e `<p>Resultados para: ' . $busca . '</p>`.
- **Impacto:** Um invasor poderia induzir um usuário (ou operador técnico) a clicar em um link malicioso contendo payloads JavaScript (ex.: `index.php?busca="><script>alert(document.cookie)</script>`), sequestrando sessões ou executando ações não autorizadas em nome da vítima.
- **O que foi feito:** Aplicou-se `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` tanto no atributo `value` do formulário quanto no parágrafo de exibição do termo buscado.
- **Status:** Corrigido (`corrigido: true`).

---

### Achado F5: Gargalo de performance N+1 queries na listagem de chamados e no exportador CSV
- **Localização:** `code/lib.php`, linhas 85 a 91 e linhas 132 a 138 (original)
- **Categoria:** `performance`
- **Severidade:** `alta`
- **Confiança:** 95
- **Mecanismo:** Tanto na listagem (`listarChamados`) quanto no CSV (`exportarCsv`), o código realizava uma consulta para buscar os chamados e, dentro de um laço `while`, executava individualmente uma consulta `SELECT nome FROM usuarios WHERE id = ...` chamando `tecnicoNome()` para cada registro encontrado. Para $N$ chamados, eram disparadas $N+1$ queries no banco de dados.
- **Impacto:** Degradação severa no tempo de resposta com o crescimento do banco de dados, alto consumo de conexões e latência excessiva no MySQL, tornando a listagem lenta e o download do CSV propenso a timeouts.
- **O que foi feito:** As consultas de `listarChamados()` e `exportarCsv()` foram otimizadas utilizando `LEFT JOIN usuarios u ON c.tecnico_id = u.id` diretamente no SQL, trazendo o nome do técnico em uma única query otimizada e preenchendo a chave `tecnico_nome`. A função `tecnicoNome()` foi mantida funcional e segura para compatibilidade com chamadas externas.
- **Status:** Corrigido (`corrigido: true`).

---

### Achado F6: Gravação desnecessária em disco e vazamento de arquivos temporários no exportador CSV
- **Localização:** `code/lib.php`, linhas 125 a 150 (original)
- **Categoria:** `arquitetura`
- **Severidade:** `media`
- **Confiança:** 95
- **Mecanismo:** A função `exportarCsv()` abria um arquivo físico no caminho `EXPORT_DIR . '/chamados.csv'`, escrevia os dados, enviava os headers HTTP e lia o arquivo com `readfile()`. Porém, nunca removia o arquivo após o envio e usava um caminho fixo (`chamados.csv`).
- **Impacto:** Condição de corrida quando múltiplos usuários exportavam o CSV simultaneamente (gerando corrupção de dados ou conteúdo truncado), falha de permissão de escrita caso o diretório `/var/www/painel/tmp` não existisse no host, e retenção indevida de dados no filesystem local.
- **O que foi feito:** O fluxo de exportação foi reescrito para enviar os dados diretamente ao fluxo de saída HTTP via `php://output` com os headers apropriados (`Content-Type: text/csv` e `Content-Disposition`), dispensando gravação em disco local e evitando conflitos de concorrência.
- **Status:** Corrigido (`corrigido: true`).

---

### Achado F7: Divisão por zero em mediaResposta quando não existem chamados com resposta
- **Localização:** `code/lib.php`, linhas 110 a 116 (original)
- **Categoria:** `bug`
- **Severidade:** `media`
- **Confiança:** 95
- **Mecanismo:** A função iterava sobre os registros com `minutos_resposta IS NOT NULL` e calculava `$soma / $qtd`. Caso o banco estivesse vazio ou nenhum chamado possuísse `minutos_resposta` preenchido, `$qtd` permanecia `0`, resultando em erro fatal (`DivisionByZeroError`) no PHP 8+.
- **Impacto:** Quebra total do painel web (`Fatal Error`) e impedimento de carregamento da página inicial para bases novas ou sem chamados respondidos.
- **O que foi feito:** A consulta foi simplificada para delegar ao banco o cálculo com `SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL`. Caso não haja registros (`media === null`), a função retorna de forma segura `0.0`.
- **Status:** Corrigido (`corrigido: true`).

---

### Achado F8: Lógica de classificação de prioridade crítica com limite de operador incorreto
- **Localização:** `code/lib.php`, linha 45 (original)
- **Categoria:** `qualidade`
- **Severidade:** `baixa`
- **Confiança:** 85
- **Mecanismo:** Em `rotuloPrioridade()`, para chamados com tempo excedido (`$minutos > 30` e `$prioridade >= 3`), a condição para rotular como crítico checava `if ($prioridade == 4)`. O uso de igualdade estrita para prioridades críticas ou extensões de valores (onde a tabela define 4=crítica) é mais defensivamente tratado com `$prioridade >= 4`, alinhando-se à verificação hierárquica `$prioridade >= 3`.
- **Impacto:** Classificações futuras ou integrações com níveis de severidade estendidos poderiam cair erroneamente no ramo "Alto - atrasado".
- **O que foi feito:** A condição foi ajustada para `$prioridade >= 4`.
- **Status:** Corrigido (`corrigido: true`).

---

## 3. Decisões — O que NÃO foi alterado e por quê

1. **Algoritmo de Hash de Senhas (MD5 legado):**
   - *Decisão:* Mantido o algoritmo `MD5` na função `autenticar()` e nas consultas.
   - *Justificativa:* A coluna `senha` na tabela `usuarios` está tipada como `CHAR(32)` e o banco já se encontra populado com hashes MD5 em produção (conforme `schema.sql` e `seed.sql`). Migrar para `password_hash()` (Bcrypt/Argon2) exigiria alteração estrutural no banco de dados (`ALTER TABLE`) e processo de re-hashing das credenciais dos clientes e técnicos do ISP, o que quebraria os testes automatizados existentes e a compatibilidade do ambiente sem um plano prévio de migração.

2. **Uso da extensão mysqli:**
   - *Decisão:* Não migrado para PDO.
   - *Justificativa:* O `manifest.md` define expressamente que todas as funções públicas recebem instâncias de `mysqli $db` como contrato estrito. Trocar a camada de persistência para PDO violaria a superfície pública do sistema.

3. **Constantes e Fallbacks de Configuração (`config.php`):**
   - *Decisão:* As constantes `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `SMTP_API_KEY` e `EXPORT_DIR` foram preservadas em `config.php`.
   - *Justificativa:* Fazem parte da infraestrutura legada consumida pelo ambiente de produção. Remover os fallbacks ou alterar as constantes quebraria a inicialização do container ou da aplicação nos testes que dependem das variáveis padrão.

4. **Nomes e assinaturas de funções públicas:**
   - *Decisão:* `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` tiveram suas assinaturas, tipos e formatos de retorno 100% preservados, assim como os rótulos de status (`1 → "Aberto"`, `2 → "Em atendimento"`, `3 → "Resolvido"`).
   - *Justificativa:* Exigência contratual expressa do `manifest.md`.
