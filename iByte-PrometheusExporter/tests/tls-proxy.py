"""TLS terminator for disposable native Panel acceptance only."""
import http.client
import http.server
import os
import ssl
assert os.environ.get('WSL_DISTRO_NAME') == 'iBytePrometheusAcceptance'

class Proxy(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        connection = http.client.HTTPConnection('127.0.0.1', 8090, timeout=10)
        headers = dict(self.headers)
        headers['X-Forwarded-Proto'] = 'https'
        headers['X-Forwarded-Port'] = '8443'
        headers['Connection'] = 'close'
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        connection.request(self.command, self.path, body=body, headers=headers)
        response = connection.getresponse()
        data = response.read()
        self.send_response(response.status)
        for key, value in response.getheaders():
            if key.lower() not in ('transfer-encoding', 'connection', 'content-length'):
                self.send_header(key, value)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)
        connection.close()
    do_POST = do_GET
    def log_message(self, *_):
        pass

server = http.server.ThreadingHTTPServer(('0.0.0.0', 8443), Proxy)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/opt/ibyte-prometheus/tls.crt', '/opt/ibyte-prometheus/tls.key')
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
