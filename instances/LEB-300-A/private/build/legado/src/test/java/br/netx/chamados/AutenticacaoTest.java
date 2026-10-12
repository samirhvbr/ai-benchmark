package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.time.Duration;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

class AutenticacaoTest extends TestBase {

    @Test
    void loginDevolveTokenEUsuario() throws Exception {
        R r = post("/auth/login", null, Map.of("email", ADMIN_A, "senha", SENHA));
        assertEquals(200, r.status());
        assertTrue(r.j("token").asText().length() >= 32);
        assertEquals("2026-03-04T20:00:00Z", r.j("expira_em").asText(), "8 horas depois do relógio");
        assertEquals(admA, r.j("usuario").get("id").asLong());
        assertEquals("ADMIN", r.j("usuario").get("papel").asText());
        assertEquals(empA, r.j("usuario").get("empresa_id").asLong());
    }

    @Test
    void usuarioDaPlataformaNaoTemEmpresa() throws Exception {
        R r = post("/auth/login", null, Map.of("email", PLATAFORMA, "senha", SENHA));
        assertEquals(200, r.status());
        assertTrue(r.j("usuario").get("empresa_id").isNull());
    }

    @Test
    void senhaErradaEEmailDesconhecidoDaoOMesmoErro() throws Exception {
        R errada = post("/auth/login", null, Map.of("email", ADMIN_A, "senha", "outra-senha"));
        R desconhecido = post("/auth/login", null, Map.of("email", "ninguem@a.test", "senha", SENHA));
        assertEquals(401, errada.status());
        assertEquals(401, desconhecido.status());
        assertEquals(errada.j("erro").get("codigo"), desconhecido.j("erro").get("codigo"));
    }

    @Test
    void usuarioInativoEEmpresaInativaNaoEntram() throws Exception {
        atualizar("update usuario set ativo = false where id = ?", atdA);
        assertEquals(401, post("/auth/login", null, Map.of("email", ATENDENTE_A, "senha", SENHA)).status());
        atualizar("update empresa set ativa = false where id = ?", empB);
        assertEquals(401, post("/auth/login", null, Map.of("email", ADMIN_B, "senha", SENHA)).status());
    }

    @Test
    void semTokenOuComTokenInvalidoE401() throws Exception {
        assertEquals(401, get("/chamados", null).status());
        assertEquals(401, get("/chamados", "token-que-nao-existe").status());
        assertEquals("nao_autenticado", get("/chamados", null).j("erro").get("codigo").asText());
    }

    @Test
    void oTokenExpiraDepoisDeOitoHoras() throws Exception {
        String token = token(ADMIN_A);
        relogio.avancar(Duration.ofHours(7).plusMinutes(59));
        assertEquals(200, get("/chamados", token).status());
        relogio.avancar(Duration.ofMinutes(2));
        assertEquals(401, get("/chamados", token).status());
    }

    @Test
    void logoutDevolve204() throws Exception {
        String token = token(ADMIN_A);
        assertEquals(204, post("/auth/logout", token, null).status());
    }

    @Test
    void oAdminCriaUmUsuarioQueEntraComASenhaDefinida() throws Exception {
        String admin = token(ADMIN_A);
        R criado = post("/usuarios", admin, Map.of("email", "novo@a.test", "nome", "Novo", "papel", "ATENDENTE", "senha", "uma-senha-longa"));
        assertEquals(201, criado.status());
        assertEquals("ATENDENTE", criado.j("papel").asText());
        assertNotNull(criado.j("id"));
        assertEquals(200, post("/auth/login", null, Map.of("email", "novo@a.test", "senha", "uma-senha-longa")).status());
        assertEquals(401, post("/auth/login", null, Map.of("email", "novo@a.test", "senha", "senha-errada-1")).status());
    }

    @Test
    void usuarioNovoExigeSenhaLongaEEmailInedito() throws Exception {
        String admin = token(ADMIN_A);
        assertEquals(422, post("/usuarios", admin, Map.of("email", "x@a.test", "nome", "X", "papel", "TECNICO", "senha", "curta")).status());
        assertEquals(409, post("/usuarios", admin, Map.of("email", ATENDENTE_A, "nome", "Repetido", "papel", "TECNICO", "senha", "uma-senha-longa")).status());
        assertEquals(422, post("/usuarios", admin, Map.of("email", "p@a.test", "nome", "P", "papel", "PLATAFORMA", "senha", "uma-senha-longa")).status());
    }
}
