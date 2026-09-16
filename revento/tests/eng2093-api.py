"""ENG-2093: real API deadline qualification using one owned, side-effect-free function."""
import argparse
import datetime
import io
import json
import os
from pathlib import Path
import stat
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError('Redirect rejected')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def utc():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('config')
    parser.add_argument('directory')
    parser.add_argument('--phase', choices=['prepare', 'case', 'cleanup'], required=True)
    parser.add_argument('--case', choices=['default', 'extended', 'short', 'malformed'])
    args = parser.parse_args()
    file = Path(args.config)
    require(stat.S_IMODE(file.stat().st_mode) & 0o077 == 0, 'Private config required')
    config = json.loads(file.read_text())
    endpoint = urllib.parse.urlsplit(config['endpoint'])
    require(endpoint.scheme == 'http' and endpoint.hostname == '127.0.0.1'
            and endpoint.port == 18084 and endpoint.path == '/v1'
            and not endpoint.username and not endpoint.password
            and not endpoint.query and not endpoint.fragment, 'Owned loopback endpoint required')
    require(config.get('syntheticOnly') is True and config['project'].startswith('eng2093'), 'Owned synthetic project required')
    require(config.get('expectedImage', '').startswith('sha256:') and len(config['expectedImage']) == 71,
            'Independently verified image receipt required')
    require(config.get('evidenceLabel') in ['candidate-acceptance', 'baseline-harness-validation'], 'Evidence label required')
    out = Path(args.directory)
    out.mkdir(mode=0o700, parents=True, exist_ok=True)
    state_file = out / 'fixture.json'
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def request(method, path, data=None, raw=None, content_type='application/json', key=True):
        headers = {'X-Appwrite-Project': config['project'], 'Content-Type': content_type}
        if key:
            headers['X-Appwrite-Key'] = config['apiKey']
        req = urllib.request.Request(config['endpoint'] + path, headers=headers, method=method,
                                     data=raw if raw is not None else json.dumps(data).encode() if data is not None else None)
        try:
            with opener.open(req, timeout=110) as response:
                status, body = response.status, response.read()
        except urllib.error.HTTPError as error:
            status, body = error.code, error.read()
        try:
            body = json.loads(body) if body else {}
        except ValueError:
            raise RuntimeError('Non-JSON API response') from None
        return status, body

    def call(method, path, data=None, **kwargs):
        status, body = request(method, path, data, **kwargs)
        require(status in [200, 201, 202, 204], 'API operation failed: ' + str(status) + ' ' + path)
        return body

    if args.phase == 'prepare':
        require(not state_file.exists(), 'Fixture already exists')
        fid = 'deadline_' + uuid.uuid4().hex[:16]
        state = {'project': config['project'], 'function': fid, 'intent_utc': utc(), 'created': False, 'closed': False}
        state_file.write_text(json.dumps(state, indent=2))
        function = call('POST', '/functions', {'functionId': fid, 'name': fid, 'runtime': 'dart-3.11',
                        'execute': [], 'events': [], 'scopes': [], 'timeout': 60, 'enabled': True,
                        'logging': True, 'entrypoint': 'lib/main.dart', 'commands': 'dart pub get'})
        state.update(created=True, created_at=function['$createdAt'])
        state_file.write_text(json.dumps(state, indent=2))
        archive = io.BytesIO()
        code = b'''Future<dynamic> main(final context) async {
  final seconds = int.tryParse(context.req.headers['x-delay-seconds'] ?? '0') ?? 0;
  if (seconds < 0 || seconds > 40) return context.res.json({'error': 'invalid_delay'}, 400);
  await Future<void>.delayed(Duration(seconds: seconds));
  return context.res.json({'marker': 'eng2093', 'delay': seconds});
}
'''
        with tarfile.open(fileobj=archive, mode='w:gz') as tar:
            for name, value in [('lib/main.dart', code), ('pubspec.yaml', b'name: eng2093_fixture\nenvironment:\n  sdk: ">=3.11.0 <4.0.0"\n')]:
                item = tarfile.TarInfo(name)
                item.size = len(value)
                item.mode = 0o600
                tar.addfile(item, io.BytesIO(value))
        boundary = 'eng2093-' + uuid.uuid4().hex
        pieces = []
        for name, value in [('entrypoint', 'lib/main.dart'), ('commands', 'dart pub get'), ('activate', 'true')]:
            pieces.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + name + '"\r\n\r\n' + value + '\r\n').encode())
        pieces.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="code"; filename="code.tar.gz"\r\nContent-Type: application/gzip\r\n\r\n').encode() + archive.getvalue() + b'\r\n')
        pieces.append(('--' + boundary + '--\r\n').encode())
        deployment = call('POST', '/functions/' + fid + '/deployments', raw=b''.join(pieces),
                          content_type='multipart/form-data; boundary=' + boundary)
        state['deployment'] = deployment['$id']
        state_file.write_text(json.dumps(state, indent=2))
        deadline = time.monotonic() + 600
        while time.monotonic() < deadline:
            deployed = call('GET', '/functions/' + fid + '/deployments/' + state['deployment'])
            if deployed['status'] == 'ready':
                break
            require(deployed['status'] not in ['failed', 'canceled'], 'Synthetic deployment failed')
            time.sleep(3)
        else:
            raise RuntimeError('Deployment readiness deadline exceeded')
        require(call('GET', '/functions/' + fid)['deploymentId'] == state['deployment'], 'Fixture not active')
        state['ready'] = True
        state_file.write_text(json.dumps(state, indent=2))
        print(json.dumps({'prepared': True, 'function': fid}))
        return

    state = json.loads(state_file.read_text())
    require(state['project'] == config['project'] and not state['closed'], 'Fixture state mismatch')
    fid = state['function']
    require(fid.startswith('deadline_') and len(fid) == 25 and all(c in '0123456789abcdef' for c in fid[9:]), 'Owned function ID required')
    if args.phase == 'cleanup':
        require(not state.get('active_until_epoch') or time.time() > state['active_until_epoch'], 'Potential runtime work still active')
    status, function = request('GET', '/functions/' + fid)
    if args.phase == 'cleanup' and status == 404:
        state.update(closed=True, cleaned_utc=utc(), cleanup_result='already_absent')
        state_file.write_text(json.dumps(state, indent=2))
        print(json.dumps({'cleaned': True, 'already_absent': True}))
        return
    require(status == 200 and function.get('$id') == fid and function.get('name') == fid, 'Function ownership mismatch')
    if state.get('created'):
        require(function['$createdAt'] == state['created_at'], 'Function creation identity mismatch')
    else:
        require(args.phase == 'cleanup', 'Ambiguous creation requires cleanup')
        intended = datetime.datetime.fromisoformat(state['intent_utc'])
        created = datetime.datetime.fromisoformat(function['$createdAt'].replace('Z', '+00:00'))
        require(-5 <= (created - intended).total_seconds() <= 120
                and function.get('runtime') == 'dart-3.11'
                and function.get('execute') == [] and function.get('scopes') == [], 'Ambiguous fixture ownership mismatch')
    if args.phase == 'cleanup':
        q = [json.dumps({'method': 'equal', 'attribute': 'status', 'values': ['waiting', 'processing']}),
             json.dumps({'method': 'limit', 'values': [1]})]
        pending = call('GET', '/functions/' + fid + '/executions?' + urllib.parse.urlencode([('queries[]', value) for value in q]))
        require(not pending['executions'], 'Nonterminal execution prevents cleanup')
        call('DELETE', '/functions/' + fid)
        require(request('GET', '/functions/' + fid)[0] == 404, 'Deleted function still present')
        state.update(closed=True, cleaned_utc=utc())
        state_file.write_text(json.dumps(state, indent=2))
        print(json.dumps({'cleaned': True}))
        return

    require(args.case is not None and state.get('ready'), 'Ready fixture and case required')
    require(config.get('case') == args.case, 'Configuration case must match independently deployed API profile')
    dest = out / (args.case + '.json')
    require(not dest.exists(), 'Evidence already exists')
    report = {'started_utc': utc(), 'case': args.case, 'expected_image': config['expectedImage'],
              'evidenceLabel': config['evidenceLabel'], 'image_verification': 'external container receipt required', 'function': fid, 'checks': [], 'passed': False}
    def save():
        dest.write_text(json.dumps(report, indent=2))
    save()
    def execute(delay, asynchronous=False, key=True):
        state['active_until_epoch'] = time.time() + delay + 5
        state_file.write_text(json.dumps(state, indent=2))
        started = time.monotonic()
        status, body = request('POST', '/functions/' + fid + '/executions',
                               {'path': '/', 'method': 'GET', 'async': asynchronous,
                                'headers': {'x-delay-seconds': str(delay), 'x-probe-id': uuid.uuid4().hex}}, key=key)
        elapsed = time.monotonic() - started
        row = {'delay_s': delay, 'async': asynchronous, 'http': status, 'wall_s': round(elapsed, 3),
               'execution': body.get('$id'), 'status': body.get('status'),
               'response_status': body.get('responseStatusCode'), 'type': body.get('type'), 'message': body.get('message')}
        report['checks'].append(row)
        save()
        return status, body, elapsed
    try:
        # Prewarm the same actual handler so deadline checks do not measure cold startup.
        status, body, _ = execute(0)
        require(status == 201 and body.get('status') == 'completed' and body.get('responseStatusCode') == 200, 'Fast synchronous execution failed')
        if args.case in ['default', 'malformed']:
            status, body, elapsed = execute(35)
            require(status == 408 and body.get('type') == 'function_synchronous_timeout'
                    and '30 seconds' in body.get('message', '') and 27 <= elapsed <= 40, 'Default30 deadline contract failed')
            time.sleep(max(0, 36 - elapsed))
        elif args.case == 'extended':
            status, body, elapsed = execute(35)
            require(status == 201 and body.get('status') == 'completed' and body.get('responseStatusCode') == 200
                    and json.loads(body['responseBody']) == {'marker': 'eng2093', 'delay': 35}
                    and 34 <= elapsed < 90, 'Configured90 execution failed')
            status, _, _ = execute(0, key=False)
            require(status in [401, 403], 'Anonymous execution unexpectedly permitted')
            call('PUT', '/functions/' + fid, {'name': fid, 'execute': [], 'timeout': 1})
            try:
                status, body, elapsed = execute(3)
                require(status == 201 and body.get('status') == 'failed' and body.get('responseStatusCode') == 500
                        and elapsed < 15, 'Function execution timeout was weakened')
            finally:
                call('PUT', '/functions/' + fid, {'name': fid, 'execute': [], 'timeout': 60})
        else:
            status, body, elapsed = execute(3)
            require(status == 408 and body.get('type') == 'function_synchronous_timeout'
                    and '1 seconds' in body.get('message', '') and elapsed < 5, 'Configured1 deadline failed')
            time.sleep(max(0, 4 - elapsed))
            status, body, elapsed = execute(3, asynchronous=True)
            require(status == 202 and body.get('$id') and elapsed < 3, 'Async dispatch blocked')
            deadline = time.monotonic() + 30
            while time.monotonic() < deadline:
                result = call('GET', '/functions/' + fid + '/executions/' + body['$id'])
                if result['status'] in ['completed', 'failed']:
                    break
                time.sleep(.25)
            require(result.get('status') == 'completed' and result.get('responseStatusCode') == 200, 'Async terminal outcome failed')
            report['async_terminal'] = {'status': result['status'], 'response_status': result['responseStatusCode']}
        report['passed'] = True
    finally:
        report['finished_utc'] = utc()
        save()
    print(json.dumps({'case': args.case, 'passed': report['passed']}))


if __name__ == '__main__':
    main()
