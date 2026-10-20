"""Real local HTTPS transport tests, not a substitute for stock Wings acceptance."""
import http.server
import json
import os
import pathlib
import ssl
import subprocess
import tempfile
import threading
import time

root = pathlib.Path(__file__).resolve().parent
requests = []

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        requests.append((self.path, self.headers.get('Authorization')))
        assert self.path == '/api/servers'
        assert self.headers.get('Authorization') == 'Bearer disposable-fixture-token'
        kind = self.server.kind
        if kind == 3:
            time.sleep(2)
        self.send_response(302 if kind == 2 else 401 if kind == 4 else 200)
        if kind == 2:
            self.send_header('Location', 'https://fixture.test:1/forbidden')
        self.end_headers()
        try:
            self.wfile.write(b'x' * (2097152 + 1) if kind == 1 else b'[]')
        except (OSError, ssl.SSLError):
            pass
    def log_message(self, *_):
        pass

with tempfile.TemporaryDirectory(prefix='ibyte-transport-') as directory:
    cert, key = pathlib.Path(directory) / 'cert.pem', pathlib.Path(directory) / 'key.pem'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                    '-subj', '/CN=fixture.test', '-addext', 'subjectAltName=DNS:fixture.test',
                    '-keyout', str(key), '-out', str(cert)], check=True, capture_output=True)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    servers = []
    for kind in range(6):
        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        server.kind = kind
        server.socket = context.wrap_socket(server.socket, server_side=True)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        servers.append(server)
    try:
        # Point fixture.test (and wrong.fixture.test) at the local servers for the
        # PHP client; ".test" names do not resolve in a disposable environment.
        resolve_map = ','.join(
            f'fixture.test:{server.server_port}=>127.0.0.1' for server in servers)
        wrong_map = f'wrong.fixture.test:{servers[5].server_port}=>127.0.0.1'
        environment = {
            **os.environ,
            'IBYTE_PROMETHEUS_RESOLVE': f'{resolve_map},{wrong_map}',
        }
        subprocess.run(['php', '-d', f'curl.cainfo={cert}',
                        str(root / 'transport.php'),
                        json.dumps([s.server_port for s in servers])],
                       check=True, env=environment)
        assert len(requests) == 5, requests
        print('PASS exactly five GET requests; failed TLS and changed host sent no HTTP request')
    finally:
        for server in servers:
            server.shutdown()
