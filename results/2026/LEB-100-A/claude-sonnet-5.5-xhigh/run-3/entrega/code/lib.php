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
    $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Regra de visibilidade (manifest.md): técnico enxerga qualquer chamado; cliente
 * só os que ele mesmo abriu. Qualquer papel diferente de 'tecnico' é tratado como
 * cliente (falha fechada). Devolve o id do dono a que a consulta deve ser
 * restrita, ou null quando não há restrição.
 */
function escopoDono(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Diz se o usuário (uid/papel da sessão) pode ver o chamado carregado por verChamado().
 */
function chamadoVisivelPara(array $chamado, int $uid, string $papel): bool
{
    $dono = escopoDono($uid, $papel);
    return $dono === null || (int) $chamado['usuario_id'] === $dono;
}

/**
 * Consulta única (sem N+1) usada por listarChamados() e listarChamadosVisiveis().
 * $donoId restringe aos chamados abertos por esse usuário; null = todos.
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        // Prepared statements devolvem inteiros nativos; a query de texto anterior devolvia
        // strings. Mantém o formato antigo (valor como string, NULL como null) porque
        // consumidores externos de listarChamados() podem comparar tipos ou serializar em JSON.
        foreach ($c as $coluna => $valor) {
            if ($valor !== null) {
                $c[$coluna] = (string) $valor;
            }
        }
        $chamados[] = $c;
    }
    $stmt->close();
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Sem filtro de visibilidade: é a visão dos scripts internos (relatório, exportação).
 * Para telas com usuário logado use listarChamadosVisiveis().
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Igual a listarChamados(), mas aplicando a regra de visibilidade do usuário logado.
 */
function listarChamadosVisiveis(mysqli $db, string $busca, int $uid, string $papel): array
{
    return consultarChamados($db, $busca, escopoDono($uid, $papel));
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    // Agrega no banco (antes trazia todas as linhas para somar em PHP). COUNT(coluna)
    // ignora NULL, como o WHERE ... IS NOT NULL de antes.
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0; // nenhum chamado respondido ainda: sem dado, em vez de DivisionByZeroError
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV, escrevendo na saída (php://output).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Não grava mais o arquivo fixo EXPORT_DIR/chamados.csv: exports simultâneos
 * sobrescreviam o mesmo arquivo e entregavam CSV truncado/misturado.
 * Sem filtro de visibilidade (visão dos scripts internos); para o usuário logado
 * use exportarCsvVisivel().
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

/**
 * Igual a exportarCsv(), mas só com os chamados que o usuário logado pode ver.
 */
function exportarCsvVisivel(mysqli $db, int $uid, string $papel): void
{
    escreverCsv($db, escopoDono($uid, $papel));
}

/**
 * Corpo comum dos exports. $donoId restringe aos chamados desse usuário; null = todos.
 * O quinto argumento '\\' do fputcsv é o valor padrão antigo, explicitado porque o PHP 8.4
 * emite Deprecated (que cairia dentro do CSV) quando ele é omitido.
 */
function escreverCsv(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($donoId !== null) {
        $stmt->bind_param('i', $donoId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res === false) {
        return;
    }

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
