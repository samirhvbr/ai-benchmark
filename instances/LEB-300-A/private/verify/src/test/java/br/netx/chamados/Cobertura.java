package br.netx.chamados;

import java.util.Map;

/** Gerado por validacao/cobertura.py. O que cada veredito mecânico comprova e o que deixa para o juiz. */
final class Cobertura {
    private Cobertura() {}

    record Item(String comprova, String[] naoComprova) {}

    static final Map<String, Item> DE = Map.ofEntries(
        Map.entry("SEC-001.a", new Item("o texto injetado em status e em prioridade não muda o resultado nem causa erro de banco, e o filtro legítimo continua filtrando", new String[] {"outros pontos do sistema que montem consulta com texto de entrada", "injeção de segunda ordem (texto gravado antes e usado depois)"})),
        Map.entry("SEC-016", new Item("um ATENDENTE não altera papel, senha nem situação de outro usuário, e o ADMIN continua alterando", new String[] {"os papéis TECNICO e PLATAFORMA tentando a mesma alteração", "um ADMIN de outra empresa"})),
        Map.entry("SEC-017.a", new Item("o histórico de eventos de um chamado de outra empresa devolve 404", new String[] {"as demais rotas filhas de chamado e de ordem"})),
        Map.entry("SEC-015", new Item("nenhum login com a senha embutida antiga e nenhum texto escrito no código abre uma conta criada pela própria aplicação", new String[] {"uma credencial calculada em tempo de execução a partir de dados que o código esconde", "como a primeira conta de plataforma passa a ser criada (decisão de projeto, para o juiz ler)"})),
        Map.entry("SEC-014.a", new Item("a mesma senha em dois usuários gera valores guardados diferentes, nenhum é o SHA-256 simples, o login funciona e o formato antigo continua válido", new String[] {"o custo de calcular o hash: um esquema salgado mas rápido passa por esta prova e o juiz decide se é adequado", "a migração dos valores já gravados (ex.: regravar no login)"})),
        Map.entry("SEC-017.b", new Item("a empresa B não recebe os números da empresa A, e um segundo mês da mesma empresa não vem do cache do primeiro", new String[] {"outros caches ou estado compartilhado entre empresas"})),
        Map.entry("SEC-008", new Item("células de texto livre que começam por =, +, - ou @ saem neutralizadas, e o texto comum sai exatamente como entrou", new String[] {"caracteres iniciais de controle (tabulação, retorno de carro) e o comportamento em planilhas diferentes"})),
        Map.entry("SEC-020", new Item("nenhuma resposta de usuário (lista, detalhe, criação, alteração) traz o valor da senha", new String[] {"outras rotas que devolvam usuário embutido em outro recurso"})),
        Map.entry("SEC-013", new Item("depois do logout o mesmo token recebe 401", new String[] {"revogação por outros caminhos (expiração, várias sessões do mesmo usuário)"})),
        Map.entry("BUG-011.a", new Item("duas alterações de campos diferentes do mesmo chamado valem ambas, nas duas ordens de gravação, com threads numa JVM e com duas JVMs independentes", new String[] {"outros pares de campos e outros entrelaçamentos além dos exercitados", "mais de duas instâncias"})),
        Map.entry("BUG-011.b", new Item("duas execuções do despacho ao mesmo tempo entregam cada notificação uma vez, numa JVM e entre duas JVMs", new String[] {"mais de duas instâncias e falhas no meio da entrega (a interrupção é exercitada em BUG-014, para o lançamento)"})),
        Map.entry("BUG-014", new Item("uma interrupção antes de qualquer comando do lançamento, seguida de nova execução, não pula nem duplica ordem", new String[] {"interrupção por morte do processo (kill) em vez de erro, e interrupção dentro de um comando"})),
        Map.entry("BUG-006", new Item("o mês do relatório e a competência do lançamento seguem o fuso da empresa, em duas empresas de fusos diferentes e com a JVM num terceiro fuso", new String[] {"mudança de horário de verão e outros fusos"})),
        Map.entry("BUG-007", new Item("resolver exatamente no prazo conta como cumprido, e um prazo que cai no fim do expediente fica nesse instante", new String[] {"outras fronteiras de calendário (fim de semana, virada de mês)"})),
        Map.entry("BUG-013", new Item("a porcentagem e a média de horas usam HALF_UP, em dois conjuntos de dados que separam HALF_UP de truncar, de HALF_EVEN e de arredondar para cima", new String[] {"outros números e outras casas decimais"})),
        Map.entry("BUG-009", new Item("a reabertura de RESOLVIDO para EM_ATENDIMENTO é aceita e limpa resolvido_em", new String[] {"as demais transições continuam como o manifesto diz (cobertas pelos testes públicos, não por esta prova)"})),
        Map.entry("BUG-015", new Item("a causa original de uma falha interna aparece no registro (saída de erro e saída padrão) e o cliente recebe 500 genérico", new String[] {"outros destinos de registro (arquivo, formato estruturado) que o avaliador não captura"})),
        Map.entry("PERF-001", new Item("o número de comandos por requisição de listagem não cresce com o tamanho da página, com cliente e técnico distintos em cada linha", new String[] {"tamanhos intermediários e filtros combinados"})),
        Map.entry("PERF-003", new Item("as linhas lidas para uma página de 10 não crescem com o tamanho da tabela", new String[] {"outras páginas além da primeira e outras tabelas"})),
        Map.entry("PERF-005", new Item("o número de comandos do relatório de técnicos não cresce com o número de técnicos", new String[] {"a correção dos números do relatório (coberta pelos testes públicos)"})),
        Map.entry("PERF-006", new Item("existe índice cuja primeira coluna é a empresa em chamado e o chamado em ordem de serviço", new String[] {"que o plano de execução use o índice", "outros índices úteis"})),
        Map.entry("PERF-011", new Item("a passada do escalonamento sobre 20.000 chamados de 5 KB conclui com -Xmx48m", new String[] {"outros usos de memória do mesmo tipo em outras rotas", "falhas do avaliador ou de origem não estabelecida ficam sem veredito (inconclusivo)"})),
        Map.entry("PERF-012", new Item("o tempo de processador do despacho cresce em linha reta de 4.000 para 16.000 notificações (razão até 6)", new String[] {"tempo absoluto e outros processamentos quadráticos"})),
        Map.entry("PERF-013", new Item("completar uma ordem não repete a leitura da mesma tabela com os mesmos argumentos sem escrita entre as duas", new String[] {"leituras repetidas em outras rotas"})),
        Map.entry("ARCH-001", new Item("nenhuma classe (vista com o que herda) tem grupos de métodos desconectados com trabalho de verdade nem depende de muitas classes de acesso a dados; linhas e quantidade de métodos são só informação", new String[] {"se o que sobra junto numa classe coesa é mesmo uma só responsabilidade", "a qualidade da divisão escolhida"})),
        Map.entry("ARCH-002", new Item("nenhuma classe da camada HTTP (os pacotes das classes que usam o servidor HTTP) referencia a API JDBC", new String[] {"se a lógica movida ficou numa camada adequada"})),
        Map.entry("ARCH-004", new Item("a regra de destinatários não está escrita em mais de uma classe", new String[] {"duplicação com texto diferente (outro alias, outra ordem de colunas)"})),
        Map.entry("ARCH-008", new Item("não há ciclo entre pacotes (a raiz de composição fica de fora) e há pelo menos 9 pacotes", new String[] {"se a dependência foi realmente desfeita ou só deslocada"})),
        Map.entry("ARCH-009", new Item("poucos números sem nome fora da camada HTTP e da raiz; qualquer nome de constante serve", new String[] {"se os nomes escolhidos dizem o que o número significa (rubrica do juiz)"})),
        Map.entry("ARCH-011", new Item("a classe que escreve o cabeçalho do CSV não referencia a API JDBC", new String[] {"se a consulta ficou em lugar adequado"})),
        Map.entry("ARCH-013", new Item("duas instâncias da aplicação na mesma JVM, com bancos diferentes, continuam independentes", new String[] {"outros estados estáticos compartilhados"})),
        Map.entry("ARCH-014.a", new Item("o tempo de validade do token e o intervalo dos processamentos seguem a configuração", new String[] {"outras configurações fixas no código"})),
        Map.entry("CLN-001", new Item("nenhum método passa de 40 linhas úteis nem de 40 instruções", new String[] {"se a divisão em passos é legível"})),
        Map.entry("CLN-002", new Item("nenhum corpo de método está copiado em outro, mesmo com nomes trocados", new String[] {"cópias parciais ou de trechos menores que um método"})),
        Map.entry("CLN-005", new Item("nenhuma linha de comentário, de linha ou de bloco, parece código", new String[] {"código morto que não está em comentário"})),
        Map.entry("CLN-007", new Item("nenhuma sequência de decisões aninhadas passa de 4 níveis", new String[] {"laços aninhados e a legibilidade do fluxo principal"}))
    );

    static Item de(String id) {
        return DE.get(id);
    }
}
