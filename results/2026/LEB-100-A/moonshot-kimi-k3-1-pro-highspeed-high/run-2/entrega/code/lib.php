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
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $hash = md5($senha);
    // LEB: FINDING F7 severity=alta confidence=100 | Toda senha é reduzida a um MD5 rápido e sem salt, formato também imposto por usuarios.senha CHAR(32) | Impacto: vazamento da tabela permite testar dicionários em massa e reutilizar hashes de senhas já conhecidas | Correção proposta: migrar para password_hash/password_verify com rehash no login, mantendo verificação legada apenas durante a transição.
    $stmt = $db->prepare('SELECT id, nome, papel FROM usuarios WHERE login = ? AND senha = ?');
    $stmt->bind_param('ss', $usuario, $hash);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha ?: null;
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
        // LEB: FINDING F14 severity=baixa confidence=95 | O else trata tanto status 3 quanto qualquer outro inteiro como Resolvido; schema.sql usa TINYINT sem CHECK | Impacto: valor inválido ou futuro é silenciosamente apresentado/exportado como resolvido, mascarando dados inconsistentes | Correção proposta: tratar 3 explicitamente e definir um retorno/erro seguro para valores desconhecidos sem mudar os três rótulos contratados.
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
    $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT * FROM chamados';
    if ($busca !== '') {
        $sql .= " WHERE titulo LIKE '%" . $busca . "%'";
        // LEB: FINDING F1 severity=alta confidence=100 | busca chega de GET e é concatenada dentro da SQL; uma aspa fecha a string e altera a consulta | Impacto: cliente autenticado contorna o filtro e pode extrair dados acessíveis à conta do banco, inclusive por UNION | Correção proposta: prepared statement com placeholder e escaping apenas dos curingas de LIKE, se necessário.
    }
    $sql .= ' ORDER BY criado_em DESC';
    $res = $db->query($sql);

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        // LEB: FINDING F12 severity=media confidence=100 | Cada linha do SELECT principal dispara outro SELECT em tecnicoNome; N chamados atribuídos geram N consultas extras | Impacto: latência e carga do MySQL crescem linearmente com o tamanho da listagem | Correção proposta: obter tecnico_nome por LEFT JOIN ou pré-carregar os técnicos em uma consulta, preservando a chave no retorno.
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
    return $soma / $qtd;
    // LEB: FINDING F10 severity=media confidence=100 | qtd permanece zero quando nenhum chamado tem minutos_resposta; o retorno então divide zero por zero | Impacto: DivisionByZeroError no PHP 8 derruba a listagem justamente em base nova ou sem respostas | Correção proposta: devolver 0.0 quando qtd for zero, ou calcular COALESCE(AVG(...), 0) no banco.
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $caminho = EXPORT_DIR . '/chamados.csv';
    // LEB: FINDING F11 severity=media confidence=100 | Todas as requisições truncam, escrevem e leem o mesmo caminho sem bloqueio; escritas/leituras concorrentes se sobrepõem | Impacto: download vazio, truncado ou misturado entre requisições, além de corrupção do arquivo persistido | Correção proposta: arquivo exclusivo por requisição com bloqueio/ciclo de vida seguro, ou streaming direto com fputcsv para php://output.
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $res = $db->query('SELECT * FROM chamados ORDER BY id');
    if ($res === false) {
        return;
        // LEB: FINDING F15 severity=baixa confidence=100 | Este retorno ocorre depois de abrir/truncar o CSV e antes de fclose | Impacto: handle fica aberto até a limpeza da requisição e resta no disco um arquivo parcial apresentado como exportação | Correção proposta: fechar o handle em todos os ramos e remover/sinalizar a exportação incompleta.
    }
    while ($c = $res->fetch_assoc()) {
        $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        // LEB: FINDING F13 severity=media confidence=100 | O loop de exportação executa tecnicoNome para cada chamado, somando uma consulta por técnico_id não nulo | Impacto: exportações grandes geram centenas/milhares de round trips e bloqueiam a requisição | Correção proposta: LEFT JOIN com usuarios ou mapa de técnicos pré-carregado, sem mudar colunas nem ordenação.
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $tecnico,
            $c['criado_em'],
        ]);
        // LEB: FINDING F9 severity=media confidence=85 | Título e nome do técnico são escritos sem neutralizar =, +, - ou @ iniciais; fputcsv não impede interpretação como fórmula por planilhas | Impacto: ao abrir o CSV, um valor controlado pode executar fórmulas e exfiltrar dados ou induzir comandos conforme a planilha | Correção proposta: prefixar/neutralizar células de texto perigosas na exportação, documentando a transformação.
    }
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
}
