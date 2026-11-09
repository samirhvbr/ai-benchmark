<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Compatibilidade: as assinaturas públicas (autenticar, formatarStatus,
 * rotuloPrioridade, listarChamados, verChamado, mediaResposta, exportarCsv)
 * foram mantidas intactas. As variantes que aplicam a regra de visibilidade
 * (filtro por dono) são funções novas e são as usadas pela interface web.
 *
 * Todas as consultas usam prepared statements. Os erros do mysqli são
 * tratados localmente (catch em mysqli_sql_exception) para que uma falha de
 * banco não vire um erro 500 com stack trace na frente do usuário.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * A senha não é mais comparada por MD5: hashes novos são gerados com password_hash()
 * (bcrypt). Hashes MD5 legados ainda são aceitos (para não derrubar o banco em
 * produção) e são reescritos em bcrypt no primeiro login bem-sucedido.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    if ($usuario === '' || $senha === '') {
        return null;
    }

    try {
        $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $usuario);
        if (!$stmt->execute()) {
            return null;
        }
        $res = $stmt->get_result();
        if ($res === false) {
            return null;
        }
        $linha = $res->fetch_assoc();
    } catch (mysqli_sql_exception $e) {
        error_log('autenticar: falha no banco: ' . $e->getMessage());
        return null;
    }

    if (!$linha) {
        return null;
    }

    $hashArmazenado = (string) $linha['senha'];
    if (!verificarSenha($senha, $hashArmazenado)) {
        return null;
    }

    // Migração oportunista: hash legado (MD5) vira bcrypt no primeiro login.
    if (senhaEhLegada($hashArmazenado)) {
        migrarHashParaBcrypt($db, (int) $linha['id'], $senha, $hashArmazenado);
    }

    return [
        'id' => $linha['id'],
        'nome' => $linha['nome'],
        'papel' => $linha['papel'],
    ];
}

/**
 * Reescreve o hash MD5 legado como bcrypt.
 * Se o banco ainda tiver a coluna antiga (CHAR(32)) e truncar o valor novo,
 * o hash legado é restaurado para não trancar o usuário fora do sistema.
 */
function migrarHashParaBcrypt(mysqli $db, int $id, string $senha, string $hashLegado): void
{
    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
    try {
        $up = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        if ($up === false) {
            return;
        }
        $up->bind_param('si', $novoHash, $id);
        if (!$up->execute()) {
            return;
        }

        $check = $db->prepare('SELECT senha FROM usuarios WHERE id = ?');
        if ($check === false) {
            return;
        }
        $check->bind_param('i', $id);
        if (!$check->execute()) {
            return;
        }
        $res = $check->get_result();
        $linha = $res ? $res->fetch_assoc() : null;
        if ($linha && !hash_equals($novoHash, (string) $linha['senha'])) {
            $back = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            if ($back !== false) {
                $back->bind_param('si', $hashLegado, $id);
                $back->execute();
            }
        }
    } catch (mysqli_sql_exception $e) {
        error_log('migrarHashParaBcrypt: falha no banco: ' . $e->getMessage());
    }
}

/**
 * Papel (tecnico|cliente) do usuário, lido do banco — fonte de verdade da
 * regra de visibilidade. Devolve null se o usuário não existir (ou se a
 * consulta falhar).
 */
function papelDoUsuario(mysqli $db, int $id): ?string
{
    try {
        $stmt = $db->prepare('SELECT papel FROM usuarios WHERE id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            return null;
        }
        $res = $stmt->get_result();
        if ($res === false) {
            return null;
        }
        $linha = $res->fetch_assoc();
    } catch (mysqli_sql_exception $e) {
        error_log('papelDoUsuario: falha no banco: ' . $e->getMessage());
        return null;
    }
    return $linha ? (string) $linha['papel'] : null;
}

/**
 * Confere a senha contra o hash armazenado.
 * Aceita bcrypt (password_hash) e MD5 legado (32 caracteres hexadecimais).
 */
function verificarSenha(string $senha, string $hashArmazenado): bool
{
    if ($hashArmazenado === '') {
        return false;
    }
    if (senhaEhLegada($hashArmazenado)) {
        return hash_equals(strtolower($hashArmazenado), md5($senha));
    }
    return password_verify($senha, $hashArmazenado);
}

/**
 * O hash armazenado é do esquema legado (MD5 sem sal)?
 */
function senhaEhLegada(string $hashArmazenado): bool
{
    return strlen($hashArmazenado) === 32 && ctype_xdigit($hashArmazenado);
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    switch ($status) {
        case 1:
            return 'Aberto';
        case 2:
            return 'Em atendimento';
        default:
            return 'Resolvido';
    }
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 *
 * O SLA de 30 min só se aplica às prioridades alta (3) e crítica (4);
 * para as demais a prioridade é mostrada como normal.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    $atrasado = $minutos > 30;
    if ($prioridade >= 4) {
        return $atrasado ? 'CRITICO - SLA estourado' : 'CRITICO - dentro do SLA';
    }
    if ($prioridade === 3) {
        return $atrasado ? 'Alto - atrasado' : 'Alto - dentro do SLA';
    }
    return 'Normal';
}

