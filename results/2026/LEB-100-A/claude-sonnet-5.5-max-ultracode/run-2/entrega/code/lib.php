<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

// Regras de prioridade/SLA usadas por rotuloPrioridade()
const PAINEL_PRIORIDADE_ALTA = 3;
const PAINEL_PRIORIDADE_CRITICA = 4;
const PAINEL_SLA_MINUTOS = 30;

// Um hash bcrypt de password_hash() tem 60 caracteres: é a largura mínima de usuarios.senha
const PAINEL_BCRYPT_TAMANHO = 60;

// Colunas do chamado que o CSV usa (o CSV não precisa da descrição, que é TEXT)
const PAINEL_COLUNAS_CSV = 'c.id, c.titulo, c.status, c.criado_em';

// Escape do fputcsv: '' (RFC 4180) a partir do PHP 7.4; o '\\' padrão deixa um \" no título
// fechar o campo antes da hora e forjar colunas/registros no arquivo.
const PAINEL_CSV_ESCAPE = PHP_VERSION_ID >= 70400 ? '' : '\\';

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * A senha é conferida em PHP (password_verify) e não mais no SQL. Contas que ainda têm o hash
 * legado (md5 de 32 hex) continuam entrando e são migradas para password_hash() no primeiro
 * login bem-sucedido — ver migrarHashDeSenha().
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return recusarLogin($db);
    }

    $armazenado = (string) $linha['senha'];
    if (preg_match('/^[0-9a-f]{32}$/i', $armazenado)) {
        // hash legado: md5 em hex (a collation do banco ignorava a caixa, então normaliza)
        if (!hash_equals(strtolower($armazenado), md5($senha))) {
            return recusarLogin($db);
        }
        migrarHashDeSenha($db, (int) $linha['id'], $senha, $armazenado);
    } elseif (!password_verify($senha, $armazenado) || strpos($senha, "\0") !== false) {
        // o bcrypt ignora tudo depois de um NUL: recusa, para a senha valer inteira como no md5
        return null;
    }

    // só as três chaves do contrato: o hash nunca sai desta função
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Recusa um login que falhou sem passar pelo bcrypt (login inexistente ou md5 errado).
 * Quando já pode haver contas migradas, gasta o mesmo tempo de uma verificação bcrypt: sem
 * isso, a demora (~0,3 s contra ~3 ms) revelaria quais logins existem. Enquanto a coluna é
 * CHAR(32) nada foi migrado, todas as falhas custam igual e não há o que igualar.
 */
function recusarLogin(mysqli $db): ?array
{
    if (larguraColunaSenha($db) >= PAINEL_BCRYPT_TAMANHO) {
        password_hash('x', PASSWORD_DEFAULT);
    }
    return null;
}

/**
 * Largura, em caracteres, da coluna usuarios.senha (0 se não for possível saber).
 */
