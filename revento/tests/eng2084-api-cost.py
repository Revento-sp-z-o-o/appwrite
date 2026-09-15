"""ENG-2084 API wiring comparison; an isolated Docker fixture, never a remote target."""
import argparse
import json
from pathlib import Path
import re
import secrets
import subprocess
import time
import urllib.request
import urllib.error


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        return None


# Credentials remain on the explicitly selected loopback socket.
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

parser = argparse.ArgumentParser()
parser.add_argument('config', type=Path)
parser.add_argument('output', type=Path)
args = parser.parse_args()
assert args.config.stat().st_mode & 0o077 == 0
config = json.loads(args.config.read_text())
assert config.get('evidenceLabel') == 'candidate-acceptance'
assert config['endpoint'] == 'http://127.0.0.1:18084/v1' and config['syntheticOnly'] is True
assert config['project'].startswith('eng208')
args.output.mkdir(mode=0o700)
name = 'eng2084qualification'
database = 'qcost_' + secrets.token_hex(6)
base = '/tablesdb/' + database
pending = set()
created = None
steps = []
metrics = {}
failed = False
uncertain_write = False

def save(name, value):
    path = args.output / name
    path.write_text(json.dumps(value, indent=2) + '\n')
    path.chmod(0o600)

def call(method, path, body=None, variant='candidate', statuses=(200, 201, 202, 204)):
    global uncertain_write
    endpoint = config['endpoint'] if variant == 'candidate' else 'http://127.0.0.1:18086/v1'
    request = urllib.request.Request(endpoint + path, method=method,
        data=None if body is None else json.dumps(body).encode(),
        headers={'content-type': 'application/json', 'x-appwrite-project': config['project'], 'x-appwrite-key': config['apiKey']})
    try:
        response = opener.open(request, timeout=180)
    except urllib.error.HTTPError as error:
        response = error
    except Exception:
        if method != 'GET':
            uncertain_write = True
        raise
    with response:
        try:
            raw = response.read()
            body = json.loads(raw) if raw else {}
        except Exception:
            if method != 'GET':
                uncertain_write = True
            raise
        if method != 'GET' and response.status >= 500:
            uncertain_write = True
        steps.append({'method': method, 'path': path, 'status': response.status, 'variant': variant})
        save('steps.json', steps)
        assert response.status in statuses, (method, path, response.status, body.get('type'))
        return body

def hgets():
    output = subprocess.check_output(['docker', 'exec', name + '-redis-1', 'redis-cli', 'INFO', 'commandstats'], text=True)
    match = re.search(r'cmdstat_hget:calls=(\d+)', output)
    return int(match.group(1)) if match else 0

def ready(table):
    deadline = time.monotonic() + 60
    while True:
        rows = call('GET', base + '/tables/' + table + '/columns')['columns']
        assert not any(row['status'] in ['failed', 'stuck'] for row in rows)
        if all(row['status'] == 'available' for row in rows):
            return
        assert time.monotonic() < deadline
        time.sleep(.1)

def relation(table, target, key, inverse, two_way=True):
    call('POST', base + '/tables/' + table + '/columns/relationship', {'relatedTableId': target, 'type': 'manyToOne', 'twoWay': two_way, 'key': key, 'twoWayKey': inverse})
    ready(table)
    ready(target)

manifest = json.loads((Path(__file__).parent.parent / 'manifest.json').read_text())
expected = next(p['after_sha256'] for p in manifest['patches'] if p['name'] == 'relationship-lookups')
identities = {}
for service, source_hash in [('api', expected), ('baseline', '517ff7043de41921f984b1efc42d3a15679ac3f86eca80535a57d45478fc0cde')]:
    container = name + '-' + service + '-1'
    inspection = json.loads(subprocess.check_output(['docker', 'inspect', container], text=True))[0]
    assert inspection['Config']['Labels']['com.docker.compose.project'] == name
    assert inspection['State']['Running'] is True
    digest = subprocess.check_output(['docker', 'exec', container, 'sha256sum', '/usr/src/code/vendor/utopia-php/database/src/Database/Database.php'], text=True).split()[0]
    assert digest == source_hash
    identities[service] = {'id': inspection['Id'], 'image': inspection['Image'], 'database_sha256': digest}
