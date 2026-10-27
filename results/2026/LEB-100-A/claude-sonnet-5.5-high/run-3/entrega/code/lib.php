<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $hash = md5($senha);
    $stmt = $db->prepare('SELECT id, nome, papel FROM usuarios WHERE login = ? AND senha = ?');
    $stmt->bind_param('ss', $usuario, $hash);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha ?: null;
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
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
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
    return $t ? $t['nome'] : '-';
}

/**
 * Consulta de chamados (com 'tecnico_nome') em uma única query.
 * $usuarioId !== null restringe aos chamados abertos por esse usuário.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId, string $ordem): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $where = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $where[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . addcslashes($busca, '\\%_') . '%';
    }
    if ($usuarioId !== null) {
        $where[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $usuarioId;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $ordem;

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Sem filtro de dono: uso interno/técnico. Para clientes, ver listarChamadosDoUsuario().
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'c.criado_em DESC');
}

/**
 * Igual a listarChamados(), mas só com os chamados abertos por $usuarioId (visão do cliente).
 */
function listarChamadosDoUsuario(mysqli $db, int $usuarioId, string $busca = ''): array
{
    return consultarChamados($db, $busca, $usuarioId, 'c.criado_em DESC');
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * O chamado traz 'usuario_id'; quem expõe o resultado deve checar a visibilidade.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Regra de visibilidade: técnico vê qualquer chamado; cliente só os que abriu.
 * Papel desconhecido é tratado como cliente (nega por padrão).
 */
function chamadoVisivel(array $chamado, int $uid, string $papel): bool
{
    return $papel === 'tecnico' || (int) $chamado['usuario_id'] === $uid;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido, devolve 0.0 (antes: divisão por zero).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    return $row['media'] === null ? 0.0 : (float) $row['media'];
}

/**
 * Neutraliza "injeção de fórmula" em planilhas: campo de texto livre que começa
 * com = + - @ (ou tab/CR) é prefixado com apóstrofo.
 */
function csvSeguro(string $valor): string
{
    return $valor !== '' && strpbrk($valor[0], "=+-@\t\r") !== false ? "'" . $valor : $valor;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * $usuarioId !== null restringe aos chamados abertos por esse usuário (visão do cliente).
 * Escreve direto na saída: não há mais arquivo compartilhado em EXPORT_DIR.
 */
function exportarCsvDoUsuario(mysqli $db, ?int $usuarioId): void
{
    $chamados = consultarChamados($db, '', $usuarioId, 'c.id');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    foreach ($chamados as $c) {
        fputcsv($fp, [
            $c['id'],
            csvSeguro($c['titulo']),
            formatarStatus((int) $c['status']),
            csvSeguro($c['tecnico_nome']),
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
}

/**
 * Exporta todos os chamados (uso interno / rotina noturna).
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvDoUsuario($db, null);
}
