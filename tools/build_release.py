#!/usr/bin/env python3
"""Build an installable, deterministic WordPress release from an explicit allowlist."""
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
config = json.loads((root / 'release-config.json').read_text())
slug = config['slug']
if not re.fullmatch(r'[a-z0-9-]+', slug):
    raise SystemExit('Invalid package slug')
entry = root / config['entry']
headers = {}
for label in ['Version', 'Requires at least', 'Requires PHP', 'Update URI']:
    match = re.search(r'^[ \t/*#@]*' + re.escape(label) + r':[ \t]*(.+)$', entry.read_text(), re.M)
    if not match:
        raise SystemExit('Missing header: ' + label)
    headers[label] = match.group(1).strip()
version = headers['Version']
if not re.fullmatch(r'\d+\.\d+\.\d+', version):
    raise SystemExit('Use a stable x.y.z version')
if headers['Update URI'] != 'https://github.com/' + config['repo']:
    raise SystemExit('Update URI does not match repository')
for label in ['Requires at least', 'Requires PHP']:
    if not re.fullmatch(r'\d+\.\d+(?:\.\d+)?', headers[label]):
        raise SystemExit('Invalid requirements')
constant_file = root / ('functions.php' if config['type'] == 'theme' else config['entry'])
constant = re.search(r"define\(\s*['\"]" + re.escape(config['version_constant']) + r"['\"]\s*,\s*['\"]([^'\"]+)['\"]", constant_file.read_text())
if not constant or constant.group(1) != version:
    raise SystemExit('Header and version constant disagree')
readme = (root / 'readme.txt').read_text()
stable = re.search(r'^Stable tag:[ \t]*(.+)$', readme, re.M)
if not stable or stable.group(1).strip() != version:
    raise SystemExit('Readme stable tag and version disagree')
files = []
for item in config['runtime']:
    path = root / item
    if not path.exists():
        raise SystemExit('Missing runtime item: ' + item)
    files.extend([path] if path.is_file() else sorted(p for p in path.rglob('*') if p.is_file() and p.suffix != '.md'))
for path in files:
    if path.is_symlink() or not path.resolve().is_relative_to(root):
        raise SystemExit('Symlinks/outside files are not allowed')
    if any(part.startswith('.') or part in ('node_modules', 'tests', 'tools', 'dist') for part in path.relative_to(root).parts):
        raise SystemExit('Development file in runtime allowlist: ' + str(path))
dist = root / 'dist'
dist.mkdir(exist_ok=True)
asset = slug + '.zip'
with zipfile.ZipFile(dist / asset, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(set(files)):
        info = zipfile.ZipInfo(slug + '/' + path.relative_to(root).as_posix(), date_time=(2020, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, path.read_bytes())
digest = hashlib.sha256((dist / asset).read_bytes()).hexdigest()
manifest = {'schema_version': 1, 'type': config['type'], 'slug': slug, 'version': version, 'requires': headers['Requires at least'], 'requires_php': headers['Requires PHP'], 'tested': config['tested'], 'asset': asset, 'sha256': digest}
(dist / 'release.json').write_text(json.dumps(manifest, indent=2) + '\n')
(dist / 'SHA256SUMS').write_text(digest + '  ' + asset + '\n' + hashlib.sha256((dist / 'release.json').read_bytes()).hexdigest() + '  release.json\n')
if os.environ.get('GITHUB_OUTPUT'):
    with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
        output.write('version=' + version + '\nslug=' + slug + '\ntag=v' + version + '\n')
print(json.dumps(manifest))
