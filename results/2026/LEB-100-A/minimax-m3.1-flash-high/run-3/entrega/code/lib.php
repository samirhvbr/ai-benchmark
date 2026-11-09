<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Evolução aplicada (detalhamento em RELATORIO.md):
 *  - consultas parametrizadas: a busca por título era concatenada no SQL;
 *  - regra de visibilidade cliente/técnico aplicada sem alterar as assinaturas
 *    públicas (o usuário em contexto é informado por definirVisor());
 *  - senha: o hash legado md5 continua aceito, mas novos hashes usam
 *    password_hash()/password_verify() e o md5 é reescrito quando a coluna
 *    aceita o hash mais longo;
 *  - nome do técnico resolvido em uma consulta por listagem (elimina N+1);
 *  - média de resposta calculada no banco (elimina divisão por zero);
 *  - CSV gerado em arquivo temporário único e removido depois do envio.
 */

/* ---------------------------------------------------------------------------
 * Contexto de visibilidade (usuário logado)
 *
 * O manifesto fixa as assinaturas de listarChamados(), verChamado() e
 * exportarCsv(); não há como acrescentar um parâmetro de usuário sem alterar a
 * superfície pública. Por isso o usuário em contexto é informado fora da
 * assinatura e vale apenas enquanto houver sessão (painel web).
 *
 * Sem contexto definido — rotina noturna, relatório gerencial — vale a
 * regra histórica: sem filtro de dono.
 * ------------------------------------------------------------------------- */

/**
 * Define o usuário em contexto (chamado por index.php após o login).
 */
function definirVisor(?int $usuarioId, ?string $papel): void
{
    if ($usuarioId === null) {
        $GLOBALS['__leb_visor'] = null;
        return;
    }
    $GLOBALS['__leb_visor'] = ['id' => $usuarioId, 'papel' => (string) $papel];
}

/**
 * Usuário em contexto ou null (quando não há sessão).
 */
function visorAtual(): ?array
{
    return isset($GLOBALS['__leb_visor']) && is_array($GLOBALS['__leb_visor'])
        ? $GLOBALS['__leb_visor']
        : null;
}

/**
 * Verdadeiro quando o usuário em contexto só pode ver os próprios chamados.
 * Papéis desconhecidos são tratados como cliente (falha fechada).
 */
function ehVisorCliente(): bool
{
    $visor = visorAtual();
    if ($visor === null) {
        return false;
    }
    return $visor['papel'] !== 'tecnico';
}

/**
 * Id do usuário em contexto (null se não houver sessão).
 */
function visorUsuarioId(): ?int
{
    $visor = visorAtual();
    return $visor === null ? null : (int) $visor['id'];
}

/* ---------------------------------------------------------------------------
 * Autenticação
 * ------------------------------------------------------------------------- */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * O hash legado (md5) continua válido — o banco ainda guarda md5 para os
 * usuários existentes —, mas a verificação passa a suportar hash forte
 * (password_hash) e a comparação é feita em tempo constante.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    if ($usuario === '' || $senha === '') {
        return null;
    }
    try {
        $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $usuario);
        $stmt->execute();
        $res = $stmt->get_result();
        $linha = $res ? $res->fetch_assoc() : null;
    } catch (Throwable $e) {
        error_log('autenticar: ' . $e->getMessage());
        return null;
    }
    if (!$linha) {
        return null;
    }
    if (!senhaConfere($senha, (string) $linha['senha'])) {
        return null;
    }
    // Reescreve o hash legado quando o schema já comporta o hash novo.
    senhaReescreveSeLegada($db, (int) $linha['id'], $senha, (string) $linha['senha']);

    // Mesmos campos devolvidos pela versão anterior.
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Confere a senha contra o hash guardado.
 * bcrypt/argon2 (password_hash) e md5 legado são aceitos.
 */
function senhaConfere(string $senha, string $hash): bool
{
    if ($hash === '') {
        return false;
    }
    $info = password_get_info($hash);
    if (!empty($info['algo'])) {
        return password_verify($senha, $hash);
    }
    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        return hash_equals(strtolower($hash), md5($senha));
    }
    // Formato legado desconhecido: comparação estrita, sem constante de tempo.
    return hash_equals($hash, $senha);
}

/**
 * Reescreve o hash md5 legado com password_hash(), quando possível.
 * Só grava se a coluna aceitar o hash novo — com CHAR(32) o hash seria
 * truncado e o usuário ficaria sem login, então nesse caso nada é feito.
 */
function senhaReescreveSeLegada(mysqli $db, int $usuarioId, string $senha, string $hashAtual): void
{
    if (strlen($hashAtual) !== 32 || !ctype_xdigit($hashAtual)) {
        return; // já está em hash forte
    }
    if (!colunaSenhaAceitaHashLongo($db)) {
        return; // coluna curta: mantém o md5 (ver schema.sql)
    }
    $novo = password_hash($senha, PASSWORD_DEFAULT);
    if ($novo === false) {
        return;
    }
    try {
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('si', $novo, $usuarioId);
        @$stmt->execute();
    } catch (Throwable $e) {
        error_log('senhaReescreveSeLegada: ' . $e->getMessage());
    }
}

