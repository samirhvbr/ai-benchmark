<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Controle de acesso: listarChamados(), verChamado() e exportarCsv() (contrato do manifesto)
 * NÃO filtram por usuário: são a API interna/batch, chamada sem sessão. A aplicação web usa as
 * variantes *DoDono() com o escopo devolvido por escopoDono() (regra de visibilidade do manifesto).
 */

/**
 * Opções do bcrypt. Custo 10 fixo, e não o padrão do PHP (12 no 8.4): o formato do hash não muda
 * entre versões do PHP e cada tentativa de login custa menos CPU.
 */
function opcoesSenha(): array
{
    return ['cost' => 10];
}

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Senhas novas usam bcrypt. Contas legadas (md5 de 32 hex) continuam entrando e o hash é migrado no
 * primeiro login bem-sucedido (ver migrarHashSenha). Toda tentativa que falha gasta o custo de um bcrypt
 * (usuário inexistente, md5 legado, bcrypt com o "setting" que este código grava, hash ilegível), para o tempo
 * de resposta não revelar quais logins existem; um hash de outra origem (outro custo ou algoritmo, como $2a$ ou
 * argon2) custa o que o seu algoritmo custa mais a isca. O preço é CPU por tentativa: limite a taxa de logins na borda.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $guardado = $linha ? rtrim((string) $linha['senha']) : ''; // rtrim: CHAR(n) com PAD_CHAR_TO_FULL_LENGTH
    $legado = (bool) preg_match('/^[0-9a-f]{32}$/i', $guardado);
    $custoPago = false; // já gastou um bcrypt nesta tentativa?
    // O crypt() só calcula o bcrypt inteiro se o "setting" ($2y$10$ + sal de 22 caracteres) for válido; daí em diante
    // o custo é o mesmo, seja qual for o resto do hash (truncado, longo, corrompido). Sem setting válido a isca é paga.
    $settingProprio = '/^\$2y\$' . sprintf('%02d', opcoesSenha()['cost']) . '\$[.\/0-9A-Za-z]{22}/';
    $md5Senha = md5($senha); // sempre, como o original: o tempo não pode depender de o hash guardado ser md5 (senha de vários MB)
    if ($legado) {
        // md5 sem sal, em tempo constante (a comparação em SQL antiga ignorava a caixa do hash)
        $ok = hash_equals(strtolower($guardado), $md5Senha);
    } elseif ($linha) {
        $ok = password_verify($senha, $guardado);
        $custoPago = (bool) preg_match($settingProprio, $guardado);
    } else {
        $ok = false;
    }

    if (!$ok) {
        if (!$custoPago) {
            // Entrada fixa, não a senha recebida: o PHP 8.4 lança ValueError em password_hash() se ela tiver byte NUL.
            password_hash('x', PASSWORD_BCRYPT, opcoesSenha());
        }
        return null;
    }
    if ($legado || password_needs_rehash($guardado, PASSWORD_BCRYPT, opcoesSenha())) {
        migrarHashSenha($db, (int) $linha['id'], $guardado, $senha);
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Regrava o hash da senha com bcrypt sem nunca interromper o login: qualquer falha vai para o log e o
 * login segue valendo com o hash atual. Se usuarios.senha ainda for CHAR(32) (ALTER do schema pendente)
 * nada é gravado, porque em modo SQL não estrito o UPDATE truncaria o hash e trancaria o usuário para fora.
 * A espera por locks é limitada a 1 s (backup com LOCK TABLES, transação aberta) para o login não travar por
 * causa disto, e os valores anteriores da sessão são restaurados.
 */
function migrarHashSenha(mysqli $db, int $id, string $hashAntigo, string $senha): void
{
    $anterior = null;
    try {
        $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        $coluna = $res ? $res->fetch_row() : null;
        if (!$coluna || (int) $coluna[0] < 60) { // 60 = tamanho de um hash bcrypt
            error_log('migrarHashSenha: usuarios.senha nao comporta hash bcrypt; hash nao migrado (ver o ALTER em schema.sql)');
            return;
        }
        $novo = password_hash($senha, PASSWORD_BCRYPT, opcoesSenha());
        $res = $db->query('SELECT @@SESSION.lock_wait_timeout, @@SESSION.innodb_lock_wait_timeout');
        $anterior = $res ? $res->fetch_row() : null;
        $db->query('SET SESSION lock_wait_timeout = 1, SESSION innodb_lock_wait_timeout = 1');
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($stmt === false) { // com mysqli sem exceções (PHP < 8.1) a falha vem como false
            error_log('migrarHashSenha: ' . $db->error);
        } else {
            $stmt->bind_param('sis', $novo, $id, $hashAntigo);
            if (!$stmt->execute()) {
                error_log('migrarHashSenha: ' . $stmt->error);
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('migrarHashSenha: ' . $e->getMessage());
    }
    if ($anterior) {
        try {
            $db->query('SET SESSION lock_wait_timeout = ' . (int) $anterior[0] . ', SESSION innodb_lock_wait_timeout = ' . (int) $anterior[1]);
        } catch (Throwable $e) {
            error_log('migrarHashSenha: ' . $e->getMessage());
        }
    }
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 * Qualquer valor fora de 1 e 2 resulta em 'Resolvido' (comportamento legado; o manifesto só cobre 1..3).
 */
function formatarStatus(int $status): string
{
    $rotulos = [1 => 'Aberto', 2 => 'Em atendimento'];
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
 * Busca o nome de um técnico pelo id. Não é mais usada na listagem/export (o nome vem no JOIN);
 * mantida porque outros scripts podem chamá-la.
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $res = executarConsulta($db, 'SELECT nome FROM usuarios WHERE id = ?', 'i', [$tecnicoId]);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Executa uma consulta preparada e devolve o resultado já em memória (o statement é fechado).
 * $tipos segue o formato de mysqli_stmt::bind_param ('i', 's', ...).
 */
function executarConsulta(mysqli $db, string $sql, string $tipos = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
}

/**
 * Consultas preparadas devolvem int nas colunas numéricas; as funções públicas sempre devolveram
 * strings (query() em texto). Converte de volta para preservar o formato de retorno do manifesto.
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

/**
 * Regra de visibilidade (manifest.md): técnico vê qualquer chamado; cliente só os que abriu.
 * Devolve o usuario_id a que as consultas devem ser restritas, ou null (= sem restrição).
 * Papel desconhecido é tratado como cliente: na dúvida, restringe.
 */
function escopoDono(string $papel, int $uid): ?int
{
    return $papel === 'tecnico' ? null : $uid;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 * Sem restrição de dono: API interna/batch (a web usa listarChamadosDoDono).
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosDoDono($db, null, $busca);
}

/**
 * Igual a listarChamados(), restrita aos chamados abertos por $donoId (null = todos).
 * O nome do técnico vem no mesmo SELECT (JOIN); antes era uma consulta por chamado.
 */
function listarChamadosDoDono(mysqli $db, ?int $donoId, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id";
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
    // Desempate por id: a ordem entre chamados do mesmo instante não era definida (na prática saía por id crescente).
    $sql .= ' ORDER BY c.criado_em DESC, c.id';

    $res = executarConsulta($db, $sql, $tipos, $params);
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = linhaComoTexto($c);
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Sem restrição de dono: API interna/batch (a web usa verChamadoDoDono).
 */
function verChamado(mysqli $db, int $id): ?array
{
    return verChamadoDoDono($db, null, $id);
}

/**
 * Igual a verChamado(), mas só devolve o chamado se ele foi aberto por $donoId (null = qualquer).
 * Chamado alheio é indistinguível de chamado inexistente (null): não revela quais ids existem.
 */
function verChamadoDoDono(mysqli $db, ?int $donoId, int $id): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $params = [$id];
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $params[] = $donoId;
    }
    $res = executarConsulta($db, $sql, $tipos, $params);
    $c = $res->fetch_assoc();
    return $c ? linhaComoTexto($c) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Agrega no banco; devolve 0.0 quando nenhum chamado foi respondido ainda.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COALESCE(SUM(minutos_resposta), 0) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados');
    $linha = $res->fetch_assoc();
    $qtd = (int) $linha['qtd'];
    return $qtd > 0 ? (float) $linha['soma'] / $qtd : 0.0;
}

/**
 * Neutraliza injeção em células do CSV vindas do banco (títulos são digitados por clientes).
 *  - Fórmula (Excel/LibreOffice): texto que começa com = + - @ TAB ou CR ganha um apóstrofo na frente.
 *  - Estrutura: barra invertida colada numa aspa, ou no fim do campo (onde o fputcsv põe a aspa de
 *    fechamento), é lida de um jeito pelo escape histórico do PHP ('\\') e de outro por leitores
 *    RFC 4180 (Excel, Python): o valor forja ou funde colunas e linhas. Um espaço depois da barra desfaz a dúvida.
 */
function celulaCsvSegura(?string $texto): string
{
    $texto = (string) $texto; // coluna NULL (schema antigo sem NOT NULL) vira célula vazia, como antes
    $seguro = preg_replace('/\\\\+(?="|\z)/', '$0 ', $texto);
    if ($seguro !== null) {
        $texto = $seguro;
    }
    if ($texto !== '' && strpos("=+-@\t\r", $texto[0]) !== false) {
        return "'" . $texto;
    }
    return $texto;
}

/**
 * Exporta todos os chamados para CSV e escreve na saída (download no navegador).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * Sem restrição de dono: API interna/batch (a web usa exportarCsvDoDono).
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvDoDono($db, null);
}

/**
 * Igual a exportarCsv(), restrita aos chamados abertos por $donoId (null = todos).
 * O CSV é montado em memória e escrito na saída. A cópia em EXPORT_DIR/chamados.csv que a versão anterior
 * deixava (outros scripts podem lê-la) só é gravada na exportação COMPLETA, nunca no CSV restrito de um
 * cliente, e por troca atômica: ver gravarCopiaCsv().
 */
function exportarCsvDoDono(mysqli $db, ?int $donoId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    $tipos = '';
    $params = [];
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $params[] = $donoId;
    }
    $sql .= ' ORDER BY c.id';
    // Consulta antes de emitir qualquer byte: uma falha não vira CSV truncado com status 200.
    $res = executarConsulta($db, $sql, $tipos, $params);

    // Até 16 MB ficam em RAM; só acima disso o PHP usa um arquivo no diretório temporário do sistema.
    $csv = fopen('php://temp/maxmemory:16777216', 'w+');
    $aux = fopen('php://memory', 'w+'); // formata uma linha por vez, para conferir que ela foi gravada por inteiro
    // $escape explícito ('\\' = padrão histórico): omiti-lo é depreciado no PHP 8.4 e a saída não muda.
    // Cada escrita é conferida contra o tamanho da linha: falha total, escrita parcial (disco cheio no meio da linha)
    // ou 0 (diretório temporário inválido) abortam aqui, antes de qualquer byte: nada de CSV truncado com status 200.
    $linha = function (array $campos) use ($csv, $aux) {
        ftruncate($aux, 0);
        rewind($aux);
        fputcsv($aux, $campos, ',', '"', '\\');
        rewind($aux);
        $texto = stream_get_contents($aux);
        if ($texto === false || $texto === '' || fwrite($csv, $texto) !== strlen($texto)) {
            throw new RuntimeException('exportarCsv: falha ao montar o CSV');
        }
    };
    $linha(['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        $linha([
            $c['id'],
            celulaCsvSegura($c['titulo']),
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] === '-' ? '-' : celulaCsvSegura($c['tecnico_nome']), // '-' = sem técnico
            $c['criado_em'],
        ]);
    }

    if ($donoId === null) {
        gravarCopiaCsv($csv); // antes de responder: a cópia é atualizada mesmo que o cliente abandone o download
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    header('X-Content-Type-Options: nosniff');
    rewind($csv);
    fpassthru($csv);
    fclose($csv);
}

/**
 * Mantém o arquivo EXPORT_DIR/chamados.csv que a versão anterior deixava a cada exportação: a rotina noturna ou
 * outro script pode lê-lo. Caminho preferido: arquivo temporário oculto de nome aleatório e imprevisível (criado
 * com fopen('x'), que não sobrescreve arquivo existente) e rename, que é atômico: o arquivo nunca fica pela metade,
 * exportações concorrentes não se misturam e um link simbólico no nome final é substituído em vez de seguido. O
 * modo do arquivo existente limita o do novo (nunca se alarga o que o administrador restringiu): aplicado pelo
 * umask na criação, sem chmod por caminho; se uma ACL padrão do diretório passar por cima do umask, não se publica.
 * Se o diretório é fechado, o rename é recusado (bind mount de arquivo, outro sistema de arquivos) ou o modo não
 * pôde ser respeitado, e o arquivo é gravável, escreve no lugar, como o código anterior (não atômico, sem esperar
 * lock), mas só num arquivo comum, sem link simbólico e sem outros links físicos, e depois de conferir que o que
 * foi aberto é o inode que o nome designa agora (um link plantado nesse nome a qualquer momento não é seguido; o
 * 'r+b' também não cria arquivo); e nunca se o temporário não pôde ser criado ou copiado por inteiro (disco, inodes
 * ou cota esgotados), para não trocar uma cópia completa por outra pela metade. Falhas só vão para o log e não
 * afetam a resposta (antes o usuário recebia página vazia). Limites: o rename troca o inode (dono, grupo, ACL,
 * hardlinks e quem observa o arquivo por inotify não o acompanham) e pede espaço livre para um segundo arquivo;
 * num diretório gravável por terceiros e sem sticky bit quem tem acesso local ainda pode apagar ou trocar o
 * chamados.csv: o endurecimento definitivo é o diretório (dono = usuário do PHP, modo 0750).
 */
function gravarCopiaCsv($csv): void
{
    $tmp = null;
    try {
        error_clear_last(); // o log no fim cita só erros ocorridos aqui, não um aviso anterior da requisição
        $dir = EXPORT_DIR;
        $final = $dir . '/chamados.csv';
        $esperado = ftell($csv); // tamanho do CSV completo (cada escrita já foi conferida)
        rewind($csv);
        $semEspaco = false; // o temporário não foi criado ou ficou incompleto (disco, inodes ou cota esgotados): gravar no lugar truncaria a cópia boa
        if (is_dir($dir) && is_writable($dir)) {
            foreach (@scandir($dir) ?: [] as $nome) { // sobras de processos mortos no meio da cópia
                if (strncmp($nome, '.chamados-', 10) === 0 && substr($nome, -4) === '.tmp' && @filemtime($dir . '/' . $nome) < time() - 3600) {
                    @unlink($dir . '/' . $nome);
                }
            }
            $tmp = $dir . '/.chamados-' . bin2hex(random_bytes(8)) . '.tmp';
            $maximo = 0777;
            if (is_file($final) && ($perm = fileperms($final)) !== false) {
                $maximo = $perm & 0777;
            }
            $umaskAnterior = umask();
            try {
                umask($umaskAnterior | (~$maximo & 0777));
                $destino = @fopen($tmp, 'xb');
            } finally {
                umask($umaskAnterior);
            }
            if ($destino !== false) {
                if ((fstat($destino)['mode'] & 0777 & ~$maximo) === 0) { // senão uma ACL padrão do diretório alargou o modo
                    $copiados = @stream_copy_to_stream($csv, $destino);
                    $fechou = @fclose($destino);
                    $semEspaco = !$fechou || $copiados !== $esperado;
                    if (!$semEspaco && !is_link($tmp) && @rename($tmp, $final)) {
                        if (!is_link($final)) {
                            return;
                        }
                        @unlink($final); // trocaram o temporário por um link entre a checagem e o rename: melhor sem arquivo do que apontando para outro lugar
                    }
                } else {
                    @fclose($destino);
                }
            } else {
                $semEspaco = true; // nem o temporário foi criado: a causa vai para o log (error_get_last)
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            $tmp = null;
            rewind($csv);
        }
        if (!$semEspaco && is_file($final) && !is_link($final) && is_writable($final) && ($f = @fopen($final, 'r+b')) !== false) { // no lugar
            clearstatcache(true, $final); // a comparação abaixo vale para o que o nome é agora, não para um resultado em cache
            $aberto = fstat($f);
            $nome = @lstat($final); // lstat não segue link: se o nome virou link depois das checagens, o inode não é o aberto
            $ok = $aberto !== false && $nome !== false && ($aberto['mode'] & 0170000) === 0100000 && $aberto['nlink'] <= 1
                && $aberto['dev'] === $nome['dev'] && $aberto['ino'] === $nome['ino']
                && @flock($f, LOCK_EX | LOCK_NB) && @ftruncate($f, 0);
            $ok = $ok && @stream_copy_to_stream($csv, $f) === $esperado && @fflush($f);
            @flock($f, LOCK_UN);
            @fclose($f);
            if ($ok) {
                return;
            }
        }
        $erro = error_get_last();
        error_log('gravarCopiaCsv: nao foi possivel atualizar EXPORT_DIR/chamados.csv' . ($erro ? ' (' . $erro['message'] . ')' : ''));
    } catch (Throwable $e) {
        error_log('gravarCopiaCsv: ' . $e->getMessage());
    }
    if ($tmp !== null && is_file($tmp)) {
        @unlink($tmp);
    }
}
