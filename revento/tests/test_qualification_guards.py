"""CLI guards must reject unsafe configuration even under Python optimization."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


class QualificationGuardTest(unittest.TestCase):
    def test_cost_harness_rejects_unsafe_targets_before_docker_or_http(self):
        valid = {'endpoint': 'http://127.0.0.1:18084/v1', 'project': 'eng2083owned',
                 'syntheticOnly': True, 'evidenceLabel': 'candidate-acceptance'}
        variants = [({'endpoint': 'https://example.invalid/v1'}, 0o600),
                    ({'syntheticOnly': False}, 0o600),
                    ({'project': 'production'}, 0o600),
                    ({'evidenceLabel': 'production'}, 0o600), ({}, 0o644)]
        script = Path(__file__).with_name('eng2084-api-cost.py')
        for optimization in [[], ['-O']]:
            for change, mode in variants:
                with self.subTest(optimization=optimization, change=change, mode=mode):
                    with tempfile.TemporaryDirectory() as temporary:
                        root = Path(temporary)
                        config = root / 'config.json'
                        config.write_text(json.dumps({**valid, **change}))
                        config.chmod(mode)
                        executable = root / 'docker'
                        executable.write_text('#!/bin/sh\nexit 99\n')
                        executable.chmod(0o700)
                        env = {**os.environ, 'PATH': str(root) + os.pathsep + os.environ.get('PATH', '')}
                        result = subprocess.run([sys.executable, *optimization, str(script), str(config), str(root / 'output')],
                                                env=env, capture_output=True, text=True, timeout=10)
                        self.assertNotEqual(result.returncode, 0)
                        self.assertIn('Qualification guard failed', result.stderr)
                        self.assertFalse((root / 'output').exists())

    def test_prefix_harness_rejects_unsafe_targets_before_http(self):
        valid = {'endpoint': 'http://127.0.0.1:18084/v1', 'project': 'revento-dev2',
                 'syntheticOnly': True, 'evidenceLabel': 'candidate-acceptance'}
        variants = [({'endpoint': 'https://example.invalid/v1'}, 0o600),
                    ({'endpoint': 'http://user@127.0.0.1/v1'}, 0o600),
                    ({'endpoint': 'http://127.0.0.1/v1?redirect=1'}, 0o600),
                    ({'syntheticOnly': False}, 0o600),
                    ({'project': 'production'}, 0o600),
                    ({'evidenceLabel': 'production'}, 0o600), ({}, 0o644)]
        script = Path(__file__).with_name('eng2114-api.py')
        for optimization in [[], ['-O']]:
            for change, mode in variants:
                with self.subTest(optimization=optimization, change=change, mode=mode):
                    with tempfile.TemporaryDirectory() as temporary:
                        root = Path(temporary)
                        config = root / 'config.json'
                        config.write_text(json.dumps({**valid, **change}))
                        config.chmod(mode)
                        result = subprocess.run([sys.executable, *optimization, str(script), str(config), str(root / 'output')],
                                                capture_output=True, text=True, timeout=10)
                        self.assertNotEqual(result.returncode, 0)
                        self.assertIn('Qualification guard failed', result.stderr)
                        self.assertFalse((root / 'output').exists())

    def test_cost_harness_accepts_owned_target_up_to_container_verification(self):
        script = Path(__file__).with_name('eng2084-api-cost.py')
        for optimization in [[], ['-O']]:
            with self.subTest(optimization=optimization), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                config = root / 'config.json'
                config.write_text(json.dumps({'endpoint': 'http://127.0.0.1:18084/v1', 'project': 'eng2083owned',
                    'syntheticOnly': True, 'evidenceLabel': 'candidate-acceptance'}))
                config.chmod(0o600)
                # Stop at the first verification command: no Docker service or API is touched.
                executable = root / 'docker'
                executable.write_text('#!/bin/sh\nexit 99\n')
                executable.chmod(0o700)
                env = {**os.environ, 'PATH': str(root) + os.pathsep + os.environ.get('PATH', '')}
                result = subprocess.run([sys.executable, *optimization, str(script), str(config), str(root / 'output')],
                                        env=env, capture_output=True, text=True, timeout=10)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('exit status 99', result.stderr)
                self.assertTrue((root / 'output').is_dir())
                self.assertFalse((root / 'output' / 'owned.json').exists())


if __name__ == '__main__':
    unittest.main()
