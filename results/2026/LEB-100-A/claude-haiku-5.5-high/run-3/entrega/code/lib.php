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
    } else if ($status == 3) {
        return 'Resolvido';
    } else {
        return 'Desconhecido';
    }
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos !== null) {
        if ($prioridade >= 3) {
            if ($minutos > 30) {
                if ($prioridade == 4) {
                    return 'CRITICO - SLA estourado';
                } else {
                    return 'Alto - atrasado';
                }
            } else {
                return 'Alto - dentro do SLA';
            }
        } else {
            return 'Normal';
        }
    } else {
        return 'Aguardando 1a resposta';
    }
}

/**
 * Busca o nome de um técnico pelo id.
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
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só vê
 * os que ele mesmo abriu. Retorna o id do dono a filtrar, ou null para "sem filtro".
 */
function visibilidade(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Indica se o usuário pode ver um chamado já carregado (tela de detalhe).
 */
function podeVerChamado(array $chamado, int $uid, string $papel): bool
{
    $dono = visibilidade($uid, $papel);
    return $dono === null || (int) $chamado['usuario_id'] === $dono;
}

/**
 * Consulta base dos chamados, com o nome do técnico já resolvido (uma só query).
 * $usuarioId restringe aos chamados daquele dono; null não aplica restrição.
 * $ordem é sempre uma constante interna, nunca entrada do usuário.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId, string $ordem): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id WHERE 1=1';
    $tipos = '';
    $params = [];
    if ($usuarioId !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $usuarioId;
    }
    if ($busca !== '') {
        // Escapa os curingas do LIKE: a busca é um texto literal sobre o título.
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . addcslashes($busca, '\\%_') . '%';
    }
    $sql .= ' ORDER BY ' . $ordem;

    $stmt = $db->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Lista todos os chamados, opcionalmente filtrando pelo título.
 * Sem restrição de dono: uso interno (rotinas). Telas de usuário usam listarChamadosVisiveis.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'c.criado_em DESC');
}

/**
 * Lista só os chamados que o usuário pode ver, conforme a regra de visibilidade.
 */
function listarChamadosVisiveis(mysqli $db, string $busca, int $uid, string $papel): array
{
    return consultarChamados($db, $busca, visibilidade($uid, $papel), 'c.criado_em DESC');
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
    // AVG ignora NULLs; sem nenhum chamado respondido o resultado é NULL (0 minutos).
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados');
    $linha = $res->fetch_assoc();
    return $linha['media'] === null ? 0.0 : (float) $linha['media'];
}

/**
 * Escreve o CSV dos chamados na saída (navegador, ou stdout em CLI).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function emitirCsv(array $chamados): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    foreach ($chamados as $c) {
        fputcsv($fp, [
            $c['id'],
            neutralizarFormula($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}

/**
 * Evita que um título iniciado por =, +, -, @ (ou tab/CR) seja interpretado
 * como fórmula pelo Excel/Calc ao abrir o CSV. Título é texto livre do cliente.
 */
function neutralizarFormula(string $valor): string
{
    return $valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false ? "'" . $valor : $valor;
}

/**
 * Exporta todos os chamados para CSV (sem restrição de dono: uso das rotinas internas).
 */
function exportarCsv(mysqli $db): void
{
    emitirCsv(consultarChamados($db, '', null, 'c.id'));
}

/**
 * Exporta para CSV só os chamados que o usuário pode ver (tela de exportação).
 */
function exportarCsvVisivel(mysqli $db, int $uid, string $papel): void
{
    emitirCsv(consultarChamados($db, '', visibilidade($uid, $papel), 'c.id'));
}
