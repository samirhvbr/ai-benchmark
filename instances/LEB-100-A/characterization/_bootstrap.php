<?php
/**
 * _bootstrap.php — infraestrutura compartilhada por caracterização e verificação.
 * Requer um MySQL acessível (ver docker-compose.yml). Conecta por variáveis de ambiente.
 */

error_reporting(E_ALL & ~E_DEPRECATED); // fputcsv sem escape é deprecado no PHP 8.4 (código legado)

// Diretório do código sob avaliação. Aponte para a entrega do modelo com LEB_CODE_DIR.
define('CODE_DIR', getenv('LEB_CODE_DIR') ?: (__DIR__ . '/../code'));

/** Conecta ao MySQL de teste. Aborta com instrução se indisponível. */
function conectar(): mysqli
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: 3306);
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: 'root';
    $name = getenv('DB_NAME') ?: 'suporte';

    $db = @new mysqli($host, $user, $pass, $name, $port);
    if ($db->connect_errno) {
        fwrite(STDERR, "[ambiente] Sem MySQL em {$host}:{$port} — {$db->connect_error}\n");
        fwrite(STDERR, "[ambiente] Suba com:  docker compose up -d   (ver characterization/README.md)\n");
        exit(2);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

/** Recria o banco a partir de code/schema.sql + code/seed.sql. */
function resetarBanco(mysqli $db): void
{
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $db->query('DROP TABLE IF EXISTS chamados');
    $db->query('DROP TABLE IF EXISTS usuarios');
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    foreach (statementsSql(CODE_DIR . '/schema.sql') as $s) {
        $db->query($s);
    }
    foreach (statementsSql(CODE_DIR . '/seed.sql') as $s) {
        $db->query($s);
    }
}

/**
 * Splits a .sql file into statements the way the mysql client does: a `;` ends a
 * statement only outside quotes and comments (`-- `, `#`, block comments). A `;`
 * inside a trailing comment is valid SQL and must not cut the statement in two.
 */
function statementsSql(string $arquivo): array
{
    $sql = (string) file_get_contents($arquivo);
    $len = strlen($sql);
    $stmts = [];
    $buf = '';
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $quote !== '`') {
                $buf .= $next;
                $i++;
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
            continue;
        }
        $dashComment = $c === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]));
        if ($dashComment || $c === '#') {
            $eol = strpos($sql, "\n", $i);
            $i = $eol === false ? $len : $eol - 1;
            continue;
        }
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            $buf .= ' ';
            continue;
        }
        if ($c === ';') {
            $stmts[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $stmts[] = trim($buf);
    return array_values(array_filter($stmts, fn($s) => $s !== ''));
}

// --- micro-framework de asserção -------------------------------------------
$GLOBALS['__ok'] = 0;
$GLOBALS['__falhas'] = 0;

function checa(bool $cond, string $desc): void
{
    if ($cond) {
        $GLOBALS['__ok']++;
        echo "  \033[32m✔\033[0m {$desc}\n";
    } else {
        $GLOBALS['__falhas']++;
        echo "  \033[31m✘\033[0m {$desc}\n";
    }
}

function secao(string $titulo): void
{
    echo "\n\033[1m{$titulo}\033[0m\n";
}

function resumo(): int
{
    $ok = $GLOBALS['__ok'];
    $f = $GLOBALS['__falhas'];
    echo "\n" . str_repeat('-', 48) . "\n";
    echo "{$ok} verificações ok, {$f} falharam\n";
    return $f === 0 ? 0 : 1;
}
