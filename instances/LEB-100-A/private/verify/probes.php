<?php
/**
 * probes.php — Verificação automatizada de falhas do LEB-100-A (CONFIDENCIAL).
 *
 * Cada probe exercita o código em ../../code/ e decide:
 *   - PLANTADA   → a falha ainda está presente (esperado no legado intocado)
 *   - CORRIGIDA  → a falha foi eliminada (esperado após a correção do modelo)
 *
 * Rode ANTES (deve dar tudo PLANTADA — prova que os probes detectam) e DEPOIS
 * da entrega do modelo (o que virar CORRIGIDA vale o critério C3 da falha).
 *
 * Uso:  php probes.php [all|sec-001|sec-008|bug-001|perf-001]
 *
 * Os demais IDs (sec-003, sec-013, sec-014, sec-015, sec-017, bug-004) exigem
 * dispatcher HTTP ou inspeção — roteiro em README.md.
 */
require __DIR__ . '/../../characterization/_bootstrap.php';
if (!defined('EXPORT_DIR')) {
    define('EXPORT_DIR', sys_get_temp_dir());
}
require CODE_DIR . '/lib.php';

/** SEC-001 — SQL Injection na busca por título. */
function probe_sec001(mysqli $db): array
{
    resetarBanco($db);
    // fecha o LIKE, injeta OR verdadeiro e comenta o "%'" que o código anexa no fim
    $payload = "zzz%' OR '1'='1' -- ";
    $r = listarChamados($db, $payload);
    // legado: a injeção casa todas as 5 linhas; parametrizado: termo literal => 0
    $corrigida = count($r) === 0;
    return [$corrigida, sprintf("payload de injeção retornou %d linhas (legado=5, corrigido=0)", count($r))];
}

/** SEC-008 — CSV Injection: título que é fórmula. */
function probe_sec008(mysqli $db): array
{
    resetarBanco($db);
    $db->query("INSERT INTO chamados (id,usuario_id,tecnico_id,titulo,descricao,status,prioridade,minutos_resposta,criado_em)"
        . " VALUES (900,1,3,'=1+1','teste',1,1,NULL,'2026-06-10 10:00:00')");
    ob_start();
    @exportarCsv($db);
    // Read the output (the manifest's contract), not the legacy temp file.
    $csv = (string) ob_get_clean();
    $titulo900 = '';
    foreach (explode("\n", trim($csv)) as $ln) {
        $cols = str_getcsv($ln);
        if (($cols[0] ?? '') === '900') {
            $titulo900 = $cols[1] ?? '';
        }
    }
    $corrigida = $titulo900 !== '' && !str_starts_with($titulo900, '=');
    return [$corrigida, "célula de título exportada: '{$titulo900}' (não deve começar com '=')"];
}

/** BUG-001 — Divisão por zero na média quando nada foi respondido. */
function probe_bug001(mysqli $db): array
{
    resetarBanco($db);
    $db->query('UPDATE chamados SET minutos_resposta = NULL'); // nenhum chamado respondido
    try {
        $m = mediaResposta($db);
        return [true, "média com conjunto vazio retornou {$m} sem erro"];
    } catch (\DivisionByZeroError $e) {
        return [false, "DivisionByZeroError — divisão por zero não tratada"];
    }
}

/** PERF-001 — N+1 na listagem (uma query de técnico por chamado). */
function probe_perf001(mysqli $db): array
{
    resetarBanco($db);
    $q0 = questions($db);
    listarChamados($db, '');
    $delta = questions($db) - $q0; // inclui a própria 2ª leitura de status
    $corrigida = $delta <= 3;      // em lote: 1–2 queries + a leitura de status
    return [$corrigida, "listarChamados executou ~" . ($delta - 1) . " queries (corrigido: ≤2)"];
}

function questions(mysqli $db): int
{
    $r = $db->query("SHOW SESSION STATUS LIKE 'Questions'");
    return (int) ($r->fetch_assoc()['Value']);
}

// --- runner -----------------------------------------------------------------
$casos = [
    'sec-001' => 'probe_sec001',
    'sec-008' => 'probe_sec008',
    'bug-001' => 'probe_bug001',
    'perf-001' => 'probe_perf001',
];

$alvo = $argv[1] ?? 'all';
$rodar = $alvo === 'all' ? array_keys($casos) : [$alvo];
$json = (bool) getenv('LEB_PROBE_JSON'); // saída machine-readable p/ o harness

$db = conectar();
$aindaVulneravel = 0;
$resultados = [];
if (!$json) {
    echo "Verificação LEB-100-A — code/ em " . realpath(CODE_DIR) . "\n\n";
}
foreach ($rodar as $id) {
    if (!isset($casos[$id])) {
        fwrite(STDERR, "caso desconhecido: {$id}\n");
        exit(2);
    }
    // A probe whose call into the delivery throws counts as not fixed, and the
    // run goes on: a fix that crashes the contracted function is not a fix, and
    // one fatal must not cost the other probes their verdict.
    try {
        [$corrigida, $msg] = $casos[$id]($db);
    } catch (Throwable $e) {
        [$corrigida, $msg] = [false, 'a entrega lançou ' . get_class($e) . ': ' . $e->getMessage()];
    }
    $resultados[] = ['id' => $id, 'corrigida' => $corrigida, 'msg' => $msg];
    if (!$corrigida) {
        $aindaVulneravel++;
    }
    if (!$json) {
        $rotulo = $corrigida ? "\033[32mCORRIGIDA\033[0m" : "\033[31mPLANTADA \033[0m";
        printf("  [%s] %-9s — %s\n", $rotulo, $id, $msg);
    }
}
if ($json) {
    echo json_encode([
        'code_dir' => realpath(CODE_DIR),
        'probes' => $resultados,
        'ainda_plantadas' => $aindaVulneravel,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n" . str_repeat('-', 60) . "\n";
    echo $aindaVulneravel === 0
        ? "Todas as falhas verificadas estão CORRIGIDAS.\n"
        : "{$aindaVulneravel} falha(s) ainda PLANTADA(s).\n";
}
exit($aindaVulneravel === 0 ? 0 : 1);
