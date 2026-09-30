<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Visibilidade: listarChamados(), verChamado() e exportarCsv() mantêm a assinatura e o
 * comportamento históricos — SEM filtro por usuário, pois atendem rotinas internas
 * confiáveis. O painel web NUNCA deve chamá-las direto: usa as variantes *DoEscopo(),
 * que aplicam a regra "cliente só vê os chamados que abriu; técnico vê todos".
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
 * Legado preservado: todo valor diferente de 1 e 2 (inclusive inesperados, como 0 ou 4) vira 'Resolvido'.
 */
function formatarStatus(int $status): string
{
    $rotulos = [1 => 'Aberto', 2 => 'Em atendimento', 3 => 'Resolvido'];
    return $rotulos[$status] ?? 'Resolvido';
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    $limiteSla = 30; // minutos até a 1a resposta

    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos <= $limiteSla) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Executa uma consulta parametrizada e devolve o mysqli_result.
 * Falhas viram exceção qualquer que seja o mysqli_report() do chamador.
 */
function consultaPreparada(mysqli $db, string $sql, string $tipos = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar consulta: ' . $db->error);
    }
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar consulta: ' . $stmt->error);
    }
    $res = $stmt->get_result();
    if ($res === false) {
        throw new RuntimeException('Falha ao ler o resultado da consulta: ' . $stmt->error);
    }
    return $res;
}

/**
 * Normaliza uma linha de statement preparado para o formato histórico do modo texto:
 * todo valor não nulo é string. O retorno de listarChamados()/verChamado() sempre foi assim
 * e consumidores externos podem depender dos tipos.
 */
function chamadoComoTexto(array $linha): array
{
    foreach ($linha as $campo => $valor) {
        if ($valor !== null) {
            $linha[$campo] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Minutos até a 1a resposta como int, ou null se o chamado ainda não teve resposta.
 */
function minutosRespostaDe(array $chamado): ?int
{
    return $chamado['minutos_resposta'] !== null ? (int) $chamado['minutos_resposta'] : null;
}

/**
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $res = consultaPreparada($db, 'SELECT nome FROM usuarios WHERE id = ?', 'i', [$tecnicoId]);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Regra de negócio de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só
 * os que abriu. Devolve o id do dono a que as consultas devem ser restritas, ou null quando
 * não há restrição. Qualquer papel diferente de 'tecnico' é tratado como cliente (falha fechada).
 */
function escopoChamados(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Lista os chamados visíveis dentro do escopo ($donoId null = todos), opcionalmente
 * filtrando pelo título. Cada chamado vem com a chave extra 'tecnico_nome' ('-' sem técnico).
 * O nome do técnico vem no mesmo SELECT (LEFT JOIN): antes era 1 consulta por chamado.
 */
function listarChamadosDoEscopo(mysqli $db, ?int $donoId, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $res = consultaPreparada($db, $sql, $tipos, $params);
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = chamadoComoTexto($c);
    }
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Sem filtro de visibilidade — ver o aviso no topo do arquivo.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosDoEscopo($db, null, $busca);
}

/**
 * Carrega um chamado pelo id dentro do escopo ($donoId null = qualquer). Devolve null
 * tanto para chamado inexistente quanto para chamado de outro dono (não revela a diferença).
 */
function verChamadoDoEscopo(mysqli $db, ?int $donoId, int $id): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $params = [$id];
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    $linha = consultaPreparada($db, $sql, $tipos, $params)->fetch_assoc();
    return $linha ? chamadoComoTexto($linha) : null;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Sem filtro de visibilidade — ver o aviso no topo do arquivo.
 */
function verChamado(mysqli $db, int $id): ?array
{
    return verChamadoDoEscopo($db, null, $id);
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Agregada no banco (antes lia todas as linhas em PHP); 0.0 quando ainda não há nenhuma
 * resposta registrada (antes: DivisionByZeroError derrubava a listagem).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Neutraliza injeção de fórmula em planilhas: texto iniciado por = + - @ (ou TAB/CR) é
 * interpretado como fórmula pelo Excel/LibreOffice. O apóstrofo inicial faz a planilha
 * exibir o valor como texto literal.
 */
function celulaCsvSegura(string $valor): string
{
    if ($valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Escreve na saída o CSV dos chamados do escopo ($donoId null = todos), ordenados por id.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Vai direto para a saída (sem arquivo intermediário em disco).
 */
function exportarCsvDoEscopo(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $params[] = $donoId;
    }
    $sql .= ' ORDER BY c.id';
    $res = consultaPreparada($db, $sql, $tipos, $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    // 5o argumento explícito ('\\' = padrão histórico): o padrão implícito é depreciado no PHP 8.4.
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
        fputcsv($out, [
            $c['id'],
            celulaCsvSegura($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($out);
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Sem filtro de visibilidade — ver o aviso no topo do arquivo.
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvDoEscopo($db, null);
}
