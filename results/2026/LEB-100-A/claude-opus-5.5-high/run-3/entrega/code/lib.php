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
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return null;
    }

    $armazenado = (string) $linha['senha'];
    if (strlen($armazenado) === 32 && ctype_xdigit($armazenado)) {
        // Hash md5 legado: compara em tempo constante e migra para password_hash
        // assim que a coluna comportar o hash novo (ver schema.sql).
        if (!hash_equals(strtolower($armazenado), md5($senha))) {
            return null;
        }
        migrarHashSenha($db, (int) $linha['id'], $senha);
    } elseif (!password_verify($senha, $armazenado)) {
        return null;
    }

    unset($linha['senha']);
    return $linha;
}

/**
 * Regrava a senha com password_hash(). Só age se a coluna usuarios.senha já foi
 * alargada (ALTER TABLE do schema.sql); com CHAR(32) o hash seria truncado e o
 * usuário ficaria trancado, então nesse caso não faz nada.
 */
function migrarHashSenha(mysqli $db, int $usuarioId, string $senha): void
{
    static $colunaComporta = null;
    if ($colunaComporta === null) {
        $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS tam FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $col = $res ? $res->fetch_assoc() : null;
        $colunaComporta = $col && (int) $col['tam'] >= 255;
    }
    if (!$colunaComporta) {
        return;
    }
    $novo = password_hash($senha, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
    $stmt->bind_param('si', $novo, $usuarioId);
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
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosDoDono($db, $busca, null);
}

/**
 * Mesma listagem de listarChamados(), restrita aos chamados abertos por $donoId
 * quando ele não é null (regra de visibilidade do cliente, ver donoVisivel()).
 */
function listarChamadosDoDono(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id
             WHERE 1 = 1";
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $chamados = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        // O protocolo binário (prepare) devolve INT nativo; o contrato sempre
        // entregou strings, como o query() antigo. Mantém os tipos.
        $chamados[] = array_map(fn($v) => $v === null ? null : (string) $v, $c);
    }
    return $chamados;
}

/**
 * Regra de visibilidade: técnico vê tudo (null = sem filtro); qualquer outro
 * papel só vê os chamados que abriu.
 */
function donoVisivel(string $papel, int $uid): ?int
{
    return $papel === 'tecnico' ? null : $uid;
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
    // Sem nenhum chamado respondido a média é indefinida; 0 evita o DivisionByZeroError.
    // SUM/COUNT (e não AVG) para devolver exatamente o mesmo float do cálculo original.
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    return $qtd > 0 ? (int) $row['soma'] / $qtd : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvDoDono($db, null);
}

/**
 * Implementação do export. Com $donoId != null exporta só os chamados daquele
 * cliente. Escreve direto na saída: nada fica gravado em disco (EXPORT_DIR).
 */
function exportarCsvDoDono(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id";
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
    $fp = fopen('php://output', 'w');
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
