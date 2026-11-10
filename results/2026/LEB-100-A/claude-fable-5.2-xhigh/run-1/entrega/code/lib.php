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
 * Aceita os dois formatos de senha guardada: o md5 legado (32 hex) e password_hash().
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
    $guardada = (string) $linha['senha'];
    unset($linha['senha']);

    if (preg_match('/^[0-9a-f]{32}$/i', $guardada)) {
        // Hash legado. A comparação era feita no SQL, sem distinguir maiúsculas; daí o strtolower.
        if (!hash_equals(strtolower($guardada), md5($senha))) {
            return null;
        }
        migrarSenhaLegada($db, (int) $linha['id'], $guardada, $senha);
        return $linha;
    }
    return password_verify($senha, $guardada) ? $linha : null;
}

/**
 * Depois de um login válido com hash md5, regrava a senha com password_hash().
 *
 * Só age se a coluna usuarios.senha comportar o hash novo: no schema original ela é
 * CHAR(32), e gravar ali truncaria o hash e trancaria o usuário para fora. Qualquer
 * falha é apenas registrada — o login já foi validado e a troca fica para o próximo.
 */
function migrarSenhaLegada(mysqli $db, int $id, string $md5Atual, string $senha): void
{
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || $novo === '') {
            return;
        }
        $res = $db->query(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS tam FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        );
        $coluna = $res ? $res->fetch_assoc() : null;
        if (!$coluna || (int) $coluna['tam'] < strlen($novo)) {
            return;
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($stmt) {
            $stmt->bind_param('sis', $novo, $id, $md5Atual);
            $stmt->execute();
        }
    } catch (Throwable $e) {
        error_log('painel: migracao de senha adiada (usuario ' . $id . '): ' . $e->getMessage());
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
    if ($prioridade < 3) { // 1=baixa, 2=normal
        return 'Normal';
    }
    if ($minutos <= 30) { // SLA de 1a resposta: 30 minutos
        return 'Alto - dentro do SLA';
    }
    return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id.
 * A listagem e o export não a usam mais (o nome vem no JOIN); mantida para quem a chama.
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
 * Executa uma consulta parametrizada. Lança exceção em caso de falha, qualquer que seja
 * o modo de erro do mysqli configurado por quem abriu a conexão.
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
 * Consultas preparadas devolvem números nativos; $db->query() devolvia tudo como string.
 * Quem consome listarChamados()/verChamado() recebe as linhas no formato antigo desde
 * sempre, então ele é mantido.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $chave => $valor) {
        if ($valor !== null && !is_string($valor)) {
            $linha[$chave] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente, só os que
 * ele mesmo abriu. Devolve o id de dono a exigir nas consultas, ou null (sem restrição).
 * Papel desconhecido é tratado como cliente.
 */
function escopoVisibilidade(int $usuarioId, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $usuarioId;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosVisiveis($db, $busca, null);
}

/**
 * Listagem restrita a um dono (ver escopoVisibilidade); null lista todos.
 */
function listarChamadosVisiveis(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = 'SELECT c.*, t.nome AS tecnico_nome FROM chamados c'
         . ' LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC, c.id DESC';
    $res = consultaPreparada($db, $sql, $tipos, $params);

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c = linhaComoTexto($c);
        if ($c['tecnico_nome'] === null) {
            $c['tecnico_nome'] = '-';
        }
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    return verChamadoVisivel($db, $id, null);
}

/**
 * Detalhe restrito a um dono (ver escopoVisibilidade); null não restringe.
 */
function verChamadoVisivel(mysqli $db, int $id, ?int $donoId): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $params = [$id];
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    $c = consultaPreparada($db, $sql, $tipos, $params)->fetch_assoc();
    return $c ? linhaComoTexto($c) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido, devolve 0.0.
 */
function mediaResposta(mysqli $db): float
{
    $row = consultaPreparada(
        $db,
        'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados'
    )->fetch_assoc();
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Texto livre que começa com = + - @ (ou TAB/CR) é interpretado como fórmula quando o
 * CSV é aberto em planilha; o apóstrofo na frente faz a célula ser lida como texto.
 */
function csvTextoSeguro(string $valor): string
{
    if ($valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
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
    exportarCsvVisivel($db, null);
}

/**
 * Export restrito a um dono (ver escopoVisibilidade); null exporta todos.
 * Escreve direto na saída: não há mais arquivo intermediário compartilhado em EXPORT_DIR.
 */
function exportarCsvVisivel(mysqli $db, ?int $donoId): void
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
    // A consulta roda antes de qualquer saída: se falhar, nenhum CSV pela metade é enviado.
    $res = consultaPreparada($db, $sql, $tipos, $params);

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        throw new RuntimeException('Falha ao abrir a saida para o CSV.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    // Separador, aspas e escape explícitos: são os valores que o fputcsv já usava por
    // padrão (o PHP 8.4 emite aviso de depreciação quando o escape é omitido).
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            csvTextoSeguro((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] !== null ? csvTextoSeguro((string) $c['tecnico_nome']) : '-',
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
}
