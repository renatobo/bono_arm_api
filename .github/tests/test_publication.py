"""Publication must be verified before the production version is advertised."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

import test_release

CONFIG, ROOT = test_release.CONFIG, test_release.ROOT

sys.path.insert(0, str(ROOT / '.github/scripts'))
spec = importlib.util.spec_from_file_location('publication', ROOT / '.github/scripts/publication.py')
publication = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publication)


class PublicationTests(unittest.TestCase):
    def fixture(self, root):
        fixtures = test_release.ReleaseTests()
        text = fixtures.fixture(root)
        path = fixtures.archive(root, text)
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        (path.parent / (path.name + '.sha256')).write_text(f'{digest}  {path.name}\n')
        release = dict(tag_name='v1.0.5', draft=False, prerelease=False,
                       published_at='2026-11-17T00:00:00Z', body='Notes\r\n', assets=[
                           dict(name=path.name, state='uploaded', size=path.stat().st_size, digest='sha256:' + digest),
                           dict(name=path.name + '.sha256', state='uploaded', size=90)])
        return path, digest, release

    def test_published_matching_release(self):
        with tempfile.TemporaryDirectory() as folder:
            path, digest, release = self.fixture(Path(folder))
            publication.check_release(release, '1.0.5', path.name, 'Notes\n')
            self.assertEqual(digest, publication.check_download(path.parent, release, '1.0.5', CONFIG, digest))

    def test_wrong_latest_release_draft_notes_or_missing_assets_rejected(self):
        mutations = [dict(tag_name='v1.0.4'), dict(draft=True), dict(prerelease=True),
                     dict(published_at=None), dict(body='Wrong notes'), dict(assets=[])]
        with tempfile.TemporaryDirectory() as folder:
            path, _, release = self.fixture(Path(folder))
            for changes in mutations:
                with self.subTest(changes=changes), self.assertRaises(ValueError):
                    publication.check_release({**release, **changes}, '1.0.5', path.name, 'Notes')
            release['assets'][0]['state'] = 'new'
            with self.assertRaises(ValueError):
                publication.check_release(release, '1.0.5', path.name, 'Notes')

    def test_other_tested_artifact_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            path, _, release = self.fixture(Path(folder))
            with self.assertRaisesRegex(ValueError, 'CI-tested'):
                publication.check_download(path.parent, release, '1.0.5', CONFIG, '0' * 64)

    def test_bad_checksum_or_github_digest_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            path, _, release = self.fixture(Path(folder))
            wrong = copy.deepcopy(release); wrong['assets'][0]['digest'] = 'sha256:' + '0' * 64
            with self.assertRaises(ValueError):
                publication.check_download(path.parent, wrong, '1.0.5', CONFIG)
            (path.parent / (path.name + '.sha256')).write_text('wrong checksum')
            with self.assertRaises(ValueError):
                publication.check_download(path.parent, release, '1.0.5', CONFIG)

    def test_tag_must_point_at_exact_merge_commit(self):
        for annotated in [False, True]:
            responses = [json.dumps(dict(object=dict(type='commit', sha='b' * 40)))]
            if annotated:
                responses.insert(0, json.dumps(dict(object=dict(type='tag', sha='c' * 40))))
            with self.subTest(annotated=annotated), patch.object(publication, 'gh', side_effect=responses):
                with self.assertRaisesRegex(ValueError, 'exact release commit'):
                    publication.check_tag('owner/repo', '1.0.5', 'a' * 40)
        with patch.object(publication, 'gh', return_value=json.dumps(dict(object=dict(type='commit', sha='a' * 40)))):
            publication.check_tag('owner/repo', '1.0.5', 'a' * 40)

    def test_failed_verification_never_advertises_candidate(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root)
            with patch.object(publication, 'verify', side_effect=ValueError('download mismatch')):
                with patch.object(publication, 'git') as git:
                    with self.assertRaisesRegex(ValueError, 'download mismatch'):
                        publication.run('promote', root, CONFIG, 'a' * 40)
                    git.assert_not_called()

    def test_rejected_promotion_never_pushes_update_branch(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root)
            with patch.object(publication, 'verify'), patch.object(publication, 'promotion_check', side_effect=ValueError('backwards')):
                with patch.object(publication, 'git') as git:
                    with self.assertRaisesRegex(ValueError, 'backwards'):
                        publication.run('promote', root, CONFIG, 'a' * 40)
                    git.assert_not_called()

    def test_successful_promotion_requires_remote_read_back(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root)
            with patch.object(publication, 'verify'), patch.object(publication, 'promotion_check'):
                with patch.object(publication, 'git', side_effect=['', 'b' * 40 + '\trefs/heads/stable']):
                    with self.assertRaisesRegex(ValueError, 'Update branch'):
                        publication.run('promote', root, CONFIG, 'a' * 40)

    def test_update_branch_never_moves_backwards(self):
        publication.check_forward('1.0.5', '1.0.6', True)
        publication.check_forward('1.0.5', '1.0.5', True)
        for previous, candidate, ancestor in [('1.0.6', '1.0.5', True), ('1.0.5', '1.0.6', False)]:
            with self.assertRaises(ValueError):
                publication.check_forward(previous, candidate, ancestor)


if __name__ == '__main__': unittest.main()
