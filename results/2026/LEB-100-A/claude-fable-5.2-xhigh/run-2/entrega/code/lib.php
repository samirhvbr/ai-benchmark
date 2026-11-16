<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

/**
 * Executa uma consulta preparada e devolve o resultado (em buffer).
 * Falha sempre por exceção, mesmo que quem chama use mysqli_report(MYSQLI_REPORT_OFF).
 */
function consultaPreparada(mysqli $db, string $sql, string $tipos = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar consulta: ' . $db->error);
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar consulta: ' . $stmt->error);
    }
    $res = $stmt->get_result();
    if ($res === false) {
        throw new RuntimeException('Falha ao ler resultado: ' . $stmt->error);
    }
    return $res;
}

/**
 * Consultas preparadas devolvem colunas numéricas como int/float; o query() usado até
 * então devolvia tudo como string. Mantém o formato que os consumidores já recebem.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $chave => $valor) {
        if (is_int($valor) || is_float($valor)) {
            $linha[$chave] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $linha = consultaPreparada(
        $db,
        'SELECT id, nome, papel, senha FROM usuarios WHERE login = ?',
        's',
        [$usuario]
    )->fetch_assoc();
    if (!$linha) {
        return null;
    }
    $hash = $linha['senha'];
    unset($linha['senha']);

    // md5 sem sal (32 hex) é o formato legado: continua aceito para não invalidar as
    // senhas existentes e é trocado por password_hash() no primeiro login válido.
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    if ($legado) {
        $confere = hash_equals(strtolower($hash), md5($senha));
    } else {
        $confere = password_verify($senha, $hash);
    }
    if (!$confere) {
        return null;
    }
    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        regravarHashSenha($db, (int) $linha['id'], $senha, $hash);
    }
    return $linha;
}

/**
 * Regrava a senha com password_hash(). Melhor esforço: nunca impede o login.
 * Só grava se usuarios.senha comportar o hash novo — enquanto a coluna for CHAR(32)
 * (banco ainda não migrado, ver schema.sql) o md5 legado fica como está.
 */
function regravarHashSenha(mysqli $db, int $usuarioId, string $senha, string $hashAtual): void
{
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        $coluna = consultaPreparada(
            $db,
            'SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        )->fetch_assoc();
        if (!$coluna || (int) $coluna['tamanho'] < strlen($novo)) {
            return;
        }
        // "AND senha = ?": não sobrescreve uma senha trocada por outro processo nesse meio-tempo.
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($stmt === false) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('sis', $novo, $usuarioId, $hashAtual);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
    } catch (Throwable $e) {
        error_log('painel: hash de senha do usuario ' . $usuarioId . ' nao foi regravado: ' . $e->getMessage());
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
    } else if ($status == 3) {
        return 'Resolvido';
    } else {
        // Fora do domínio 1..3 (schema.sql): antes caía em 'Resolvido' por engano.
        return 'Desconhecido';
    }
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    $slaMinutos = 30; // limite do SLA de 1ª resposta
    $alta = 3;        // prioridades: 1=baixa, 2=normal, 3=alta, 4=crítica (schema.sql)
    $critica = 4;

    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < $alta) {
        return 'Normal';
    }
    if ($minutos <= $slaMinutos) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade == $critica ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só os que abriu.
 * Devolve o id do dono a que as consultas ficam restritas, ou null quando não há restrição.
 */
function donoRestrito(int $usuarioId, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $usuarioId;
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
    return listarChamadosDoDono($db, null, $busca);
}

/**
 * Mesma listagem, restrita aos chamados abertos por $donoId (null = sem restrição).
 */
function listarChamadosDoDono(mysqli $db, ?int $donoId, string $busca = ''): array
{
    // O nome do técnico vem no mesmo SELECT (antes: uma consulta por linha).
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $res = consultaPreparada($db, $sql, $tipos, $params);

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = linhaComoTexto($c);
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
 * Mesmo chamado, mas só se foi aberto por $donoId (null = sem restrição).
 * Chamado de outro dono responde como inexistente, para não confirmar que o id existe.
 */
function verChamadoDoDono(mysqli $db, int $id, ?int $donoId): ?array
{
    $c = verChamado($db, $id);
    if ($c !== null && $donoId !== null && (int) $c['usuario_id'] !== $donoId) {
        return null;
    }
    return $c;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    // Agregado no banco (antes: todas as linhas vinham para o PHP somar).
    // COUNT(coluna) ignora NULL, como o filtro IS NOT NULL de antes.
    $r = consultaPreparada(
        $db,
        'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados'
    )->fetch_assoc();
    $qtd = (int) $r['qtd'];
    if ($qtd === 0) {
        return 0.0; // nenhum chamado respondido ainda (antes: divisão por zero)
    }
    return (int) $r['soma'] / $qtd;
}

/**
 * Neutraliza valores que uma planilha interpretaria como fórmula (começam com =, +, -, @,
 * TAB, CR ou LF), prefixando-os com apóstrofo. Os demais saem inalterados.
 */
function neutralizarFormulaCsv(string $valor): string
{
    if ($valor !== '' && strpos("=+-@\t\r\n", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
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
 * Mesmo CSV, restrito aos chamados abertos por $donoId (null = sem restrição).
 */
function exportarCsvDoDono(mysqli $db, ?int $donoId): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, t.nome AS tecnico_nome'
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $params[] = $donoId;
    }
    $sql .= ' ORDER BY c.id';
    // Consulta antes dos cabeçalhos: se falhar, ainda dá para responder com erro.
    $res = consultaPreparada($db, $sql, $tipos, $params);

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }
    // Direto na saída: sem o arquivo intermediário compartilhado em EXPORT_DIR.
    // Separador, aspas e escape explícitos = mesmos bytes de antes, sem depender do
    // padrão de $escape (obsoleto desde o PHP 8.4).
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            neutralizarFormulaCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] === null ? '-' : neutralizarFormulaCsv($c['tecnico_nome']),
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
}
