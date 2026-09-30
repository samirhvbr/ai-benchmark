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
 * Aceita o hash md5 legado e hashes de password_hash(). Quando a senha confere
 * com o md5 e a coluna usuarios.senha já foi alargada (ver schema.sql), o hash
 * é regravado com password_hash(). Sem a migração da coluna nada é regravado.
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
    $hash = (string) $linha['senha'];
    unset($linha['senha']);

    if (password_get_info($hash)['algoName'] !== 'unknown') {
        return password_verify($senha, $hash) ? $linha : null;
    }

    // md5 legado; a comparação antiga era feita no SQL, sem diferenciar maiúsculas
    if (!hash_equals(strtolower($hash), md5($senha))) {
        return null;
    }
    atualizarHashSenha($db, (int) $linha['id'], $senha);
    return $linha;
}

/**
 * Regrava a senha com password_hash() se a coluna comportar o hash novo.
 * Falha aqui nunca impede o login (o usuário do painel pode não ter UPDATE).
 */
function atualizarHashSenha(mysqli $db, int $id, string $senha): void
{
    try {
        $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $col = $res ? $res->fetch_row() : null;
        if (!$col || (int) $col[0] < 255) {
            return;
        }
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $novo, $id);
            $stmt->execute();
        }
    } catch (mysqli_sql_exception $e) {
        error_log('painel: nao foi possivel atualizar o hash da senha do usuario ' . $id . ': ' . $e->getMessage());
    }
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
 * Busca o nome de um técnico pelo id.
 * A listagem e o export não a usam mais (fazem JOIN); mantida para outros scripts.
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
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só
 * os que ele mesmo abriu. Devolve o id do dono pelo qual filtrar, ou null para
 * não filtrar. Qualquer papel diferente de 'tecnico' é tratado como cliente.
 */
function donoVisivel(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Diz se o usuário logado pode ver este chamado (tela de detalhe).
 */
function podeVerChamado(array $chamado, int $uid, string $papel): bool
{
    $dono = donoVisivel($uid, $papel);
    return $dono === null || (int) $chamado['usuario_id'] === $dono;
}

/**
 * Consulta da listagem, com o nome do técnico via JOIN (uma consulta só).
 * $donoId null = todos os chamados.
 *
 * Continua usando query() (protocolo texto): com prepared statement os campos
 * numéricos voltariam como int em vez de string e o retorno de listarChamados
 * mudaria para quem o consome. Por isso o termo é escapado com real_escape_string.
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ' . $donoId;
    }
    if ($busca !== '') {
        $filtros[] = "c.titulo LIKE '%" . $db->real_escape_string($busca) . "%'";
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $res = $db->query($sql);

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
 * Listagem aplicando a regra de visibilidade do usuário logado.
 */
function listarChamadosVisiveis(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarChamados($db, $busca, donoVisivel($uid, $papel));
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Não verifica permissão: quem chama deve usar podeVerChamado().
 */
function verChamado(mysqli $db, int $id): ?array
{
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido, devolve 0.
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
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

/**
 * Export CSV aplicando a regra de visibilidade do usuário logado.
 */
function exportarCsvVisiveis(mysqli $db, int $uid, string $papel): void
{
    escreverCsv($db, donoVisivel($uid, $papel));
}

/**
 * Escreve o CSV direto na saída, sem arquivo intermediário em EXPORT_DIR.
 * $donoId null = todos os chamados.
 */
function escreverCsv(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ' . $donoId;
    }
    $sql .= ' ORDER BY c.id';
    $res = $db->query($sql);
    if ($res === false) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    // separador, aspas e escape explícitos: mesmos bytes de antes, sem o aviso do PHP 8.4
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
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
