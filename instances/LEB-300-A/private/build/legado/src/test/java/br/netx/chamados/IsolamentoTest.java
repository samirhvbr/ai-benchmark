package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotEquals;

/** Quem não é da plataforma só enxerga a própria empresa, e os papéis valem como a tabela do manifesto. */
class IsolamentoTest extends TestBase {

    @Test
    void outraEmpresaNaoEnxergaOChamadoNemNaListaNemNoDetalheNemParaAlterar() throws Exception {
        long meu = abrirChamado(token(ATENDENTE_A), cliA, "da empresa A", "NORMAL");
        String b = token(ADMIN_B);
        assertEquals(0, get("/chamados", b).j("total").asInt());
        assertEquals(404, get("/chamados/" + meu, b).status());
        assertEquals(404, patch("/chamados/" + meu, b, Map.of("titulo", "invadido")).status());
        assertEquals(404, post("/chamados/" + meu + "/transicoes", b, Map.of("para", "EM_ATENDIMENTO")).status());
        assertEquals(404, post("/chamados/" + meu + "/eventos", b, Map.of("detalhe", "intruso")).status());
        assertEquals("da empresa A", get("/chamados/" + meu, token(ATENDENTE_A)).j("titulo").asText());
    }

    @Test
    void erroDeOutraEmpresaEIgualAoDeRecursoInexistente() throws Exception {
        long meu = abrirChamado(token(ATENDENTE_A), cliA, "x", "NORMAL");
        String b = token(ADMIN_B);
        R alheio = get("/chamados/" + meu, b);
        R inexistente = get("/chamados/99999999", b);
        assertEquals(inexistente.status(), alheio.status());
        assertEquals(inexistente.bruto(), alheio.bruto());
    }

    @Test
    void ordensClientesETecnicosTambemSaoDaEmpresa() throws Exception {
        String atendenteA = token(ATENDENTE_A);
        long chamado = abrirChamado(atendenteA, cliA, "com ordem", "NORMAL");
        long ordem = criarOrdem(atendenteA, chamado, tecA);
        String b = token(ADMIN_B);
        assertEquals(404, get("/ordens/" + ordem, b).status());
        assertEquals(0, get("/ordens", b).j("total").asInt());
        assertEquals(404, get("/clientes/" + cliA, b).status());
        assertEquals(0, get("/tecnicos", b).j("total").asInt());
        assertEquals(404, patch("/usuarios/" + atdA, b, Map.of("nome", "invadido")).status());
        assertEquals(0, get("/usuarios", b).j("itens").findValuesAsText("email").stream().filter(e -> e.endsWith("@a.test")).count());
        assertEquals(1, get("/clientes", b).j("total").asInt(), "só o cliente da própria empresa");
    }

    @Test
    void aPlataformaLeDeQualquerEmpresaMasNaoEscreve() throws Exception {
        long meu = abrirChamado(token(ATENDENTE_A), cliA, "visível à plataforma", "NORMAL");
        String p = token(PLATAFORMA);
        assertEquals(1, get("/chamados?empresa_id=" + empA, p).j("total").asInt());
        assertEquals(0, get("/chamados?empresa_id=" + empB, p).j("total").asInt());
        assertEquals(200, get("/chamados/" + meu, p).status());
        assertEquals(403, post("/chamados", p, Map.of("cliente_id", cliA, "titulo", "x")).status());
        assertEquals(403, patch("/chamados/" + meu, p, Map.of("titulo", "x")).status());
        assertEquals(403, post("/chamados/" + meu + "/transicoes", p, Map.of("para", "EM_ATENDIMENTO")).status());
        assertEquals(200, get("/usuarios?empresa_id=" + empA, p).status());
        assertEquals(403, post("/usuarios", p, Map.of("email", "z@a.test", "nome", "Z", "papel", "ADMIN", "senha", "uma-senha-longa")).status());
    }

