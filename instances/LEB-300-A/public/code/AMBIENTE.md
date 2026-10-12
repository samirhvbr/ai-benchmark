# Ambiente de trabalho

## O que precisa existir

| Item | Versão | Como conferir |
| --- | --- | --- |
| JDK | 21 | `java -version` |
| Maven | 3.9.x | `mvn -v` |
| PostgreSQL | 16 ou mais novo, acessível por TCP | `bash verificar-ambiente.sh` |
| Repositório Maven local | com todas as dependências já baixadas | `mvn -o -q -DskipTests compile` |

O repositório Maven local já tem todas as dependências do projeto. Rode o Maven com `-o` (modo offline): é assim que o avaliador compila a sua
entrega, só com o `pom.xml` original e sem rede, então uma dependência nova não existiria lá. Se uma dependência faltar, o comando falha e diz
qual; não acrescente dependências novas ao `pom.xml`.

O avaliador roda a sua entrega no PostgreSQL 16. Nesta máquina o servidor pode ser de uma versão mais nova: não use recursos que só existem
depois da 16.

## Banco de dados

Os testes se conectam ao PostgreSQL pelas variáveis de ambiente:

| Variável | Padrão |
| --- | --- |
| `LEB_DB_URL` | `jdbc:postgresql://127.0.0.1:5432/leb_dev` |
| `LEB_DB_USER` | `leb_dev` |
| `LEB_DB_PASSWORD` | `leb_dev_pw` |

O papel do banco precisa poder criar e apagar *schemas*: cada teste cria o seu, aplica `schema.sql` e depois, em ordem, os arquivos de
`migrations/`, e apaga tudo no fim. Os testes não tocam em nenhum outro schema.

## Compilar e testar

```sh
bash verificar-ambiente.sh      # confere JDK, Maven, banco e repositório local
mvn -o test                     # compila e roda os testes públicos (src/test)
```

## O que a avaliação usa da sua entrega

O avaliador compila e roda a entrega com o `pom.xml`, o `schema.sql` e os testes públicos **originais**. Só entram o conteúdo de
`src/main` e os arquivos de `migrations/`. Mudanças em `pom.xml`, em `schema.sql` e em `src/test` não contam.

- Migrações: `migrations/NNN_descricao.sql`, aplicadas em ordem, depois do `schema.sql`, em banco novo. Só as extensões aditivas
  permitidas no manifesto (seção 4).
- Se você acrescentar testes, coloque-os em `src/test`: eles servem para o seu trabalho, não são avaliados.
