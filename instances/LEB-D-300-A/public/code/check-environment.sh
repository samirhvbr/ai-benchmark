#!/usr/bin/env bash
# Checks the working environment without the network. Exits 0 when everything is in place and 1 when something is missing.
set -u
missing=0
ok()   { echo "ok       $*"; }
lack() { echo "MISSING  $*"; missing=$((missing + 1)); }

version=$(java -version 2>&1 | head -1)
case "$version" in *'"21'*) ok "JDK 21 ($version)";; *) lack "JDK 21 (found: ${version:-none})";; esac

if command -v mvn >/dev/null 2>&1; then ok "Maven ($(mvn -v 2>/dev/null | head -1))"; else lack "Maven 3.9.x"; fi

if command -v tar >/dev/null 2>&1; then ok "tar ($(tar --version 2>/dev/null | head -1))"; else lack "tar"; fi

url="${LEB_DB_URL:-jdbc:postgresql://127.0.0.1:5432/leb_dev}"
hostport=$(echo "$url" | sed -E 's#^jdbc:postgresql://([^/?]+).*#\1#')
host="${hostport%%:*}"; port="${hostport##*:}"; [ "$port" = "$hostport" ] && port=5432
if (exec 3<>"/dev/tcp/$host/$port") 2>/dev/null; then ok "PostgreSQL reachable at $host:$port"; else lack "PostgreSQL at $host:$port (variable LEB_DB_URL)"; fi

if [ -f pom.xml ] && command -v mvn >/dev/null 2>&1; then
  if mvn -o -q -DskipTests compile >/dev/null 2>&1; then ok "local Maven repository complete (offline build)"; else lack "local Maven repository incomplete: run 'mvn -o -DskipTests compile' to see what is missing"; fi
fi

[ "$missing" -eq 0 ] && echo "environment ready" || echo "$missing item(s) to fix"
exit $((missing > 0))
