"""Lost-create and cleanup-retry controls without Docker or network access."""
import contextlib
import datetime
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import urllib.error
import urllib.parse

spec = importlib.util.spec_from_file_location('prefix_harness', Path(__file__).with_name('eng2114-api.py'))
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class Response(io.BytesIO):
    def __init__(self, status, data):
        super().__init__(json.dumps(data).encode())
        self.status = status


class OwnedAPI:
    def __init__(self, receipt, lost_path):
        self.receipt = receipt
        self.lost_path = lost_path
        self.resources = {}
        self.fail_cleanup = False
        self.intent_observed_before_post = False

    def open(self, request, timeout):
        path = urllib.parse.urlsplit(request.full_url).path.removeprefix('/v1')
        data = json.loads(request.data) if request.data else {}
        if request.method == 'POST' and path.endswith('/jwts'):
            return Response(201, {'jwt': path.split('/')[2]})
        if path == '/account':
            return Response(200, {'$id': request.get_header('X-appwrite-jwt')})
        if request.method == 'POST':
            rid = data.get('userId', data.get('databaseId'))
            intended_path = path + '/' + rid
            state = json.loads(self.receipt.read_text())
            if not any(i['path'] == intended_path for i in state['intents']):
                raise AssertionError('Create intent was not persisted before POST')
            self.intent_observed_before_post = True
            result = {'$id': rid, 'name': data['name'],
                      '$createdAt': datetime.datetime.now(datetime.timezone.utc).isoformat()}
            self.resources[intended_path] = result
            if path == self.lost_path:
                raise urllib.error.URLError('Synthetic lost create response')
            return Response(201, result)
        if self.fail_cleanup and request.method == 'DELETE':
            return Response(503, {})
        if request.method == 'DELETE':
            self.resources.pop(path, None)
            return Response(204, {})
        return Response(200, self.resources[path]) if path in self.resources else Response(404, {})


class CleanupTest(unittest.TestCase):
    def exercise(self, lost_path, fail_cleanup=False, mutate_owner=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / 'output'
            config = root / 'config.json'
            config.write_text(json.dumps({'endpoint': 'http://127.0.0.1:18084/v1',
                'project': 'revento-dev2', 'syntheticOnly': True,
                'evidenceLabel': 'candidate-acceptance', 'expectedImage': 'synthetic', 'apiKey': 'synthetic'}))
            config.chmod(0o600)
            api = OwnedAPI(output / 'result.json', lost_path)
            api.fail_cleanup = fail_cleanup
            args = ['probe', str(config), str(output)]
            with patch('sys.argv', args), patch.object(harness.urllib.request, 'build_opener', return_value=api), \
                    contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(urllib.error.URLError):
                    harness.main()
            state = json.loads(api.receipt.read_text())
            self.assertTrue(api.intent_observed_before_post)
            self.assertEqual(state['cleanup'], not fail_cleanup)
            if fail_cleanup:
                self.assertTrue(api.resources)
                self.assertTrue(state['cleanup_errors'])
                api.fail_cleanup = False
                if mutate_owner:
                    next(iter(api.resources.values()))['name'] = 'unrelated'
                with patch('sys.argv', args + ['--cleanup-only']), \
                        patch.object(harness.urllib.request, 'build_opener', return_value=api), \
                        contextlib.redirect_stdout(io.StringIO()):
                    if mutate_owner:
                        with self.assertRaises(RuntimeError):
                            harness.main()
                    else:
                        harness.main()
                state = json.loads(api.receipt.read_text())
                self.assertEqual(state['cleanup'], not mutate_owner)
            self.assertEqual(bool(api.resources), mutate_owner)

    def test_lost_user_create_response_is_reconciled(self):
        self.exercise('/users')

    def test_lost_database_create_response_cleans_database_and_users(self):
        self.exercise('/tablesdb')

    def test_failed_delete_records_pending_and_can_be_retried(self):
        self.exercise('/tablesdb', fail_cleanup=True)

    def test_changed_ownership_is_never_deleted(self):
        self.exercise('/users', fail_cleanup=True, mutate_owner=True)


if __name__ == '__main__':
    unittest.main()
