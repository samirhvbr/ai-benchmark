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
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$linha) {
        return null;
    }

    $hashArmazenado = (string) $linha['senha'];
    $hashLegado = preg_match('/\A[0-9a-f]{32}\z/i', $hashArmazenado) === 1;
    $senhaCorreta = $hashLegado
        ? hash_equals(strtolower($hashArmazenado), md5($senha))
        : password_verify($senha, $hashArmazenado);

    if (!$senhaCorreta) {
        return null;
    }

    if ($hashLegado || password_needs_rehash($hashArmazenado, PASSWORD_DEFAULT)) {
        atualizarHashSenha($db, (int) $linha['id'], $senha);
    }

    unset($linha['senha']);
    return $linha;
}

/**
 * Atualiza hashes legados somente quando a coluna comporta password_hash().
 * Instalações antigas com CHAR(32) continuam autenticando até a migração do schema.
 */
function atualizarHashSenha(mysqli $db, int $usuarioId, string $senha): void
{
    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
    $res = $db->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'usuarios'
            AND COLUMN_NAME = 'senha'"
    );
    if ($res === false || !($coluna = $res->fetch_assoc()) || (int) $coluna['tamanho'] < strlen($novoHash)) {
        return;
    }

    $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('si', $novoHash, $usuarioId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Escapa texto para os contextos HTML usados pelas telas.
 */
function escaparHtml(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    return consultarChamados($db, $busca, null);
}

/**
 * Variante usada pela interface web para aplicar a regra de visibilidade.
 */
function listarChamadosParaUsuario(mysqli $db, string $busca, int $usuarioId, string $papel): array
{
    if ($papel === 'tecnico') {
        return consultarChamados($db, $busca, null);
    }
    if ($papel === 'cliente') {
        return consultarChamados($db, $busca, $usuarioId);
    }
    return [];
}

/**
 * Consulta compartilhada pela listagem pública e pela listagem com escopo.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
         LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    $filtros = [];
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $termo = '%' . $busca . '%';
    }
    if ($usuarioId !== null) {
        $filtros[] = 'c.usuario_id = ?';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($busca !== '' && $usuarioId !== null) {
        $stmt->bind_param('si', $termo, $usuarioId);
    } elseif ($busca !== '') {
        $stmt->bind_param('s', $termo);
    } elseif ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    $stmt->close();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $chamado = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $chamado;
}

/**
 * Carrega um chamado apenas quando o usuário da interface pode vê-lo.
 */
function verChamadoParaUsuario(mysqli $db, int $id, int $usuarioId, string $papel): ?array
{
    $chamado = verChamado($db, $id);
    if ($chamado === null || !in_array($papel, ['cliente', 'tecnico'], true)) {
        return null;
    }
    if ($papel === 'cliente' && (int) $chamado['usuario_id'] !== $usuarioId) {
        return null;
    }
    return $chamado;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    return consultarMediaResposta($db, null);
}

/**
 * Calcula o indicador somente sobre chamados visíveis na interface.
 */
function mediaRespostaParaUsuario(mysqli $db, int $usuarioId, string $papel): float
{
    if ($papel === 'tecnico') {
        return consultarMediaResposta($db, null);
    }
    if ($papel === 'cliente') {
        return consultarMediaResposta($db, $usuarioId);
    }
    return 0.0;
}

function consultarMediaResposta(mysqli $db, ?int $usuarioId): float
{
    $sql = 'SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL';
    if ($usuarioId !== null) {
        $sql .= ' AND usuario_id = ?';
    }
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return 0.0;
    }
    if ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $linha && $linha['media'] !== null ? (float) $linha['media'] : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

/**
 * Exporta somente os chamados visíveis ao usuário autenticado da interface.
 */
function exportarCsvParaUsuario(mysqli $db, int $usuarioId, string $papel): void
{
    if ($papel === 'tecnico') {
        escreverCsv($db, null);
        return;
    }
    escreverCsv($db, $papel === 'cliente' ? $usuarioId : -1);
}

/**
 * Impede que texto controlado por usuário seja interpretado como fórmula em planilhas.
 */
function valorCsvSeguro(string $valor): string
{
    if (preg_match('/\A[\x00-\x20]*[=+\-@]/', $valor) === 1) {
        return "'" . $valor;
    }
    return $valor;
}

function escreverCsv(mysqli $db, ?int $usuarioId): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");

    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
         LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        fclose($fp);
        return;
    }
    if ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            valorCsvSeguro((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            valorCsvSeguro((string) $c['tecnico_nome']),
            $c['criado_em'],
        ], ',', '"', '');
    }
    $stmt->close();
    fclose($fp);
}
