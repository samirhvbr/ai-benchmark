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
    $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Regra de visibilidade (manifest.md): cliente só vê os chamados que abriu;
 * técnico vê todos. Retorna o id do dono a filtrar, ou null quando não há restrição.
 * Papel desconhecido cai no caso restrito.
 */
function donoVisivel(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Lista todos os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Consulta chamados pelo título e, se $usuarioId não for null, só os desse dono.
 * $busca vai como parâmetro preparado, nunca como texto do SQL.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
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
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
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
 * Sem nenhum chamado respondido, devolve 0.0.
 */
function mediaResposta(mysqli $db): float
{
    [$soma, $qtd] = $db->query('SELECT SUM(minutos_resposta), COUNT(minutos_resposta) FROM chamados')->fetch_row();
    return (int) $qtd > 0 ? (float) $soma / (int) $qtd : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

/**
 * Escreve na saída o CSV dos chamados visíveis a $usuarioId (null = todos).
 * Não grava cópia em disco: um arquivo fixo em EXPORT_DIR ficaria sem controle de
 * acesso e seria sobrescrito por exportações concorrentes.
 */
function escreverCsv(mysqli $db, ?int $usuarioId): void
{
    $chamados = consultarChamados($db, '', $usuarioId);
    usort($chamados, function ($a, $b) {
        return $a['id'] <=> $b['id'];
    });

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    foreach ($chamados as $c) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