/**
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    try {
        $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
        if ($stmt === false) {
            return '-';
        }
        $stmt->bind_param('i', $tecnicoId);
        if (!$stmt->execute()) {
            return '-';
        }
        $res = $stmt->get_result();
        if ($res === false) {
            return '-';
        }
        $t = $res->fetch_assoc();
    } catch (mysqli_sql_exception $e) {
        error_log('tecnicoNome: falha no banco: ' . $e->getMessage());
        return '-';
    }
    return $t ? (string) $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Sem filtro de visibilidade (assinatura pública preservada): a rotina
 * noturna de exportação e o relatório gerencial continuam recebendo tudo.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosVisiveis($db, $busca, null);
}

/**
 * Lista os chamados aplicando a regra de visibilidade.
 *
 * - $busca: termo aplicado no título (LIKE com curinga escapado).
 * - $apenasUsuarioId: quando informado, devolve somente os chamados abertos
 *   por aquele usuário (regra de negócio: cliente só vê os próprios chamados).
 *
 * O nome do técnico vem por JOIN (evita uma consulta por linha).
 */
function listarChamadosVisiveis(mysqli $db, string $busca = '', ?int $apenasUsuarioId = null): array
{
    $sql = 'SELECT c.*, t.nome AS tecnico_nome'
         . ' FROM chamados c'
         . ' LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $tipos = '';
    $params = [];

    if ($busca !== '') {
        $sql .= " WHERE c.titulo LIKE ? ESCAPE '!'";
        $tipos .= 's';
        $params[] = '%' . escaparLike($busca) . '%';
    }
    if ($apenasUsuarioId !== null) {
        $sql .= ($busca !== '' ? ' AND' : ' WHERE') . ' c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $apenasUsuarioId;
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if (count($params) === 1) {
            $stmt->bind_param($tipos, $params[0]);
        } elseif (count($params) === 2) {
            $stmt->bind_param($tipos, $params[0], $params[1]);
        }
        if (!$stmt->execute()) {
            return [];
        }
        $res = $stmt->get_result();
        if ($res === false) {
            return [];
        }
    } catch (mysqli_sql_exception $e) {
        error_log('listarChamados: falha no banco: ' . $e->getMessage());
        return [];
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = $c['tecnico_nome'] !== null ? (string) $c['tecnico_nome'] : '-';
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Escapa os curingas do LIKE para que o termo buscado seja literal.
 * O SQL usa ESCAPE '!'.
 */
function escaparLike(string $texto): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    try {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            return null;
        }
        $res = $stmt->get_result();
        if ($res === false) {
            return null;
        }
        return $res->fetch_assoc() ?: null;
    } catch (mysqli_sql_exception $e) {
        error_log('verChamado: falha no banco: ' . $e->getMessage());
        return null;
    }
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Devolve 0.0 quando não há amostra (evita divisão por zero).
 */
function mediaResposta(mysqli $db): float
{
    try {
        $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
        if ($res === false) {
            return 0.0;
        }
        $row = $res->fetch_assoc();
    } catch (mysqli_sql_exception $e) {
        error_log('mediaResposta: falha no banco: ' . $e->getMessage());
        return 0.0;
    }
    if (!$row || $row['media'] === null) {
        return 0.0;
    }
    return (float) $row['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvVisivel($db, null);
}

/**
 * Exporta os chamados para CSV aplicando a regra de visibilidade.
 *
 * - $apenasUsuarioId: quando informado, exporta somente os chamados abertos
 *   por aquele usuário; técnico (null) exporta todos.
 *
 * O arquivo é gerado em arquivo temporário com permissão restrita (0600) e
 * removido após o envio: ele não fica disponível dentro da árvore do site.
 */
function exportarCsvVisivel(mysqli $db, ?int $apenasUsuarioId = null): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.tecnico_id, c.criado_em, t.nome AS tecnico_nome'
         . ' FROM chamados c'
         . ' LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($apenasUsuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            responderErroExportacao();
            return;
        }
        if ($apenasUsuarioId !== null) {
            $stmt->bind_param('i', $apenasUsuarioId);
        }
        if (!$stmt->execute()) {
            responderErroExportacao();
            return;
        }
        $res = $stmt->get_result();
        if ($res === false) {
            responderErroExportacao();
            return;
        }
    } catch (mysqli_sql_exception $e) {
        error_log('exportarCsv: falha no banco: ' . $e->getMessage());
        responderErroExportacao();
        return;
    }

    $dir = defined('EXPORT_DIR') ? EXPORT_DIR : sys_get_temp_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        $dir = sys_get_temp_dir();
    }
    $caminho = tempnam($dir, 'chamados_');
    if ($caminho === false) {
        responderErroExportacao();
        return;
    }
    @chmod($caminho, 0600);

    $fp = fopen($caminho, 'wb');
    if ($fp === false) {
        @unlink($caminho);
        responderErroExportacao();
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($caminho);
        responderErroExportacao();
        return;
    }

    // Cabeçalho exatamente como declarado no manifesto de superfície pública:
    // ID,Titulo,Status,Tecnico,Aberto em (sem aspas que fputcsv colocaria no
    // campo "Aberto em" por causa do espaço).
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $res->fetch_assoc()) {
        $tecnico = $c['tecnico_nome'] !== null ? (string) $c['tecnico_nome'] : '-';
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $tecnico,
            $c['criado_em'],
        ]);
    }
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        $tamanho = filesize($caminho);
        if ($tamanho !== false) {
            header('Content-Length: ' . $tamanho);
        }
    }
    readfile($caminho);
    @unlink($caminho);
}

/**
 * Resposta de erro do export (o cliente precisa saber que o CSV não saiu).
 */
function responderErroExportacao(): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Falha ao gerar o arquivo de chamados.';
}
