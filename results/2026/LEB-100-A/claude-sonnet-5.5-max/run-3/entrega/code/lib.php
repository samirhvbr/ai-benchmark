<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Visibilidade (manifest.md): cliente só vê os chamados que abriu; técnico vê todos.
 * listarChamados(), verChamado() e exportarCsv() mantêm assinatura e comportamento
 * (visão irrestrita, usada pelas rotinas internas, que não têm usuário logado).
 * A camada web deve usar as variantes *Visiveis / *Visivel, que aplicam a regra
 * por usuário e papel.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Aceita hashes gerados por password_hash() e o MD5 legado. Ao autenticar com um
 * hash MD5 e se a coluna já comportar, regrava a senha com password_hash()
 * (migração oportunista, usuário a usuário; ver atualizarHashDeSenha()).
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

    $guardado = (string) $linha['senha'];
    if (senhaEhMd5Legado($guardado)) {
        if (!hash_equals(strtolower($guardado), md5($senha))) {
            return null;
        }
        atualizarHashDeSenha($db, (int) $linha['id'], $senha, $guardado);
    } elseif (!password_verify($senha, $guardado)) {
        return null;
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Hash legado: 32 dígitos hexadecimais (MD5). Hashes de password_hash() começam com '$'.
 */
function senhaEhMd5Legado(string $hash): bool
{
    return (bool) preg_match('/^[0-9a-f]{32}$/i', $hash);
}

/**
 * Troca o hash MD5 de um usuário por password_hash(), após um login bem-sucedido.
 * Nunca impede o login: se a coluna ainda for CHAR(32) (ALTER TABLE pendente), se o
 * usuário não puder gravar ou se algo falhar, o hash legado simplesmente permanece.
 * O "AND senha = ?" evita sobrescrever uma senha trocada entre a leitura e a gravação.
 */
function atualizarHashDeSenha(mysqli $db, int $id, string $senha, string $hashAtual): void
{
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || $novo === '') {
            return;
        }
        // Só grava se o hash novo couber na coluna: em modo SQL não estrito o MySQL
        // truncaria o valor em silêncio e o usuário ficaria sem conseguir entrar.
        $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $col = $res ? $res->fetch_row() : null;
        if (!$col || (int) $col[0] < strlen($novo)) {
            return;
        }
        $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $upd->bind_param('sis', $novo, $id, $hashAtual);
        $upd->execute();
        $upd->close();
    } catch (Throwable $e) {
        error_log('autenticar: hash da senha do usuario ' . $id . ' nao atualizado: ' . $e->getMessage());
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
 * (prioridade: 3=alta, 4=crítica; $minutos: tempo até a 1ª resposta, SLA de 30 min.)
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
 * Mantida por compatibilidade; listarChamados() e o export já trazem o nome
 * via JOIN (uma consulta por chamado, aqui, era o N+1 da listagem).
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
 * Escopo de visibilidade (manifest.md): id do dono a que a consulta deve ser restrita,
 * ou null quando não há restrição. Técnico vê tudo; qualquer outro papel (cliente, ou
 * valor desconhecido) só vê os chamados que abriu.
 */
function escopoDoUsuario(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Monta a cláusula WHERE comum (dono + busca por título) com seus parâmetros.
 * Devolve [string $where, string $tipos, array $params]. Os curingas % e _ da busca
 * continuam valendo, como antes; o valor sempre vai como parâmetro, nunca no SQL.
 */
function filtroDeChamados(string $busca, ?int $donoId): array
{
    $cond = [];
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $cond[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($busca !== '') {
        $cond[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    return [$cond ? ' WHERE ' . implode(' AND ', $cond) : '', $tipos, $params];
}

/**
 * Executa uma consulta preparada e devolve todas as linhas.
 */
function consultaPreparada(mysqli $db, string $sql, string $tipos = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $linhas = [];
    while ($c = $res->fetch_assoc()) {
        $linhas[] = linhaComoTexto($c);
    }
    $stmt->close();
    return $linhas;
}

/**
 * Consultas preparadas devolvem inteiros nativos; o contrato histórico (query() em modo
 * texto) devolve sempre string (ou NULL). Preserva esse formato para os consumidores.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $campo => $valor) {
        if ($valor !== null) {
            $linha[$campo] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título (visão irrestrita).
 * Retorna cada chamado já com a chave extra 'tecnico_nome' ('-' quando sem técnico).
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarLista($db, $busca, null);
}

/**
 * Como listarChamados(), mas só com o que o usuário pode ver (ver escopoDoUsuario()).
 */
function listarChamadosVisiveis(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarLista($db, $busca, escopoDoUsuario($uid, $papel));
}

function consultarLista(mysqli $db, string $busca, ?int $donoId): array
{
    list($where, $tipos, $params) = filtroDeChamados($busca, $donoId);
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id'
        . $where . ' ORDER BY c.criado_em DESC, c.id DESC';
    return consultaPreparada($db, $sql, $tipos, $params);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe (visão irrestrita).
 */
function verChamado(mysqli $db, int $id): ?array
{
    return consultarChamado($db, $id, null);
}

/**
 * Como verChamado(), mas devolve null para chamado que o usuário não pode ver
 * (indistinguível de um chamado inexistente).
 */
function verChamadoVisivel(mysqli $db, int $id, int $uid, string $papel): ?array
{
    return consultarChamado($db, $id, escopoDoUsuario($uid, $papel));
}

function consultarChamado(mysqli $db, int $id, ?int $donoId): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $params = [$id];
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    $linhas = consultaPreparada($db, $sql, $tipos, $params);
    return $linhas ? $linhas[0] : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Indicador global: considera todos os chamados. Sem nenhum chamado respondido, 0.0.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COUNT(minutos_resposta) AS qtd, COALESCE(SUM(minutos_resposta), 0) AS soma FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados em CSV, escrevendo na saída (visão irrestrita).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    emitirCsv($db, null);
}

/**
 * Como exportarCsv(), mas só com os chamados que o usuário pode ver.
 */
function exportarCsvVisivel(mysqli $db, int $uid, string $papel): void
{
    emitirCsv($db, escopoDoUsuario($uid, $papel));
}

/**
 * Envia o CSV direto na resposta, sem arquivo intermediário: não há arquivo fixo
 * compartilhado entre requisições nem cópia dos dados esquecida em disco.
 */
function emitirCsv(mysqli $db, ?int $donoId): void
{
    list($where, $tipos, $params) = filtroDeChamados('', $donoId);
    $sql = "SELECT c.id, c.titulo, c.status, COALESCE(t.nome, '-') AS tecnico_nome, c.criado_em"
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id'
        . $where . ' ORDER BY c.id';
    $linhas = consultaPreparada($db, $sql, $tipos, $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    // 5º argumento ($escape) explícito: mesmo formato de sempre e sem o aviso de
    // depreciação do PHP 8.4 por depender do valor padrão.
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    foreach ($linhas as $c) {
        fputcsv($fp, [
            $c['id'],
            neutralizarCelulaCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
}

/**
 * Título é texto livre do cliente: célula iniciada por = + - @ (ou TAB/CR) é executada como
 * fórmula ao abrir o CSV no Excel/LibreOffice. O apóstrofo inicial a transforma em texto.
 */
function neutralizarCelulaCsv(string $valor): string
{
    if ($valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
}
