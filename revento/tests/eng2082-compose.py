"""Render the maintained Compose definition without contacting a Docker daemon."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='eng2082-compose-') as directory:
    env_file = Path(directory) / '.env'
    env_file.write_text('_APP_LIMIT_DATABASE_TRANSACTION=454\n_APP_FUNCTIONS_SYNC_TIMEOUT=90\n')
    environment = {'PATH': os.environ['PATH'], 'HOME': os.environ['HOME']}
    result = subprocess.run(['docker', 'compose', '--project-directory', directory,
                             '--env-file', str(env_file), '--profile', '*', '-f',
                             str(root / 'docker-compose.yml'), 'config', '--format', 'json'],
                            env=environment, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('Compose rendering failed; no container action was requested')
    compose = json.loads(result.stdout)
    checked = []
    for name, service in compose['services'].items():
        values = service.get('environment', {})
        if '_APP_LIMIT_DATABASE_BATCH' in values:
            if values.get('_APP_LIMIT_DATABASE_TRANSACTION') != '454':
                raise RuntimeError('Transaction setting not forwarded to ' + name)
            checked.append(name)
    if 'appwrite' not in checked:
        raise RuntimeError('API service was not checked')
    if compose['services']['appwrite']['environment'].get('_APP_FUNCTIONS_SYNC_TIMEOUT') != '90':
        raise RuntimeError('Synchronous timeout not forwarded to API')
print(json.dumps({'passed': True, 'services': checked, 'configured_value': 454}))
