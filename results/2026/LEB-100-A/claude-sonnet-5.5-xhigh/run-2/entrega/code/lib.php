<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Visibilidade dos chamados: listarChamados(), verChamado() e exportarCsv() mantêm a
 * assinatura pública e continuam devolvendo TODOS os chamados (é o que as rotinas internas
 * precisam; elas não têm usuário logado). A web (index.php) usa as variantes *Visiveis(),
 * que aplicam a regra "cliente só vê o que abriu; técnico vê tudo".
 */

// ---------------------------------------------------------------------------
// Infra de acesso a dados
// ---------------------------------------------------------------------------

/**
 * Executa um SELECT com parâmetros vinculados e devolve o resultado já em memória.
 * $tipos segue o formato de mysqli_stmt::bind_param ('s', 'i', ...).
 */
function selecionarPreparado(mysqli $db, string $sql, string $tipos = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar a consulta: ' . $db->error);
    }
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao executar a consulta: ' . $erro);
    }
    $res = $stmt->get_result();
    $stmt->close();
    if ($res === false) {
        throw new RuntimeException('A consulta nao devolveu resultado.');
    }
    return $res;
}

/**
 * O legado lia com $db->query() (protocolo texto): toda coluna chegava como string (ou null).
 * Prepared statements devolvem int nativo; voltamos ao formato antigo para não alterar
 * o retorno que os consumidores externos já conhecem.
 */
function linhaComoTexto(array $linha): array
{
    foreach ($linha as $coluna => $valor) {
        if ($valor !== null) {
            $linha[$coluna] = (string) $valor;
        }
    }
    return $linha;
}

// ---------------------------------------------------------------------------
// Autenticação
// ---------------------------------------------------------------------------

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Senhas novas/atualizadas ficam em password_hash(). Hashes MD5 legados (32 hex) ainda são
 * aceitos e, no primeiro login bem-sucedido, são regravados com password_hash() — desde que a
 * coluna usuarios.senha já comporte o novo hash (ver schema.sql); senão o MD5 é mantido.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $linha = selecionarPreparado(
        $db,
        'SELECT id, nome, papel, senha FROM usuarios WHERE login = ?',
        's',
        [$usuario]
    )->fetch_assoc();
    if (!$linha) {
        return null;
    }

    $guardado = (string) $linha['senha'];
    $legado = (bool) preg_match('/^[0-9a-fA-F]{32}$/', $guardado);
    if ($legado) {
        $ok = hash_equals(strtolower($guardado), md5($senha));
    } else {
        $ok = password_verify($senha, $guardado);
    }
    if (!$ok) {
        return null;
    }

    if ($legado || password_needs_rehash($guardado, PASSWORD_DEFAULT)) {
        atualizarHashSenha($db, (int) $linha['id'], $senha, $guardado);
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Regrava o hash da senha de um usuário que acabou de se autenticar.
 * Nunca impede o login: qualquer falha é só registrada no log.
 */
function atualizarHashSenha(mysqli $db, int $id, string $senha, string $hashAtual): void
{
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        // Com a coluna ainda em CHAR(32), o hash novo seria rejeitado (modo estrito) ou, pior,
        // TRUNCADO em silêncio (sql_mode permissivo), trancando o usuário para fora.
        $col = selecionarPreparado(
            $db,
            "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        )->fetch_assoc();
        if (!$col || $col['tamanho'] === null || (int) $col['tamanho'] < strlen($novo)) {
            return;
        }
        // "AND senha = ?": só troca se ninguém alterou a senha nesse meio-tempo.
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $stmt->bind_param('sis', $novo, $id, $hashAtual);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('autenticar: hash da senha nao atualizado (usuario ' . $id . '): ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Rótulos
// ---------------------------------------------------------------------------

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
    $limiteSlaMinutos = 30;

    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos <= $limiteSlaMinutos) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id.
 * Mantida por compatibilidade: listagem e export agora trazem o nome via JOIN (sem 1 consulta por linha).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $t = selecionarPreparado($db, 'SELECT nome FROM usuarios WHERE id = ?', 'i', [$tecnicoId])->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

// ---------------------------------------------------------------------------
// Visibilidade (regra de negócio)
// ---------------------------------------------------------------------------

/**
 * Escopo de visibilidade de um usuário: null = vê todos os chamados (técnico);
 * caso contrário, o id do usuário cujos chamados ele pode ver (só os que ele abriu).
 * Falha fechada: qualquer papel que não seja 'tecnico' é tratado como cliente.
 */
function escopoVisibilidade(string $papel, int $uid): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Lista os chamados visíveis ao escopo $donoId (null = todos), opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome' ('-' quando não há técnico).
 */
function listarChamadosVisiveis(mysqli $db, ?int $donoId, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    $condicoes = [];
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($donoId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC, c.id DESC';

    $res = selecionarPreparado($db, $sql, $tipos, $params);
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = linhaComoTexto($c);
    }
    return $chamados;
}

/**
 * Lista TODOS os chamados, opcionalmente filtrando pelo título (uso interno; sem filtro de visibilidade).
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosVisiveis($db, null, $busca);
}

/**
 * Carrega um chamado pelo id, se ele for visível ao escopo $donoId (null = qualquer).
 */
function verChamadoVisivel(mysqli $db, ?int $donoId, int $id): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $params = [$id];
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    $linha = selecionarPreparado($db, $sql, $tipos, $params)->fetch_assoc();
    return $linha ? linhaComoTexto($linha) : null;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe (uso interno; sem filtro de visibilidade).
 */
function verChamado(mysqli $db, int $id): ?array
{
    return verChamadoVisivel($db, null, $id);
}

// ---------------------------------------------------------------------------
// Indicadores e exportação
// ---------------------------------------------------------------------------

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Devolve 0.0 quando nenhum chamado tem resposta registrada.
 */
function mediaResposta(mysqli $db): float
{
    $linha = selecionarPreparado(
        $db,
        'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados'
    )->fetch_assoc();
    $qtd = (int) $linha['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (float) $linha['soma'] / $qtd;
}

/**
 * Neutraliza "injeção de fórmula" em planilhas: células de texto que começam com = + - @ (ou TAB/CR)
 * seriam executadas como fórmula pelo Excel/LibreOffice. O apóstrofo inicial força texto.
 */
function csvNeutralizarFormula(string $valor): string
{
    if ($valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Exporta os chamados visíveis ao escopo $donoId (null = todos) em CSV, direto para a saída.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsvVisivel(mysqli $db, ?int $donoId): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, t.nome AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $params[] = $donoId;
    }
    $sql .= ' ORDER BY c.id';
    // Consulta antes de emitir qualquer byte: se falhar, não sai um CSV "200 OK" pela metade.
    $res = selecionarPreparado($db, $sql, $tipos, $params);

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }
    // Sem arquivo em disco (o antigo EXPORT_DIR/chamados.csv era compartilhado entre todas as
    // requisições). Escape '' = CSV padrão RFC 4180 (aspas duplicadas), e evita o aviso de
    // depreciação do PHP 8.4 para fputcsv() sem $escape.
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            csvNeutralizarFormula((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] === null ? '-' : csvNeutralizarFormula((string) $c['tecnico_nome']),
            $c['criado_em'],
        ], ',', '"', '');
    }
    fclose($fp);
}

/**
 * Exporta TODOS os chamados para CSV e devolve o arquivo ao navegador (uso interno; sem filtro de visibilidade).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvVisivel($db, null);
}
