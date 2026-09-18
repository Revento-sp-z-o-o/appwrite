"""Prefix-search and RLS acceptance in an explicitly synthetic loopback project."""
import argparse
import datetime
import re
import hashlib
import json
from pathlib import Path
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('config', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--cleanup-only', action='store_true')
    args = parser.parse_args()
    require(args.config.stat().st_mode & 63 == 0, 'Qualification guard failed: private config required')
    config = json.loads(args.config.read_text())
    endpoint = urllib.parse.urlsplit(config['endpoint'])
    require(endpoint.scheme == 'http' and endpoint.hostname == '127.0.0.1'
            and endpoint.path == '/v1' and not endpoint.username and not endpoint.password
            and not endpoint.query and not endpoint.fragment,
            'Qualification guard failed: explicit loopback API required')
    require(config['syntheticOnly'] is True
            and (config['project'] == 'revento-dev2' or config['project'].startswith('eng2083'))
            and config['evidenceLabel'] == 'candidate-acceptance',
            'Qualification guard failed: owned synthetic project required')
    if not args.cleanup_only:
        args.output.mkdir(mode=0o700)


    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *unused):
            return None


    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())


    def call(method, path, data=None, auth=None, allowed=(200, 201, 202, 204)):
        headers = {'Content-Type': 'application/json', 'X-Appwrite-Project': config['project']}
        headers.update({'X-Appwrite-Key': config['apiKey']} if auth is None else auth)
        request = urllib.request.Request(config['endpoint'] + path, method=method,
            data=None if data is None else json.dumps(data).encode(), headers=headers)
        try:
            response = opener.open(request, timeout=45)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read()
            result = json.loads(raw) if raw else {}
            require(response.status in allowed,
                    f'Unexpected HTTP {response.status} {method} {path.split("?")[0]}')
            return response.status, result


    def value(method, path, data=None, **kwargs):
        return call(method, path, data, **kwargs)[1]


    def query(method, attribute=None, values=None):
        result = {'method': method, 'values': values or []}
        if attribute is not None:
            result['attribute'] = attribute
        return result


    identity = 'qprefix_' + secrets.token_hex(6)
    database = '/tablesdb/' + identity
    table = database + '/tables/probe'
    report = {'expected_image': config['expectedImage'],
              'image_verification': 'Separate container/source-hash receipt required',
              'database': identity, 'project': config['project'], 'endpoint': config['endpoint'],
              'intents': [], 'cases': [], 'cleanup_errors': [], 'passed': False}


    def save():
        target = args.output / 'result.json'
        target.write_text(json.dumps(report, indent=2) + '\n')
        target.chmod(0o600)


    def create_owned(path, data, identity_key):
        rid = data[identity_key]
        call('GET', path + '/' + rid, allowed=(404,))
        item = {'path': path + '/' + rid, 'id': rid, 'name': data['name'],
                'intent_utc': datetime.datetime.now(datetime.timezone.utc).isoformat()}
        report['intents'].append(item)
        save()
        result = value('POST', path, data)
        item['created_at'] = result['$createdAt']
        save()


    def cleanup():
        report['cleanup_errors'] = []
        for item in reversed(report['intents']):
            try:
                require(item['path'] in ['/tablesdb/' + identity, '/users/' + identity + '_owner',
                                         '/users/' + identity + '_outsider'], 'Unexpected cleanup path')
                status, resource = call('GET', item['path'], allowed=(200, 404))
                if status != 404:
                    require(resource.get('$id') == item['id'] and resource.get('name') == item['name'],
                            'Cleanup ownership mismatch')
                    created_at = resource['$createdAt']
                    if 'created_at' in item:
                        require(created_at == item['created_at'], 'Resource identity changed')
                    else:
                        created = datetime.datetime.fromisoformat(created_at.replace('Z', '+00:00'))
                        intended = datetime.datetime.fromisoformat(item['intent_utc'])
                        require(-5 <= (created - intended).total_seconds() <= 120,
                                'Ambiguous creation ownership mismatch')
                    call('DELETE', item['path'], allowed=(200, 202, 204, 404))
                    call('GET', item['path'], allowed=(404,))
                item['absent_verified'] = True
            except Exception as error:
                report['cleanup_errors'].append({'path': item['path'], 'error': type(error).__name__})
            save()
        report['cleanup'] = not report['cleanup_errors']
        report['cleanup_scope'] = 'API absence verified; internal asynchronous database storage reclamation not attested'
        save()


    if args.cleanup_only:
        report = json.loads((args.output / 'result.json').read_text())
        identity = report['database']
        require(re.fullmatch(r'qprefix_[0-9a-f]{12}', identity) is not None
                and report['project'] == config['project'] and report['endpoint'] == config['endpoint'],
                'Cleanup receipt target mismatch')
        cleanup()
        require(report['cleanup'], 'Cleanup pending; rerun --cleanup-only with the same receipt')
        return

    try:
        actors = {'anonymous': {}}
        for role in ['owner', 'outsider']:
            uid = identity + '_' + role
            create_owned('/users', {'userId': uid, 'name': uid}, 'userId')
            jwt = value('POST', '/users/' + uid + '/jwts', {'duration': 900})['jwt']
            actors[role] = {'X-Appwrite-JWT': jwt}
            require(value('GET', '/account', auth=actors[role])['$id'] == uid, 'Actor mismatch')
        create_owned('/tablesdb', {'databaseId': identity, 'name': identity}, 'databaseId')
        value('POST', database + '/tables', {'tableId': 'probe', 'name': 'probe',
              'permissions': [], 'rowSecurity': True})
        for key in ['text', 'dictionary_text', 'tokens']:
            value('POST', table + '/columns/string', {'key': key, 'size': 1024, 'required': False})
        value('POST', table + '/columns/integer', {'key': 'number_value', 'required': True})
        deadline = time.monotonic() + 90
        while not (len(columns := value('GET', table)['columns']) == 4
                   and all(c['status'] == 'available' for c in columns)):
            require(time.monotonic() < deadline, 'Column readiness deadline')
            time.sleep(.25)
        for key, kind in [('text', 'fulltext'), ('dictionary_text', 'fulltext'),
                          ('tokens', 'fulltext'), ('number_value', 'key')]:
            value('POST', table + '/indexes', {'key': key + '_idx', 'type': kind, 'columns': [key]})
        deadline = time.monotonic() + 90
        while not (len(indexes := value('GET', table)['indexes']) == 4
                   and all(i['status'] == 'available' for i in indexes)):
            require(time.monotonic() < deadline, 'Index readiness deadline')
            time.sleep(.25)
        token = 'dfv_' + hashlib.sha256(b'level\0beginner').hexdigest()[:28]
        number = 'dfn_' + hashlib.sha256(b'age\0number').hexdigest()[:28]
        rows = [('a', 'zolty smok', 'theme', token + ' ' + number, 12, 'owner'),
                ('b', 'niebieski smok', 'running', token, 20, 'owner'),
                ('c', 'żółty smok ١٢٣٤ a١٢٣٤ Ⅷalpha ²beta Добрый ΚΑΛΟΣ', 'the', '', 8, 'owner'),
                ('d', None, None, '', 0, 'owner'),
                ('e', 'zolty slon', 'runner', '', 15, 'owner'),
                ('hidden', 'zolty smok', 'theme', token + ' ' + number, 12, 'outsider')]
        for rid, text, dictionary, tokens, n, owner in rows:
            value('POST', table + '/rows', {'rowId': rid,
                  'data': {'text': text, 'dictionary_text': dictionary, 'tokens': tokens, 'number_value': n},
                  'permissions': ['read("user:' + identity + '_' + owner + '")']})
        cases = [
            ('prefix', [query('search', 'text', ['zol*'])], ['a', 'e']),
            ('whole_word', [query('search', 'text', ['zolty'])], ['a', 'e']),
            ('nonmatch', [query('search', 'text', ['absent*'])], []),
            ('unicode', [query('search', 'text', ['żół*'])], ['c']),
            ('arabic_numeric', [query('search', 'text', ['١٢٣*'])], ['c']),
            ('mixed_numeric', [query('search', 'text', ['a١٢٣*'])], ['c']),
            ('mixed_numeric_mismatch', [query('search', 'text', ['a١٢٤*'])], []),
            ('letter_number_boundary', [query('search', 'text', ['alpha*'])], []),
            ('other_number_boundary', [query('search', 'text', ['beta*'])], []),
            ('cyrillic_case', [query('search', 'text', ['доб*'])], ['c']),
            ('greek_case', [query('search', 'text', ['καλ*'])], ['c']),
            ('not_prefix_excludes_null', [query('notSearch', 'text', ['zol*'])], ['b', 'c']),
            ('grouped_or', [query('or', values=[query('search', 'text', ['zol*']), query('search', 'text', ['żół*'])])], ['a', 'c', 'e']),
            ('two_prefixes', [query('search', 'text', ['zol*']), query('search', 'text', ['smo*'])], ['a']),
            ('literal_stopword', [query('search', 'dictionary_text', ['the*'])], ['a', 'c']),
            ('literal_stem', [query('search', 'dictionary_text', ['runn*'])], ['b', 'e']),
            ('quoted_token', [query('search', 'tokens', ['"' + token + '"'])], ['a', 'b']),
            ('full_conjunction', [query('search', 'text', ['zol*']),
                 query('search', 'text', ['smo*']),
                 query('search', 'tokens', ['"' + token + '"']),
                 query('search', 'tokens', ['"' + number + '"']),
                 query('greaterThanEqual', 'number_value', [10]),
                 query('lessThanEqual', 'number_value', [15])], ['a']),
        ]
        for route, group, listing, response_key in [('tablesdb', 'tables', 'rows', 'rows'),
                                                   ('databases', 'collections', 'documents', 'documents')]:
            root = '/' + route + '/' + identity + '/' + group + '/probe/' + listing
            probes = [(name, predicates, 'owner', expected) for name, predicates, expected in cases]
            probes += [('outsider_scope', [query('search', 'text', ['zol*'])], 'outsider', ['hidden']),
                       ('anonymous_scope', [query('search', 'text', ['zol*'])], 'anonymous', []),
                       ('outsider_negative_scope', [query('notSearch', 'text', ['zol*'])], 'outsider', []),
                       ('anonymous_negative_scope', [query('notSearch', 'text', ['zol*'])], 'anonymous', [])]
            for name, predicates, actor, expected in probes:
                queries = [*predicates, query('limit', values=[25]), query('orderAsc', '$id')]
                path = root + '?' + urllib.parse.urlencode({'queries[]': [json.dumps(q) for q in queries],
                                                            'ttl': 0, 'total': 'false'}, doseq=True)
                status, result = call('GET', path, auth=actors[actor])
                ids = [row['$id'] for row in result[response_key]]
                report['cases'].append({'route': route, 'case': name, 'actor': actor,
                                        'status': status, 'ids': ids, 'expected': expected,
                                        'passed': ids == expected})
                save()
            page_ids = []
            cursor = None
            for _ in range(3):
                queries = [query('search', 'text', ['zol*']), query('limit', values=[1]), query('orderAsc', '$id')]
                if cursor is not None:
                    queries.append(query('cursorAfter', values=[cursor]))
                path = root + '?' + urllib.parse.urlencode({'queries[]': [json.dumps(q) for q in queries],
                                                           'ttl': 0, 'total': 'false'}, doseq=True)
                rows = value('GET', path, auth=actors['owner'])[response_key]
                if not rows:
                    break
                page_ids.extend(row['$id'] for row in rows)
                cursor = rows[-1]['$id']
            report['cases'].append({'route': route, 'case': 'prefix_pagination', 'actor': 'owner',
                                    'ids': page_ids, 'expected': ['a', 'e'], 'passed': page_ids == ['a', 'e']})
        report['passed'] = all(case['passed'] for case in report['cases'])
    finally:
        cleanup()
    print(json.dumps({'passed': report['passed'], 'cleanup': report['cleanup'],
                      'cases': len(report['cases']),
                      'failures': [(c['route'], c['case']) for c in report['cases'] if not c['passed']]}))
    require(report['passed'] and report['cleanup'], 'Prefix qualification failed; inspect retained receipt')


if __name__ == '__main__':
    main()
