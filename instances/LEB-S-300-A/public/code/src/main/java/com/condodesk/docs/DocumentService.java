package com.condodesk.docs;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.DocumentKind;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Model.StoredDocument;
import com.condodesk.domain.Principal;
import com.condodesk.reports.Csv;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.ContentRepository;
import com.condodesk.repo.FinanceRepository;

import java.io.IOException;
import java.io.UncheckedIOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardOpenOption;
import java.time.Clock;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.UUID;
import java.util.regex.Pattern;

/**
 * Documents of the buildings: uploads checked by content, kept under generated names outside any served path, downloads by id
 * for whoever may see them, fetches from approved sites, and the archive of a building.
 */
public final class DocumentService {
    private static final Map<String, String> TYPES = Map.of("pdf", "application/pdf", "png", "image/png", "jpg", "image/jpeg", "jpeg", "image/jpeg");
    private static final java.util.Set<String> BLOCKED = java.util.Set.of("php", "jsp", "exe", "sh", "bat", "js");
    private static final Pattern LABEL = Pattern.compile("[A-Za-z0-9._-]{1,40}");
    private static final int MAX_NAME = 120;

    public record Download(StoredDocument document, byte[] bytes) {}

    public record Archive(String fileName, byte[] bytes) {}

    private final Db db;
    private final BuildingRepository buildings;
    private final ContentRepository content;
    private final FinanceRepository finance;
    private final AuditLog audit;
    private final Clock clock;
    private final Path root;
    private final int maxBytes;
    private final UrlFetcher fetcher;
    private final Archiver archiver;

    public DocumentService(Db db, BuildingRepository buildings, ContentRepository content, FinanceRepository finance, AuditLog audit,
                           Clock clock, Path storageDir, int maxBytes, UrlFetcher fetcher, Archiver archiver) {
        this.db = db;
        this.buildings = buildings;
        this.content = content;
        this.finance = finance;
        this.audit = audit;
        this.clock = clock;
        this.root = storageDir.toAbsolutePath().normalize();
        this.maxBytes = maxBytes;
        this.fetcher = fetcher;
        this.archiver = archiver;
    }

    /** A document of the building as a whole, uploaded by its manager. Payment proofs go through {@link #uploadProof}. */
    public StoredDocument upload(Principal p, long buildingId, DocumentKind kind, String fileName, byte[] data) {
        p.requireManager(buildingId);
        if (kind == DocumentKind.PROOF) throw AppException.invalid("Payment proofs are attached to an invoice.");
        return store(p, buildingId, null, kind, fileName, data, null);
    }

    /** A payment proof a resident (or the manager) attaches to an invoice of their unit. */
    public StoredDocument uploadProof(Principal p, long invoiceId, String fileName, byte[] data) {
        Invoice i = db.read(c -> finance.invoice(c, invoiceId)).filter(x -> p.canSeeUnit(x.buildingId(), x.unitId()))
                .orElseThrow(AppException::notFound);
        return store(p, i.buildingId(), i.unitId(), DocumentKind.PROOF, fileName, data, null);
    }

    public StoredDocument fetch(Principal p, long buildingId, DocumentKind kind, String url) {
        p.requireManager(buildingId);
        if (kind == DocumentKind.PROOF) throw AppException.invalid("Payment proofs are attached to an invoice.");
        UrlFetcher.Fetched f = fetcher.fetch(url);
        return store(p, buildingId, null, kind, f.fileName(), f.body(), url);
    }

    public List<StoredDocument> list(Principal p, long buildingId) {
        if (!p.belongsTo(buildingId)) throw AppException.notFound();
        return db.read(c -> content.documents(c, buildingId, p.manages(buildingId) ? null : p.unitIds()));
    }

