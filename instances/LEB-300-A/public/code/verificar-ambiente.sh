#!/usr/bin/env bash
# Confere o ambiente de trabalho sem usar a rede. Sai com 0 se tudo estiver certo e com 1 se algo faltar.
set -u
falhas=0
ok()  { echo "ok     $*"; }
erro() { echo "FALTA  $*"; falhas=$((falhas + 1)); }

versao=$(java -version 2>&1 | head -1)
case "$versao" in *'"21'*) ok "JDK 21 ($versao)";; *) erro "JDK 21 (encontrado: ${versao:-nenhum})";; esac

if command -v mvn >/dev/null 2>&1; then ok "Maven ($(mvn -v 2>/dev/null | head -1))"; else erro "Maven 3.9.x"; fi

url="${LEB_DB_URL:-jdbc:postgresql://127.0.0.1:5432/leb_dev}"
hostport=$(echo "$url" | sed -E 's#^jdbc:postgresql://([^/?]+).*#\1#')
host="${hostport%%:*}"; porta="${hostport##*:}"; [ "$porta" = "$hostport" ] && porta=5432
if (exec 3<>"/dev/tcp/$host/$porta") 2>/dev/null; then ok "PostgreSQL acessível em $host:$porta"; else erro "PostgreSQL em $host:$porta (variável LEB_DB_URL)"; fi

if [ -f pom.xml ] && command -v mvn >/dev/null 2>&1; then
  if mvn -o -q -DskipTests compile >/dev/null 2>&1; then ok "repositório Maven local completo (compilação offline)"; else erro "repositório Maven local incompleto: rode 'mvn -o -DskipTests compile' para ver o que falta"; fi
fi

[ "$falhas" -eq 0 ] && echo "ambiente pronto" || echo "$falhas item(ns) a resolver"
exit $((falhas > 0))
