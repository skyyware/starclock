import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REQUIRED_CHECKS = {'unit', 'integration', 'handoff-failure', 'admin-security', 'concurrent-ticks',
                   'concurrent-reservations', 'tick-evidence', 'inactive-mailpoet', 'archive-race', 'lifecycle', 'admin-lifecycle'}


def suite_hash():
    digest = hashlib.sha256()
    files = sorted((ROOT / 'tests').glob('*')) + [ROOT / 'bin/verify.py', ROOT / 'bin/release_check.py']
    for source in files:
        if source.is_file():
            digest.update(str(source.relative_to(ROOT)).encode() + b'\0' + source.read_bytes())
    return digest.hexdigest()


def check(package_hash, reports, matrix, expected_suite):
    if len(reports) != len(matrix) or {report.get('case') for report in reports} != set(matrix):
        raise ValueError('Exactly one report is required for every matrix case.')
    for report in reports:
        if report.get('passed') is not True or report.get('package_sha256') != package_hash:
            raise ValueError('A failed report or a different package cannot authorize release.')
        if report.get('suite_sha256') != expected_suite or report.get('environment') != matrix[report['case']]:
            raise ValueError('Test suite or dependency versions changed; rerun verification.')
        if set(report.get('checks', {})) != REQUIRED_CHECKS:
            raise ValueError('Required checks are missing.')
        for name, result in {**report['checks'], 'closeout': report.get('closeout')}.items():
            if name == 'concurrent-reservations':
                if result != {'winner': 1, 'duplicates': 7}:
                    raise ValueError('Concurrency check failed.')
            elif not result or not result.get('checks') or not all(value is True for value in result['checks'].values()):
                raise ValueError(f'{name} did not pass every check.')
    return {'release_checks': 'passed', 'package_sha256': package_hash, 'cases': list(matrix)}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Reject incomplete or stale release evidence. No network or deployment.')
    parser.add_argument('package', type=Path)
    parser.add_argument('reports', type=Path, nargs='+')
    args = parser.parse_args()
    result = check(hashlib.sha256(args.package.read_bytes()).hexdigest(),
                   [json.loads(path.read_text()) for path in args.reports],
                   json.loads((ROOT / 'tests/matrix.json').read_text()), suite_hash())
    print(json.dumps(result, indent=2))
