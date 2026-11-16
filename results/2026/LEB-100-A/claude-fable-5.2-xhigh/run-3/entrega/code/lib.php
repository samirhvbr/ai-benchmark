<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * listarChamados(), verChamado() e exportarCsv() não recebem o usuário: devolvem todos
 * os chamados, como os scripts internos esperam. Código que atende um usuário logado
 * deve usar as variantes *Visivel*, que aplicam a regra de visibilidade.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * A senha guardada pode estar em password_hash() ou no md5 simples herdado de 2013.
 * O md5 continua aceito para ninguém perder o acesso e é substituído no primeiro
 * login correto (ver regravarSenha).
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();

    $guardada = $linha ? (string) $linha['senha'] : '';
    $legada = (bool) preg_match('/\A[0-9a-f]{32}\z/i', $guardada);
    if ($legada) {
        $confere = hash_equals(strtolower($guardada), md5($senha));
    } else {
        $confere = $linha && password_verify($senha, $guardada);
    }
    if (!$confere) {
        if (!$linha || $legada) {
            // Nenhum hash lento rodou neste caminho: gasta o tempo de um, para a demora
            // da resposta não revelar se o login existe.
            password_hash('', PASSWORD_DEFAULT);
        }
        return null;
    }

    if ($legada || password_needs_rehash($guardada, PASSWORD_DEFAULT)) {
        regravarSenha($db, (int) $linha['id'], $guardada, $senha);
    }
    unset($linha['senha']);
    return $linha;
}

/**
 * Regrava a senha de um usuário com password_hash(), logo após um login correto.
 *
 * É melhor esforço e nunca impede o login. Só grava se a coluna usuarios.senha comportar
 * o hash: no schema original ela é CHAR(32), e um hash cortado trancaria o usuário para
 * fora. Enquanto a coluna não for alargada (ver schema.sql), o md5 legado continua valendo.
 */
function regravarSenha(mysqli $db, int $id, string $guardada, string $senha): void
{
    try {
        $res = $db->query('SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $coluna = $res ? $res->fetch_assoc() : null;
        $tamanho = $coluna ? (int) $coluna['tamanho'] : 0;
        if ($tamanho < 60) {
            return;
        }
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || strlen($novo) > $tamanho) {
            return;
        }
        // "AND senha = ?": só substitui o hash que acabou de ser conferido.
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($stmt) {
            $stmt->bind_param('sis', $novo, $id, $guardada);
            $stmt->execute();
        }
    } catch (Throwable $e) {
        error_log('painel: senha do usuario ' . $id . ' nao regravada: ' . $e->getMessage());
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
    } else {
        return 'Resolvido';
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
    if ($prioridade == 4) {
        return 'CRITICO - SLA estourado';
    }
    return 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id.
 * A listagem e o export já trazem o nome por JOIN; a função fica para os scripts
 * internos que ainda a chamam.
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
 * Regra de visibilidade: técnico vê qualquer chamado; cliente só os que ele mesmo abriu.
 * Devolve o usuario_id pelo qual filtrar, ou null quando o usuário pode ver todos.
 * Papel desconhecido é tratado como cliente (na dúvida, restringe).
 */
function donoVisivel(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Executa um SELECT com os valores ligados como parâmetros (nunca concatenados no SQL).
 */
function executarConsulta(mysqli $db, string $sql, string $tipos = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar consulta: ' . $db->error);
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar consulta: ' . $stmt->error);
    }
    $res = $stmt->get_result();
    if ($res === false) {
        throw new RuntimeException('Falha ao ler resultado: ' . $stmt->error);
    }
    return $res;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosVisiveis($db, null, $busca);
}

/**
 * Como listarChamados(), restrito aos chamados abertos por $donoId (null = todos).
 */
function listarChamadosVisiveis(mysqli $db, ?int $donoId, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $res = executarConsulta($db, $sql, $tipos, $params);

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        // Consulta preparada devolve INT como int; mysqli::query() devolvia tudo como
        // string. Quem consome a lista recebe estas linhas, então o formato fica o de antes.
        foreach ($c as $coluna => $valor) {
            if ($valor !== null) {
                $c[$coluna] = (string) $valor;
            }
        }
        $chamados[] = $c;
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
 * Como verChamado(), mas só devolve o chamado se ele foi aberto por $donoId (null = qualquer).
 * Chamado de outro dono e chamado inexistente dão o mesmo null, para não confirmar ids.
 */
function verChamadoVisivel(mysqli $db, int $id, ?int $donoId): ?array
{
    $c = verChamado($db, $id);
    if ($c !== null && $donoId !== null && (int) $c['usuario_id'] !== $donoId) {
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
    // SUM/COUNT ignoram NULL; a divisão continua no PHP para o valor ser o mesmo de antes.
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvVisivel($db, null);
}

/**
 * Como exportarCsv(), restrito aos chamados abertos por $donoId (null = todos).
 * Escreve direto na saída, sem arquivo intermediário em disco.
 */
function exportarCsvVisivel(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($donoId !== null) {
        $res = executarConsulta($db, $sql . ' WHERE c.usuario_id = ? ORDER BY c.id', 'i', [$donoId]);
    } else {
        $res = executarConsulta($db, $sql . ' ORDER BY c.id');
    }

    // A consulta vem antes dos cabeçalhos: se ela falhar, nada de CSV pela metade.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    // Separador, aspas e escape explícitos: são os valores padrão de sempre, mas o PHP 8.4
    // emite aviso de depreciação (dentro do CSV, se exibido) quando o escape é omitido.
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
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
