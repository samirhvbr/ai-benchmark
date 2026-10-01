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
 * LEGADO: a senha é guardada como md5 sem sal (schema: senha CHAR(32)). Trocar o hash exige
 * alterar a coluna e coordenar com os demais sistemas que leem esta tabela (ver RELATORIO.md, F7).
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
    $limiteSlaMinutos = 30;

    // Sem 1ª resposta registrada ainda.
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    // Abaixo de "alta" (3) o SLA não altera o rótulo.
    if ($prioridade < 3) {
        return 'Normal';
    }
    // Alta (3) e crítica (4).
    if ($minutos <= $limiteSlaMinutos) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id.
 * Não é mais usada por listarChamados/exportarCsv (que resolvem o nome com JOIN, em vez de
 * uma consulta por linha); mantida para os relatórios internos que possam chamá-la.
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
 * Lista TODOS os chamados, opcionalmente filtrando pelo título (uso interno e relatórios).
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Telas voltadas ao usuário devem usar listarChamadosVisiveis() (regra de visibilidade).
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Não aplica a regra de visibilidade: quem expõe o chamado a um usuário deve usar podeVerChamado().
 */
function verChamado(mysqli $db, int $id): ?array
{
    $res = consultaPreparada($db, 'SELECT * FROM chamados WHERE id = ?', 'i', [$id]);
    $c = $res->fetch_assoc();
    return $c ? linhaComoTexto($c) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Devolve 0.0 quando ainda não há nenhum chamado respondido.
 */
function mediaResposta(mysqli $db): float
{
    $res = consultaPreparada($db, 'SELECT COUNT(minutos_resposta) AS qtd, SUM(minutos_resposta) AS soma FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (float) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV, escrevendo-o na saída (resposta HTTP ou stdout).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Telas voltadas ao usuário devem usar exportarCsvVisiveis() (regra de visibilidade).
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

// ---------------------------------------------------------------------------
// Visibilidade (regra de negócio do manifest.md) e auxiliares internos.
// São acréscimos: nenhuma assinatura pública acima foi alterada.
// ---------------------------------------------------------------------------

/**
 * Regra de visibilidade: técnico vê qualquer chamado; cliente só os que ele mesmo abriu.
 * Qualquer papel diferente de 'tecnico' é tratado como cliente (nega por padrão).
 */
function podeVerChamado(array $chamado, int $usuarioId, string $papel): bool
{
    return $papel === 'tecnico' || (int) $chamado['usuario_id'] === $usuarioId;
}

/**
 * Como listarChamados(), mas só com os chamados que o usuário pode ver.
 */
function listarChamadosVisiveis(mysqli $db, int $usuarioId, string $papel, string $busca = ''): array
{
    return consultarChamados($db, $busca, $papel === 'tecnico' ? null : $usuarioId);
}

/**
 * Como exportarCsv(), mas só com os chamados que o usuário pode ver.
 */
function exportarCsvVisiveis(mysqli $db, int $usuarioId, string $papel): void
{
    escreverCsv($db, $papel === 'tecnico' ? null : $usuarioId);
}

/**
 * Consulta base dos chamados, já com 'tecnico_nome' (um JOIN em vez de uma consulta por linha).
 * Com $usuarioId, restringe aos chamados abertos por esse usuário. O termo de busca é sempre
 * um parâmetro da consulta, nunca parte do SQL.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $params = [];
    if ($usuarioId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $usuarioId;
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $res = consultaPreparada($db, $sql, $tipos, $params);
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = linhaComoTexto($c);
    }
    return $chamados;
}

/**
 * Escreve o CSV de chamados direto na saída (sem arquivo intermediário em disco).
 * Com $usuarioId, só os chamados abertos por esse usuário.
 */
function escreverCsv(mysqli $db, ?int $usuarioId): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, u.nome AS tecnico_nome'
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $params = [$usuarioId];
    }
    $sql .= ' ORDER BY c.id';
    $res = consultaPreparada($db, $sql, $tipos, $params);

    if (!headers_sent()) { // chamado por cron/CLI após alguma saída, header() avisaria dentro do CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }

    // escape "\\" explícito = comportamento histórico do fputcsv; omitir é "deprecated" no PHP 8.4
    // e o aviso sairia no meio do CSV.
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            celulaCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] === null ? '-' : celulaCsv($c['tecnico_nome']),
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
}

/**
 * Neutraliza "CSV injection": planilhas tratam uma célula iniciada por = + - @ (ou TAB/CR)
 * como fórmula. O título do chamado é digitado pelo cliente e aberto pelo técnico.
 */
function celulaCsv(string $valor): string
{
    if ($valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Executa uma consulta preparada e devolve o resultado. Em qualquer falha lança exceção
 * (mysqli_sql_exception, que é um RuntimeException, ou o próprio RuntimeException quando o mysqli
 * está sem modo exceção) — nunca devolve "false" para o chamador tratar como resultado vazio.
 */
function consultaPreparada(mysqli $db, string $sql, string $tipos = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar a consulta: ' . $db->error);
    }
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar a consulta: ' . $stmt->error);
    }
    $res = $stmt->get_result();
    if ($res === false) {
        throw new RuntimeException('Falha ao ler o resultado da consulta: ' . $stmt->error);
    }
    return $res;
}

/**
 * Consultas preparadas devolvem inteiros nativos; as telas e os scripts do ISP sempre receberam
 * strings (protocolo texto do mysqli). Converte de volta para manter o formato de retorno.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $campo => $valor) {
        if ($valor !== null && !is_string($valor)) {
            $linha[$campo] = (string) $valor;
        }
    }
    return $linha;
}
