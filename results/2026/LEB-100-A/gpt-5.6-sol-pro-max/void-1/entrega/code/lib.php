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
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ? LIMIT 1');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        // Mantém custo semelhante ao caminho de um usuário existente.
        password_verify($senha, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        return null;
    }

    $hash = (string) $linha['senha'];
    $legado = preg_match('/^[a-f0-9]{32}$/i', $hash) === 1;
    if ($legado) {
        $senhaValida = hash_equals(strtolower($hash), md5($senha));
        password_verify($senha, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
    } else {
        $senhaValida = password_verify($senha, $hash);
    }
    if (!$senhaValida) {
        return null;
    }

    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $id = (int) $linha['id'];
        $atualizar->bind_param('si', $novoHash, $id);
        $atualizar->execute();
    }

    unset($linha['senha']);
    return $linha;
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
 * Restringe consultas web ao dono para qualquer papel que não seja técnico.
 */
function donoDoEscopo(int $usuarioId, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $usuarioId;
}

/**
 * Consulta compartilhada pela listagem pública e pela listagem com escopo.
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($donoId !== null && $busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('is', $donoId, $termo);
    } elseif ($donoId !== null) {
        $stmt->bind_param('i', $donoId);
    } elseif ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
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
 * Variante usada pela rota web, preservando a assinatura pública legada.
 */
function listarChamadosVisiveis(mysqli $db, string $busca, int $usuarioId, string $papel): array
{
    return consultarChamados($db, $busca, donoDoEscopo($usuarioId, $papel));
}

/**
 * Consulta compartilhada pelo detalhe público e pelo detalhe com escopo.
 */
function consultarChamado(mysqli $db, int $id, ?int $donoId): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
    }
    $stmt = $db->prepare($sql);
    if ($donoId === null) {
        $stmt->bind_param('i', $id);
    } else {
        $stmt->bind_param('ii', $id, $donoId);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    return consultarChamado($db, $id, null);
}

/**
 * Variante usada pela rota web, preservando a assinatura pública legada.
 */
function verChamadoVisivel(mysqli $db, int $id, int $usuarioId, string $papel): ?array
{
    return consultarChamado($db, $id, donoDoEscopo($usuarioId, $papel));
}

/**
 * Calcula a média no banco, opcionalmente limitada aos chamados de um dono.
 */
function calcularMediaResposta(mysqli $db, ?int $donoId): float
{
    $sql = 'SELECT AVG(minutos_resposta) AS media FROM chamados';
    if ($donoId !== null) {
        $sql .= ' WHERE usuario_id = ?';
    }
    $stmt = $db->prepare($sql);
    if ($donoId !== null) {
        $stmt->bind_param('i', $donoId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    return calcularMediaResposta($db, null);
}

/**
 * Variante usada pela rota web, preservando a assinatura pública legada.
 */
function mediaRespostaVisivel(mysqli $db, int $usuarioId, string $papel): float
{
    return calcularMediaResposta($db, donoDoEscopo($usuarioId, $papel));
}

/**
 * Impede que planilhas interpretem dados do banco como fórmulas.
 */
function protegerCelulaCsv($valor): string
{
    $texto = (string) $valor;
    if (preg_match('/^(?:[=+\-@\t\r]|\s+[=+\-@])/u', $texto) === 1) {
        return "'" . $texto;
    }
    return $texto;
}

/**
 * Exportação compartilhada pela função pública e pela rota com escopo.
 */
function exportarCsvComEscopo(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
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

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    header('Cache-Control: no-store');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            protegerCelulaCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            protegerCelulaCsv($c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvComEscopo($db, null);
}

/**
 * Variante usada pela rota web, preservando a assinatura pública legada.
 */
function exportarCsvVisivel(mysqli $db, int $usuarioId, string $papel): void
{
    exportarCsvComEscopo($db, donoDoEscopo($usuarioId, $papel));
}
