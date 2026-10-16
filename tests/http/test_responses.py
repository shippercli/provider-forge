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
        readback_environments = {}
        deployment_polls = {}
        partial = {'databases': [], 'environment': 'APP_ENV=production\n', 'analytics_attempts': 0}
        partial_events = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                received.append((self.path, self.headers.get('Authorization'), body))
                procedure = self.path.rsplit('/', 1)[-1]
                case = self.headers.get('X-Fixture-Case', '')
                if case.startswith('status-summary-'):
                    result = case.removeprefix('status-summary-')
                    record = {'id': '21', 'type': 'deploymentStatuses', 'attributes': {'status': 'finished', 'started_at': '2026-10-16T12:00:00Z', 'env': 'synthetic-status-secret'}, 'logs': 'synthetic-status-secret'}
                    envelope = {'data': record}
                    if result == 'null': record['attributes']['status'] = None
                    if result == 'missing': envelope = {}
                    if result == 'false': envelope['success'] = False
                    if result == 'unknown': record['attributes']['status'] = 'unknown'
                    if result == 'missing-time': record['attributes'].pop('started_at')
                    status, payload = 200, json.dumps(envelope).encode()
                elif case.startswith('deployment-result-'):
                    result = case.removeprefix('deployment-result-')
                    record = {'id': '21', 'type': 'deployments', 'attributes': {'status': 'queued'}}
                    if self.command == 'GET':
                        if not self.path.endswith('/deployments/21'):
                            raise AssertionError('Polling must retain the created deployment ID')
                        deployment_polls[result] = deployment_polls.get(result, 0) + 1
                        record['attributes']['status'] = 'deploying' if result == 'progress' and deployment_polls[result] == 1 else 'finished' if result in ['finished', 'progress', 'wrong-id'] else result
                        if result == 'wrong-id': record['id'] = '22'
                    status, payload = 200, json.dumps({'data': record}).encode()
                elif case.startswith('raw-env-'):
                    envelopes = {'missing': {}, 'attributes': {'data': {'attributes': {}}},
                        'null': {'data': {'attributes': {'content': None}}},
                        'number': {'data': {'attributes': {'content': 123}}},
                        'false': {'success': False, 'data': {'attributes': {'content': ''}}},
                        'errors': {'errors': ['synthetic-secret'], 'data': {'attributes': {'content': ''}}},
                        'valid': {'data': {'attributes': {'content': ''}}}}
                    status, payload = 200, json.dumps(envelopes[case.removeprefix('raw-env-')]).encode()
                elif case.startswith('readback-env-'):
                    if self.command == 'PUT':
                        if case == 'readback-env-valid':
                            readback_environments[case] = json.loads(body)['environment']
                        envelope = {}
                    else:
                        envelope = {'data': {'attributes': {'content': readback_environments.get(case, 'KEEP=retained\n')}}}
                    status, payload = 200, json.dumps(envelope).encode()
                elif case.startswith('malformed-env-'):
                    if self.command != 'GET':
                        raise AssertionError('Malformed environment must not be updated')
                    content = {'malformed-env-double': 'TOKEN="unfinished',
                        'malformed-env-single': "KEEP=retained\nBROKEN='unfinished",
                        'malformed-env-name': 'INVALID NAME=value\n'}[case]
                    status, payload = 200, json.dumps({'data': {'attributes': {'content': content}}}).encode()
                elif case == 'partial-recovery':
                    path = urlsplit(self.path).path
                    status = 200
                    if path.endswith('/servers/7'):
                        envelope = {'data': {'id': '7', 'type': 'servers', 'attributes': {'database_type': 'mysql84'}}}
                    elif path.endswith('/environment'):
                        if self.command == 'PUT':
                            partial['environment'] = json.loads(body)['environment']
                            partial_events.append('deliver')
                            envelope = {}
                        else:
                            envelope = {'data': {'attributes': {'content': partial['environment']}}}
                    elif self.command == 'POST':
                        request = json.loads(body)
                        name = request['name']
                        partial_events.append('create:' + name)
                        if name == 'analytics': partial['analytics_attempts'] += 1
                        if name == 'analytics' and partial['analytics_attempts'] == 1:
                            status, envelope = 500, {'message': 'synthetic second creation failure'}
                        else:
                            record = {'id': '21' if name == 'main' else '22', 'type': 'databases',
                                      'attributes': {'name': name, 'status': 'installed'}}
                            partial['databases'].append(record)
                            envelope = {'data': record}
                    elif path.endswith('/database/schemas'):
                        envelope = {'data': partial['databases'], 'meta': {'next_cursor': None}}
                    else:
                        identity = path.rsplit('/', 1)[-1]
                        envelope = {'data': next(row for row in partial['databases'] if row['id'] == identity)}
                    payload = json.dumps(envelope).encode()
                elif case.startswith('database-create-'):
                    variant = case.removeprefix('database-create-')
                    if self.command == 'GET' and urlsplit(self.path).path.endswith('/servers/7'):
                        engine = 'postgres18' if variant == 'engine-mismatch' else 'unknown' if variant == 'engine-unknown' else 'mysql84'
                        envelope = {'data': {'id': '7', 'type': 'servers', 'attributes': {'database_type': engine}}}
                    elif self.command == 'GET' and urlsplit(self.path).path.endswith('/database/schemas'):
                        envelope = {'data': [], 'meta': {'next_cursor': None}}
                    else:
                        if self.command == 'POST':
                            request = json.loads(body)
                            password = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@' if variant == 'valid-explicit' else 'synthetic-database-secret'
                            if request != {'name': 'main', 'user': 'application', 'password': password}:
                                raise AssertionError('Unexpected Forge database payload')
                        attributes = {'status': 'installed'}
                        if variant != 'missing-name': attributes['name'] = 'main' if variant in ['valid', 'valid-explicit'] else 'foreign'
                        envelope = {'data': {'id': '21', 'type': 'databases', 'attributes': attributes}}
                    status, payload = 200, json.dumps(envelope).encode()
                elif case.startswith('resource-'):
                    variant = case.removeprefix('resource-')
                    record = {'id': '22' if variant == 'foreign' else '21', 'type': 'resource',
                        'attributes': {'name': 'fixture.test', 'command': 'fixture', 'status': 'installed', 'active': True}}
                    if variant == 'wrong-domain': record['attributes']['name'] = 'other.test'
                    elif variant == 'domain-case': record['attributes']['name'] = 'FIXTURE.test'
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
            do_PUT = do_POST

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-responses.php')), f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(redirected, [])
                self.assertEqual(partial_events, ['create:main', 'deliver', 'create:analytics', 'create:analytics', 'deliver'])
                for variant in ['valid', 'valid-explicit', 'foreign', 'missing-name']:
                    scoped = [(path, body) for path, _, body in received if '/orgs/' + variant + '/' in path]
                    self.assertEqual(len(scoped), 4)
                    self.assertTrue(scoped[0][0].endswith('/servers/7'))
                    self.assertTrue(scoped[1][0].split('?')[0].endswith('/database/schemas'))
                    self.assertTrue(scoped[2][0].endswith('/database/schemas'))
                    self.assertTrue(scoped[3][0].endswith('/database/schemas/21'))
                    self.assertEqual(json.loads(scoped[2][1])['password'], 'literal-${PROJECT_NAME}-${PROFILE}-+%:@' if variant == 'valid-explicit' else 'synthetic-database-secret')
                self.assertEqual(len(received), 284)
                for variant in ['engine-mismatch', 'engine-unknown']:
                    scoped = [(path, body) for path, _, body in received if '/orgs/' + variant + '/' in path]
                    self.assertEqual(len(scoped), 1)
                    self.assertTrue(scoped[0][0].endswith('/servers/7'))
                    self.assertEqual(scoped[0][1], b'')
                self.assertTrue(all(auth == 'Bearer synthetic-authorization-secret' for _, auth, _ in received))
                self.assertEqual(sum(b'synthetic-body-secret' in body for _, _, body in received), 26)
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
