"""Recovery tests for the qualification CLI, without Docker or network access."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import urllib.error

spec = importlib.util.spec_from_file_location('deadline_harness', Path(__file__).with_name('eng2093-api.py'))
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class Response(io.BytesIO):
    def __init__(self, status, data):
        super().__init__(json.dumps(data).encode())
        self.status = status


class OwnedAPI:
    def __init__(self, function):
        self.function = function
        self.deleted = False

    def open(self, request, timeout):
        if '/executions?' in request.full_url:
            return Response(200, {'executions': []})
        if request.method == 'DELETE':
            self.deleted = True
            self.function = None
            return Response(204, {})
        if self.function is None:
            raise urllib.error.HTTPError(request.full_url, 404, 'missing', {}, io.BytesIO(b'{}'))
        return Response(200, self.function)


class CleanupTest(unittest.TestCase):
    def run_cleanup(self, exists=True, wrong_time=False, wrong_name=False, live=False):
        fid = 'deadline_0123456789abcdef'
        function = {'$id': fid, 'name': 'unrelated' if wrong_name else fid,
                    '$createdAt': '2026-09-15T00:00:00Z' if wrong_time else '2026-09-16T00:00:01Z',
                    'runtime': 'dart-3.11', 'execute': [], 'scopes': []}
        api = OwnedAPI(function if exists else None)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = root/'config.json'
            config.write_text(json.dumps({'endpoint':'http://127.0.0.1:18084/v1', 'project':'eng2093test',
                                          'syntheticOnly':True, 'apiKey':'synthetic-test-only',
                                          'expectedImage':'sha256:'+'a'*64, 'evidenceLabel':'candidate-acceptance'}))
            config.chmod(0o600)
            state = {'project':'eng2093test', 'function':fid, 'created':False, 'closed':False,
                     'intent_utc':'2026-09-16T00:00:00+00:00'}
            if live:
                state['active_until_epoch'] = 9999999999
            fixture = root/'fixture.json'
            fixture.write_text(json.dumps(state))
            with patch('sys.argv', ['probe', str(config), str(root), '--phase', 'cleanup']), \
                    patch.object(harness.urllib.request, 'build_opener', return_value=api), \
                    contextlib.redirect_stdout(io.StringIO()):
                if wrong_time or wrong_name or live:
                    with self.assertRaises(RuntimeError):
                        harness.main()
                else:
                    harness.main()
            final = json.loads(fixture.read_text())
        return api.deleted, final['closed']

    def test_lost_create_response_can_clean_owned_function(self):
        self.assertEqual(self.run_cleanup(), (True, True))

    def test_absent_function_closes_ambiguous_intent(self):
        self.assertEqual(self.run_cleanup(exists=False), (False, True))

    def test_old_function_cannot_be_deleted(self):
        self.assertEqual(self.run_cleanup(wrong_time=True), (False, False))

    def test_unrelated_name_cannot_be_deleted(self):
        self.assertEqual(self.run_cleanup(wrong_name=True), (False, False))

    def test_active_window_blocks_even_absent_cleanup(self):
        self.assertEqual(self.run_cleanup(exists=False, live=True), (False, False))


if __name__ == '__main__':
    unittest.main()
