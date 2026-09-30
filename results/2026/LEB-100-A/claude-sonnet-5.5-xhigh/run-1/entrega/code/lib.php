<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Regra de visibilidade: cliente só enxerga chamados que ele abriu; técnico
 * enxerga todos. As funções públicas de assinatura antiga (listarChamados,
 * verChamado, exportarCsv) NÃO filtram por usuário — servem aos scripts
 * internos. O painel web usa as variantes *Visiveis/*Visivel, que recebem o
 * dono a restringir (null = sem restrição, só para técnico).
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Aceita senhas gravadas em md5 (legado) e em password_hash(). Um login válido
 * com hash legado migra o hash para password_hash() se a coluna comportar.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$linha) {
        // Gasta o tempo de uma verificação real: não deixa enumerar logins pelo tempo de resposta.
        password_verify($senha, '$2y$12$vEB5lPkk1gHQIk/5w6A5lufMUT94K4FagbGWGOxdTldxrgBbDVS8e');
        return null;
    }

    $guardado = (string) $linha['senha'];
    if (hashLegadoMd5($guardado)) {
        if (!hash_equals(strtolower($guardado), md5($senha))) {
            return null;
        }
        migrarHashSenha($db, (int) $linha['id'], $guardado, $senha);
    } elseif (!password_verify($senha, $guardado)) {
        return null;
    }

    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    }
    if ($status === 2) {
        return 'Em atendimento';
    }
    return 'Resolvido';
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    $limiteSlaMinutos = 30;

    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos <= $limiteSlaMinutos) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id.
 *
 * @deprecated listarChamados() e exportarCsv() já trazem o nome por JOIN; esta função
 *             faz uma consulta por chamado. Mantida só para quem a chama diretamente.
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
 * Sem filtro de dono: uso interno. O painel web usa listarChamadosVisiveis().
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosVisiveis($db, null, $busca);
}

/**
 * Como listarChamados(), restrito aos chamados abertos por $donoId.
 * $donoId = null não restringe (técnico).
 */
function listarChamadosVisiveis(mysqli $db, ?int $donoId, string $busca = ''): array
{
    $res = consultarChamadosBase(
        $db,
        'c.*, COALESCE(t.nome, \'-\') AS tecnico_nome',
        'c.criado_em DESC, c.id DESC',
        $busca,
        $donoId
    );

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = linhaComoTexto($c);
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Sem filtro de dono: uso interno. O painel web usa verChamadoVisivel().
 */
function verChamado(mysqli $db, int $id): ?array
{
    return verChamadoVisivel($db, null, $id);
}

/**
 * Como verChamado(), mas só devolve o chamado se ele pertencer a $donoId
 * (chamado alheio é indistinguível de chamado inexistente). null = sem restrição.
 */
function verChamadoVisivel(mysqli $db, ?int $donoId, int $id): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $args = [$id];
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $args[] = $donoId;
    }

    $stmt = $db->prepare($sql);
    $stmt->bind_param($tipos, ...$args);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $linha ? linhaComoTexto($linha) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhuma resposta registrada devolve 0.0.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COUNT(minutos_resposta) AS qtd, SUM(minutos_resposta) AS soma FROM chamados');
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
 * Sem filtro de dono: uso interno. O painel web usa exportarCsvVisivel().
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvVisivel($db, null);
}

/**
 * Como exportarCsv(), restrito aos chamados abertos por $donoId (null = todos).
 * Escreve direto na saída: não deixa arquivo em disco nem compartilha estado
 * entre requisições simultâneas.
 */
function exportarCsvVisivel(mysqli $db, ?int $donoId): void
{
    $res = consultarChamadosBase(
        $db,
        'c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, \'-\') AS tecnico_nome',
        'c.id',
        '',
        $donoId
    );

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }

    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '');
    while ($c = $res->fetch_assoc()) {
        fputcsv($saida, [
            $c['id'],
            neutralizarFormulaCsv((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '');
    }
    fclose($saida);
}

// ---------------------------------------------------------------------------
// Internas (não fazem parte da superfície pública)
// ---------------------------------------------------------------------------

/**
 * Consulta base de chamados com o nome do técnico (LEFT JOIN, sem N+1).
 * $colunas e $ordem são fragmentos fixos escolhidos pelo chamador — nunca dado
 * do usuário. $busca e $donoId entram sempre como parâmetros.
 */
function consultarChamadosBase(mysqli $db, string $colunas, string $ordem, string $busca, ?int $donoId): mysqli_result
{
    $sql = 'SELECT ' . $colunas
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    $tipos = '';
    $args = [];

    if ($donoId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $args[] = $donoId;
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $args[] = '%' . $busca . '%';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY ' . $ordem;

    $stmt = $db->prepare($sql);
    if ($args) {
        $stmt->bind_param($tipos, ...$args);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
}

/**
 * Prepared statements devolvem inteiros nativos; o código legado sempre recebeu
 * texto (query() sem prepare). Mantém o formato que os consumidores já conhecem.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $campo => $valor) {
        $linha[$campo] = $valor === null ? null : (string) $valor;
    }
    return $linha;
}

/**
 * Impede que um título vire fórmula ao abrir o CSV no Excel/Calc
 * (=, +, -, @, TAB, CR no início da célula).
 */
function neutralizarFormulaCsv(string $texto): string
{
    if ($texto !== '' && strpos("=+-@\t\r", $texto[0]) !== false) {
        return "'" . $texto;
    }
    return $texto;
}

/** md5 hexadecimal de 32 caracteres, como gravado pelo sistema legado. */
function hashLegadoMd5(string $hash): bool
{
    return strlen($hash) === 32 && ctype_xdigit($hash);
}

/**
 * Troca o hash md5 do usuário por password_hash(), sem nunca impedir o login.
 * Só grava se a coluna usuarios.senha comportar o hash novo: em CHAR(32),
 * um UPDATE truncaria o hash e trancaria o usuário para fora.
 */
function migrarHashSenha(mysqli $db, int $id, string $hashAntigo, string $senha): void
{
    try {
        $res = $db->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        );
        $col = $res ? $res->fetch_assoc() : null;
        if (!$col || (int) $col['n'] < 60) { // 60 = tamanho de um hash bcrypt; evita gastar CPU à toa
            return;
        }
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (strlen($novo) > (int) $col['n']) {
            return;
        }

        // "AND senha = antigo": não sobrescreve uma troca de senha feita em paralelo.
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $stmt->bind_param('sis', $novo, $id, $hashAntigo);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log('autenticar: nao foi possivel migrar o hash da senha do usuario ' . $id . ': ' . $e->getMessage());
    }
}
