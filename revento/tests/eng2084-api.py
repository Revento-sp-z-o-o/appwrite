"""ENG-2084 real API permissions/transaction regression on isolated loopback only."""
import argparse
import hashlib
import json
from pathlib import Path
import secrets
import time
import urllib.parse
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
parser.add_argument('--staging-only', action='store_true')
args = parser.parse_args()

def require(value, message):
    if not value:
        raise RuntimeError(message)

def private_write(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')
    path.chmod(0o600)

def digest(value):
    return hashlib.sha256(value).hexdigest()

require(args.config.stat().st_mode & 0o077 == 0, 'Private config required')
config = json.loads(args.config.read_text())
require(config.get('evidenceLabel') == 'candidate-acceptance', 'Candidate acceptance label required')
endpoint = urllib.parse.urlsplit(config['endpoint'])
require(endpoint.hostname in ['127.0.0.1', 'localhost'] and endpoint.scheme == 'http'
        and endpoint.path == '/v1' and not endpoint.username and not endpoint.password
        and not endpoint.query and not endpoint.fragment, 'Isolated loopback API required')
require(config.get('syntheticOnly') is True and config['project'].startswith('eng208'), 'Owned synthetic project required')
server_auth = {'X-Appwrite-Key': config['apiKey']}

def api_call(method, path, body=None, auth=None):
    request = urllib.request.Request(config['endpoint'] + path, method=method,
        data=None if body is None else json.dumps(body).encode(),
        headers={'content-type': 'application/json', 'x-appwrite-project': config['project'], **(auth or {})})
    try:
        response = opener.open(request, timeout=180)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read()
        return response.status, json.loads(raw) if raw else {}

def get(path):
    return api_call('GET', path, auth=server_auth)

tag = 'qrel_' + secrets.token_hex(6)
out = args.output
out.mkdir(mode=0o700)
db = '/tablesdb/' + tag
created = None
users = []
transactions = []
checks = []
steps = []
uncertain_write = False
failed = False
private_write(out / 'intent.json', {'variant': 'candidate', 'project': config['project'], 'endpoint': config['endpoint'],
    'database_id': tag, 'source_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(), 'expected_image': config['expectedImage'],
    'existing_fixture_rows_touched': False})
print('API comparison ' + tag, flush=True)

def call(method, path, body=None, auth=None, statuses=(200, 201, 202, 204)):
    global uncertain_write
    start = time.monotonic()
    try:
        code, result = api_call(method, path, body=body, auth=server_auth if auth is None else auth)
    except Exception:
        if method != 'GET':
            uncertain_write = True
        raise
    if method != 'GET' and code >= 500:
        uncertain_write = True
    step = {'method': method, 'path': path.split('?', 1)[0], 'actor': 'server' if auth is None else 'client',
            'status': code, 'seconds': time.monotonic() - start}
    steps.append(step)
    private_write(out / ('step-' + str(len(steps)) + '.json'), step)
    require(code in statuses, 'Unexpected API status ' + str(code) + ' at ' + path.split('?', 1)[0] + ' type=' + str(result.get('type') if isinstance(result, dict) else None))
    return result

def row(table, identity, selects=None, auth=None):
    path = db + '/tables/' + table + '/rows/' + identity
    if selects:
        path += '?' + urllib.parse.urlencode({'queries[]': json.dumps({'method': 'select', 'values': selects})})
    return call('GET', path, auth=auth)

def available():
    deadline = time.monotonic() + 60
    while True:
        columns = [column for table in ['events', 'activities', 'details'] for column in call('GET', db + '/tables/' + table + '/columns')['columns']]
        if all(column['status'] == 'available' for column in columns):
            return
        require(not any(column['status'] in ['failed', 'stuck'] for column in columns), 'Column creation failed')
        require(time.monotonic() < deadline, 'Columns did not become available')
        time.sleep(.3)

try:
    require(get(db)[0] == 404, 'Probe database already exists')
    created = call('POST', '/tablesdb', {'databaseId': tag, 'name': tag})
    private_write(out / 'database-owned.json', {key: created[key] for key in ['$id', '$createdAt', 'name']})
    actors = {}
    for name in ['owner', 'outsider']:
        user = call('POST', '/users', {'userId': tag + '_' + name, 'name': 'Synthetic relationship ' + name})
        users.append({'id': user['$id'], 'createdAt': user['$createdAt']})
        private_write(out / ('user-' + name + '-owned.json'), users[-1])
        jwt = call('POST', '/users/' + user['$id'] + '/jwts', {'duration': 900})['jwt']
        actors[name] = {'X-Appwrite-JWT': jwt}
    owner = users[0]['id']
    permissions = ['read("user:' + owner + '")', 'update("user:' + owner + '")']
    if not args.staging_only:
        for table in ['events', 'activities', 'details']:
            call('POST', db + '/tables', {'tableId': table, 'name': table, 'permissions': [], 'rowSecurity': True})
            call('POST', db + '/tables/' + table + '/columns/varchar', {'key': 'label', 'size': 64, 'required': False})
        available()
        call('POST', db + '/tables/activities/columns/relationship', {'relatedTableId': 'events', 'type': 'manyToOne', 'twoWay': True, 'key': 'event', 'twoWayKey': 'activities', 'onDelete': 'restrict'})
        available()
        call('POST', db + '/tables/activities/columns/relationship', {'relatedTableId': 'details', 'type': 'oneToOne', 'twoWay': True, 'key': 'detail', 'twoWayKey': 'activity', 'onDelete': 'setNull'})
        available()
        for identity in ['first', 'second']:
            call('POST', db + '/tables/events/rows', {'rowId': identity, 'data': {'label': identity}, 'permissions': permissions})
        call('POST', db + '/tables/details/rows', {'rowId': 'private', 'data': {'label': 'private'}, 'permissions': permissions})
        call('POST', db + '/tables/activities/rows', {'rowId': 'private', 'data': {'label': 'before', 'event': 'first', 'detail': 'private'}, 'permissions': permissions})
        call('POST', db + '/tables/activities/rows', {'rowId': 'public', 'data': {'label': 'public', 'event': 'first'}, 'permissions': ['read("any")']})
        first = row('activities', 'private', ['*', 'event.*', 'detail.*'], actors['owner'])
        require(first['event']['$id'] == 'first' and first['detail']['$id'] == 'private', 'Owner relationship population failed')
        for auth in [actors['outsider'], {}]:
            public = row('activities', 'public', ['*', 'event.*'], auth)
            require(not public.get('event'), 'Private related row exposed to readable parent')
            call('GET', db + '/tables/activities/rows/private', auth=auth, statuses=(401, 403, 404))
            call('PATCH', db + '/tables/activities/rows/private', {'data': {'label': 'forbidden'}}, auth=auth, statuses=(401, 403, 404))
        checks.append('owner population, readable root hides forbidden relation, outsider/anonymous denial')
        tx = call('POST', '/tablesdb/transactions', {'ttl': 120})['$id']
        transactions.append(tx)
        call('POST', '/tablesdb/transactions/' + tx + '/operations', {'operations': [
            {'databaseId': tag, 'tableId': 'activities', 'action': 'update', 'rowId': 'private', 'data': {'label': 'after', 'event': 'second'}},
            {'databaseId': tag, 'tableId': 'activities', 'action': 'create', 'rowId': 'created', 'data': {'label': 'created', 'event': 'second', '$permissions': permissions}},
        ]})
        committed = call('PATCH', '/tablesdb/transactions/' + tx, {'commit': True})
        require(committed['status'] == 'committed', 'Transaction not committed')
        require(set(row('activities', 'created', auth=actors['owner'])['$permissions']) == set(permissions), 'Created row permissions differ')
        updated = row('activities', 'private', ['*', 'event.*', 'detail.*'], actors['owner'])
        require(updated['label'] == 'after' and updated['event']['$id'] == 'second' and updated['detail']['$id'] == 'private', 'Transaction lost data or relationship')
        require({x['$id'] for x in row('events', 'first', ['*', 'activities.*'], actors['owner'])['activities']} == {'public'}, 'Old inverse links wrong')
        require({x['$id'] for x in row('events', 'second', ['*', 'activities.*'], actors['owner'])['activities']} == {'private', 'created'}, 'New inverse links wrong')
        checks.append('staging/commit, create/update, inverse relationship maintenance and unchanged unrelated relation')
        tx = call('POST', '/tablesdb/transactions', {'ttl': 120})['$id']
        transactions.append(tx)
        call('PATCH', db + '/tables/activities/rows/private', {'data': {'label': 'rollback', 'event': 'first'}, 'transactionId': tx})
        call('PATCH', '/tablesdb/transactions/' + tx, {'rollback': True})
        rolled = row('activities', 'private', ['*', 'event.*'], actors['owner'])
        require(rolled['label'] == 'after' and rolled['event']['$id'] == 'second', 'Rollback changed persisted row')
        checks.append('rollback preserves scalar and relation')
        call('DELETE', db + '/tables/details/rows/private')
        require(row('activities', 'private', ['*', 'detail.*'], actors['owner']).get('detail') is None, 'Set-null deletion failed')
        checks.append('set-null related deletion')
        # ENG-2083: exercise final batched reads with a real caller, including write-only authorization.
        tx = call('POST', '/tablesdb/transactions', {'ttl': 120}, auth=actors['owner'])['$id']
        transactions.append(tx)
        call('PATCH', db + '/tables/activities/rows/private', {'data': {'label': 'owner_committed'}, 'transactionId': tx}, auth=actors['owner'])
        for auth in [actors['outsider'], {}]:
            call('PATCH', '/tablesdb/transactions/' + tx, {'commit': True}, auth=auth, statuses=(401, 403, 404))
        require(call('GET', '/tablesdb/transactions/' + tx)['status'] == 'pending', 'Unauthorized actor changed transaction')
        require(call('PATCH', '/tablesdb/transactions/' + tx, {'commit': True}, auth=actors['owner'])['status'] == 'committed', 'Owner commit failed')
        require(row('activities', 'private', auth=actors['owner'])['label'] == 'owner_committed', 'Owner committed value missing')
        checks.append('JWT owner commit; outsider and anonymous cannot commit another transaction')
        call('POST', db + '/tables', {'tableId': 'writeonly', 'name': 'writeonly', 'permissions': ['update("user:' + owner + '")'], 'rowSecurity': False})
        call('POST', db + '/tables/writeonly/columns/varchar', {'key': 'label', 'size': 64, 'required': False})
        deadline = time.monotonic() + 60
        while call('GET', db + '/tables/writeonly/columns')['columns'][0]['status'] != 'available':
            require(time.monotonic() < deadline, 'Write-only column readiness timeout')
            time.sleep(.3)
        call('POST', db + '/tables/writeonly/rows', {'rowId': 'private', 'data': {'label': 'before'}})
        call('GET', db + '/tables/writeonly/rows/private', auth=actors['owner'], statuses=(401, 403, 404))
        tx = call('POST', '/tablesdb/transactions', {'ttl': 120}, auth=actors['owner'])['$id']
        transactions.append(tx)
        call('PATCH', db + '/tables/writeonly/rows/private', {'data': {'label': 'writeonly_committed'}, 'transactionId': tx})
        require(call('PATCH', '/tablesdb/transactions/' + tx, {'commit': True}, auth=actors['owner'])['status'] == 'committed', 'Write-only commit failed')
        require(row('writeonly', 'private')['label'] == 'writeonly_committed', 'Write-only commit missing')
        call('GET', db + '/tables/writeonly/rows/private', auth=actors['owner'], statuses=(401, 403, 404))
        checks.append('server-staged write-only transaction commits as owner JWT without final-row read access')
        call('POST', db + '/tables/activities/indexes', {'key': 'unique_label', 'type': 'unique', 'columns': ['label']})
        deadline = time.monotonic() + 60
        while call('GET', db + '/tables/activities/indexes/unique_label')['status'] != 'available':
            require(time.monotonic() < deadline, 'Unique index readiness timeout')
            time.sleep(.3)
        tx = call('POST', '/tablesdb/transactions', {'ttl': 120})['$id']
        transactions.append(tx)
        call('POST', '/tablesdb/transactions/' + tx + '/operations', {'operations': [
            {'databaseId': tag, 'tableId': 'activities', 'action': 'update', 'rowId': 'private', 'data': {'label': 'must_roll_back'}},
            {'databaseId': tag, 'tableId': 'activities', 'action': 'create', 'rowId': 'constraint_failure', 'data': {'label': 'public'}},
        ]})
        call('PATCH', '/tablesdb/transactions/' + tx, {'commit': True}, statuses=(409,))
        require(call('GET', '/tablesdb/transactions/' + tx)['status'] == 'failed', 'Constraint failure not terminal')
        require(row('activities', 'private')['label'] == 'owner_committed', 'Failed commit left partial update')
        call('GET', db + '/tables/activities/rows/constraint_failure', statuses=(404,))
        checks.append('failed commit is atomic and terminal after unique-constraint violation')
        private_write(out / 'permissions-checkpoint.json', {'passed': True, 'checks': checks.copy(), 'steps': steps.copy()})

    # The patched operations route is distinct from single-document transaction staging.
    call('POST', db + '/tables', {'tableId': 'staging', 'name': 'staging', 'permissions': ['create("user:' + owner + '")'], 'rowSecurity': True})
    call('POST', db + '/tables/staging/columns/varchar', {'key': 'label', 'size': 64, 'required': False})
    call('POST', db + '/tables/staging/columns/integer', {'key': 'count', 'required': False})
    deadline = time.monotonic() + 60
    while not all(x['status'] == 'available' for x in call('GET', db + '/tables/staging/columns')['columns']):
        require(time.monotonic() < deadline, 'Staging columns readiness timeout')
        time.sleep(.1)
    acl = permissions + ['delete("user:' + owner + '")']
    for route, group_key, resource_key in [('tablesdb', 'tableId', 'rowId'), ('databases', 'collectionId', 'documentId')]:
        txpath = '/' + route + '/transactions'
        identity = route + '_row'
        def operation(action, identity, data=None):
            value = {'databaseId': tag, group_key: 'staging', resource_key: identity, 'action': action}
            if data is not None:
                value['data'] = data
            return value
        def stage(tx, operations, actor=actors['owner'], statuses=(201,)):
            return call('POST', txpath + '/' + tx + '/operations', {'operations': operations}, auth=actor, statuses=statuses)
        tx = call('POST', txpath, {'ttl': 120}, auth=actors['owner'])['$id']
        transactions.append(tx)
        stage(tx, [operation('create', identity, {'label': 'created', 'count': 2, '$permissions': acl}),
                   operation('update', identity, {'label': 'same-batch'})], actor=None)
        stage(tx, [operation('update', identity, {'label': 'prior-batch'}),
                   operation('increment', identity, {('column' if route == 'tablesdb' else 'attribute'): 'count', 'value': 3}),
                   operation('decrement', identity, {('column' if route == 'tablesdb' else 'attribute'): 'count', 'value': 1})])
        require(call('PATCH', txpath + '/' + tx, {'commit': True}, auth=actors['owner'])['status'] == 'committed', 'Dependent operations did not commit')
        require(row('staging', identity)['label'] == 'prior-batch' and row('staging', identity)['count'] == 4, 'Staged overlay/counter mismatch')
        tx = call('POST', txpath, {'ttl': 120}, auth=actors['owner'])['$id']
        transactions.append(tx)
        for actor in [actors['outsider'], {}]:
            stage(tx, [operation('update', identity, {'label': 'forbidden'})], actor=actor, statuses=(401, 403, 404))
        stage(tx, [operation('update', 'missing', {'label': 'missing'})], statuses=(404,))
        require(call('GET', txpath + '/' + tx, auth=actors['owner'])['operations'] == 0, 'Rejected stage added operations')
        stage(tx, [operation('upsert', identity, {'$id': identity, 'label': 'existing-upsert'}),
                   operation('upsert', identity + '_new', {'$id': identity + '_new', 'label': 'new-upsert', 'count': 0, '$permissions': acl})])
        stage(tx, [operation('delete', identity)])
        require(call('PATCH', txpath + '/' + tx, {'commit': True}, auth=actors['owner'])['status'] == 'committed', 'Upsert/delete commit failed')
        call('GET', db + '/tables/staging/rows/' + identity, statuses=(404,))
        require(row('staging', identity + '_new')['label'] == 'new-upsert', 'Missing upsert output')
    checks.append('legacy and TablesDB create/update/upsert/delete/counters, same/prior batch, missing and unauthorized staging')
    private_write(out / 'result.json', {'variant': 'candidate', 'passed': True, 'checks': checks, 'steps': steps,
        'qualification_scope': 'Native Appwrite API synthetic relationship/transaction comparison; not full publication acceptance'})
    print(json.dumps({'checks_passed': True, 'cleanup_pending': True, 'checks': checks}), flush=True)
except Exception:
    failed = True
    raise
finally:
    # Never erase an ambiguous transaction. Each exact transaction must have a
    # terminal state before the owned database and users can be removed.
    settled = not uncertain_write and not failed
    for tx in ([] if uncertain_write else transactions):
        state = call('GET', '/tablesdb/transactions/' + tx)['status']
        if state == 'pending':
            state = call('PATCH', '/tablesdb/transactions/' + tx, {'rollback': True})['status']
        settled = settled and state in ['committed', 'failed']
    if created is not None and settled:
        fresh = call('GET', db)
        require(all(fresh[key] == created[key] for key in ['$id', '$createdAt', 'name']), 'Database ownership changed')
        call('DELETE', db)
        require(get(db)[0] == 404, 'Owned database still exists')
        for user in users:
            fresh = call('GET', '/users/' + user['id'])
            require(fresh['$createdAt'] == user['createdAt'] and fresh['$id'] == user['id'], 'User ownership changed')
            call('DELETE', '/users/' + user['id'])
            require(get('/users/' + user['id'])[0] == 404, 'Owned user still exists')
        private_write(out / 'cleanup.json', {'closed': True, 'database_absent': True, 'users_absent': len(users), 'transactions': transactions})
        print('Owned API probe resources cleaned', flush=True)
    elif created is not None:
        print('Unsettled API transaction: cleanup deferred at ' + str(out), flush=True)

require((out / 'cleanup.json').is_file() and json.loads((out / 'cleanup.json').read_text()).get('closed') is True,
          'API comparison not accepted: cleanup is incomplete')
require((out / 'result.json').is_file() and json.loads((out / 'result.json').read_text()).get('passed') is True,
          'API comparison checks did not pass')
private_write(out / 'acceptance.json', {'passed': True, 'variant': 'candidate',
    'result_sha256': digest((out / 'result.json').read_bytes()),
    'cleanup_sha256': digest((out / 'cleanup.json').read_bytes())})
print(json.dumps({'passed': True, 'cleanup_closed': True, 'evidence': str(out / 'acceptance.json')}), flush=True)
