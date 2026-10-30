import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import zipfile
from release_check import suite_hash

root = Path(__file__).resolve().parents[1]
matrix = json.loads((root / 'tests/matrix.json').read_text())
parser = argparse.ArgumentParser(description='Verify a package in an existing isolated synthetic WordPress installation.')
parser.add_argument('--case', choices=matrix, required=True)
parser.add_argument('--wp', type=Path, required=True)
parser.add_argument('--php', type=Path, required=True)
parser.add_argument('--wp-cli', type=Path, required=True)
parser.add_argument('--package', type=Path, required=True)
parser.add_argument('--sandbox', type=Path)
args = parser.parse_args()
stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
out = root / '.runtime/verification' / args.case / stamp
out.mkdir(parents=True)
prefix = ['/usr/bin/sandbox-exec', '-f', str(args.sandbox.resolve())] if args.sandbox else []
php = prefix + [str(args.php.resolve()), '-d', 'disable_functions=mail,exec,shell_exec,system,passthru,proc_open,popen']
wp = php + [str(args.wp_cli.resolve()), f'--path={args.wp.resolve()}']
env = os.environ.copy()
report = {'case': args.case, 'package_sha256': hashlib.sha256(args.package.read_bytes()).hexdigest(), 'suite_sha256': suite_hash(),
          'started_at': datetime.now(timezone.utc).isoformat(), 'passed': False, 'checks': {}, 'closeout': None}


def run(label, command):
    result = subprocess.run(command, capture_output=True, text=True, env=env)
    (out / f'{label}.stdout').write_text(result.stdout)
    (out / f'{label}.stderr').write_text(result.stderr)
    if result.returncode:
        raise RuntimeError(f'{label} failed; see {out / (label + ".stderr")}')
    return result.stdout.strip()


def script(label, filename):
    path = candidate / 'tests' / filename
    output = run(label, wp + ['eval', 'require ' + json.dumps(str(path)) + ';'])
    data = json.loads(output)
    if 'checks' in data and (not data['checks'] or not all(value is True for value in data['checks'].values())):
        raise RuntimeError(f'{label} did not pass every check')
    return data


candidate = out / 'candidate/starclock'
safe_to_test = False
try:
    with zipfile.ZipFile(args.package) as archive:
        for item in archive.infolist():
            parts = Path(item.filename).parts
            if len(parts) < 2 or parts[0] != 'starclock' or '..' in parts or item.is_dir():
                raise RuntimeError('Unexpected package path')
        archive.extractall(out / 'candidate')
    shutil.copytree(root / 'tests', candidate / 'tests')
    report['environment'] = script('environment', 'environment.php')
    if report['environment'] != matrix[args.case]:
        raise RuntimeError(f'Expected {matrix[args.case]}, got {report["environment"]}')
    safe_to_test = True
    report['checks']['unit'] = json.loads(run('unit', php + [str(candidate / 'tests/unit.php')]))
    for name in ['integration', 'handoff-failure', 'admin-security']:
        report['checks'][name] = script(name, name + '.php')
    setup = script('race-setup', 'race-setup.php')
    env['STARCLOCK_RACE_PLAN'] = str(setup['plan_id'])
    with ThreadPoolExecutor(max_workers=8) as pool:
        futures = [pool.submit(run, f'tick-{index}', wp + ['eval', 'require ' + json.dumps(str(candidate / 'tests/tick-race.php')) + ';']) for index in range(8)]
        for future in futures:
            future.result()
    report['checks']['concurrent-ticks'] = script('race-result', 'race-result.php')
    with ThreadPoolExecutor(max_workers=8) as pool:
        futures = [pool.submit(run, f'reservation-{index}', wp + ['eval', 'require ' + json.dumps(str(candidate / 'tests/concurrency.php')) + ';']) for index in range(8)]
        reservations = [future.result() for future in futures]
    if reservations.count('winner') != 1 or reservations.count('duplicate') != 7:
        raise RuntimeError(f'Invalid reservation results: {reservations}')
    report['checks']['concurrent-reservations'] = {'winner': 1, 'duplicates': 7}
    script('pause-after-races', 'closeout.php')
    report['checks']['tick-evidence'] = script('tick-evidence', 'tick-evidence.php')
    report['checks']['inactive-mailpoet'] = json.loads(run('inactive-mailpoet', wp + ['--skip-plugins=mailpoet', 'eval',
        'require ' + json.dumps(str(candidate / 'tests/inactive-mailpoet.php')) + ';']))
    report['passed'] = True
except Exception as error:
    report['error'] = str(error)
finally:
    if safe_to_test:
        try:
            report['closeout'] = script('closeout', 'closeout.php')
        except Exception as error:
            report['passed'] = False
            report['closeout_error'] = str(error)
    report['finished_at'] = datetime.now(timezone.utc).isoformat()
    (out / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({'report': str(out / 'report.json'), 'passed': report['passed'],
                      'environment': report.get('environment'), 'error': report.get('error'),
                      'closeout': report['closeout'] is not None and 'closeout_error' not in report}, indent=2))
raise SystemExit(0 if report['passed'] else 1)
