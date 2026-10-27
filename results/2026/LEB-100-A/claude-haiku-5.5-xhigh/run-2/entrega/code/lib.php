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
 *
 * Senhas novas são guardadas com password_hash(). Hashes md5 legados ainda são
 * aceitos e regravados com password_hash() no primeiro login bem-sucedido.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return null;
    }

    $armazenado = (string) $linha['senha'];
    if (substr($armazenado, 0, 1) === '$') {
        if (!password_verify($senha, $armazenado)) {
            return null;
        }
    } else {
        // Hash legado (md5 sem sal): aceito apenas para migrar a conta.
        if (!hash_equals($armazenado, md5($senha))) {
            return null;
        }
        atualizarHashSenha($db, (int) $linha['id'], $senha);
    }

    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Regrava a senha com password_hash(), mas só se a coluna `senha` couber o hash.
 * Bases antigas têm CHAR(32): nesse caso o UPDATE truncaria o hash e trancaria a
 * conta. Enquanto a coluna não for ampliada (ver schema.sql), o login legado segue
 * funcionando sem alteração.
 */
function atualizarHashSenha(mysqli $db, int $id, string $senha): void
{
    $novo = password_hash($senha, PASSWORD_DEFAULT);
    $res = $db->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
    );
    $coluna = $res ? $res->fetch_assoc() : null;
    if (!$coluna || (int) $coluna['tamanho'] < strlen($novo)) {
        return;
    }

    $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
    $stmt->bind_param('si', $novo, $id);
    $stmt->execute();
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
    }
    return 'Desconhecido';
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
 * Busca o nome de um técnico pelo id. A listagem e o export usam JOIN
 * (ver consultarChamados); esta função continua disponível para outros scripts.
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
 * Regra de visibilidade (manifest): técnico vê qualquer chamado; cliente só os que abriu.
 */
function papelVeTodos(string $papel): bool
{
    return $papel === 'tecnico';
}

/**
 * Indica se o usuário (papel + uid da sessão) pode ver o chamado.
 */
function usuarioPodeVer(array $chamado, string $papel, int $uid): bool
{
    return papelVeTodos($papel) || (int) $chamado['usuario_id'] === $uid;
}

/**
 * Consulta comum de chamados, com 'tecnico_nome' vindo do JOIN (uma única query).
 * $usuarioId = null: sem filtro de dono. $ordem é constante interna, nunca vem da requisição.
 *
 * Usa query() com literais escapados, e não prepared statement, de propósito: o
 * original devolvia todos os campos como string, e prepared statement devolveria
 * int. Escapar com real_escape_string() é seguro porque set_charset('utf8mb4') é
 * chamado antes (index.php e os scripts internos).
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId, string $ordem): array
{
    $condicoes = [];
    if ($usuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ' . $usuarioId;
    }
    if ($busca !== '') {
        // Primeiro escapa % e _ (padrão LIKE), depois as aspas do literal SQL.
        $padrao = '%' . addcslashes($busca, '%_\\') . '%';
        $condicoes[] = "c.titulo LIKE '" . $db->real_escape_string($padrao) . "'";
    }
    $where = $condicoes ? ' WHERE ' . implode(' AND ', $condicoes) : '';

    $res = $db->query(
        "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
         FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id"
        . $where . ' ORDER BY ' . $ordem
    );
    return $res->fetch_all(MYSQLI_ASSOC);
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título (sem filtro de dono:
 * uso interno dos relatórios). Retorna cada chamado já com a chave 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'c.criado_em DESC');
}

/**
 * Lista só os chamados que o papel pode ver. É a listagem usada pelo painel web.
 */
function listarChamadosVisiveis(mysqli $db, string $papel, int $uid, string $busca = ''): array
{
    return consultarChamados($db, $busca, papelVeTodos($papel) ? null : $uid, 'c.criado_em DESC');
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe. Não filtra por dono:
 * quem decide a visibilidade é usuarioPodeVer().
 */
function verChamado(mysqli $db, int $id): ?array
{
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido, devolve 0.0.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $linha = $res->fetch_assoc();
    $qtd = (int) $linha['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $linha['soma'] / $qtd;
}

/**
 * Escreve o cabeçalho e as linhas do CSV no stream $fp (quem chama fecha o stream).
 * $usuarioId = null: todos os chamados.
 */
function gravarCsv($fp, mysqli $db, ?int $usuarioId): void
{
    // Cabeçalho escrito à mão: fputcsv colocaria aspas em "Aberto em" e fugiria do manifest.
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    foreach (consultarChamados($db, '', $usuarioId, 'c.id ASC') as $c) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
}

/**
 * Exporta todos os chamados para CSV: grava EXPORT_DIR/chamados.csv e o devolve
 * ao navegador. Uso interno (rotina noturna). O painel web usa exportarCsvVisivel().
 */
function exportarCsv(mysqli $db): void
{
    $caminho = EXPORT_DIR . '/chamados.csv';
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    gravarCsv($fp, $db, null);
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
}

/**
 * Exporta para o navegador só os chamados que o papel pode ver. Não grava arquivo em
 * disco: cada requisição tem a sua saída, sem arquivo compartilhado entre usuários.
 */
function exportarCsvVisivel(mysqli $db, string $papel, int $uid): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    gravarCsv($fp, $db, papelVeTodos($papel) ? null : $uid);
    fclose($fp);
}
