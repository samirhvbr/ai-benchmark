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
    $hash = md5($senha);
    $stmt = $db->prepare('SELECT id, nome, papel FROM usuarios WHERE login = ? AND senha = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ss', $usuario, $hash);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $linha ?: null;
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
    if ($res === false) {
        return '-';
    }
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Retorna o id do cliente logado quando houver sessão web ativa.
 */
function clienteLogadoId(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    if (($_SESSION['papel'] ?? null) !== 'cliente') {
        return null;
    }

    if (!isset($_SESSION['uid']) || !is_numeric($_SESSION['uid'])) {
        return null;
    }

    return (int) $_SESSION['uid'];
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $clienteId = clienteLogadoId();
    $stmt = null;

    if ($clienteId !== null && $busca !== '') {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE usuario_id = ? AND titulo LIKE ? ORDER BY criado_em DESC');
        if ($stmt === false) {
            return [];
        }
        $termoBusca = '%' . $busca . '%';
        $stmt->bind_param('is', $clienteId, $termoBusca);
        $stmt->execute();
        $res = $stmt->get_result();
    } elseif ($clienteId !== null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE usuario_id = ? ORDER BY criado_em DESC');
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } elseif ($busca !== '') {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE titulo LIKE ? ORDER BY criado_em DESC');
        if ($stmt === false) {
            return [];
        }
        $termoBusca = '%' . $busca . '%';
        $stmt->bind_param('s', $termoBusca);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query('SELECT * FROM chamados ORDER BY criado_em DESC');
    }

    if ($res === false) {
        if ($stmt !== null) {
            $stmt->close();
        }
        return [];
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        $chamados[] = $c;
    }

    if ($stmt !== null) {
        $stmt->close();
    }

    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $clienteId = clienteLogadoId();

    if ($clienteId !== null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $id, $clienteId);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
    }

    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $linha ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res === false) {
        return 0.0;
    }

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
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $caminho = EXPORT_DIR . '/chamados.csv';
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $clienteId = clienteLogadoId();
    $stmt = null;

    if ($clienteId !== null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE usuario_id = ? ORDER BY id');
        if ($stmt === false) {
            fclose($fp);
            return;
        }
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query('SELECT * FROM chamados ORDER BY id');
    }

    if ($res === false) {
        if ($stmt !== null) {
            $stmt->close();
        }
        fclose($fp);
        return;
    }

    while ($c = $res->fetch_assoc()) {
        $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $tecnico,
            $c['criado_em'],
        ]);
    }

    if ($stmt !== null) {
        $stmt->close();
    }

    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
}
