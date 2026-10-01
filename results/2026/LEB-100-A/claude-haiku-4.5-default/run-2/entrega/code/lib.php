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
    $stmt->bind_param('ss', $usuario, $hash);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
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
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Parâmetros opcionais para controle de acesso (adicionados sem quebrar compatibilidade):
 * - $filtrarPorUsuario: se definido, retorna apenas chamados do usuário
 * - $papel: 'cliente' ou 'tecnico' (se cliente + $filtrarPorUsuario, filtra automaticamente)
 */
function listarChamados(mysqli $db, string $busca = '', ?int $filtrarPorUsuario = null, ?string $papel = null): array
{
    $sql = 'SELECT * FROM chamados WHERE 1=1';

    // Controle de acesso: clientes veem só seus chamados
    if ($papel === 'cliente' && $filtrarPorUsuario !== null) {
        $sql .= ' AND usuario_id = ' . (int)$filtrarPorUsuario;
    }

    // Filtro de busca com prepared statement
    if ($busca !== '') {
        $sql .= ' AND titulo LIKE ?';
    }

    $sql .= ' ORDER BY criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Parâmetros opcionais para controle de acesso:
 * - $filtrarPorUsuario: se definido, verifica se o chamado pertence ao usuário
 * - $papel: se 'cliente', valida que o usuário é o dono do chamado
 */
function verChamado(mysqli $db, int $id, ?int $filtrarPorUsuario = null, ?string $papel = null): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';

    // Controle de acesso: clientes veem só seus chamados
    if ($papel === 'cliente' && $filtrarPorUsuario !== null) {
        $sql .= ' AND usuario_id = ?';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $id, $filtrarPorUsuario);
    } else {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
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
    return $qtd > 0 ? $soma / $qtd : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Parâmetros opcionais para controle de acesso:
 * - $filtrarPorUsuario: se definido, exporta apenas chamados do usuário
 * - $papel: 'cliente' ou 'tecnico'
 */
function exportarCsv(mysqli $db, ?int $filtrarPorUsuario = null, ?string $papel = null): void
{
    $caminho = EXPORT_DIR . '/chamados.csv';
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $sql = 'SELECT * FROM chamados';
    if ($papel === 'cliente' && $filtrarPorUsuario !== null) {
        $sql .= ' WHERE usuario_id = ?';
        $stmt = $db->prepare($sql . ' ORDER BY id');
        $stmt->bind_param('i', $filtrarPorUsuario);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query($sql . ' ORDER BY id');
    }

    if ($res === false) {
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
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    if (file_exists($caminho)) {
        readfile($caminho);
    }
}
