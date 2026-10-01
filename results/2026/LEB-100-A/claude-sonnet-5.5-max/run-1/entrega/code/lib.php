<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Visibilidade (regra de negócio): cliente só vê os chamados que abriu; técnico vê todos.
 * As funções legadas listarChamados(), verChamado() e exportarCsv() não conhecem o usuário e
 * continuam devolvendo TUDO — são para uso interno (job noturno, relatório). Toda requisição
 * web deve usar as variantes *DoUsuario(), que aplicam a regra.
 */

/**
 * Executa uma consulta parametrizada e devolve o resultado. Todo valor que vem de fora entra
 * por $params (nunca concatenado ao SQL). Falha vira exceção em qualquer versão do PHP.
 */
function consultaParametrizada(mysqli $db, string $sql, string $tipos = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar a consulta: ' . $db->error);
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar a consulta: ' . $stmt->error);
    }
    $res = $stmt->get_result();
    if ($res === false) {
        throw new RuntimeException('Consulta sem resultado: ' . $stmt->error);
    }
    return $res;
}

/**
 * Consulta preparada devolve int onde query() devolvia string; os consumidores legados esperam
 * o formato antigo (todo valor como string, NULL continua NULL), então ele é preservado.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $coluna => $valor) {
        if ($valor !== null && !is_string($valor)) {
            $linha[$coluna] = (string) $valor;
        }
    }
    return $linha;
}

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

    // Senhas antigas são md5 puro (32 hex, sem sal); as novas, password_hash(). Os dois formatos
    // convivem até cada usuário logar uma vez: nesse login o hash legado é substituído.
    $armazenado = (string) $linha['senha'];
    $legado = preg_match('/^[0-9a-f]{32}$/i', $armazenado) === 1;
    $confere = $legado
        ? hash_equals(strtolower($armazenado), md5($senha))
        : password_verify($senha, $armazenado);
    if (!$confere) {
        return null;
    }
    if ($legado || password_needs_rehash($armazenado, PASSWORD_DEFAULT)) {
        migrarHashSenha($db, (int) $linha['id'], $senha);
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Grava o hash novo da senha. Melhor esforço: nunca pode derrubar um login válido.
 * Se a coluna ainda é CHAR(32) (schema antigo) o hash não cabe e, sem modo SQL estrito, o MySQL
 * o truncaria em silêncio e trancaria o usuário para fora; então, nesse caso, não grava nada.
 * Sem permissão de UPDATE para o usuário do banco o efeito é o mesmo (só log).
 */
function migrarHashSenha(mysqli $db, int $id, string $senha): void
{
    try {
        $col = consultaParametrizada(
            $db,
            'SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        )->fetch_assoc();
        $tamanho = $col ? (int) $col['tamanho'] : 0;
        if ($tamanho < 60) {
            return;
        }
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (strlen($novo) > $tamanho) {
            return;
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->bind_param('si', $novo, $id);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[painel] nao foi possivel migrar o hash de senha do usuario ' . $id . ': ' . $e->getMessage());
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
 * Prioridade 3 (alta) e 4 (crítica) têm SLA de 30 min para a 1ª resposta; abaixo disso é "Normal".
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
 * Busca o nome de um técnico pelo id.
 * @deprecated listarChamados() e exportarCsv() já trazem o nome no mesmo SELECT (JOIN); chamar
 *             isto dentro de um laço volta a gerar uma consulta por linha.
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $res = consultaParametrizada($db, 'SELECT nome FROM usuarios WHERE id = ?', 'i', [$tecnicoId]);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Regra de visibilidade. Devolve o id do dono a filtrar, ou null quando não há filtro:
 * técnico vê qualquer chamado; qualquer outro papel (cliente, vazio, desconhecido) só vê os
 * que abriu. Na dúvida, nega.
 */
function donoDoEscopo(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Consulta de chamados, cada um com a chave extra 'tecnico_nome' ('-' sem técnico).
 * Um único SELECT com JOIN. $donoId = null → sem filtro de dono.
 */
function consultarChamados(mysqli $db, ?int $donoId, string $busca): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
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

    $res = consultaParametrizada($db, $sql, $tipos, $params);
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = linhaComoTexto($c);
    }
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * SEM filtro de visibilidade (uso interno). Na web: listarChamadosDoUsuario().
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, null, $busca);
}

/**
 * Lista os chamados que o usuário pode ver (cliente: os que abriu; técnico: todos).
 */
function listarChamadosDoUsuario(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarChamados($db, donoDoEscopo($uid, $papel), $busca);
}

/**
 * Carrega um chamado pelo id; $donoId != null restringe aos chamados desse dono.
 */
function buscarChamado(mysqli $db, ?int $donoId, int $id): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $params = [$id];
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    $linha = consultaParametrizada($db, $sql, $tipos, $params)->fetch_assoc();
    return $linha ? linhaComoTexto($linha) : null;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * SEM filtro de visibilidade (uso interno). Na web: verChamadoDoUsuario().
 */
function verChamado(mysqli $db, int $id): ?array
{
    return buscarChamado($db, null, $id);
}

/**
 * Carrega o chamado se o usuário puder vê-lo; senão null (igual a "não existe", de propósito:
 * não revela quais ids existem).
 */
function verChamadoDoUsuario(mysqli $db, int $uid, string $papel, int $id): ?array
{
    return buscarChamado($db, donoDoEscopo($uid, $papel), $id);
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido devolve 0.0 (antes: divisão por zero derrubava a página).
 */
function mediaResposta(mysqli $db): float
{
    $r = consultaParametrizada(
        $db,
        'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados'
    )->fetch_assoc();
    $qtd = (int) $r['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $r['soma'] / $qtd;
}

/**
 * Neutraliza "injeção de fórmula": célula de texto que começa com = + - @ (ou TAB/CR) é
 * executada como fórmula ao abrir o CSV no Excel/LibreOffice. O apóstrofo força texto.
 */
function csvSeguro(string $valor): string
{
    if ($valor !== '' && strpbrk($valor[0], "=+-@\t\r") !== false) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Escreve o CSV de chamados direto na saída, sem arquivo intermediário.
 * $donoId = null → todos os chamados.
 */
function escreverCsvChamados(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $params[] = $donoId;
    }
    $sql .= ' ORDER BY c.id';

    // A consulta roda antes de qualquer saída: se falhar, não sai CSV truncado com status 200.
    $res = consultaParametrizada($db, $sql, $tipos, $params);

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        throw new RuntimeException('Saida indisponivel para o CSV.');
    }
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }
    // Delimitador, aspas e escape explícitos = os padrões do PHP: o formato não muda (e o PHP 8.4
    // deixa de avisar sobre o $escape implícito).
    $linha = function (array $campos) use ($fp) {
        fputcsv($fp, $campos, ',', '"', '\\');
    };
    $linha(['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        $linha([
            $c['id'],
            csvSeguro((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * SEM filtro de visibilidade (uso interno). Na web: exportarCsvDoUsuario().
 */
function exportarCsv(mysqli $db): void
{
    escreverCsvChamados($db, null);
}

/**
 * Exporta para CSV só os chamados que o usuário pode ver.
 */
function exportarCsvDoUsuario(mysqli $db, int $uid, string $papel): void
{
    escreverCsvChamados($db, donoDoEscopo($uid, $papel));
}
