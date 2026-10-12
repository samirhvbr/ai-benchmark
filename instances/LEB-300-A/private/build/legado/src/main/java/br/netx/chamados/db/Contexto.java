package br.netx.chamados.db;

import javax.sql.DataSource;

/** O DataSource da composição mais recente, para quem acessa o banco sem recebê-lo. */
final class Contexto {
    private static volatile DataSource dataSource;

    private Contexto() {}

    static void definir(DataSource ds) {
        dataSource = ds;
    }

    static DataSource dataSource() {
        return dataSource;
    }
}
