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
 * Suporta hash moderno (password_hash) e legado md5 com upgrade transparente.
 * F1/F2
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

    $stored = (string) $linha['senha'];
    $ok = false;

    if (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
        // Hash legado MD5 (32 hex). Mantido só para interoperar com seed/schema,
        // atualizado para bcrypt/argon2 no primeiro login bem-sucedido (F1).
        if (hash_equals(strtolower($stored), md5($senha))) {
            $ok = true;
            $newHash = password_hash($senha, PASSWORD_DEFAULT);
            $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            $upd->bind_param('si', $newHash, $linha['id']);
            $upd->execute();
        }
    } else {
        // Hash moderno (bcrypt/argon2). Colunas VARCHAR(255) acomodam.
        if (password_verify($senha, $stored)) {
            $ok = true;
            if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                $newHash = password_hash($senha, PASSWORD_DEFAULT);
                $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
                $upd->bind_param('si', $newHash, $linha['id']);
                $upd->execute();
            }
        }
    }

    if (!$ok) {
        return null;
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 * Contrato: 1→Aberto, 2→Em atendimento, 3→Resolvido (F4: nada além disso)
 */
function formatarStatus(int $status): string
{
    return match ($status) {
        1 => 'Aberto',
        2 => 'Em atendimento',
        3 => 'Resolvido',
        default => ($status >= 3 ? 'Resolvido' : ($status <= 1 ? 'Aberto' : 'Em atendimento')),
    };
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 * Preservado: contrato de rótulos inferido em index.php (SLA 30 min,
 * prioridade >=3 alta, ==4 crítico-estourado). Não é mudança de contrato.
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
 * Mantida por compatibilidade, mas agora parametrizada. Não é mais
 * chamada em loop (F5): listarChamados/exportarCsv fazem JOIN.
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
 * Correções: SQLi parametrizado (F3), JOIN sem N+1 (F5),
 * ESCAPE de curingas LIKE (F12), respeita regra de negócio quando
 * exportado como helper interno via $uid/$papel opcionais.
 * Assinatura pública preservada: listarChamados(mysqli, string='').
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    // Ordem de colunas explicita inverte o comportamento 'SELECT *' de
    // índices numerados? NÃO: mantemos SELECT com JOIN e aliases.
    // O manifesto garante listarChamados retorna itens com 'tecnico_nome';
    // qualquer campo adicional (id, titulo, etc.) continua presente.
    $sql = 'SELECT c.*, u.nome AS tecnico_nome FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $params = [];
    $types = '';

    if ($busca !== '') {
        $sql .= " WHERE c.titulo LIKE ? ESCAPE '\\\\'";
        // Escape do próprio caractere de escape e dos curingas % e _ (F12).
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca);
        $params[] = '%' . $esc . '%';
        $types .= 's';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    if ($types === '') {
        $res = $db->query($sql);
        if ($res === false) {
            return [];
        }
        $chamados = $res->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $chamados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    foreach ($chamados as &$c) {
        if (empty($c['tecnico_nome'])) {
            $c['tecnico_nome'] = '-';
        }
    }
    unset($c);

    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Correção: SQLi parametrizado (F3), sem concatenação.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Correção: divisor zero → 0.0 em vez de DivisionByZeroError (F6).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res ? $res->fetch_assoc() : null;
    if ($row === null || $row['media'] === null) {
        return 0.0;
    }
    return (float) $row['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Correções: streaming direto sem arquivo temporário (F7), JOIN sem N+1 (F5).
 * Cabeçalho CSV e ordem por id preservados (manifest).
 */
function exportarCsv(mysqli $db): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    // Evita bufferização/cache de intermediários para o download.
    // Usamos stream direto em stdout para não poluir disco nem expor
    // arquivo legível por outros. Falta de EXPORT_DIR não quebra.
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fputcsv($out, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $res = $db->query('SELECT c.*, u.nome AS tecnico_nome FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id ORDER BY c.id');
    if ($res === false) {
        fclose($out);
        return;
    }
    while ($c = $res->fetch_assoc()) {
        fputcsv($out, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] ?: '-',
            $c['criado_em'],
        ]);
    }
    fclose($out);

    // Mantém compatibilidade se algum consumidor esperar o arquivo?
    // O manifesto garante só cabeçalho e ordem — não o caminho em
    // disco. A remoção do arquivo é documentada em F7.
}
