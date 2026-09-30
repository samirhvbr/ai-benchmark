<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Convenções desta camada:
 *  - toda entrada externa chega ao SQL por prepared statement (bind_param);
 *  - listarChamados/verChamado devolvem os valores como string|null, como o
 *    mysqli::query() legado sempre devolveu (ver normalizarLinha);
 *  - as funções do manifesto continuam devolvendo TODOS os chamados; a regra
 *    de visibilidade (cliente só vê o que abriu, técnico vê tudo) é aplicada
 *    pelas variantes *Visiveis, que o index.php usa.
 */

/** Limite (minutos) da 1ª resposta para o SLA de prioridade alta/crítica. */
const SLA_PRIMEIRA_RESPOSTA_MINUTOS = 30;

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Aceita dois formatos em usuarios.senha:
 *  - password_hash() (bcrypt/argon2): formato atual;
 *  - md5 sem salt (32 hex): legado. Ao autenticar com sucesso o hash é
 *    regravado em password_hash(), de forma transparente (migrarHashSenha).
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $hash = (string) $linha['senha'];
    unset($linha['senha']);

    if (senhaEhMd5Legado($hash)) {
        // comparação em tempo constante; strtolower preserva a comparação
        // case-insensitive que a collation da coluna fazia no SQL
        if (!hash_equals(strtolower($hash), md5($senha))) {
            return null;
        }
        migrarHashSenha($db, (int) $linha['id'], $senha, $hash);
        return $linha;
    }

    if (!password_verify($senha, $hash)) {
        return null;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        migrarHashSenha($db, (int) $linha['id'], $senha, $hash);
    }
    return $linha;
}

/** Hash legado: md5 em hexadecimal (32 caracteres). */
function senhaEhMd5Legado(string $hash): bool
{
    return preg_match('/^[0-9a-fA-F]{32}$/', $hash) === 1;
}

/**
 * Regrava a senha do usuário com password_hash(). Melhor esforço: nunca
 * impede um login já validado. Só grava se a coluna comporta o hash novo
 * (a coluna legada é CHAR(32); ver schema.sql) — evita truncar o hash e
 * trancar o usuário fora. O WHERE senha = ? evita corrida entre dois logins.
 */
function migrarHashSenha(mysqli $db, int $id, string $senha, string $hashAtual): void
{
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || !colunaSenhaComporta($db, strlen($novo))) {
            return;
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $stmt->bind_param('sis', $novo, $id, $hashAtual);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('painel: falha ao migrar hash de senha do usuario ' . $id . ': ' . $e->getMessage());
    }
}

/** Verdadeiro se usuarios.senha aceita um valor com $tamanho caracteres. */
function colunaSenhaComporta(mysqli $db, int $tamanho): bool
{
    static $capacidade = null;
    if ($capacidade === null) {
        $res = $db->query(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        );
        $row = $res ? $res->fetch_assoc() : null;
        $capacidade = $row ? (int) $row['n'] : 0;
    }
    return $capacidade >= $tamanho;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status == 1) {
        return 'Aberto';
    } else if ($status == 2) {
        return 'Em atendimento';
    } else {
        return 'Resolvido';
    }
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 * Mesma tabela de decisão de sempre, em forma plana:
 *   sem 1ª resposta            -> 'Aguardando 1a resposta'
 *   prioridade < 3             -> 'Normal'
 *   resposta dentro do SLA     -> 'Alto - dentro do SLA'
 *   fora do SLA, prioridade 4  -> 'CRITICO - SLA estourado'; demais -> 'Alto - atrasado'
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos <= SLA_PRIMEIRA_RESPOSTA_MINUTOS) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade === 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id. Mantida para quem a chama de fora; a
 * listagem e o export não a usam mais (o nome vem por JOIN, sem N+1).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $t ? (string) $t['nome'] : '-';
}

/** Técnico vê qualquer chamado; qualquer outro papel é tratado como cliente. */
function ehTecnico(string $papel): bool
{
    return $papel === 'tecnico';
}

