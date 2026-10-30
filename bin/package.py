from pathlib import Path
import hashlib
import json
import re
import subprocess
import zipfile

root = Path(__file__).resolve().parents[1]
header = (root / 'starclock.php').read_text()
version = re.search(r'Version: ([0-9.]+)', header).group(1)
files = [root / 'starclock.php', root / 'README.md', root / 'LICENSE', root / 'CHANGELOG.md']
files += sorted((root / 'src').glob('*.php')) + sorted((root / 'assets').glob('*'))
files += sorted((root / 'docs').glob('*.md'))
for source in files:
    if source.suffix == '.php':
        subprocess.run(['php', '-l', str(source)], check=True, capture_output=True)
subprocess.run(['php', str(root / 'tests/unit.php')], check=True, capture_output=True)
out = root / '.runtime/dist'
out.mkdir(parents=True, exist_ok=True)
target = out / f'starclock-{version}.zip'
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
    for source in files:
        relative = source.relative_to(root)
        entry = zipfile.ZipInfo('starclock/' + str(relative), (2026, 10, 29, 0, 0, 0))
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, source.read_bytes())
manifest = {'version': version, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
            'files': [str(p.relative_to(root)) for p in files],
            'file_sha256': {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest() for p in files}}
(out / f'starclock-{version}.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(json.dumps({'package': str(target), **manifest}, indent=2))
