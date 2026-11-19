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
    $hash = $linha['senha'];
    if (preg_match('/^[a-f0-9]{32}$/iD', $hash)) {
        if (!hash_equals(strtolower($hash), md5($senha))) {
            return null;
        }
        // Em instalações com CHAR(32), aguarde a ampliação antes de gravar.
        $coluna = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        if ((int) $coluna->fetch_row()[0] >= 255) {
            $novo = password_hash($senha, PASSWORD_ARGON2ID);
            $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND BINARY senha = ?');
            $atualizar->bind_param('sis', $novo, $linha['id'], $hash);
            $atualizar->execute();
            if ($atualizar->affected_rows === 0) {
                // Outra conexão pode ter migrado ou trocado a senha.
                $stmt->execute();
                $linha = $stmt->get_result()->fetch_assoc();
                if (!$linha || !password_verify($senha, $linha['senha'])) {
                    return null;
                }
            }
        }
    } elseif (!password_verify($senha, $hash)) {
        return null;
    }
    unset($linha['senha']);
    return $linha;
}

function papelAtualDoUsuario(mysqli $db, int $uid): ?string
{
    $stmt = $db->prepare('SELECT papel FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha['papel'] ?? null;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    switch ($status) {
        case 1: return 'Aberto';
        case 2: return 'Em atendimento';
        case 3: return 'Resolvido';
        default: return 'Desconhecido';
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
    return $prioridade === 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca avulsa do nome de um técnico pelo id.
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
    return listarChamadosPorDono($db, $busca, null);
}

function listarChamadosPorDono(mysqli $db, string $busca, ?int $dono): array
{
    $stmt = $db->prepare("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
        WHERE (? IS NULL OR c.usuario_id = ?) AND c.titulo LIKE ?
        ORDER BY c.criado_em DESC");
    $padrao = '%' . $busca . '%';
    $stmt->bind_param('iis', $dono, $dono, $padrao);
    $stmt->execute();
    $res = $stmt->get_result();
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        // A consulta textual original entregava números como strings.
        $chamados[] = array_map(static function ($valor) {
            return is_int($valor) ? (string) $valor : $valor;
        }, $c);
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
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    return mediaRespostaPorDono($db, null);
}

function mediaRespostaPorDono(mysqli $db, ?int $dono): float
{
    // O operando de ponto flutuante preserva as casas da divisão original em PHP.
    $stmt = $db->prepare('SELECT COALESCE(AVG(minutos_resposta * 1e0), 0) FROM chamados
        WHERE ? IS NULL OR usuario_id = ?');
    $stmt->bind_param('ii', $dono, $dono);
    $stmt->execute();
    return (float) $stmt->get_result()->fetch_row()[0];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvPorDono($db, null);
}

function exportarCsvPorDono(mysqli $db, ?int $dono): void
{
    $stmt = $db->prepare("SELECT c.id, c.titulo, c.status, COALESCE(t.nome, '-'), c.criado_em
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
        WHERE ? IS NULL OR c.usuario_id = ?
        ORDER BY c.id");
    $stmt->bind_param('ii', $dono, $dono);
    $stmt->execute();
    $stmt->bind_result($id, $titulo, $status, $tecnico, $abertoEm);
    $fp = fopen('php://output', 'wb');
    if ($fp === false) {
        throw new RuntimeException('Falha ao abrir a saída do CSV.');
    }
    try {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        $cabecalho = "ID,Titulo,Status,Tecnico,Aberto em\n";
        if (fwrite($fp, $cabecalho) !== strlen($cabecalho)) {
            throw new RuntimeException('Falha ao escrever o cabeçalho do CSV.');
        }
        while (($lido = $stmt->fetch()) === true) {
            $campos = [$id, $titulo, formatarStatus($status), $tecnico, $abertoEm];
            if (fputcsv($fp, $campos, ',', '"', '') === false) {
                throw new RuntimeException('Falha ao escrever a linha do CSV.');
            }
        }
        if ($lido === false) {
            throw new RuntimeException('Falha ao ler os chamados para o CSV.');
        }
    } finally {
        fclose($fp);
        $stmt->close();
    }
}
