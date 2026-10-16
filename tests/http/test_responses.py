"""Real Guzzle transport checks; all headers, request bodies and response secrets are synthetic."""
import http.server
import json
from pathlib import Path
import subprocess
import threading
from urllib.parse import parse_qs, urlsplit
import unittest


class ResponsesTest(unittest.TestCase):
    def test_status_and_diagnostics(self):
        received = []
        redirected = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                received.append((self.path, self.headers.get('Authorization'), body))
                procedure = self.path.rsplit('/', 1)[-1]
                case = self.headers.get('X-Fixture-Case', '')
                if case.startswith('resource-'):
                    variant = case.removeprefix('resource-')
                    record = {'id': '22' if variant == 'foreign' else '21', 'type': 'resource',
                        'attributes': {'name': 'fixture.test', 'command': 'fixture', 'status': 'installed', 'active': True}}
                    envelope = {'data': record}
                    if variant == 'fraction': record['id'] = 21.5
                    elif variant == 'shadow': record['attributes']['id'] = 22
                    elif variant == 'errors': envelope['errors'] = [self.headers.get('Authorization', '')]
                    elif variant == 'missing': envelope = {}
                    elif variant == 'list': envelope['data'] = []
                    elif variant == 'null': envelope['data'] = None
                    status, payload = 200, json.dumps(envelope).encode()
                elif case.startswith('raw-id-'):
                    value = case.removeprefix('raw-id-')
                    identities = {'fraction': 21.5, 'boolean': True, 'junk': '21junk', 'exponent': '2.1e1',
                        'padded': ' 21', 'leading-zero': '021', 'null': None, 'valid-int': 21, 'valid-string': '21'}
                    attributes = {'name': 'fixture', 'command': 'fixture'}
                    if value in ['shadow', 'shadow-fraction', 'valid-same']:
                        attributes['id'] = {'shadow': 22, 'shadow-fraction': 21.5, 'valid-same': '21'}[value]
                    identity = identities.get(value, '21')
                    status, payload = 200, json.dumps({'data': [{'id': identity, 'type': 'resource', 'attributes': attributes}],
                        'meta': {'next_cursor': None}}).encode()
                elif case in ['duplicate-id', 'duplicate-site']:
                    cursor = parse_qs(urlsplit(self.path).query).get('page[cursor]', [None])[0]
                    if case == 'duplicate-id':
                        data = [{'id': '1', 'type': 'resource', 'attributes': {'name': 'fixture-' + str(cursor), 'command': 'fixture'}}]
                        meta = {'next_cursor': 'first' if cursor is None else None}
                    else:
                        data = [{'id': str(i + 1), 'type': 'sites', 'attributes': {'name': name}}
                            for i, name in enumerate(['fixture.test', 'FIXTURE.test'])]
                        meta = {'next_cursor': None}
                    status, payload = 200, json.dumps({'data': data, 'meta': meta}).encode()
                elif case in ['cursor-self', 'cursor-cycle', 'cursor-valid']:
                    cursor = parse_qs(urlsplit(self.path).query).get('page[cursor]', [None])[0]
                    index = {None: 0, 'first': 1, 'second': 2}[cursor]
                    chain = {'cursor-self': ['first', 'first'], 'cursor-cycle': ['first', 'second', 'first'],
                        'cursor-valid': ['first', 'second', None]}[case]
                    payload = json.dumps({'data': [{'id': str(index + 1), 'type': 'resource',
                        'attributes': {'name': 'fixture-' + str(index), 'command': 'fixture-' + str(index)}}],
                        'meta': {'next_cursor': chain[index]}}).encode()
                    status = 200
                elif self.path.startswith('/orgs/org/servers/7/sites'):
                    case = self.headers['X-Fixture-Case']
                    secret = self.headers.get('Authorization', '')
                    shapes = {'object': {'data': {}}, 'numeric-object': {'data': {'0': {'id': '1'}}},
                        'array-record': {'data': [[]]}, 'errors': {'data': [], 'errors': [secret]},
                        'error': {'data': [], 'error': secret}, 'false': {'data': [], 'success': False},
                        'meta-array': {'data': [], 'meta': []}, 'cursor-number': {'data': [], 'meta': {'next_cursor': 42}},
                        'cursor-empty': {'data': [], 'meta': {'next_cursor': ''}},
                        'valid': {'data': [], 'meta': {'next_cursor': None}}, 'valid-no-meta': {'data': []}}
                    status, payload = 200, json.dumps(shapes[case]).encode()
                elif procedure.startswith('status-'):
                    _, status, format_ = procedure.split('-')
                    status = int(status)
                    echoed = self.headers.get('Authorization', '') + body.decode() + 'synthetic-error-secret'
                    payload = json.dumps({'json': {'accepted': True, 'message': echoed}}).encode() if format_ == 'json' else echoed.encode()
                elif procedure.startswith('success-'):
                    status = 200
                    payload = json.dumps({'json': {'accepted': True}} if procedure.endswith('direct') else {'result': {'data': {'json': {'accepted': True}}}}).encode()
                else:
                    redirected.append(self.path)
                    status, payload = 200, b'{}'
                self.send_response(status)
                if 300 <= status < 400:
                    self.send_header('Location', '/collect')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            do_GET = do_POST

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-responses.php')), f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(redirected, [])
                self.assertEqual(len(received), 211)
                self.assertTrue(all(auth == 'Bearer synthetic-authorization-secret' for _, auth, _ in received))
                self.assertEqual(sum(b'synthetic-body-secret' in body for _, _, body in received), 26)
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
