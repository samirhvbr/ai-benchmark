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
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só
 * os que ele mesmo abriu. Papel desconhecido cai na regra mais restrita.
 */
function podeVerChamado(array $chamado, int $uid, string $papel): bool
{
    return $papel === 'tecnico' || (int) $chamado['usuario_id'] === $uid;
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
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    // Nome do técnico via LEFT JOIN (antes: uma consulta por chamado em tecnicoNome()).
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('s', $padrao);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        // Prepared statement devolve colunas INT como int; a consulta antiga
        // devolvia string. Mantém o formato que os consumidores já recebem.
        foreach ($c as $k => $v) {
            if ($v !== null) {
                $c[$k] = (string) $v;
            }
        }
        $chamados[] = $c;
    }
    return $chamados;
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
    // Soma e contagem no banco; a divisão continua aqui para manter a mesma precisão.
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0; // nenhum chamado respondido ainda (antes: DivisionByZeroError)
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvFiltrado($db, null);
}

/**
 * Mesmo CSV de exportarCsv(), só com os chamados aceitos por $filtro
 * (null = todos). A rota web usa para aplicar a regra de visibilidade.
 */
function exportarCsvFiltrado(mysqli $db, ?callable $filtro): void
{
    $res = $db->query(
        "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
           FROM chamados c
           LEFT JOIN usuarios t ON t.id = c.tecnico_id
          ORDER BY c.id"
    );
    if ($res === false) {
        return;
    }

    // Direto na saída: o arquivo fixo em EXPORT_DIR ficava sob a raiz web e era
    // compartilhado entre exportações simultâneas.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    // Separador, delimitador e escape explícitos: mesmos valores de antes, sem o
    // aviso de depreciação do PHP 8.4 (que ia parar dentro do CSV).
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
        if ($filtro !== null && !$filtro($c)) {
            continue;
        }
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
