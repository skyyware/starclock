from copy import deepcopy
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'bin'))
from release_check import check, REQUIRED_CHECKS


class ReleaseGateTest(unittest.TestCase):
    def setUp(self):
        self.matrix = {'one': {'wordpress': '7.1.4', 'php': '8.3.36', 'mailpoet': '5.44.0'}}
        checks = {name: {'checks': {'verified': True}} for name in REQUIRED_CHECKS}
        checks['concurrent-reservations'] = {'winner': 1, 'duplicates': 7}
        self.report = {'case': 'one', 'passed': True, 'package_sha256': 'package', 'suite_sha256': 'suite',
                       'environment': self.matrix['one'], 'checks': checks, 'closeout': {'checks': {'paused': True}}}

    def test_complete_evidence(self):
        self.assertEqual(check('package', [self.report], self.matrix, 'suite')['release_checks'], 'passed')

    def test_missing_case(self):
        with self.assertRaises(ValueError):
            check('package', [], self.matrix, 'suite')

    def test_stale_or_failed_evidence(self):
        for field, value in [('passed', False), ('package_sha256', 'old'), ('suite_sha256', 'old'),
                             ('environment', {}), ('checks', {}), ('closeout', None)]:
            report = deepcopy(self.report)
            report[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                check('package', [report], self.matrix, 'suite')

    def test_false_closeout(self):
        self.report['closeout']['checks']['paused'] = False
        with self.assertRaises(ValueError):
            check('package', [self.report], self.matrix, 'suite')

    def test_duplicate_campaigns(self):
        self.report['checks']['concurrent-reservations']['winner'] = 2
        with self.assertRaises(ValueError):
            check('package', [self.report], self.matrix, 'suite')


if __name__ == '__main__':
    unittest.main()
