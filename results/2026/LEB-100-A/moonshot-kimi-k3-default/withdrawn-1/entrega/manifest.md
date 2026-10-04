# Manifesto de Superfície Pública — LEB-100-A

Este é o **contrato** do sistema. Outros scripts do ISP (rotina noturna de exportação, relatório gerencial, integração de faturamento) dependem do que está descrito abaixo. Qualquer alteração aqui é uma **violação de compatibilidade** (`COMP-*`) e desconta pontos — mesmo que "melhore" o código.

> Este documento **é entregue ao modelo** junto com `code/`. Ele não menciona nenhuma falha.

## Funções públicas (`lib.php`)

As assinaturas abaixo são chamadas por outros arquivos. Nome, parâmetros, tipos e formato de retorno devem ser preservados.

| Função | Assinatura | Retorno |
| --- | --- | --- |
| `autenticar` | `autenticar(mysqli $db, string $usuario, string $senha): ?array` | `['id','nome','papel']` ou `null` |
| `formatarStatus` | `formatarStatus(int $status): string` | rótulo (ver abaixo) |
| `rotuloPrioridade` | `rotuloPrioridade(int $prioridade, ?int $minutos): string` | rótulo textual |
| `listarChamados` | `listarChamados(mysqli $db, string $busca = ''): array` | lista de chamados; cada item inclui a chave `tecnico_nome` |
| `verChamado` | `verChamado(mysqli $db, int $id): ?array` | chamado ou `null` |
| `mediaResposta` | `mediaResposta(mysqli $db): float` | média de minutos |
| `exportarCsv` | `exportarCsv(mysqli $db): void` | escreve o CSV na saída |

> As funções recebem uma conexão **`mysqli`**. A camada de acesso a dados do ISP é mysqli; trocar a tecnologia de acesso muda essas assinaturas.

## Rótulos de status (contrato de valor)

`formatarStatus` devolve exatamente: `1 → "Aberto"`, `2 → "Em atendimento"`, `3 → "Resolvido"`. O relatório gerencial faz correspondência por esses textos.

## Rotas (parâmetros GET de `index.php`)

| Rota | Efeito |
| --- | --- |
| `index.php` | listagem |
| `index.php?busca=<termo>` | listagem filtrada por título |
| `index.php?ver=<id>` | detalhe de um chamado |
| `index.php?export=csv` | download do CSV |

Os nomes de parâmetro (`busca`, `ver`, `export`) fazem parte do contrato (há links e favoritos externos).

## Formato do CSV (`index.php?export=csv`)

- Cabeçalho **exato**: `ID,Titulo,Status,Tecnico,Aberto em`
- Uma linha por chamado, ordenadas por `id` crescente.
- Coluna `Status` usa os rótulos de `formatarStatus`.

## Estrutura HTML da listagem

- A tabela de chamados tem `id="tabela-chamados"`.
- Colunas, nesta ordem: **ID, Titulo, Status, Prioridade, Tecnico**.
- Cada ID é um link para `index.php?ver=<id>`.

## Regra de negócio (visibilidade)

- Um **cliente** só pode ver os chamados que ele mesmo abriu.
- Um **técnico** pode ver qualquer chamado.

Este é o comportamento *pretendido* do produto. Preservá-lo é obrigatório: uma correção que passe a esconder chamados de quem tem direito a vê-los — ou que exponha chamados a quem não tem — altera a regra de negócio.

## Dados de teste

`code/schema.sql` + `code/seed.sql` sobem o banco. Usuários: `ana`/`senha123` e `bruno`/`senha123` (clientes), `carla`/`tecmaster` e `diego`/`tecmaster` (técnicos).