    @Test
    void aPlataformaCriaEmpresasEOAdminSoAlteraAPropria() throws Exception {
        R criada = post("/empresas", token(PLATAFORMA), Map.of("nome", "Gama", "fuso_horario", "America/Manaus", "expediente_inicio", "07:30", "expediente_fim", "17:30"));
        assertEquals(201, criada.status());
        assertEquals("America/Manaus", criada.j("fuso_horario").asText());
        assertEquals("07:30", criada.j("expediente_inicio").asText());
        assertEquals(422, post("/empresas", token(PLATAFORMA), Map.of("nome", "Ruim", "fuso_horario", "Marte/Olimpo", "expediente_inicio", "08:00", "expediente_fim", "18:00")).status());
        assertEquals(422, post("/empresas", token(PLATAFORMA), Map.of("nome", "Ruim", "fuso_horario", "UTC", "expediente_inicio", "18:00", "expediente_fim", "08:00")).status());
        assertEquals(403, post("/empresas", token(ADMIN_A), Map.of("nome", "X", "fuso_horario", "UTC", "expediente_inicio", "08:00", "expediente_fim", "18:00")).status());

        String admin = token(ADMIN_A);
        assertEquals(200, chamar("PUT", "/empresas/" + empA, admin, Map.of("nome", "Alfa Telecom")).status());
        assertEquals("Alfa Telecom", get("/empresas/" + empA, admin).j("nome").asText());
        assertEquals(404, chamar("PUT", "/empresas/" + empB, admin, Map.of("nome", "invadida")).status());
        assertEquals(403, chamar("PUT", "/empresas/" + empA, admin, Map.of("ativa", false)).status(), "só a plataforma ativa e desativa");
        assertEquals(1, get("/empresas", admin).j("total").asInt());
        assertNotEquals(1, get("/empresas", token(PLATAFORMA)).j("total").asInt());
    }

    @Test
    void cadaPapelFazSoOQueATabelaPermite() throws Exception {
        String admin = token(ADMIN_A);
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        assertEquals(403, get("/usuarios", atendente).status());
        assertEquals(403, post("/usuarios", atendente, Map.of("email", "y@a.test", "nome", "Y", "papel", "ADMIN", "senha", "uma-senha-longa")).status());
        assertEquals(403, post("/tecnicos", atendente, Map.of("usuario_id", tecUsuarioA, "nome", "Z")).status());
        assertEquals(200, get("/tecnicos", atendente).status());
        assertEquals(403, get("/clientes", tecnico).status());
        assertEquals(403, post("/clientes", tecnico, Map.of("nome", "Novo")).status());
        assertEquals(201, post("/clientes", atendente, Map.of("nome", "Cliente Novo", "email", "novo@cliente.test")).status());
        assertEquals(200, chamar("PUT", "/clientes/" + cliA, atendente, Map.of("telefone", "11 99999-0000")).status());
        assertEquals("11 99999-0000", get("/clientes/" + cliA, admin).j("telefone").asText());
        assertEquals("Cliente A", get("/clientes/" + cliA, admin).j("nome").asText(), "PUT parcial mantém o resto");
    }

    @Test
    void detalheDeUsuarioETecnicoSoDaPropriaEmpresa() throws Exception {
        String admin = token(ADMIN_A);
        assertEquals(ATENDENTE_A, get("/usuarios/" + atdA, admin).j("email").asText());
        assertEquals(404, get("/usuarios/" + atdA, token(ADMIN_B)).status());
        assertEquals(403, get("/usuarios/" + atdA, token(ATENDENTE_A)).status());
        assertEquals(200, get("/usuarios/" + atdA, token(PLATAFORMA)).status());
        assertEquals("Tecnico A", get("/tecnicos/" + tecA, token(ATENDENTE_A)).j("nome").asText());
        assertEquals(404, get("/tecnicos/" + tecA, token(ADMIN_B)).status());
        assertEquals(403, get("/tecnicos/" + tecA, token(TECNICO_A)).status());
    }

    @Test
    void oTecnicoSoVeOClienteDeUmChamadoAtribuido() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        assertEquals(404, get("/clientes/" + cliA, tecnico).status());
        long chamado = abrirChamado(atendente, cliA, "atribuído", "NORMAL");
        patch("/chamados/" + chamado, atendente, Map.of("tecnico_id", tecA));
        assertEquals(200, get("/clientes/" + cliA, tecnico).status());
    }

    @Test
    void tecnicoPrecisaDeUmUsuarioTecnicoDaMesmaEmpresa() throws Exception {
        String admin = token(ADMIN_A);
        assertEquals(422, post("/tecnicos", admin, Map.of("usuario_id", atdA, "nome", "Atendente como técnico")).status());
        assertEquals(422, post("/tecnicos", admin, Map.of("usuario_id", admB, "nome", "De outra empresa")).status());
        long novo = usuario(empA, "novotec@a.test", "TECNICO");
        R r = post("/tecnicos", admin, Map.of("usuario_id", novo, "nome", "Novo Técnico"));
        assertEquals(201, r.status());
        assertEquals(200, chamar("PUT", "/tecnicos/" + r.j("id").asLong(), admin, Map.of("ativo", false)).status());
    }
}
