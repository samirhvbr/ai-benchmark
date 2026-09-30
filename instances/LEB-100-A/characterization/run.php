<?php
/**
 * run.php — Testes de caracterização do LEB-100-A.
 *
 * Travam a SUPERFÍCIE PÚBLICA (manifest.md), não as falhas. Devem passar:
 *   (a) no código legado intocado;
 *   (b) na entrega do modelo, SE ela preservou a compatibilidade.
 * Uma correção que quebra qualquer teste aqui = regressão / violação COMP.
 *
 * Uso:  DB_HOST=127.0.0.1 DB_PORT=3306 php run.php
 */
require __DIR__ . '/_bootstrap.php';
define('EXPORT_DIR', sys_get_temp_dir());
require CODE_DIR . '/lib.php';

$db = conectar();
resetarBanco($db);

secao('autenticar() — contrato de login');
$ana = autenticar($db, 'ana', 'senha123');
checa($ana !== null && $ana['nome'] === 'Ana Souza', "ana/senha123 autentica e retorna nome");
checa($ana !== null && $ana['papel'] === 'cliente', "ana tem papel 'cliente'");
$tec = autenticar($db, 'carla', 'tecmaster');
checa($tec !== null && $tec['papel'] === 'tecnico', "carla tem papel 'tecnico'");
checa(autenticar($db, 'ana', 'errada') === null, "senha errada retorna null");
checa(array_keys($ana) === ['id', 'nome', 'papel'], "retorno tem exatamente id,nome,papel");

secao('formatarStatus() — rótulos contratados');
checa(formatarStatus(1) === 'Aberto', "1 => Aberto");
checa(formatarStatus(2) === 'Em atendimento', "2 => Em atendimento");
checa(formatarStatus(3) === 'Resolvido', "3 => Resolvido");

secao('rotuloPrioridade() — tabela-verdade');
checa(rotuloPrioridade(2, 10) === 'Normal', "(2,10) => Normal");
checa(rotuloPrioridade(3, 40) === 'Alto - atrasado', "(3,40) => Alto - atrasado");
checa(rotuloPrioridade(4, 40) === 'CRITICO - SLA estourado', "(4,40) => CRITICO");
checa(rotuloPrioridade(3, 20) === 'Alto - dentro do SLA', "(3,20) => dentro do SLA");
checa(rotuloPrioridade(2, null) === 'Aguardando 1a resposta', "(2,null) => Aguardando");

secao('listarChamados() — listagem e busca');
$todos = listarChamados($db, '');
checa(count($todos) === 5, "sem busca retorna os 5 chamados");
checa(array_key_exists('tecnico_nome', $todos[0]), "cada item traz 'tecnico_nome'");
$busca = listarChamados($db, 'Roteador');
checa(count($busca) === 1 && (int) $busca[0]['id'] === 105, "busca 'Roteador' acha o chamado 105");

secao('verChamado() — detalhe por id');
$c = verChamado($db, 101);
checa($c !== null && (int) $c['id'] === 101, "verChamado(101) retorna o chamado 101");
checa(verChamado($db, 9999) === null, "id inexistente retorna null");

secao('mediaResposta() — indicador de SLA');
$m = mediaResposta($db);
checa(abs($m - (12 + 40 + 25) / 3) < 0.01, "média dos minutos respondidos ≈ 25.67");

secao('exportarCsv() — formato do arquivo');
ob_start();
@exportarCsv($db); // @ suprime o warning de header() em CLI
// The manifest promises the CSV on the output, not a file on disk: read what was
// written, so a delivery that streams straight to php://output is not a regression.
$csv = (string) ob_get_clean();
$linhas = array_values(array_filter(explode("\n", trim($csv))));
$header = str_getcsv($linhas[0]); // compara campos, não bytes (quoting é serialização)
checa($header === ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], "colunas do CSV: ID,Titulo,Status,Tecnico,Aberto em");
checa(count($linhas) === 6, "cabeçalho + 5 chamados = 6 linhas");
checa(str_getcsv($linhas[1])[0] === '101', "primeira linha de dados é o chamado 101 (ordenado por id)");

exit(resumo());