function larguraColunaSenha(mysqli $db): int
{
    try {
        $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $col = $res ? $res->fetch_assoc() : null;
        return $col ? (int) $col['n'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Troca o hash md5 legado por password_hash() após um login bem-sucedido.
 *
 * Só grava se a coluna usuarios.senha comportar o novo hash (schema.sql declara VARCHAR(255);
 * bancos antigos têm CHAR(32) até rodar o ALTER TABLE do RELATORIO.md). A checagem é
 * obrigatória: fora do modo estrito, um UPDATE em coluna menor TRUNCA o hash e tranca o usuário.
 * Qualquer falha é ignorada — migrar é oportunista e nunca pode impedir o login.
 */
function migrarHashDeSenha(mysqli $db, int $id, string $senha, string $hashLegado): void
{
    // o bcrypt só lê os 72 primeiros bytes e para no NUL: essas contas ficam em md5 (que confere tudo)
    if (strlen($senha) >= 72 || strpos($senha, "\0") !== false) {
        return;
    }
    $largura = larguraColunaSenha($db);
    if ($largura < PAINEL_BCRYPT_TAMANHO) {
        return; // coluna ainda estreita: nem calcula o hash
    }
    ob_start(); // um warning do mysqli (modo REPORT_ERROR, sem STRICT) não pode vazar antes do redirect do login
    try {
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || strlen($novo) > $largura) {
            return;
        }
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if (!$stmt) {
            error_log('painel: migracao do hash de senha ignorada: ' . $db->error);
            return;
        }
        $stmt->bind_param('sis', $novo, $id, $hashLegado);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('painel: migracao do hash de senha ignorada: ' . $e->getMessage());
    } finally {
        ob_end_clean();
    }
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 *
 * Legado preservado de propósito: qualquer valor diferente de 1 e 2 (inclusive fora do
 * domínio 1..3 do schema) continua saindo como 'Resolvido' — ver RELATORIO.md (Decisões).
 */
function formatarStatus(int $status): string
{
    $rotulos = [1 => 'Aberto', 2 => 'Em atendimento'];
    return $rotulos[$status] ?? 'Resolvido';
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 * (Mesmas saídas de antes, em guardas simples no lugar de quatro níveis de if aninhado.)
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < PAINEL_PRIORIDADE_ALTA) {
        return 'Normal';
    }
    if ($minutos <= PAINEL_SLA_MINUTOS) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade == PAINEL_PRIORIDADE_CRITICA ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id.
 *
 * @deprecated Uma consulta por chamado (N+1): a listagem e o CSV agora trazem o nome por JOIN
 *             em abrirConsultaChamados(). Mantida só porque scripts externos podem chamá-la.
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
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só os que abriu.
 * Devolve o id do dono a que a consulta deve ser restrita, ou null (sem restrição).
 * Qualquer papel que não seja 'tecnico' é tratado como cliente: na dúvida, nega.
 */
function escopoDeVisibilidade(int $uid, string $papel): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Este usuário pode ver este chamado? ($chamado é uma linha de chamados, com 'usuario_id'.)
 */
function chamadoVisivel(array $chamado, int $uid, string $papel): bool
{
    $dono = escopoDeVisibilidade($uid, $papel);
    return $dono === null || (int) $chamado['usuario_id'] === $dono;
}

/**
 * Executa a consulta única usada pela listagem e pelo CSV e devolve o resultado ainda não lido.
 * O nome do técnico vem por LEFT JOIN (antes: uma consulta extra por linha). $colunas é sempre
 * texto fixo do código, nunca dado do usuário. $usuarioId null = sem restrição de dono.
 * $ordemPorId: true = ORDER BY id (CSV); false = mais recentes primeiro (listagem), com os
 * empates de criado_em por id crescente, que é como o original os devolvia na prática.
 */
function abrirConsultaChamados(mysqli $db, string $colunas, string $busca, ?int $usuarioId, bool $ordemPorId): mysqli_result
{
    $sql = "SELECT $colunas, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    $tipos = '';
    $params = [];
    if ($usuarioId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $usuarioId;
    }
    if ($busca !== '') {
        // '%' e '_' digitados continuam valendo como curinga do LIKE, como sempre valeram
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= $ordemPorId ? ' ORDER BY c.id' : ' ORDER BY c.criado_em DESC, c.id';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('consulta de chamados nao preparada: ' . $db->error);
    }
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute() || !($res = $stmt->get_result())) {
        throw new RuntimeException('consulta de chamados falhou: ' . $stmt->error);
    }
    return $res;
}

/**
 * A conexão devolve int/float nativos também em query() (MYSQLI_OPT_INT_AND_FLOAT_NATIVE)?
 */
function conexaoDevolveTiposNativos(mysqli $db): bool
{
    $res = $db->query('SELECT 1');
    $linha = $res ? $res->fetch_row() : null;
    return $linha !== null && is_int($linha[0]);
}

/**
 * Lê todas as linhas de abrirConsultaChamados() com as colunas inteiras do chamado ('c.*') e
 * 'tecnico_nome' por último.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId, bool $ordemPorId): array
{
    $res = abrirConsultaChamados($db, 'c.*', $busca, $usuarioId, $ordemPorId);
    // prepared statements devolvem int nativo; o formato histórico destas listas é string/null
    $paraTexto = !conexaoDevolveTiposNativos($db);
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        if ($paraTexto) {
            foreach ($c as $campo => $valor) {
                if ($valor !== null) {
                    $c[$campo] = (string) $valor;
                }
            }
        }
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Sem restrição de dono (scripts internos); a tela usa listarChamadosVisiveis().
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, false);
}

/**
 * Igual a listarChamados(), mas só com o que este usuário pode ver (ver escopoDeVisibilidade).
 */
function listarChamadosVisiveis(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarChamados($db, $busca, escopoDeVisibilidade($uid, $papel), false);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Não aplica visibilidade: quem expõe o resultado confere com chamadoVisivel().
 */
function verChamado(mysqli $db, int $id): ?array
{
    // $id é int (tipado): não há como injetar SQL por aqui
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Agregada no banco; sem nenhum chamado respondido devolve 0.0 (antes: divisão por zero).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COUNT(minutos_resposta) AS qtd, COALESCE(SUM(minutos_resposta), 0) AS soma FROM chamados');
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    return $qtd > 0 ? (int) $row['soma'] / $qtd : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Sem restrição de dono (scripts internos); a tela usa exportarCsvVisivel().
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv(abrirConsultaChamados($db, PAINEL_COLUNAS_CSV, '', null, true));
}

/**
 * Igual a exportarCsv(), mas só com os chamados que este usuário pode ver.
 */
function exportarCsvVisivel(mysqli $db, int $uid, string $papel): void
{
    escreverCsv(abrirConsultaChamados($db, PAINEL_COLUNAS_CSV, '', escopoDeVisibilidade($uid, $papel), true));
}

/**
 * Escreve o CSV direto na saída, uma linha por vez. Antes ia para um arquivo fixo em EXPORT_DIR
 * e era relido: requisições simultâneas se sobrescreviam e sobrava em disco uma cópia de todos
 * os chamados. A consulta já rodou (em $chamados) antes do primeiro cabeçalho HTTP, então uma
 * falha de banco vira o erro 500 genérico e não um CSV truncado.
 */
function escreverCsv(mysqli_result $chamados): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', PAINEL_CSV_ESCAPE);
    while ($c = $chamados->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            neutralizarFormula((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            // '-' é o marcador de "sem técnico" e sai como sempre
            $c['tecnico_nome'] === '-' ? '-' : neutralizarFormula((string) $c['tecnico_nome']),
            $c['criado_em'],
        ], ',', '"', PAINEL_CSV_ESCAPE);
    }
    fclose($fp);
    $chamados->free();
}

/**
 * Título é texto livre do cliente: iniciado por = + - @ (ou TAB/CR), a planilha o executaria
 * como fórmula. Um apóstrofo na frente o mantém como texto.
 */
function neutralizarFormula(string $valor): string
{
    return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
}
