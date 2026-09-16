"""Synthetic cache-race acceptance against an explicitly owned loopback API."""
import argparse
import concurrent.futures
import json
from pathlib import Path
import secrets
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

def require(condition, message):
    if not condition:
        raise RuntimeError(message)


parser = argparse.ArgumentParser()
parser.add_argument('config', type=Path)
parser.add_argument('output', type=Path)
args = parser.parse_args()
require(args.config.stat().st_mode & 63 == 0, 'Qualification guard failed: args.config.stat().st_mode & 63 == 0')
config = json.loads(args.config.read_text())
endpoint = urllib.parse.urlsplit(config['endpoint'])
require(endpoint.scheme == 'http' and endpoint.hostname == '127.0.0.1', "Qualification guard failed: endpoint.scheme == 'http' and endpoint.hostname == '127.0.0.1'")
require(endpoint.path == '/v1' and (not endpoint.username) and (not endpoint.password), "Qualification guard failed: endpoint.path == '/v1' and (not endpoint.username) and (not endpoint.password)")
require(not endpoint.query and (not endpoint.fragment), 'Qualification guard failed: not endpoint.query and (not endpoint.fragment)')
require(config['syntheticOnly'] is True and config['project'].startswith('eng2083'), "Qualification guard failed: config['syntheticOnly'] is True and config['project'].startswith('eng2083')")
require(config['evidenceLabel'] == 'candidate-acceptance', "Qualification guard failed: config['evidenceLabel'] == 'candidate-acceptance'")
args.output.mkdir(mode=0o700)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *unused):
        return None


def call(method, path, data=None, auth=None, allowed=(200, 201, 202, 204)):
    # Each thread has its own opener; never forward credentials to a proxy/redirect.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    headers = {'Content-Type': 'application/json', 'X-Appwrite-Project': config['project']}
    headers.update({'X-Appwrite-Key': config['apiKey']} if auth is None else auth)
    request = urllib.request.Request(config['endpoint'] + path, method=method,
        data=None if data is None else json.dumps(data).encode(), headers=headers)
    try:
        response = opener.open(request, timeout=90)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read()
        result = json.loads(raw) if raw else {}
        if response.status not in allowed:
            raise RuntimeError(f'Unexpected {response.status} {method} {path.split("?")[0]}: {result.get("type")}')
        return response.status, result


def value(method, path, data=None, **kwargs):
    return call(method, path, data, **kwargs)[1]


identity = 'qcache_' + secrets.token_hex(6)
db = '/tablesdb/' + identity
target = db + '/tables/states/rows/r0'
report = {'expected_image': config['expectedImage'], 'image_verification': 'Separate container/source-hash receipt required', 'database': identity, 'cases': [], 'passed': False, 'cleanup': False}
created = False
user_created = False
pending = set()
stop = threading.Event()


def save():
    path = args.output / 'result.json'
    path.write_text(json.dumps(report, indent=2) + '\n')
    path.chmod(0o600)


try:
    value('POST', '/users', {'userId': identity, 'name': 'Synthetic cache qualification'})
    user_created = True
    jwt = value('POST', '/users/' + identity + '/jwts', {'duration': 900})['jwt']
    user = {'X-Appwrite-JWT': jwt}
    value('POST', '/tablesdb', {'databaseId': identity, 'name': identity})
    created = True
    value('POST', db + '/tables', {'tableId': 'states', 'name': 'states', 'permissions': [], 'rowSecurity': True})
    value('POST', db + '/tables/states/columns/integer', {'key': 'generation', 'required': True})
    for _ in range(200):
        columns = value('GET', db + '/tables/states/columns')['columns']
        if len(columns) == 1 and columns[0]['status'] == 'available':
            break
        time.sleep(.2)
    else:
        raise RuntimeError('Column did not become available')
    rows = [{'$id': 'r' + str(i), 'generation': 1, '$permissions': ['read("user:' + identity + '")']} for i in range(200)]
    for offset in range(0, 200, 100):
        value('POST', db + '/tables/states/rows', {'rows': rows[offset:offset + 100]})
    expected = 1
    for route, group_key, row_key in [('tablesdb', 'tableId', 'rowId'), ('databases', 'collectionId', 'documentId')]:
        for iteration in range(3):
            before = expected
            expected += 1
            require(value('GET', target, auth=user)['generation'] == before, "Qualification guard failed: value('GET', target, auth=user)['generation'] == before")
            tx_path = '/' + route + '/transactions'
            tx = value('POST', tx_path, {'ttl': 120})['$id']
            pending.add((tx_path, tx))
            operations = [{'databaseId': identity, group_key: 'states', row_key: 'r' + str(i), 'action': 'update', 'data': {'generation': expected}} for i in range(200)]
            value('POST', tx_path + '/' + tx + '/operations', {'operations': operations})
            seen = []
            ready = threading.Event()
            stop.clear()

            def poll():
                while not stop.is_set():
                    seen.append(value('GET', target, auth=user)['generation'])
                    ready.set()
                    stop.wait(.005)

            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                future = pool.submit(poll)
                try:
                    require(ready.wait(5), 'Concurrent reader did not start')
                    started = time.monotonic()
                    committed = value('PATCH', tx_path + '/' + tx, {'commit': True})
                    elapsed = time.monotonic() - started
                    require(committed['status'] == 'committed', "Qualification guard failed: committed['status'] == 'committed'")
                    pending.remove((tx_path, tx))
                finally:
                    stop.set()
                    future.result(timeout=95)
            point = value('GET', target, auth=user)['generation']
            admin = value('GET', target)['generation']
            saved = []
            for offset in [0, 100]:
                queries = [json.dumps({'method': 'limit', 'values': [100]}), json.dumps({'method': 'offset', 'values': [offset]})]
                path = db + '/tables/states/rows?' + urllib.parse.urlencode({'queries[]': queries}, doseq=True)
                saved.extend(value('GET', path, auth=user)['rows'])
            correct = len(saved) == 200 and all(row['generation'] == expected for row in saved)
            case = {'route': route, 'iteration': iteration + 1, 'expected': expected, 'user_point': point, 'admin_point': admin,
                    'all_200_list_rows_correct': correct, 'polls': len(seen), 'observed_previous_committed': before in seen,
                    'commit_seconds': elapsed, 'passed': point == admin == expected and correct and before in seen}
            report['cases'].append(case)
            save()
            require(case['passed'], 'Committed cache inconsistent')
    report['passed'] = True
finally:
    stop.set()
    # Preserve failed/ambiguous fixtures for inspection; successful runs close only owned IDs.
    if report['passed'] and not pending:
        if created:
            value('DELETE', db)
            call('GET', db, allowed=(404,))
        if user_created:
            value('DELETE', '/users/' + identity)
            call('GET', '/users/' + identity, allowed=(404,))
        report['cleanup'] = True
    save()
print(json.dumps(report))
