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
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $hash = $linha['senha'];
    if (preg_match('/\A[0-9a-f]{32}\z/i', $hash)) {
        if (!hash_equals(strtolower($hash), md5($senha))) {
            return null;
        }

        $novoHash = password_hash($senha, PASSWORD_ARGON2ID);
        if ($novoHash === false) {
            throw new RuntimeException('Falha ao atualizar o hash de senha.');
        }
        $id = (int) $linha['id'];
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND CAST(senha AS BINARY) = ?');
        $stmt->bind_param('sis', $novoHash, $id, $hash);
        $stmt->execute();
        $atualizado = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$atualizado) {
            return null;
        }
    } elseif (!password_verify($senha, $hash)) {
        return null;
    }

    unset($linha['senha']);
    return $linha;
}

/** @internal Lê o papel vigente sem depender do valor no momento do login. */
function papelDoUsuario(mysqli $db, int $uid): ?string
{
    $stmt = $db->prepare('SELECT papel FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $linha['papel'] ?? null;
}

function escaparHtml(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    } elseif ($status === 2) {
        return 'Em atendimento';
    } elseif ($status === 3) {
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
 * Busca o nome de um técnico pelo id.
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
    $chamados = listarChamadosPorDono($db, $busca, null);
    if ($busca !== '' && $chamados) {
        $numerosNativos = $db->query('SELECT 0 AS amostra')->fetch_assoc();
        if (is_string($numerosNativos['amostra'])) {
            foreach ($chamados as $indice => $chamado) {
                foreach ($chamado as $campo => $valor) {
                    if (is_int($valor)) {
                        $chamados[$indice][$campo] = (string) $valor;
                    }
                }
            }
        }
    }
    return $chamados;
}

/** @internal Escopo da consulta: null indica acesso global autorizado. */
function listarChamadosPorDono(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id";
    $filtros = [];
    $tipos = '';
    $parametros = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $donoId;
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    if (!$parametros) {
        return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param($tipos, ...$parametros);
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
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
    return $res->fetch_assoc() ?: null;
}

/** @internal Escopo da consulta: null indica acesso global autorizado. */
function verChamadoPorDono(mysqli $db, int $id, ?int $donoId): ?array
{
    if ($donoId === null) {
        return verChamado($db, $id);
    }
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
    $stmt->bind_param('ii', $id, $donoId);
    $stmt->execute();
    $chamado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    return mediaRespostaPorDono($db, null);
}

/** @internal Escopo da consulta: null indica acesso global autorizado. */
function mediaRespostaPorDono(mysqli $db, ?int $donoId): float
{
    $sql = 'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados';
    $stmt = $db->prepare($sql . ($donoId !== null ? ' WHERE usuario_id = ?' : ''));
    if ($donoId !== null) {
        $stmt->bind_param('i', $donoId);
    }
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $qtd = (int) $linha['qtd'];
    return $qtd === 0 ? 0.0 : (float) $linha['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV e escreve na saída.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvPorDono($db, null);
}

/** @internal Escopo da consulta: null indica acesso global autorizado. */
function exportarCsvPorDono(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, COALESCE(u.nome, '-'), c.criado_em
            FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id";
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $stmt = $db->prepare($sql . ' ORDER BY c.id');
    if ($donoId !== null) {
        $stmt->bind_param('i', $donoId);
    }
    $stmt->execute();
    $stmt->bind_result($id, $titulo, $status, $tecnico, $criadoEm);

    $saida = fopen('php://output', 'w');
    if ($saida === false) {
        $stmt->close();
        throw new RuntimeException('Falha ao abrir a saída CSV.');
    }
    try {
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="chamados.csv"');
        }
        $cabecalho = "ID,Titulo,Status,Tecnico,Aberto em\n";
        if (fwrite($saida, $cabecalho) !== strlen($cabecalho)) {
            throw new RuntimeException('Falha ao escrever o cabeçalho CSV.');
        }
        while ($stmt->fetch()) {
            $campos = [$id, $titulo, formatarStatus((int) $status), $tecnico, $criadoEm];
            if (fputcsv($saida, $campos, ',', '"', '') === false) {
                throw new RuntimeException('Falha ao escrever uma linha CSV.');
            }
        }
    } finally {
        fclose($saida);
        $stmt->close();
    }
}