/**
 * O schema atual da tabela usuarios aceita o hash novo (>= 60 caracteres)?
 * Consulta única por requisição; em caso de erro responde "não" (mantém md5).
 */
function colunaSenhaAceitaHashLongo(mysqli $db): bool
{
    static $resposta = null;
    if ($resposta !== null) {
        return $resposta;
    }
    $resposta = false;
    try {
        $stmt = $db->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'usuarios\' AND COLUMN_NAME = \'senha\''
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $linha = $res ? $res->fetch_assoc() : null;
        if ($linha && $linha['tamanho'] !== null) {
            $resposta = ((int) $linha['tamanho']) >= 60;
        }
    } catch (Throwable $e) {
        error_log('colunaSenhaAceitaHashLongo: ' . $e->getMessage());
    }
    return $resposta;
}

/* ---------------------------------------------------------------------------
 * Rótulos
 * ------------------------------------------------------------------------- */

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 *
 * Contrato de valor (manifest.md): 1 = Aberto, 2 = Em atendimento,
 * 3 = Resolvido. Qualquer valor fora da faixa continua sendo tratado como
 * "Resolvido" — comportamento histórico mantido para não alterar o que os
 * consumidores externos já esperam.
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

/* ---------------------------------------------------------------------------
 * Acesso a dados
 * ------------------------------------------------------------------------- */

/**
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
 * O resultado fica em cache por requisição para não repetir a consulta.
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $cache = &cacheNomesUsuarios();
    if (array_key_exists($tecnicoId, $cache)) {
        return $cache[$tecnicoId];
    }
    $nome = '-';
    try {
        $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $tecnicoId);
            $stmt->execute();
            $res = $stmt->get_result();
            $linha = $res ? $res->fetch_assoc() : null;
            if ($linha) {
                $nome = (string) $linha['nome'];
            }
        }
    } catch (Throwable $e) {
        error_log('tecnicoNome: ' . $e->getMessage());
    }
    $cache[$tecnicoId] = $nome;
    return $nome;
}

/**
 * Cache de nomes por id de usuário (por requisição).
 */
function &cacheNomesUsuarios(): array
{
    if (!isset($GLOBALS['__leb_nomes_usuarios']) || !is_array($GLOBALS['__leb_nomes_usuarios'])) {
        $GLOBALS['__leb_nomes_usuarios'] = [];
    }
    return $GLOBALS['__leb_nomes_usuarios'];
}

/**
 * Carrega os nomes de vários usuários em uma única consulta.
 * Substitui o N+1 (uma consulta por chamado) da listagem e do export.
 */
function precarregarNomesUsuarios(mysqli $db, array $ids): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, function ($id) {
        return $id > 0;
    }));
    if (!$ids) {
        return;
    }
    $cache = &cacheNomesUsuarios();
    $faltando = array_values(array_diff($ids, array_keys($cache)));
    if (!$faltando) {
        return;
    }
    try {
        $in = implode(',', array_fill(0, count($faltando), '?'));
        $stmt = $db->prepare('SELECT id, nome FROM usuarios WHERE id IN (' . $in . ')');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param(str_repeat('i', count($faltando)), ...$faltando);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($linha = $res->fetch_assoc()) {
                $cache[(int) $linha['id']] = (string) $linha['nome'];
            }
        }
    } catch (Throwable $e) {
        error_log('precarregarNomesUsuarios: ' . $e->getMessage());
        return;
    }
    foreach ($faltando as $id) {
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = '-';
        }
    }
}

/**
 * Normaliza uma linha para o mesmo formato de valores devolvido pela versão
 * anterior (strings e null). Consulta parametrizada devolve tipos nativos do
 * MySQL (int, float); o contrato historico do painel e do relatorio gerencial
 * e o de strings.
 */
