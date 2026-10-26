"""Testes HTTP com a biblioteca padrão. Exige banco descartável já semeado."""
import csv
import html
import http.cookiejar
import io
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

checks = 0

def verify(ok, message):
    global checks
    if not ok:
        raise AssertionError(message)
    checks += 1

with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
base = f'http://127.0.0.1:{port}/index.php'

def client():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)), jar

def request(opener, query='', post=None, headers=None):
    data = urllib.parse.urlencode(post).encode() if post is not None else None
    req = urllib.request.Request(base + query, data=data, headers=headers or {})
    with opener.open(req, timeout=5) as response:
        return response.read().decode('utf-8'), response.headers

def ids(body):
    return list(map(int, re.findall(r'<a href="index.php\?ver=(\d+)">', body)))

with tempfile.TemporaryDirectory(prefix='leb-http-') as temp:
    with open(Path(temp) / 'server.log', 'w+') as log:
        server = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'error_reporting=-1',
                                   '-d', f'session.save_path={temp}', '-S', f'127.0.0.1:{port}',
                                   '-t', str(Path(__file__).resolve().parent.parent / 'code')],
                                  stdout=log, stderr=log)
        try:
            guest, jar = client()
            for attempt in range(100):
                try:
                    body, headers = request(guest)
                    break
                except urllib.error.URLError:
                    if server.poll() is not None:
                        raise RuntimeError('Servidor encerrou durante inicialização')
                    time.sleep(0.05)
            else:
                raise RuntimeError('Servidor não iniciou')
            verify('name="login"' in body, 'Login antes da listagem')
            cookie = headers.get('Set-Cookie', '')
            verify('HttpOnly' in cookie and 'SameSite=Lax' in cookie, 'Atributos do cookie')
            old_sid = next(c.value for c in jar if c.name == 'PHPSESSID')
            for route in ['?ver=101', '?export=csv', '?busca=Fatura']:
                body, _ = request(guest, route)
                verify('name="login"' in body, 'Rota exige autenticação')
            for post in [{'login[]': 'ana', 'senha': 'senha123'}, {'login': 'ana', 'senha[]': 'senha123'},
                         {'login': 'ana', 'senha': 'errada'}]:
                body, _ = request(guest, post=post)
                verify('name="login"' in body, 'Login malformado ou incorreto rejeitado')
            body, _ = request(guest, post={'login': 'ana', 'senha': 'senha123'})
            new_sid = next(c.value for c in jar if c.name == 'PHPSESSID')
            verify(new_sid != old_sid, 'ID de sessão renovado no login')
            stale = urllib.request.build_opener()
            body_old, _ = request(stale, headers={'Cookie': f'PHPSESSID={old_sid}'})
            verify('name="login"' in body_old, 'Sessão anterior não está autenticada')
            for login, password, expected, exported in [
                ('ana', 'senha123', [105,102,101], [101,102,105]),
                ('bruno', 'senha123', [104,103], [103,104]),
                ('carla', 'tecmaster', [105,104,103,102,101], [101,102,103,104,105]),
                ('diego', 'tecmaster', [105,104,103,102,101], [101,102,103,104,105])]:
                op, _ = client()
                body, _ = request(op, post={'login': login, 'senha': password})
                verify(ids(body) == expected, f'Visibilidade HTTP {login}')
                verify('id="tabela-chamados"' in body and
                       '<th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th>' in body,
                       'Estrutura HTML contratual')
                for ticket in range(101,106):
                    detail, _ = request(op, f'?ver={ticket}')
                    verify((f'<h1>Chamado #{ticket}</h1>' in detail) == (ticket in exported), 'Detalhe HTTP')
                data, headers = request(op, '?export=csv')
                rows = list(csv.reader(io.StringIO(data)))
                verify(rows[0] == ['ID','Titulo','Status','Tecnico','Aberto em'] and
                       [int(row[0]) for row in rows[1:]] == exported, 'CSV HTTP')
                verify(headers['Content-Type'] == 'text/csv; charset=utf-8' and
                       headers['Content-Disposition'] == 'attachment; filename="chamados.csv"', 'Headers CSV')
                payload = '\'"><script>alert(1)</script>&'
                body, _ = request(op, '?' + urllib.parse.urlencode({'busca': payload}))
                verify(payload not in body and html.escape(payload, quote=True).replace('&#x27;', '&#039;') in body,
                       'Busca escapada nos contextos HTML')
                body, _ = request(op, '?' + urllib.parse.urlencode({'busca': "%' OR 1=1 -- "}))
                verify(ids(body) == [], 'SQL injection HTTP sem resultados indevidos')
                body, _ = request(op, '?busca[]=x')
                verify(ids(body) == expected, 'Busca estruturada não gera TypeError')
                body, _ = request(op, '?ver[]=101')
                verify('Chamado nao encontrado.' in body, 'ID estruturado não é convertido em outro chamado')
        finally:
            server.terminate()
            server.wait(timeout=5)
        log.seek(0)
        diagnostics = log.read()
        verify(not any(word in diagnostics for word in ['Fatal error', 'Warning:', 'Deprecated:', 'Uncaught']),
               'Sem avisos ou falhas PHP nas requisições')
print(f'OK: {checks} verificações HTTP.')
