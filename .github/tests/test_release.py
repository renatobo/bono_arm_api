"""Exercise the actual release metadata and archive guards without publishing."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile
sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('release', ROOT / '.github/scripts/release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
CONFIG = json.loads((ROOT / '.github/release.json').read_text())


class ReleaseTests(unittest.TestCase):
    def pr(self):
        return dict(merged=True, base={'ref': 'main'}, labels=[{'name': 'release'}], merge_commit_sha='a' * 40)

    def test_exact_merge_commit(self):
        self.assertEqual('a' * 40, release.eligible_pr(self.pr()))

    def test_unmerged_rejected(self):
        pr = self.pr(); pr['merged'] = False
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def test_unlabelled_rejected(self):
        pr = self.pr(); pr['labels'] = []
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def test_other_branch_rejected(self):
        pr = self.pr(); pr['base']['ref'] = 'other'
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def test_commit_injection_rejected(self):
        pr = self.pr(); pr['merge_commit_sha'] = '$(echo injected)'
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def fixture(self, root):
        text = f" * Version: 1.0.5\nPrimary Branch: {CONFIG['update_branch']}\nRelease Asset: true\ndefine( '{CONFIG['constant']}', '1.0.5' );\n"
        (root / CONFIG['main']).write_text(text)
        if CONFIG['readme']: (root / 'readme.txt').write_text('Stable tag: 1.0.5\nVersion: 1.0.5\n')
        documents = {}
        for filename, keys in CONFIG.get('specs', []):
            document = documents.setdefault(filename, {})
            for key in keys[:-1]: document = document.setdefault(key, {})
            document[keys[-1]] = '1.0.5'
        for filename, document in documents.items():
            p = root / filename; p.parent.mkdir(parents=True, exist_ok=True); p.write_text(json.dumps(document))
        (root / 'release-notes').mkdir()
        (root / 'release-notes/1.0.5.md').write_text('## New Features\n- None.\n## Improvements\n## Bug Fixes\n')
        (root / 'dist').mkdir()
        return text

    def archive(self, root, text, extra=None):
        path = root / 'dist' / CONFIG['zip'].format(version='1.0.5')
        with zipfile.ZipFile(path, 'w') as archive:
            files = {CONFIG['main']: text, 'uninstall.php': '<?php'}
            if CONFIG['readme']: files['readme.txt'] = (root / 'readme.txt').read_text()
            files.update({filename: (root / filename).read_text() for filename, _ in CONFIG.get('specs', [])})
            for name, data in files.items():
                archive.writestr(CONFIG['slug'] + '/' + name, data)
            if extra: archive.writestr(*extra)
        return path

    def test_matching_metadata(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root)
            self.assertEqual('1.0.5', release.metadata(root, CONFIG))

    def test_constant_mismatch(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            (root / CONFIG['main']).write_text(text.replace("'1.0.5'", "'1.0.4'"))
            with self.assertRaises(ValueError): release.metadata(root, CONFIG)

    def test_php_tested_up_to_header_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            (root / CONFIG['main']).write_text('Tested up to: 7.1\n' + text)
            with self.assertRaisesRegex(ValueError, 'Tested up to'):
                release.metadata(root, CONFIG)

    def test_unpublished_main_update_branch_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            (root / CONFIG['main']).write_text(text.replace('Primary Branch: stable', 'Primary Branch: main'))
            with self.assertRaisesRegex(ValueError, 'Primary Branch'):
                release.metadata(root, CONFIG)

    def test_source_archive_downloads_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            (root / CONFIG['main']).write_text(text.replace('Release Asset: true', 'Release Asset: false'))
            with self.assertRaisesRegex(ValueError, 'release asset'):
                release.metadata(root, CONFIG)

    def test_packaged_metadata_must_match_bootstrap(self):
        for filename in ['readme.txt'] + [filename for filename, _ in CONFIG['specs']]:
            with self.subTest(filename=filename), tempfile.TemporaryDirectory() as folder:
                root = Path(folder); text = self.fixture(root)
                p = root / filename; p.write_text(p.read_text().replace('1.0.5', '1.0.4'))
                with self.assertRaises(ValueError):
                    release.validate_zip(self.archive(root, text), '1.0.5', CONFIG)

    def test_invalid_semver(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            (root / CONFIG['main']).write_text(text.replace('1.0.5', '01.0.5'))
            with self.assertRaises(ValueError): release.metadata(root, CONFIG)

    def test_missing_notes(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root); (root / 'release-notes/1.0.5.md').unlink()
            with self.assertRaises(FileNotFoundError): release.metadata(root, CONFIG)

    def test_missing_sections(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root); (root / 'release-notes/1.0.5.md').write_text('Incomplete')
            with self.assertRaises(ValueError): release.metadata(root, CONFIG)

    def test_valid_zip(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            release.validate_zip(self.archive(root, text), '1.0.5', CONFIG)

    def test_dev_file_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            path = self.archive(root, text, (CONFIG['slug'] + '/.git', 'private git pointer'))
            with self.assertRaises(ValueError): release.validate_zip(path, '1.0.5', CONFIG)

    def test_traversal_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            path = self.archive(root, text, (CONFIG['slug'] + '/../secret', 'bad'))
            with self.assertRaises(ValueError): release.validate_zip(path, '1.0.5', CONFIG)

    def test_zip_version_mismatch(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root)
            path = self.archive(root, text.replace('1.0.5', '1.0.4'))
            with self.assertRaises(ValueError): release.validate_zip(path, '1.0.5', CONFIG)

    def test_downloaded_manifest_rejected_for_other_commit(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); text = self.fixture(root); path = self.archive(root, text)
            (root / '.github').mkdir(); (root / '.github/release.json').write_text(json.dumps(CONFIG))
            import hashlib
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            manifest = dict(commit='b' * 40, version='1.0.5', filename=path.name, sha256=digest)
            (root / 'dist/release-manifest.json').write_text(json.dumps(manifest))
            (root / 'dist' / (path.name + '.sha256')).write_text(f'{digest}  {path.name}\n')
            result = subprocess.run([sys.executable, '-B', str(ROOT / '.github/scripts/release.py'), 'verify'], cwd=root, env={**os.environ, 'RELEASE_SHA': 'a' * 40}, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            manifest['commit'] = 'a' * 40; (root / 'dist/release-manifest.json').write_text(json.dumps(manifest))
            result = subprocess.run([sys.executable, '-B', str(ROOT / '.github/scripts/release.py'), 'verify'], cwd=root, env={**os.environ, 'RELEASE_SHA': 'a' * 40}, capture_output=True)
            self.assertEqual(0, result.returncode, result.stderr)


if __name__ == '__main__': unittest.main()
