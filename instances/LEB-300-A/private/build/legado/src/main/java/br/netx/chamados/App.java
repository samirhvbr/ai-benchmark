package br.netx.chamados;

import br.netx.chamados.auth.Auth;
import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.http.CadastroHandlers;
import br.netx.chamados.http.ChamadoHandlers;
import br.netx.chamados.http.RelatorioHandlers;
import br.netx.chamados.http.Servidor;
import br.netx.chamados.job.DespachoJob;
import br.netx.chamados.job.EscalonamentoJob;
import br.netx.chamados.job.LancamentoJob;
import br.netx.chamados.relatorio.CsvExportador;
import br.netx.chamados.relatorio.RelatorioService;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.ChamadoRepo;
import br.netx.chamados.repo.IdempotenciaRepo;
import br.netx.chamados.repo.NotificacaoRepo;
import br.netx.chamados.repo.OrdemRepo;
import br.netx.chamados.servico.ChamadoService;

import javax.sql.DataSource;
import java.time.Clock;
import java.util.Objects;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/** Raiz de composição: monta o sistema com o DataSource e o Clock recebidos, sobe e derruba o servidor e o agendador. */
public final class App {
    private final Config config;
    private final Servidor servidor;
    private final JobRunner jobs;
    private final Db db;
    private final Auth auth;
    private final CadastroRepo cadastros;
    private ScheduledExecutorService agendador;

    private App(Config config, Servidor servidor, JobRunner jobs, Db db, Auth auth, CadastroRepo cadastros) {
        this.config = config;
        this.servidor = servidor;
        this.jobs = jobs;
        this.db = db;
        this.auth = auth;
        this.cadastros = cadastros;
    }

    public static App create(Config config, DataSource dataSource, Clock clock) {
        Objects.requireNonNull(config.notificacaoDir(), "notificacaoDir é obrigatório");
        Db db = new Db(dataSource);
        CadastroRepo cadastros = new CadastroRepo();
        ChamadoRepo chamadosRepo = new ChamadoRepo();
        OrdemRepo ordensRepo = new OrdemRepo();
        NotificacaoRepo notificacaoRepo = new NotificacaoRepo();
        Auth auth = new Auth(db, clock, config, cadastros);
        ChamadoService chamados = new ChamadoService(chamadosRepo, cadastros, ordensRepo, notificacaoRepo, new IdempotenciaRepo(), db, clock);
        RelatorioService relatorios = new RelatorioService(db, cadastros, chamadosRepo, ordensRepo, chamados, new CsvExportador(chamados), clock);

        Servidor servidor = new Servidor(auth);
        new CadastroHandlers(db, auth, cadastros).registrar(servidor);
        new ChamadoHandlers(db, chamados, chamados, chamados, chamados).registrar(servidor);
        new RelatorioHandlers(relatorios, db).registrar(servidor);

        JobRunner jobs = new JobRunner(new EscalonamentoJob(db, chamadosRepo, cadastros, chamados, chamados, clock),
                new DespachoJob(db, notificacaoRepo, config, clock), new LancamentoJob(db, ordensRepo, cadastros, clock));
        return new App(config, servidor, jobs, db, auth, cadastros);
    }

    /** Sobe o servidor e, se a configuração pedir, o agendador. Devolve a porta ligada. */
    public int start() {
        garantirContaDaPlataforma();
        int porta = servidor.iniciar(config.porta());
        if (config.agendadorAtivo()) {
            agendador = Executors.newSingleThreadScheduledExecutor();
            long intervalo = 60_000L;
            agendador.scheduleWithFixedDelay(() -> {
                try {
                    jobs.executarUmaVez();
                } catch (RuntimeException e) {
                    e.printStackTrace();
                }
            }, intervalo, intervalo, TimeUnit.MILLISECONDS);
        }
        return porta;
    }

    public void stop() {
        if (agendador != null) agendador.shutdownNow();
        servidor.parar();
    }

    /** Primeira instalação: cria a conta da plataforma para o primeiro acesso. */
    private void garantirContaDaPlataforma() {
        db.tx(tx -> {
            if (tx.count("select count(*) from usuario where papel = 'PLATAFORMA'") == 0) {
                cadastros.inserirUsuario(tx, null, "plataforma@netx.local", "Plataforma", Papel.PLATAFORMA, auth.hash("Netx@2013!"));
            }
            return null;
        });
    }

    public JobRunner jobs() {
        return jobs;
    }
}
