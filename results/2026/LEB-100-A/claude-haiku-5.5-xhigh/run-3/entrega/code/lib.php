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
 * Senhas em md5 (legado) são aceitas e regravadas com password_hash no primeiro
 * login bem-sucedido.
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

    $guardada = $linha['senha'];
    if (password_get_info($guardada)['algoName'] !== 'unknown') {
        $ok = password_verify($senha, $guardada);
    } else {
        $ok = hash_equals($guardada, md5($senha));
        if ($ok) {
            regravarSenha($db, (int) $linha['id'], $senha);
        }
    }
    if (!$ok) {
        return null;
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Troca o hash md5 legado por password_hash. Só regrava se a coluna senha já foi
 * ampliada (ver schema.sql): um bcrypt de 60 caracteres gravado em CHAR(32) seria
 * truncado e o usuário ficaria sem conseguir entrar.
 */
function regravarSenha(mysqli $db, int $id, string $senha): void
{
    $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS largura FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
    $coluna = $res->fetch_assoc();
    if ((int) ($coluna['largura'] ?? 0) < 60) {
        return;
    }
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $id);
    $stmt->execute();
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 * Valor fora de 1/2/3 não é "Resolvido": vira "Desconhecido".
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
 * Escapa os curingas do LIKE (%, _ e a própria barra), para a busca ser texto literal.
 */
function escaparLike(string $texto): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
}

/**
 * Consulta chamados com filtro opcional por título e por dono ($usuarioId null = sem
 * filtro). Cada item já traz 'tecnico_nome' (um JOIN, em vez de uma consulta por linha).
 * $ordem é texto fixo do código, nunca vem do usuário.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId, string $ordem): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id'
         . ' WHERE c.titulo LIKE ? AND (? IS NULL OR c.usuario_id = ?)'
         . ' ORDER BY ' . $ordem;
    $stmt = $db->prepare($sql);
    $like = '%' . escaparLike($busca) . '%';
    $stmt->bind_param('sii', $like, $usuarioId, $usuarioId);
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Não aplica a regra de visibilidade: para a tela, use listarChamadosVisiveis.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null, 'c.criado_em DESC');
}

/**
 * Como listarChamados, aplicando a regra de visibilidade do papel (manifest.md):
 * técnico vê todos; cliente só vê os chamados que abriu.
 */
function listarChamadosVisiveis(mysqli $db, int $uid, string $papel, string $busca = ''): array
{
    return consultarChamados($db, $busca, $papel === 'tecnico' ? null : $uid, 'c.criado_em DESC');
}

/**
 * Regra de visibilidade de um chamado: técnico vê qualquer um; cliente só os que abriu.
 * Qualquer papel que não seja 'tecnico' é tratado como cliente.
 */
function podeVer(array $chamado, int $uid, string $papel): bool
{
    return $papel === 'tecnico' || (int) $chamado['usuario_id'] === $uid;
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
 * Sem nenhum chamado respondido, devolve 0.
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
 * Impede que planilhas tratem o texto como fórmula (CSV injection).
 */
function protegerCelula(string $valor): string
{
    return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
}

/**
 * Exporta todos os chamados para CSV e escreve na saída (usado pela rotina noturna).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}

/**
 * Exporta os chamados visíveis ao usuário (técnico: todos; cliente: só os seus).
 */
function exportarCsvVisivel(mysqli $db, int $uid, string $papel): void
{
    escreverCsv($db, $papel === 'tecnico' ? null : $uid);
}

/**
 * Escreve o CSV direto na saída. A versão anterior gravava um arquivo fixo em
 * EXPORT_DIR, deixava-o lá e o relia: ficava acessível por URL e era compartilhado
 * entre requisições simultâneas.
 */
function escreverCsv(mysqli $db, ?int $usuarioId): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    // Cabeçalho literal: fputcsv colocaria aspas em "Aberto em" (espaço), fora do manifest.
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    foreach (consultarChamados($db, '', $usuarioId, 'c.id') as $c) {
        // Só o título é texto livre; o nome do técnico vem de cadastro interno.
        fputcsv($fp, [
            $c['id'],
            protegerCelula($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
