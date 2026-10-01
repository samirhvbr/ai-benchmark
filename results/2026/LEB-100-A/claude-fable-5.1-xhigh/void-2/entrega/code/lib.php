<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Convenção: as funções públicas do manifesto (autenticar, formatarStatus,
 * rotuloPrioridade, listarChamados, verChamado, mediaResposta, exportarCsv)
 * mantêm assinatura e retorno. As variantes "*DoUsuario" aplicam a regra de
 * visibilidade do cliente (só vê o que ele mesmo abriu) e são usadas pelo
 * index.php. As demais funções são internas.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Aceita o hash legado (md5 hexadecimal, 32 chars) e o formato atual
 * (password_hash). Quem ainda está em md5 é migrado no primeiro login
 * bem-sucedido — ver atualizarHashSenha().
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

    $hash = (string) $linha['senha'];
    unset($linha['senha']);

    if (senhaEhMd5Legado($hash)) {
        if (!hash_equals(strtolower($hash), md5($senha))) {
            return null;
        }
        atualizarHashSenha($db, (int) $linha['id'], $senha);
        return $linha;
    }

    if (!password_verify($senha, $hash)) {
        return null;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        atualizarHashSenha($db, (int) $linha['id'], $senha);
    }
    return $linha;
}

/**
 * Interno: true se o valor armazenado é um md5 hexadecimal legado.
 */
function senhaEhMd5Legado(string $hash): bool
{
    return strlen($hash) === 32 && ctype_xdigit($hash);
}

/**
 * Interno: regrava a senha do usuário com password_hash(). Melhor esforço —
 * nunca impede o login. Só grava se a coluna `usuarios.senha` já comporta o
 * hash novo: em bancos ainda com CHAR(32) (antes do ALTER de schema.sql) o
 * hash seria truncado em modo não-estrito e trancaria o usuário para sempre.
 */
function atualizarHashSenha(mysqli $db, int $id, string $senha): void
{
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!colunaSenhaComporta($db, strlen($novo))) {
            return;
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('si', $novo, $id);
        $stmt->execute();
    } catch (\Throwable $e) {
        error_log('painel: nao foi possivel migrar o hash de senha do usuario ' . $id . ': ' . $e->getMessage());
    }
}

/**
 * Interno: verifica se `usuarios.senha` aceita um valor de $tamanho bytes.
 */
function colunaSenhaComporta(mysqli $db, int $tamanho): bool
{
    $res = $db->query("SHOW COLUMNS FROM usuarios LIKE 'senha'");
    $col = $res ? $res->fetch_assoc() : null;
    if (!$col) {
        return false;
    }
    $tipo = strtolower((string) $col['Type']);
    if (preg_match('/^(?:var)?char\((\d+)\)/', $tipo, $m)) {
        return (int) $m[1] >= $tamanho;
    }
    return (bool) preg_match('/text$/', $tipo); // tinytext/text/mediumtext/longtext
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
 * Busca o nome de um técnico pelo id.
 * Mantida para scripts internos; a listagem e o export passaram a resolver
 * o nome via JOIN (ver consultarChamados / emitirCsv).
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
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Como listarChamados(), mas restrita aos chamados abertos por $usuarioId
 * (regra de visibilidade do cliente — manifest.md).
 */
function listarChamadosDoUsuario(mysqli $db, int $usuarioId, string $busca = ''): array
{
    return consultarChamados($db, $busca, $usuarioId);
}

/**
 * Interno: consulta base da listagem. $donoId === null => sem filtro de dono.
 * A busca é um LIKE '%termo%' com o termo passado como parâmetro; os
 * curingas '%' e '_' continuam com o significado do LIKE (comportamento
 * histórico da rota ?busca=).
 */
function consultarChamados(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $cond = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $cond[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $cond[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($cond) {
        $sql .= ' WHERE ' . implode(' AND ', $cond);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    return array_map('linhaComoTexto', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

/**
 * Interno: preserva o tipo histórico das linhas. O código antigo usava
 * mysqli::query(), que devolve todo valor não-nulo como string; os prepared
 * statements devolvem tipos nativos (int/float). Consumidores externos
 * (relatório gerencial, exportação) podem comparar valores de forma estrita,
 * então reproduzimos o formato antigo: NULL continua NULL, o resto vira string.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $k => $v) {
        if ($v !== null && !is_array($v)) {
            $linha[$k] = (string) $v;
        }
    }
    return $linha;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    return carregarChamado($db, $id, null);
}

/**
 * Como verChamado(), mas só devolve o chamado se ele foi aberto por
 * $usuarioId; caso contrário null (indistinguível de "não existe").
 */
function verChamadoDoUsuario(mysqli $db, int $id, int $usuarioId): ?array
{
    return carregarChamado($db, $id, $usuarioId);
}

/**
 * Interno: SELECT de um chamado, com filtro de dono opcional.
 */
function carregarChamado(mysqli $db, int $id, ?int $donoId): ?array
{
    if ($donoId === null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        $stmt->bind_param('ii', $id, $donoId);
    }
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha ? linhaComoTexto($linha) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Devolve 0.0 quando nenhum chamado tem primeira resposta registrada.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd'
                    . ' FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res ? $res->fetch_assoc() : null;
    $qtd = $row ? (int) $row['qtd'] : 0;
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Também grava uma cópia completa em EXPORT_DIR/chamados.csv (legado).
 */
function exportarCsv(mysqli $db): void
{
    emitirCsv($db, null);
}

/**
 * Como exportarCsv(), mas só com os chamados abertos por $usuarioId.
 * Não grava a cópia em disco (ela é o export completo, de uso interno).
 */
function exportarCsvDoUsuario(mysqli $db, int $usuarioId): void
{
    emitirCsv($db, $usuarioId);
}

/**
 * Interno: monta o CSV em memória (php://temp), opcionalmente persiste a
 * cópia completa e envia ao cliente. Nenhum arquivo compartilhado é lido
 * de volta, então exports simultâneos não se corrompem.
 */
function emitirCsv(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';
    $stmt = $db->prepare($sql);
    if ($donoId !== null) {
        $stmt->bind_param('i', $donoId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    // $escape = "" fixa o comportamento RFC-4180 (sem escape por barra
    // invertida) e evita a mudança de default anunciada no PHP 8.4, mantendo
    // o CSV estável entre versões. Para os dados atuais a saída é idêntica.
    $fp = fopen('php://temp', 'w+');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '');
    }

    if ($donoId === null) {
        persistirCsv($fp, EXPORT_DIR . '/chamados.csv');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    rewind($fp);
    fpassthru($fp);
    fclose($fp);
}

/**
 * Interno: grava o conteúdo de $fp em $destino de forma atômica (arquivo
 * temporário no mesmo diretório + rename). Falhas só vão para o log: o
 * download do usuário não depende do disco.
 */
function persistirCsv($fp, string $destino): void
{
    $dir = dirname($destino);
    $tmp = @tempnam($dir, 'chamados-');
    if ($tmp === false || dirname($tmp) !== $dir) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        error_log('painel: EXPORT_DIR inacessivel para gravar ' . $destino);
        return;
    }
    $out = @fopen($tmp, 'w');
    if ($out === false) {
        @unlink($tmp);
        error_log('painel: nao foi possivel escrever ' . $tmp);
        return;
    }
    rewind($fp);
    stream_copy_to_stream($fp, $out);
    fclose($out);
    @chmod($tmp, 0666 & ~umask()); // mesmas permissões que fopen('w') dava
    if (!@rename($tmp, $destino)) {
        @unlink($tmp);
        error_log('painel: nao foi possivel publicar ' . $destino);
    }
}
