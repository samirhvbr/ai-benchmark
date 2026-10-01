<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Convenções desta revisão:
 *  - Todo SQL que recebe valor vindo de fora usa prepared statement.
 *  - As funções do manifesto continuam devolvendo os mesmos tipos de antes
 *    (valores como string, como o protocolo texto do mysqli entrega) — ver
 *    normalizarLinha().
 *  - A regra de visibilidade (cliente só vê os próprios chamados) fica nas
 *    variantes *DoUsuario() e em podeVerChamado(). As funções do manifesto
 *    continuam globais porque as rotinas internas (exportação noturna,
 *    relatório gerencial) precisam do conjunto completo.
 */

/** Hash bcrypt válido, de senha aleatória, usado só para igualar o custo de uma verificação. */
const SENHA_HASH_FICTICIO = '$2y$12$tVL/8Qo.sqWp11KnUYy9w.2zdrmiMHbja6htP1H3gioXOsHEM3en6';

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Aceita o hash legado (md5 sem sal, 32 hex) e o formato de password_hash().
 * Quando um usuário com hash legado autentica, o hash é migrado para
 * password_hash() — desde que a coluna já tenha sido alargada (ver schema.sql)
 * e o usuário do banco possa gravar em `usuarios`; caso contrário nada é escrito.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$linha) {
        // Mesmo custo de uma verificação real: o tempo de resposta não revela logins válidos.
        password_verify($senha, SENHA_HASH_FICTICIO);
        return null;
    }

    $hashArmazenado = (string) $linha['senha'];
    unset($linha['senha']);

    if (senhaEhMd5Legado($hashArmazenado)) {
        // A comparação antiga era feita pelo MySQL (case-insensitive); strtolower preserva isso.
        if (!hash_equals(strtolower($hashArmazenado), md5($senha))) {
            return null;
        }
        atualizarHashSenha($db, (int) $linha['id'], $senha);
        return $linha;
    }

    if (!password_verify($senha, $hashArmazenado)) {
        return null;
    }
    if (password_needs_rehash($hashArmazenado, PASSWORD_DEFAULT)) {
        atualizarHashSenha($db, (int) $linha['id'], $senha);
    }
    return $linha;
}

/** Verdadeiro se o valor armazenado ainda é um md5 hexadecimal (esquema legado). */
function senhaEhMd5Legado(string $hash): bool
{
    return preg_match('/^[0-9a-f]{32}$/i', $hash) === 1;
}

/**
 * Regrava a senha do usuário com password_hash(). Melhor esforço: nunca falha o login.
 * Não grava se a coluna não comporta o hash (truncar bloquearia o usuário).
 */
function atualizarHashSenha(mysqli $db, int $usuarioId, string $senha): void
{
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || strlen($novo) > capacidadeColunaSenha($db)) {
            return;
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->bind_param('si', $novo, $usuarioId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Sem privilégio de UPDATE, coluna antiga etc.: segue autenticando com o hash atual.
    }
}

/** Tamanho máximo (em caracteres) da coluna usuarios.senha no banco conectado; 0 se não souber. */
function capacidadeColunaSenha(mysqli $db): int
{
    try {
        $res = $db->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        );
        $linha = $res ? $res->fetch_assoc() : null;
        return $linha ? (int) $linha['n'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
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
 * Mesma tabela de saída da versão anterior (verificada caso a caso), em forma linear.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos <= 30) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id (usado por relatórios internos).
 * A listagem e o export já trazem o nome via JOIN e não chamam esta função.
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

/**
 * Prepared statements (mysqlnd) devolvem int/float nativos; consultas de texto
 * devolvem tudo como string. As funções do manifesto sempre entregaram string
 * aos consumidores — esta função mantém esse formato.
 */
function normalizarLinha(array $linha): array
{
    foreach ($linha as $coluna => $valor) {
        if ($valor !== null && !is_string($valor)) {
            $linha[$coluna] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Como listarChamados(), mas só com os chamados abertos por $usuarioId
 * (regra de visibilidade do cliente — manifest.md).
 */
function listarChamadosDoUsuario(mysqli $db, int $usuarioId, string $busca = ''): array
{
    return consultarChamados($db, $busca, $usuarioId);
}

/** Consulta comum às duas listagens. $apenasUsuarioId = null traz todos. */
function consultarChamados(mysqli $db, string $busca, ?int $apenasUsuarioId): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $condicoes = [];
    $tipos = '';
    $valores = [];
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $valores[] = '%' . $busca . '%';
    }
    if ($apenasUsuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $valores[] = $apenasUsuarioId;
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($valores) {
        $stmt->bind_param($tipos, ...$valores);
    }
    $stmt->execute();
    $linhas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map('normalizarLinha', $linhas);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Não aplica visibilidade: quem monta a tela decide com podeVerChamado().
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $linha ? normalizarLinha($linha) : null;
}

/**
 * Regra de negócio de visibilidade (manifest.md): técnico vê qualquer chamado;
 * cliente só vê os que ele mesmo abriu. Papel desconhecido é tratado como cliente.
 */
function podeVerChamado(array $chamado, int $usuarioId, string $papel): bool
{
    if ($papel === 'tecnico') {
        return true;
    }
    return isset($chamado['usuario_id']) && (int) $chamado['usuario_id'] === $usuarioId;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhuma primeira resposta registrada devolve 0.0 (antes: DivisionByZeroError).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query(
        'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd'
        . ' FROM chamados WHERE minutos_resposta IS NOT NULL'
    );
    $linha = $res ? $res->fetch_assoc() : null;
    if (!$linha || (int) $linha['qtd'] === 0) {
        return 0.0;
    }
    return (float) $linha['soma'] / (int) $linha['qtd'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    emitirCsvChamados($db, null);
}

/**
 * Como exportarCsv(), mas só com os chamados abertos por $usuarioId
 * (regra de visibilidade do cliente — manifest.md).
 */
function exportarCsvDoUsuario(mysqli $db, int $usuarioId): void
{
    emitirCsvChamados($db, $usuarioId);
}

/**
 * Escreve o CSV direto na saída (php://output), sem arquivo intermediário em disco.
 * Mesmo cabeçalho, ordem (id crescente) e escape da versão anterior.
 */
function emitirCsvChamados(mysqli $db, ?int $apenasUsuarioId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($apenasUsuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($apenasUsuarioId !== null) {
        $stmt->bind_param('i', $apenasUsuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
        fputcsv($saida, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($saida);
    $stmt->close();
}