save('intent.json', {'database': database, 'project': config['project'], 'containers': identities, 'syntheticOnly': True})
try:
    call('GET', base, statuses=(404,))
    created = call('POST', '/tablesdb', {'databaseId': database, 'name': database})
    save('owned.json', {key: created[key] for key in ['$id', '$createdAt', 'name']})
    for table in ['drafts', 'mirrors', 'types', 'threads', 'locations']:
        call('POST', base + '/tables', {'tableId': table, 'name': table, 'permissions': ['read("any")'], 'rowSecurity': True})
        call('POST', base + '/tables/' + table + '/columns/varchar', {'key': 'label', 'size': 64, 'required': False})
        ready(table)
    relation('types', 'threads', 'default_thread', 'types')
    relation('locations', 'threads', 'thread', 'locations')
    for table in ['drafts', 'mirrors']:
        for target, key in [('types', 'type_definition'), ('threads', 'thread'), ('locations', 'location')]:
            relation(table, target, key, 'activities' if table == 'drafts' else 'published_rows', table == 'drafts')
    for table, data in [('threads', {}), ('types', {'default_thread': 'shared'}), ('locations', {'thread': 'shared'})]:
        call('POST', base + '/tables/' + table + '/rows', {'rowId': 'shared', 'data': {'label': table, **data}, 'permissions': ['read("any")']})
    for i in range(150):
        for table in ['drafts', 'mirrors']:
            call('POST', base + '/tables/' + table + '/rows', {'rowId': 'r' + str(i), 'data': {'label': 'before', 'type_definition': 'shared', 'thread': 'shared', 'location': 'shared'}, 'permissions': ['read("any")']})
    print('150-row API graph seeded', flush=True)
    for variant in ['baseline', 'candidate']:
        tx = call('POST', '/tablesdb/transactions', {'ttl': 900}, variant)['$id']
        pending.add(tx)
        save('pending.json', sorted(pending))
        operations = [{'databaseId': database, 'tableId': 'mirrors', 'action': 'update', 'rowId': 'r' + str(i), 'data': {'label': variant, 'type_definition': 'shared', 'thread': 'shared', 'location': 'shared'}} for i in range(150)]
        before = hgets()
        start = time.monotonic()
        call('POST', '/tablesdb/transactions/' + tx + '/operations', {'operations': operations}, variant)
        metrics[variant] = {'stage_seconds': time.monotonic() - start, 'stage_hgets': hgets() - before}
        before = hgets()
        start = time.monotonic()
        result = call('PATCH', '/tablesdb/transactions/' + tx, {'commit': True}, variant)
        assert result['status'] == 'committed'
        metrics[variant].update(commit_seconds=time.monotonic() - start, commit_hgets=hgets() - before)
        pending.remove(tx)
        save('pending.json', sorted(pending))
        save('metrics.json', metrics)
        for i in range(150):
            assert call('GET', base + '/tables/mirrors/rows/r' + str(i))['label'] == variant
        print(json.dumps({variant: metrics[variant]}), flush=True)
    assert metrics['candidate']['stage_hgets'] < metrics['baseline']['stage_hgets'] * .5, 'Staging route did not remove relationship expansion'
    assert metrics['candidate']['commit_hgets'] < metrics['baseline']['commit_hgets'] * .9, 'Commit route did not narrow old-row reads'
    save('result.json', {'passed': True, 'metrics': metrics, 'rows_verified_each_variant': 150})
except Exception:
    failed = True
    raise
finally:
    save('state.json', {'failed': failed, 'uncertain_write': uncertain_write, 'pending': sorted(pending)})
    # Do not erase an ambiguous/unfinished transaction or its fixture.
    if created and not pending and not failed and not uncertain_write:
        fresh = call('GET', base)
        assert all(fresh[key] == created[key] for key in ['$id', '$createdAt', 'name'])
        call('DELETE', base)
        call('GET', base, statuses=(404,))
        save('cleanup.json', {'closed': True, 'database_absent': True})
assert (args.output / 'result.json').is_file()
assert (args.output / 'cleanup.json').is_file()
print('ENG2084 native API wiring comparison passed and fixture closed', flush=True)
