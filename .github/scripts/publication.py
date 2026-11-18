"""Read back published assets before advertising them to Git Updater."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from release import metadata, validate_zip


def gh(*args):
    return subprocess.check_output(['gh', *args], text=True)


def git(*args):
    return subprocess.check_output(['git', *args], text=True).strip()


def check_release(release, value, filename, notes):
    if release.get('draft') or release.get('prerelease') or not release.get('published_at'):
        raise ValueError('Release must be published and stable')
    if release.get('tag_name') != 'v' + value:
        raise ValueError('Latest published release does not match the candidate version')
    if release.get('body', '').replace('\r\n', '\n').strip() != notes.strip():
        raise ValueError('Published notes differ from checked-in release notes')
    assets = release.get('assets', [])
    if sorted(asset['name'] for asset in assets) != sorted([filename, filename + '.sha256']):
        raise ValueError('Release must contain exactly the versioned ZIP and checksum')
    if any(asset.get('state') != 'uploaded' or asset.get('size', 0) <= 0 for asset in assets):
        raise ValueError('Release assets are incomplete')


def check_download(folder, release, value, config, expected_digest=None):
    filename = config['zip'].format(version=value)
    path = folder / filename
    validate_zip(path, value, config)
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    if expected_digest is not None and digest != expected_digest:
        raise ValueError('Published ZIP differs from the CI-tested artifact')
    if (folder / (filename + '.sha256')).read_text() != f'{digest}  {filename}\n':
        raise ValueError('Published checksum does not match the downloaded ZIP')
    asset = next(asset for asset in release['assets'] if asset['name'] == filename)
    if asset.get('digest') != 'sha256:' + digest or asset['size'] != path.stat().st_size:
        raise ValueError('GitHub asset digest/size differs from the downloaded ZIP')
    return digest


def check_tag(repo, value, sha):
    ref = json.loads(gh('api', f'repos/{repo}/git/ref/tags/v{value}'))['object']
    if ref['type'] == 'tag':
        ref = json.loads(gh('api', f'repos/{repo}/git/tags/{ref["sha"]}'))['object']
    if ref['type'] != 'commit' or ref['sha'] != sha:
        raise ValueError('Published tag does not point to the exact release commit')


def verify(root, config, sha, expected_digest=None):
    value = metadata(root, config)
    filename = config['zip'].format(version=value)
    repo = os.environ['GH_REPO']
    release = json.loads(gh('api', f'repos/{repo}/releases/latest'))
    check_release(release, value, filename, (root / f'release-notes/{value}.md').read_text())
    check_tag(repo, value, sha)
    with tempfile.TemporaryDirectory() as folder:
        gh('release', 'download', 'v' + value, '--repo', repo, '--dir', folder,
           '--pattern', filename, '--pattern', filename + '.sha256')
        digest = check_download(Path(folder), release, value, config, expected_digest)
    print(f'Verified published v{value} at {sha}, SHA-256 {digest}')


def check_forward(old_version, new_version, ancestor):
    parse = lambda value: tuple(int(part) for part in value.split('.'))
    if parse(old_version) > parse(new_version) or not ancestor:
        raise ValueError('Refusing to roll the verified update branch backwards')


def promotion_check(root, config, sha):
    branch = config['update_branch']
    remote = git('ls-remote', '--heads', 'origin', 'refs/heads/' + branch)
    if not remote:
        return
    git('fetch', 'origin', f'refs/heads/{branch}:refs/remotes/origin/{branch}')
    previous = git('rev-parse', 'refs/remotes/origin/' + branch)
    text = git('show', previous + ':' + config['main'])
    old = re.search(r'^Version:\s*(\S+)', text, re.M)[1]
    ancestor = subprocess.run(['git', 'merge-base', '--is-ancestor', previous, sha], check=False).returncode == 0
    check_forward(old, metadata(root, config), ancestor)


def run(command, root, config, sha):
    if command == 'preflight':
        promotion_check(root, config, sha)
        return
    if command not in ['verify', 'promote', 'audit']:
        raise ValueError('Unknown publication command')
    value = metadata(root, config)
    expected = None
    if command != 'audit':
        expected = hashlib.sha256((root / 'dist' / config['zip'].format(version=value)).read_bytes()).hexdigest()
    verify(root, config, sha, expected)
    if command == 'promote':
        promotion_check(root, config, sha)
        git('push', 'origin', f'{sha}:refs/heads/{config["update_branch"]}')
    if command in ['promote', 'audit']:
        remote = git('ls-remote', '--heads', 'origin', 'refs/heads/' + config['update_branch'])
        if not remote or remote.split()[0] != sha:
            raise ValueError('Update branch does not match the verified published release')


if __name__ == '__main__':
    root = Path.cwd()
    config = json.loads((root / '.github/release.json').read_text())
    sha = os.environ.get('RELEASE_SHA') or git('rev-parse', 'HEAD')
    if git('rev-parse', 'HEAD') != sha:
        raise ValueError('Publication must use the exact checked-out release commit')
    run(sys.argv[1], root, config, sha)
