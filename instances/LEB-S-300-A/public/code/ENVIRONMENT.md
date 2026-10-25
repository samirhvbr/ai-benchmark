# Working environment

## What must be present

| Item | Version | How to check |
| --- | --- | --- |
| JDK | 21 | `java -version` |
| Maven | 3.9.x | `mvn -v` |
| PostgreSQL | 16 or newer, reachable over TCP | `bash check-environment.sh` |
| Local Maven repository | with every dependency of the project already downloaded | `mvn -o -q -DskipTests compile` |
| `tar` | any | `tar --version` |

The local Maven repository already holds every dependency of the project. Run Maven with `-o` (offline): that is how the
evaluator builds your delivery, with the original `pom.xml` and no network, so a new dependency would not exist there. If a
dependency is missing, the command fails and says which; do not add dependencies to `pom.xml`.

The evaluator runs your delivery on PostgreSQL 16. The server on this machine may be newer: do not use features that only exist
after 16.

## Database

The tests reach PostgreSQL through these environment variables:

| Variable | Default |
| --- | --- |
| `LEB_DB_URL` | `jdbc:postgresql://127.0.0.1:5432/leb_dev` |
| `LEB_DB_USER` | `leb_dev` |
| `LEB_DB_PASSWORD` | `leb_dev_pw` |

The database role must be able to create and drop schemas: each test creates its own, applies `schema.sql` and then, in name
order, the files of `migrations/`, and drops it at the end. The tests touch no other schema.

## Build and test

```sh
bash check-environment.sh      # checks the JDK, Maven, the database and the local repository
mvn -o test                    # builds and runs the public tests (src/test)
```

## What the evaluation takes from your delivery

The evaluator builds and runs the delivery with the **original** `pom.xml`, `schema.sql` and public tests. Only the contents of
`src/main` and the files of `migrations/` count; changes to `pom.xml`, `schema.sql` and `src/test` do not.

- Migrations: `migrations/NNN_description.sql`, applied in name order after `schema.sql`, on a new database. Only the additive
  changes that the manifest allows (section 6).
- If you add tests, put them in `src/test`: they serve your work and are not evaluated.