/** Regra de visibilidade do manifesto: cliente só vê o chamado que ele mesmo abriu. */
function podeVerChamado(array $chamado, int $uid, string $papel): bool
{
    return ehTecnico($papel) || (isset($chamado['usuario_id']) && (int) $chamado['usuario_id'] === $uid);
}

/**
 * Devolve os valores como string|null, exatamente como o mysqli::query()
 * legado entregava (protocolo de texto). Prepared statements usam o protocolo
 * binário e devolveriam int nas colunas inteiras — isso mudaria o formato de
 * retorno visto por outros scripts (comparação estrita, json_encode).
 */
function normalizarLinha(array $linha): array
{
    foreach ($linha as $k => $v) {
        if ($v !== null && !is_string($v)) {
            $linha[$k] = (string) $v;
        }
    }
    return $linha;
}

/**
 * Consulta interna compartilhada pela listagem e pelo CSV.
 * Cada linha traz todas as colunas de `chamados` mais 'tecnico_nome' (nome do
 * técnico ou '-'), resolvido por LEFT JOIN em uma única consulta.
 *
 * @param string   $busca   filtro por título: substring literal (curingas do LIKE escapados)
 * @param int|null $donoId  null = todos os chamados; id = só os abertos por esse usuário
 * @param string   $ordem   'recentes' (criado_em DESC, listagem) ou 'id' (id ASC, CSV)
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId, string $ordem): array
{
    $ordens = ['recentes' => 'c.criado_em DESC', 'id' => 'c.id ASC'];
    if (!isset($ordens[$ordem])) {
        throw new InvalidArgumentException('ordem invalida: ' . $ordem);
    }

    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    $tipos = '';
    $valores = [];
    if ($busca !== '') {
        // '!' como caractere de escape do LIKE: independe do sql_mode
        // (NO_BACKSLASH_ESCAPES) e o padrão inteiro vai como parâmetro.
        $condicoes[] = "c.titulo LIKE ? ESCAPE '!'";
        $tipos .= 's';
        $valores[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busca) . '%';
    }
    if ($donoId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $valores[] = $donoId;
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY ' . $ordens[$ordem];

    $stmt = $db->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = normalizarLinha($c);
    }
    $stmt->close();
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'recentes');
}

/**
 * Listagem já restrita ao que o usuário pode ver (regra de visibilidade do
 * manifesto). Usada pelo index.php; o filtro é aplicado no SQL.
 */
function listarChamadosVisiveis(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarChamados($db, $busca, ehTecnico($papel) ? null : $uid, 'recentes');
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Não aplica a regra de visibilidade: quem chama decide (index.php usa podeVerChamado).
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $c ? normalizarLinha($c) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhuma 1ª resposta registrada devolve 0.0 (antes: divisão por zero).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $row = $res ? $res->fetch_assoc() : null;
    $qtd = $row ? (int) $row['qtd'] : 0;
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

/** Mesmo CSV de exportarCsv, restrito ao que o usuário pode ver. */
function exportarCsvVisiveis(mysqli $db, int $uid, string $papel): void
{
    escreverCsv($db, ehTecnico($papel) ? null : $uid);
}

/**
 * Escreve o CSV direto na saída (php://output): nada vai para disco, então não
 * há arquivo compartilhado entre requisições nem cópia alcançável pela web.
 * A consulta roda antes de qualquer cabeçalho, para uma falha não virar um
 * download parcial com status 200.
 */
function escreverCsv(mysqli $db, ?int $donoId): void
{
    $chamados = consultarChamados($db, '', $donoId, 'id');

    $saida = fopen('php://output', 'w');
    if ($saida === false) {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }
    // separador/delimitador/escape explícitos: bytes idênticos aos de antes e sem o
    // aviso de depreciação do PHP 8.4 (que, com display_errors, contaminava o CSV)
    fputcsv($saida, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    foreach ($chamados as $c) {
        fputcsv($saida, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($saida);
}
