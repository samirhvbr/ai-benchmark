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
 * Senhas novas são guardadas com password_hash(). Senhas legadas (MD5 puro)
 * continuam aceitas e são regravadas no formato novo no primeiro login.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha || !senhaConfere($senha, $linha['senha'])) {
        return null;
    }
    if (password_get_info($linha['senha'])['algo'] === null) {
        atualizarHashSenha($db, (int) $linha['id'], $senha);
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Confere a senha contra o valor guardado (formato novo ou MD5 legado).
 */
function senhaConfere(string $senha, string $armazenada): bool
{
    if (password_get_info($armazenada)['algo'] !== null) {
        return password_verify($senha, $armazenada);
    }
    return hash_equals($armazenada, md5($senha));
}

/**
 * Regrava a senha no formato novo. Só grava se a coluna `senha` couber o hash:
 * com a coluna ainda CHAR(32), um hash truncado trancaria o usuário para fora.
 */
function atualizarHashSenha(mysqli $db, int $id, string $senha): void
{
    $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
    $tamanho = (int) ($res->fetch_assoc()['tamanho'] ?? 0);
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    if (strlen($hash) > $tamanho) {
        return;
    }
    $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $id);
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
 * Consulta base dos chamados. $usuarioId null = todos; senão só os abertos por esse usuário.
 * $ordem é um trecho fixo do ORDER BY, sempre escrito no código (nunca vem da requisição).
 * Cada chamado já traz a chave 'tecnico_nome' (via JOIN, sem uma consulta por linha).
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId, string $ordem): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id WHERE 1 = 1';
    $tipos = '';
    $params = [];
    if ($usuarioId !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $usuarioId;
    }
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . addcslashes($busca, '%_\\') . '%';
    }
    $stmt = $db->prepare($sql . ' ORDER BY ' . $ordem);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    // prepared statement devolve inteiros nativos; mantém strings, como o query() de antes
    $chamados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return array_map(fn($c) => array_map(fn($v) => $v === null ? null : (string) $v, $c), $chamados);
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'c.criado_em DESC');
}

/**
 * Como listarChamados(), mas só traz os chamados que o usuário pode ver (ver podeVer()).
 */
function listarChamadosVisiveis(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarChamados($db, $busca, $papel === 'tecnico' ? null : $uid, 'c.criado_em DESC');
}

/**
 * Verdadeiro se o usuário pode abrir o chamado: técnico vê qualquer um,
 * cliente só os que ele mesmo abriu.
 */
function podeVer(string $papel, int $uid, array $chamado): bool
{
    return $papel === 'tecnico' || (int) $chamado['usuario_id'] === $uid;
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
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $linha = $res->fetch_assoc();
    return (int) $linha['qtd'] === 0 ? 0.0 : (int) $linha['soma'] / (int) $linha['qtd'];
}

/**
 * Exporta todos os chamados para CSV e escreve na saída (download no navegador).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

/**
 * Exporta para CSV só os chamados que o usuário pode ver (ver podeVer()).
 */
function exportarCsvVisiveis(mysqli $db, int $uid, string $papel): void
{
    escreverCsv($db, $papel === 'tecnico' ? null : $uid);
}

/**
 * Escreve o CSV direto na saída. Não grava arquivo em disco: EXPORT_DIR fica
 * dentro do webroot e o arquivo ficaria acessível sem login.
 */
function escreverCsv(mysqli $db, ?int $usuarioId): void
{
    $chamados = consultarChamados($db, '', $usuarioId, 'c.id');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    foreach ($chamados as $c) {
        fputcsv($fp, [
            $c['id'],
            protegerCelula($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}

/**
 * Impede que um texto vindo do usuário seja lido como fórmula no Excel/Calc.
 */
function protegerCelula(string $valor): string
{
    return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
}
