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
 * A coluna usuarios.senha aceita dois formatos:
 *  - password_hash() (começa com '$') — formato atual;
 *  - md5 puro (32 hex) — legado. Quando um login com hash legado dá certo, o
 *    registro é regravado com password_hash() se a coluna já comportar o novo
 *    tamanho (ver schema.sql). Em bancos ainda com CHAR(32) nada é alterado.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $hash = (string) $linha['senha'];
    if ($hash !== '' && $hash[0] === '$') {
        $ok = password_verify($senha, $hash);
    } else {
        $ok = hash_equals(strtolower($hash), md5($senha));
        if ($ok) {
            migrarSenhaLegada($db, (int) $linha['id'], $senha);
        }
    }
    if (!$ok) {
        return null;
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Regrava a senha de um usuário com password_hash(), apenas se a coluna
 * usuarios.senha já foi ampliada (ALTER em schema.sql). Nunca impede o login:
 * qualquer falha aqui é ignorada e o hash legado continua valendo.
 */
function migrarSenhaLegada(mysqli $db, int $usuarioId, string $senha): void
{
    try {
        $res = $db->query(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        );
        $col = $res ? $res->fetch_assoc() : null;
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!$col || strlen($novo) > (int) $col['n']) {
            return; // coluna ainda CHAR(32): gravar truncaria o hash e trancaria o usuário
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->bind_param('si', $novo, $usuarioId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // migração é oportunista; o login já foi validado
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
 * Busca o nome de um técnico pelo id (usado por relatórios internos).
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
    $stmt->close();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Devolve TODOS os chamados (é o que a rotina noturna e o relatório gerencial
 * esperam). A regra de visibilidade por papel fica em consultarChamados().
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Consulta de chamados com filtro de visibilidade (manifest.md):
 *  - $donoId === null → todos os chamados (técnico);
 *  - $donoId = id     → só os chamados abertos por esse usuário (cliente).
 * Mesmo formato de retorno de listarChamados().
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $cond = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $cond[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $cond[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($cond) {
        $sql .= ' WHERE ' . implode(' AND ', $cond);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $chamados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $c ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Devolve 0.0 quando nenhum chamado tem primeira resposta registrada.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $r = $res ? $res->fetch_assoc() : null;
    if (!$r || (int) $r['qtd'] === 0) {
        return 0.0;
    }
    return (int) $r['soma'] / (int) $r['qtd'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * Também publica a cópia completa em EXPORT_DIR/chamados.csv (rotina noturna).
 */
function exportarCsv(mysqli $db): void
{
    gerarCsvChamados($db, null);
}

/**
 * Gera o CSV de chamados (mesmo formato de exportarCsv) e o escreve na saída.
 *  - $donoId === null → todos os chamados; a cópia em EXPORT_DIR é atualizada;
 *  - $donoId = id     → só os chamados desse usuário; EXPORT_DIR não é tocado.
 */
function gerarCsvChamados(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico_nome"
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

    // Monta o CSV inteiro antes de publicar/enviar: quem lê o arquivo em
    // EXPORT_DIR nunca encontra uma versão pela metade.
    $fp = fopen('php://temp', 'w+');
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
    $stmt->close();
    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);

    if ($donoId === null) {
        publicarArquivoExport($csv);
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }
    echo $csv;
}

/**
 * Grava EXPORT_DIR/chamados.csv de forma atômica (arquivo temporário + rename),
 * para que exportações simultâneas não se misturem nem exponham arquivo parcial.
 * Falha em silêncio: a entrega ao navegador não depende do arquivo.
 */
function publicarArquivoExport(string $csv): void
{
    if (!is_dir(EXPORT_DIR) || !is_writable(EXPORT_DIR)) {
        return;
    }
    $tmp = tempnam(EXPORT_DIR, 'chamados.');
    if ($tmp === false) {
        return;
    }
    if (file_put_contents($tmp, $csv) === false
        || !chmod($tmp, 0666 & ~umask())
        || !rename($tmp, EXPORT_DIR . '/chamados.csv')) {
        @unlink($tmp);
    }
}
