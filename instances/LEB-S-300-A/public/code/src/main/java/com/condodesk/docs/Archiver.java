package com.condodesk.docs;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.concurrent.TimeUnit;
import java.util.stream.Stream;

/** Packs files into a tar.gz with the system's {@code tar}, run with an argument vector and no shell. */
public final class Archiver {
    private static final long TIMEOUT_SECONDS = 60;

    /**
     * Archives the named files of {@code dir} together with an {@code index.csv}. The names come after {@code --}, so none of
     * them can be read as an option.
     */
    public byte[] tarGz(Path dir, List<String> fileNames, String indexCsv) throws IOException {
        Path work = Files.createTempDirectory("condodesk-archive");
        try {
            Path out = work.resolve("archive.tar.gz");
            Files.writeString(work.resolve("index.csv"), indexCsv);
            List<String> cmd = new ArrayList<>(List.of("tar", "-czf", out.toString(), "-C", work.toString(), "index.csv", "-C", dir.toString(), "--"));
            cmd.addAll(fileNames);
            Process proc = new ProcessBuilder(cmd).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.DISCARD).start();
            try {
                if (!proc.waitFor(TIMEOUT_SECONDS, TimeUnit.SECONDS)) {
                    proc.destroyForcibly();
                    throw new IOException("tar did not finish in " + TIMEOUT_SECONDS + " seconds");
                }
            } catch (InterruptedException e) {
                proc.destroyForcibly();
                Thread.currentThread().interrupt();
                throw new IOException("interrupted", e);
            }
            if (proc.exitValue() != 0) throw new IOException("tar exited with " + proc.exitValue());
            return Files.readAllBytes(out);
        } finally {
            try (Stream<Path> files = Files.walk(work)) {
                for (Path p : files.sorted(Comparator.reverseOrder()).toList()) Files.deleteIfExists(p);
            }
        }
    }
}