function normalizarLinha(array $linha): array
{
    foreach ($linha as $chave => $valor) {
        if ($valor !== null && !is_string($valor)) {
            $linha[$chave] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Escapa os curingas do LIKE para que a busca trate % e _ como texto literal.
 */
function escaparLike(string $valor): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor);
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Quando há usuário em contexto com papel de cliente, só são devolvidos os
 * chamados abertos por ele (regra de negócio do manifesto).
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $filtros = [];
    $tipos = '';
    $valores = [];

    if ($busca !== '') {
        $filtros[] = "titulo LIKE ? ESCAPE '\\\\'";
        $tipos .= 's';
        $valores[] = '%' . escaparLike($busca) . '%';
    }
    if (ehVisorCliente()) {
        $filtros[] = 'usuario_id = ?';
        $tipos .= 'i';
        $valores[] = (int) visorUsuarioId();
    }

    $sql = 'SELECT * FROM chamados';
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY criado_em DESC';

    $linhas = [];
    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if ($tipos !== '') {
            $stmt->bind_param($tipos, ...$valores);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            // fetch_assoc (e não fetch_all) para manter os mesmos tipos de
            // valor devolvidos pela versão anterior (strings).
            while ($linha = $res->fetch_assoc()) {
                $linhas[] = normalizarLinha($linha);
            }
        }
    } catch (Throwable $e) {
        error_log('listarChamados: ' . $e->getMessage());
        return [];
    }

    // Uma consulta para os nomes dos técnicos em vez de uma por chamado.
    $idsTecnicos = [];
    foreach ($linhas as $linha) {
        if ($linha['tecnico_id'] !== null) {
            $idsTecnicos[(int) $linha['tecnico_id']] = true;
        }
    }
    precarregarNomesUsuarios($db, array_keys($idsTecnicos));

    $chamados = [];
    foreach ($linhas as $linha) {
        $linha['tecnico_nome'] = tecnicoNome(
            $db,
            $linha['tecnico_id'] !== null ? (int) $linha['tecnico_id'] : null
        );
        $chamados[] = $linha;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Cliente só enxerga os próprios chamados (retorna null para os demais).
 */
function verChamado(mysqli $db, int $id): ?array
{
    try {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $chamado = $res ? $res->fetch_assoc() : null;
    } catch (Throwable $e) {
        error_log('verChamado: ' . $e->getMessage());
        return null;
    }
    if (!$chamado) {
        return null;
    }
    $chamado = normalizarLinha($chamado);
    if (ehVisorCliente() && (int) $chamado['usuario_id'] !== (int) visorUsuarioId()) {
        return null;
    }
    return $chamado;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Calculada no banco; devolve 0.0 quando não há amostra (evita divisão por zero).
 */
function mediaResposta(mysqli $db): float
{
    try {
        $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(*) AS qtd '
            . 'FROM chamados WHERE minutos_resposta IS NOT NULL');
    } catch (Throwable $e) {
        error_log('mediaResposta: ' . $e->getMessage());
        return 0.0;
    }
    if (!$res) {
        return 0.0;
    }
    $linha = $res->fetch_assoc();
    if (!$linha) {
        return 0.0;
    }
    $qtd = (int) $linha['qtd'];
    if ($qtd === 0) {
        return 0.0; // amostra vazia: a divisão anterior gerava erro fatal
    }
    return ((int) $linha['soma']) / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * O CSV é montado em arquivo temporário de nome único (evita corrida entre
 * requisições e o ataque de symlink sobre um nome fixo) e removido depois do
 * envio. Cliente em contexto exporta apenas os próprios chamados.
 */
function exportarCsv(mysqli $db): void
{
    $caminho = arquivoTemporarioCsv();
    if ($caminho === null) {
        return;
    }
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        @unlink($caminho);
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    try {
        $sql = 'SELECT * FROM chamados';
        if (ehVisorCliente()) {
            $sql .= ' WHERE usuario_id = ' . (int) visorUsuarioId();
        }
        $sql .= ' ORDER BY id';
        $res = $db->query($sql);
        if ($res !== false) {
            $linhas = [];
            while ($linha = $res->fetch_assoc()) {
                $linhas[] = $linha;
            }
            precarregarNomesUsuarios($db, array_map(function ($l) {
                return $l['tecnico_id'] !== null ? (int) $l['tecnico_id'] : 0;
            }, $linhas));
            foreach ($linhas as $c) {
                $tecnico = tecnicoNome(
                    $db,
                    $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null
                );
                fputcsv($fp, [
                    $c['id'],
                    csvValorSeguro((string) $c['titulo']),
                    formatarStatus((int) $c['status']),
                    $tecnico,
                    $c['criado_em'],
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('exportarCsv: ' . $e->getMessage());
    }
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
    @unlink($caminho);
}

/**
 * Caminho de um arquivo temporário para o CSV.
 * Usa EXPORT_DIR quando existir e for gravável; caso contrário, o diretório
 * temporário do sistema (o antigo caminho fixo nem sempre existe e, quando
 * existe, costuma ficar dentro do document root).
 */
function arquivoTemporarioCsv(): ?string
{
    $dir = defined('EXPORT_DIR') ? EXPORT_DIR : '';
    if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir();
    }
    $caminho = @tempnam($dir, 'chamados_');
    return $caminho === false ? null : $caminho;
}

/**
 * Neutraliza fórmulas no campo de texto do CSV (CSV injection):
 * planilha como Excel/Sheets executa conteúdo iniciado por = + - @.
 * Aplicado ao título (dado digitado pelo cliente); os demais campos vêm de
 * cadastro interno e mantêm o valor original.
 */
function csvValorSeguro(string $valor): string
{
    if ($valor === '') {
        return $valor;
    }
    $primeiro = $valor[0];
    if ($primeiro === '=' || $primeiro === '+' || $primeiro === '-' || $primeiro === '@'
        || $primeiro === "\t" || $primeiro === "\r" || $primeiro === "\n") {
        return "'" . $valor;
    }
    return $valor;
}
