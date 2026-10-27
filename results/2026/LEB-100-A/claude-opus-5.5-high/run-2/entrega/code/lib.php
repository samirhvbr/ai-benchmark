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
 * Aceita tanto o hash legado (md5) quanto password_hash(); um login legado
 * bem-sucedido regrava a senha com password_hash() quando a coluna já comporta
 * (ver migração em schema.sql).
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();

    if (!$linha) {
        // mesmo custo de um usuário existente, para não revelar logins válidos pelo tempo
        password_verify($senha, '$2y$12$FoY9wdKP1QjTLuxkAUlON.kxXADERJE.q7opl8blSRbtcOJ9RvBtW');
        return null;
    }

    $guardada = $linha['senha'];
    if (strlen($guardada) === 32 && ctype_xdigit($guardada)) {
        $ok = hash_equals(strtolower($guardada), md5($senha));
        if ($ok) {
            migrarHashSenha($db, (int) $linha['id'], $senha);
        }
    } else {
        $ok = password_verify($senha, $guardada);
    }

    if (!$ok) {
        return null;
    }
    unset($linha['senha']);
    return $linha;
}

/**
 * Regrava a senha com password_hash() — só se a coluna já foi alargada,
 * senão o hash seria truncado (sql_mode não estrito) e o usuário perderia o acesso.
 */
function migrarHashSenha(mysqli $db, int $id, string $senha): void
{
    try {
        $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS tam FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $col = $res ? $res->fetch_assoc() : null;
        if (!$col || (int) $col['tam'] < 255) {
            return;
        }
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->bind_param('si', $novo, $id);
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // a migração é oportunista: falhar aqui não pode impedir o login
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
 * Consulta interna de chamados já com 'tecnico_nome' (um único JOIN, sem N+1).
 * $donoId restringe aos chamados abertos por esse usuário (visão de cliente).
 * Os valores voltam como string, como em mysqli::query(), para não mudar
 * os tipos que os consumidores de listarChamados() já recebem.
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId, string $ordem): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    $where = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $where[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $where[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= $ordem === 'id' ? ' ORDER BY c.id' : ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
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
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'criado_em');
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
 * Sem nenhum chamado respondido, devolve 0.0.
 */
function mediaResposta(mysqli $db): float
{
    // soma/contagem (e não AVG) para manter exatamente a mesma divisão em PHP de antes
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(*) AS qtd FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    if ((int) $row['qtd'] === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / (int) $row['qtd'];
}

/**
 * Escreve o CSV de chamados direto na saída. $donoId restringe aos chamados
 * de um cliente; null exporta todos.
 */
function escreverCsvChamados(mysqli $db, ?int $donoId): void
{
    $chamados = consultarChamados($db, '', $donoId, 'id');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    foreach ($chamados as $c) {
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

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsvChamados($db, null);
}
