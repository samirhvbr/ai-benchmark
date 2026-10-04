<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Funções auxiliares internas levam o prefixo "painel" para não colidir com nomes de
 * scripts externos que incluam este arquivo.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Senhas novas ficam em password_hash(). Contas antigas ainda têm md5 (32 hex): são
 * aceitas e regravadas com password_hash() no primeiro login bem-sucedido, assim que a
 * coluna usuarios.senha comportar o hash novo (VARCHAR(255) — ver schema.sql).
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) {
        painelGastarTempoDeHash($senha);
        return null;
    }

    $guardada = (string) $linha['senha'];
    if (preg_match('/^[0-9a-f]{32}$/i', $guardada)) {
        // legado: md5 sem sal
        if (!hash_equals(strtolower($guardada), md5($senha))) {
            painelGastarTempoDeHash($senha);
            return null;
        }
        $regravar = true;
    } elseif (strpos($senha, "\0") === false && password_verify($senha, $guardada)) {
        // (NUL fora: o bcrypt do PHP pararia de ler a senha nele)
        $regravar = password_needs_rehash($guardada, PASSWORD_DEFAULT);
    } else {
        if (strpos($senha, "\0") !== false || !password_get_info($guardada)['algo']) {
            painelGastarTempoDeHash($senha); // o password_verify não gastou tempo de hash (NUL, ou conta com hash irreconhecível, ex.: '!')
        }
        return null;
    }
    if ($regravar) {
        painelRegravarSenha($db, (int) $linha['id'], $guardada, $senha);
    }

    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Falha de login: gasta o tempo de um hash bcrypt. Sem isso, "login inexistente" e "conta
 * ainda em md5" responderiam em milissegundos e "senha errada em conta já migrada" em
 * ~0,3 s, e o tempo revelaria quais logins existem.
 */
function painelGastarTempoDeHash(string $senha): void
{
    try {
        password_hash(str_replace("\0", '', $senha), PASSWORD_DEFAULT); // sem NUL: o PHP 8.4 recusaria (ValueError)
    } catch (Throwable $e) {
        // nada a igualar
    }
}

/**
 * Regrava a senha com password_hash(). Nunca impede o login: qualquer falha é só
 * registrada no log. Só grava se a coluna comportar o hash novo — num sql_mode não
 * estrito o MySQL truncaria o hash em silêncio e o usuário ficaria trancado para fora.
 */
function painelRegravarSenha(mysqli $db, int $id, string $hashAntigo, string $senha): void
{
    if (strlen($senha) > 72 || strpos($senha, "\0") !== false) {
        return; // o bcrypt só enxerga os 72 primeiros bytes (e para no NUL): migrar enfraqueceria a conta; fica em md5 até a troca de senha
    }
    try {
        $largura = painelLarguraColunaSenha($db);
        if ($largura < 60) {
            return; // coluna ainda CHAR(32): nem calcula o hash (um bcrypt tem 60 caracteres)
        }
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || strlen($novo) > $largura) {
            return;
        }
        $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $upd->bind_param('sis', $novo, $id, $hashAntigo);
        $upd->execute();
        $upd->close();
    } catch (Throwable $e) {
        error_log('autenticar: nao foi possivel regravar a senha do usuario ' . $id . ': ' . $e->getMessage());
    }
}

/**
 * Largura (em caracteres) da coluna usuarios.senha; 0 se não for possível descobrir.
 */
