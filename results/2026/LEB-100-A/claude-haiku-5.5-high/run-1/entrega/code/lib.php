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
    if (!$linha || !verificarSenha($db, (int) $linha['id'], $linha['senha'], $senha)) {
        return null;
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Confere a senha com o hash guardado. Hash md5 legado (sem sal) é aceito e
 * regravado com password_hash no mesmo login. A regravação é best-effort: se a
 * coluna ainda for CHAR(32) (migração pendente), o login segue com md5.
 */
function verificarSenha(mysqli $db, int $id, string $guardado, string $senha): bool
{
    if (password_get_info($guardado)['algo'] !== null) {
        return password_verify($senha, $guardado);
    }
    if (!hash_equals($guardado, md5($senha))) {
        return false;
    }
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->bind_param('si', $novo, $id);
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // coluna ainda estreita: mantém o md5 até a migração de schema.sql
    }
    return true;
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
 * Regra de visibilidade: técnico vê qualquer chamado; cliente só os que abriu.
 * Papel desconhecido cai no ramo do cliente (falha fechada).
 */
function podeVerChamado(array $chamado, int $uid, string $papel): bool
{
    return $papel === 'tecnico' || (int) $chamado['usuario_id'] === $uid;
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
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $termo = '%' . addcslashes($busca, '%_\\') . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
    $res = $db->query('SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL');
    $soma = 0;
    $qtd = 0;
    while ($row = $res->fetch_assoc()) {
        $soma += (int) $row['minutos_resposta'];
        $qtd++;
    }
    if ($qtd === 0) {
        return 0.0;
    }
    return $soma / $qtd;
}

/**
 * Neutraliza fórmulas do Excel/Calc em células vindas de usuário (CSV injection).
 */
function protegerCelula(string $valor): string
{
    return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
}

/**
 * Exporta todos os chamados para CSV: grava o arquivo em EXPORT_DIR (de forma
 * atômica) e escreve o mesmo conteúdo na saída.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $fp = fopen('php://temp', 'w+');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $res = $db->query("SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico_nome
                       FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
                       ORDER BY c.id");
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            protegerCelula($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    rewind($fp);
    $conteudo = stream_get_contents($fp);
    fclose($fp);

    // escrita em arquivo temporário + rename: leitores nunca veem arquivo truncado
    $caminho = EXPORT_DIR . '/chamados.csv';
    $tmp = $caminho . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $conteudo) !== false) {
        rename($tmp, $caminho);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    echo $conteudo;
}
