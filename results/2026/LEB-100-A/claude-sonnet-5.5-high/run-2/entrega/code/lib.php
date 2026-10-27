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
    }
    if ($status == 2) {
        return 'Em atendimento';
    }
    return 'Resolvido';
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
 * Busca o nome de um técnico pelo id.
 * Mantida por compatibilidade; listagem e export já trazem o nome via JOIN.
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
 * Executa um SELECT parametrizado e devolve as linhas com valores em string
 * (ou null), o mesmo formato que ->query() devolvia antes — os consumidores
 * externos recebem os mesmos tipos de sempre.
 */
function selecionarLinhas(mysqli $db, string $sql, string $tipos = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $linhas = [];
    while ($c = $res->fetch_assoc()) {
        foreach ($c as $k => $v) {
            $c[$k] = $v === null ? null : (string) $v;
        }
        $linhas[] = $c;
    }
    $stmt->close();
    return $linhas;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * $usuarioId (opcional): quando informado, devolve só os chamados abertos por
 * esse usuário (regra de visibilidade do cliente). Sem ele, lista todos
 * (comportamento dos scripts internos e do técnico).
 */
function listarChamados(mysqli $db, string $busca = '', ?int $usuarioId = null): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $cond = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $cond[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($usuarioId !== null) {
        $cond[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $usuarioId;
    }
    if ($cond) {
        $sql .= ' WHERE ' . implode(' AND ', $cond);
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    return selecionarLinhas($db, $sql, $tipos, $params);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * $usuarioId (opcional): se informado, só devolve o chamado se for desse dono.
 */
function verChamado(mysqli $db, int $id, ?int $usuarioId = null): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $params = [$id];
    if ($usuarioId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $params[] = $usuarioId;
    }
    $linhas = selecionarLinhas($db, $sql, $tipos, $params);
    return $linhas[0] ?? null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido devolve 0.0 (antes: divisão por zero).
 */
function mediaResposta(mysqli $db): float
{
    // SUM/COUNT no banco e divisão em PHP: AVG() do SQL arredonda a 4 casas e
    // alteraria o valor devolvido aos consumidores.
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(*) AS qtd FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    return $qtd > 0 ? (int) $row['soma'] / $qtd : 0.0;
}

/**
 * Exporta os chamados em CSV direto para a saída (download).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * $usuarioId (opcional): restringe aos chamados desse dono (cliente).
 */
function exportarCsv(mysqli $db, ?int $usuarioId = null): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $params[] = $usuarioId;
    }
    $sql .= ' ORDER BY c.id';
    // consulta antes de qualquer saída: se falhar, não sai CSV pela metade
    $linhas = selecionarLinhas($db, $sql, $tipos, $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    foreach ($linhas as $c) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
}