function painelLarguraColunaSenha(mysqli $db): int
{
    $res = $db->query(
        'SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS'
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
    );
    $linha = $res ? $res->fetch_assoc() : null;
    return $linha ? (int) $linha['n'] : 0;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    $rotulos = [1 => 'Aberto', 2 => 'Em atendimento', 3 => 'Resolvido'];
    // Fora de 1..3 o legado sempre devolveu 'Resolvido'; mantido (o relatório gerencial casa por texto).
    return $rotulos[$status] ?? 'Resolvido';
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
    return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id.
 * As listagens e o export agora trazem o nome por JOIN (uma query só); esta função
 * fica por compatibilidade. O id é int tipado, então a concatenação não é injetável.
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
 * Consulta de chamados já com o nome do técnico, numa única query (JOIN).
 * $donoId = null -> todos os chamados; $donoId = <id> -> só os abertos por esse usuário.
 * Os valores voltam como string (ou null), exatamente como o query() legado devolvia numa
 * conexão padrão: consumidores externos podem comparar com ===.
 */
function painelConsultarChamados(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $valores = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $valores[] = $donoId;
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $valores[] = '%' . $busca . '%';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($valores) {
        $stmt->bind_param($tipos, ...$valores);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        foreach ($c as $campo => $valor) {
            if (is_int($valor)) {
                $c[$campo] = (string) $valor;
            }
        }
        $chamados[] = $c;
    }
    $stmt->close();
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return painelConsultarChamados($db, $busca, null);
}

/**
 * Como listarChamados(), mas só com os chamados abertos por $usuarioId
 * (visão do cliente — ver podeVerChamado()).
 */
function listarChamadosDoUsuario(mysqli $db, int $usuarioId, string $busca = ''): array
{
    return painelConsultarChamados($db, $busca, $usuarioId);
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
 * Regra de visibilidade (manifest.md), num só lugar: o técnico vê qualquer chamado; qualquer
 * outro papel é tratado como cliente e só vê os que abriu (fechado por padrão).
 */
function veTodosOsChamados(string $papel): bool
{
    return $papel === 'tecnico';
}

/**
 * Esse usuário pode ver esse chamado? $chamado é o array de verChamado()/listarChamados().
 */
function podeVerChamado(array $chamado, int $usuarioId, string $papel): bool
{
    return veTodosOsChamados($papel) || (int) $chamado['usuario_id'] === $usuarioId;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhum chamado respondido ainda, devolve 0.0 (antes: divisão por zero).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COALESCE(SUM(minutos_resposta), 0) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
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
 * O CSV é escrito direto na saída (sem arquivo temporário em disco) e, para os scripts
 * internos que a chamam, os bytes são os mesmos de sempre.
 */
function exportarCsv(mysqli $db): void
{
    painelEscreverCsv($db, null, false);
}

/**
 * Download pela web (index.php?export=csv): como exportarCsv(), mas feito para ser aberto em
 * planilha por uma pessoa — neutraliza "injeção de fórmula" no título e usa o escape RFC 4180
 * — e, com $usuarioId, só com os chamados abertos por esse usuário (visão do cliente).
 */
function exportarCsvWeb(mysqli $db, ?int $usuarioId = null): void
{
    painelEscreverCsv($db, $usuarioId, true);
}

/**
 * Núcleo do export: consulta ANTES de emitir qualquer byte (se falhar, não sai CSV pela
 * metade) e transmite as linhas em ordem de id. Os campos continuam passando por fputcsv
 * (mesmas aspas do formato legado).
 */
function painelEscreverCsv(mysqli $db, ?int $donoId, bool $paraPlanilha): void
{
    $sql = "SELECT c.id, c.titulo, c.status, COALESCE(t.nome, '-') AS tecnico_nome, c.criado_em"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($stmt !== false && $donoId !== null) {
        $stmt->bind_param('i', $donoId);
    }
    $res = ($stmt !== false && $stmt->execute()) ? $stmt->get_result() : false;
    if ($res === false) {
        // Só chega aqui com MYSQLI_REPORT_OFF (padrão do PHP < 8.1); em modo exceção o erro já subiu.
        // Como no original, não sai CSV pela metade — agora com HTTP 500 e o motivo no log.
        error_log('exportarCsv: consulta falhou: ' . $db->error);
        http_response_code(500);
        return;
    }

    // O escape padrão do fputcsv ('\') não dobra uma aspa que vem depois de barra invertida e
    // deixa o título "abrir" células novas; '' (RFC 4180) só existe no PHP >= 7.4. exportarCsv()
    // mantém o '\' do original, explícito porque o PHP 8.4 avisa quando o argumento falta.
    $escape = ($paraPlanilha && PHP_VERSION_ID >= 70400) ? '' : '\\';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', $escape);
    while ($c = $res->fetch_assoc()) {
        $titulo = (string) $c['titulo'];
        fputcsv($saida, [
            $c['id'],
            $paraPlanilha ? painelNeutralizarFormula($titulo) : $titulo,
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', $escape);
    }
    fclose($saida);
    $stmt->close();
}

/**
 * Impede "injeção de fórmula": planilhas tratam célula iniciada por = + - @ (ou TAB/CR)
 * como fórmula. O título é digitado pelo cliente, então ganha um apóstrofo na frente.
 */
function painelNeutralizarFormula(string $valor): string
{
    return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
}
