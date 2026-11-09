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
 * A senha é verificada com password_verify(), que aceita tanto hashes
 * modernos quanto o esquema legado md5 armazenado em schema.sql. A busca
 * por login é feita por prepared statement, evitando injeção de SQL.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if ($linha === null) {
        return null;
    }
    if (!password_verify($senha, $linha['senha'])) {
        return null;
    }
    unset($linha['senha']);
    return $linha;
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
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = '', ?int $clienteId = null, ?string $papel = null): array
{
    // Regra de visibilidade (manifest.md): um cliente so ve os chamados que
    // ele mesmo abriu. Um tecnico ve todos. Isso e aplicado como filtro de
    // ownership antes de qualquer consulta.
    $soCliente = ($papel === 'cliente' && $clienteId !== null);

    if ($busca !== '' && $soCliente) {
        $stmt = $db->prepare(
            'SELECT * FROM chamados WHERE usuario_id = ? AND titulo LIKE ? ORDER BY criado_em DESC'
        );
        $like = '%' . $busca . '%';
        $stmt->bind_param('is', $clienteId, $like);
        $stmt->execute();
        $res = $stmt->get_result();
    } elseif ($busca !== '') {
        $stmt = $db->prepare(
            'SELECT * FROM chamados WHERE titulo LIKE ? ORDER BY criado_em DESC'
        );
        $like = '%' . $busca . '%';
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
    } elseif ($soCliente) {
        $stmt = $db->prepare(
            'SELECT * FROM chamados WHERE usuario_id = ? ORDER BY criado_em DESC'
        );
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query('SELECT * FROM chamados ORDER BY criado_em DESC');
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
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
 */
function exportarCsv(mysqli $db): void
{
    $caminho = EXPORT_DIR . '/chamados.csv';
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $stmt = $db->prepare('SELECT * FROM chamados ORDER BY id');
    $stmt->execute();
    $res = $stmt->get_result();
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

    // Envia os cabecalhos ANTES de ler o arquivo, para que o navegador
    // trate a saida como download e nao exiba o CSV cru no proprio corpo.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    header('Content-Length: ' . filesize($caminho));
    readfile($caminho);
}
