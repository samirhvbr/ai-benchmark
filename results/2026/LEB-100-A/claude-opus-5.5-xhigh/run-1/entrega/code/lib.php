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
 * Aceita o hash md5 legado e o formato de password_hash(); no primeiro login
 * bem-sucedido com md5 o hash é regravado no formato novo (ver atualizarHashSenha).
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

    $hash = (string) $linha['senha'];
    unset($linha['senha']);
    $legado = strlen($hash) === 32 && ctype_xdigit($hash);
    $ok = $legado
        ? hash_equals(strtolower($hash), md5($senha))
        : password_verify($senha, $hash);
    if (!$ok) {
        return null;
    }
    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        atualizarHashSenha($db, (int) $linha['id'], $senha);
    }
    return $linha;
}

/**
 * Regrava a senha com password_hash(). Só grava se a coluna usuarios.senha já
 * comporta o hash novo: na coluna legada CHAR(32) o valor seria truncado (ou o
 * UPDATE falharia) e o usuário perderia o acesso. Uma falha aqui nunca impede o login.
 */
function atualizarHashSenha(mysqli $db, int $id, string $senha): void
{
    $novo = password_hash($senha, PASSWORD_DEFAULT);
    try {
        $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $coluna = $res ? $res->fetch_row() : null;
        if (!is_string($novo) || !$coluna || (int) $coluna[0] < strlen($novo)) {
            return;
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $novo, $id);
            $stmt->execute();
            $stmt->close();
        }
    } catch (mysqli_sql_exception $e) {
        error_log('painel: falha ao migrar hash de senha do usuario ' . $id . ': ' . $e->getMessage());
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
    } else if ($status == 3) {
        return 'Resolvido';
    } else {
        // valor fora do domínio (1..3) não pode aparecer como "Resolvido"
        return 'Desconhecido';
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
 * Busca o nome de um técnico pelo id.
 * Mantida para outros scripts; listagem e export usam o JOIN de consultarChamados().
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
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só os
 * que ele mesmo abriu. Devolve o usuario_id pelo qual filtrar, ou null (sem filtro).
 * Qualquer papel diferente de 'tecnico' é tratado como cliente (falha fechada).
 */
function donoVisivel(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Consulta de chamados com o nome do técnico (um LEFT JOIN em vez de uma consulta
 * por linha). $donoId restringe aos chamados abertos por esse usuário; $busca filtra
 * o título como texto literal (sem curingas do LIKE).
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId, string $ordem): array
{
    $sql = 'SELECT c.*, t.nome AS tecnico_nome FROM chamados c'
         . ' LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($busca !== '') {
        $filtros[] = "c.titulo LIKE ? ESCAPE '!'";
        $tipos .= 's';
        $params[] = '%' . strtr($busca, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= $ordem === 'id' ? ' ORDER BY c.id' : ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        // o prepared statement devolve INT como int; os consumidores sempre receberam
        // string (query() sem prepared) — mantém o formato de retorno
        foreach ($c as $k => $v) {
            if ($v !== null) {
                $c[$k] = (string) $v;
            }
        }
        $c['tecnico_nome'] = $c['tecnico_nome'] ?? '-';
        $chamados[] = $c;
    }
    $stmt->close();
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Não aplica a regra de visibilidade: para a tela, use listarChamadosVisiveis().
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'criado_em');
}

/**
 * Como listarChamados(), restrito ao que o usuário logado pode ver.
 */
function listarChamadosVisiveis(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarChamados($db, $busca, donoVisivel($uid, $papel), 'criado_em');
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Não aplica a regra de visibilidade: para a tela, use verChamadoVisivel().
 */
function verChamado(mysqli $db, int $id): ?array
{
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
    return $res->fetch_assoc() ?: null;
}

/**
 * Como verChamado(), mas devolve null também quando o usuário não pode ver o
 * chamado — mesma resposta de "não existe", para não revelar ids de terceiros.
 */
function verChamadoVisivel(mysqli $db, int $id, int $uid, string $papel): ?array
{
    $c = verChamado($db, $id);
    $dono = donoVisivel($uid, $papel);
    if ($c === null || ($dono !== null && (int) $c['usuario_id'] !== $dono)) {
        return null;
    }
    return $c;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido, devolve 0.0.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(minutos_resposta), COUNT(minutos_resposta) FROM chamados');
    [$soma, $qtd] = $res->fetch_row();
    $qtd = (int) $qtd;
    return $qtd > 0 ? (int) $soma / $qtd : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Não aplica a regra de visibilidade: para a tela, use exportarCsvVisivel().
 */
function exportarCsv(mysqli $db): void
{
    escreverCsvChamados($db, null);
}

/**
 * Como exportarCsv(), restrito ao que o usuário logado pode ver.
 */
function exportarCsvVisivel(mysqli $db, int $uid, string $papel): void
{
    escreverCsvChamados($db, donoVisivel($uid, $papel));
}

/**
 * Escreve o CSV direto na saída. Não passa mais por um arquivo fixo em EXPORT_DIR:
 * ele ficava legível no disco após o download e era sobrescrito por requisições
 * concorrentes (um usuário podia receber o arquivo gerado para outro).
 */
function escreverCsvChamados(mysqli $db, ?int $donoId): void
{
    // consulta antes de qualquer header/saída: se falhar, nada sai como "CSV"
    $chamados = consultarChamados($db, '', $donoId, 'id');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    // separador/aspas/escape explícitos = os padrões de sempre (saída idêntica);
    // omitir $escape gera E_DEPRECATED no PHP 8.4, que sairia no meio do CSV
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    foreach ($chamados as $c) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
}