    public Download download(Principal p, long id) {
        StoredDocument d = db.read(c -> content.document(c, id)).orElseThrow(AppException::notFound);
        try {
            return new Download(d, Files.readAllBytes(pathOf(d.buildingId(), d.storedName())));
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
    }

    /** The old viewer's link: a stored file by its name, in the building's folder. */
    public byte[] raw(Principal p, long buildingId, String fileName) {
        if (!p.belongsTo(buildingId)) throw AppException.notFound();
        if (fileName == null) throw AppException.invalid("file is required.");
        try {
            return Files.readAllBytes(root.resolve(String.valueOf(buildingId)).resolve(fileName));
        } catch (IOException e) {
            throw AppException.notFound();
        }
    }

    /** Every document of a building in one tar.gz, with an index of their original names. */
    public Archive archive(Principal p, long buildingId, String label) {
        p.requireManager(buildingId);
        Building b = db.read(c -> buildings.byId(c, buildingId)).orElseThrow(AppException::notFound);
        String name = label == null ? "documents-" + b.dateAt(clock.instant()) : label;
        List<StoredDocument> docs = db.read(c -> content.documents(c, buildingId, null));
        StringBuilder index = new StringBuilder(Csv.row(List.of("file", "name", "kind", "created_at")));
        List<String> files = new ArrayList<>();
        for (StoredDocument d : docs) {
            files.add(d.storedName());
            index.append(Csv.row(List.of(d.storedName(), d.originalName(), d.kind().name(), d.createdAt().toString())));
        }
        try {
            Path dir = Files.createDirectories(root.resolve(String.valueOf(buildingId)));
            byte[] bytes = archiver.tarGz(dir, files, index.toString(), name);
            db.tx(c -> {
                audit.record(c, p.userId(), "archive", b.name() + " " + docs.size() + " documents", clock.instant());
                return null;
            });
            return new Archive(name + ".tar.gz", bytes);
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
    }

    private StoredDocument store(Principal p, long buildingId, Long unitId, DocumentKind kind, String fileName, byte[] data, String sourceUrl) {
        if (data.length == 0) throw AppException.invalid("The file is empty.");
        if (data.length > maxBytes) throw AppException.invalid("The file is larger than " + (maxBytes / (1024 * 1024)) + " MB.");
        String name = cleanName(fileName);
        String ext = name.contains(".") ? name.substring(name.lastIndexOf('.') + 1).toLowerCase(Locale.ROOT) : "";
        String sniffed = sniff(data);
        if (BLOCKED.contains(ext) || !ext.matches("[a-z0-9]{0,5}")) throw AppException.unsupported("That kind of file is not accepted.");
        String type = sniffed != null ? sniffed : ext.equals("html") || ext.equals("htm") ? "text/html" : ext.equals("svg") ? "image/svg+xml" : "application/octet-stream";
        String stored = UUID.randomUUID() + "." + ext;
        Path path = pathOf(buildingId, stored);
        try {
            Files.createDirectories(path.getParent());
            Files.write(path, data, StandardOpenOption.CREATE_NEW);
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
        try {
            return db.tx(c -> {
                long id = content.insertDocument(c, buildingId, unitId, kind, name, stored, type, data.length, sourceUrl, p.userId(), clock.instant());
                audit.record(c, p.userId(), "document", kind + " " + name, clock.instant());
                return content.document(c, id).orElseThrow();
            });
        } catch (RuntimeException e) {
            try {
                Files.deleteIfExists(path);
            } catch (IOException suppressed) {
                e.addSuppressed(suppressed);
            }
            throw e;
        }
    }

    /** The file of a stored name, which must stay inside the storage root. */
    private Path pathOf(long buildingId, String storedName) {
        Path p = root.resolve(String.valueOf(buildingId)).resolve(storedName).normalize();
        if (!p.startsWith(root.resolve(String.valueOf(buildingId)))) throw AppException.notFound();
        return p;
    }

    /** The file name without any directory part or control character, at most 120 characters. */
    static String cleanName(String fileName) {
        String n = fileName == null ? "" : fileName;
        n = n.substring(Math.max(n.lastIndexOf('/'), n.lastIndexOf('\\')) + 1).replaceAll("\\p{Cntrl}", "").trim();
        if (n.length() > MAX_NAME) n = n.substring(n.length() - MAX_NAME);
        return n.isEmpty() ? "document" : n;
    }

    /** The type the content shows, from its first bytes. */
    static String sniff(byte[] d) {
        if (d.length >= 5 && d[0] == '%' && d[1] == 'P' && d[2] == 'D' && d[3] == 'F' && d[4] == '-') return "application/pdf";
        if (d.length >= 8 && (d[0] & 0xff) == 0x89 && d[1] == 'P' && d[2] == 'N' && d[3] == 'G' && d[4] == 0x0d && d[5] == 0x0a
                && d[6] == 0x1a && d[7] == 0x0a) return "image/png";
        if (d.length >= 3 && (d[0] & 0xff) == 0xff && (d[1] & 0xff) == 0xd8 && (d[2] & 0xff) == 0xff) return "image/jpeg";
        return null;
    }
}
