"""Exercise real installer update functions using disposable files and mocked host services."""
import errno
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

SOURCE = (Path(__file__).resolve().parents[1] / 'install.sh').read_text()
HELPERS = SOURCE[SOURCE.index('purge_installer_dir() {'):SOURCE.index('set_vpnbot_webhooks() {')]
UPDATE = SOURCE[SOURCE.index('function update_bot() {'):SOURCE.index('function remove_bot() {')]


def bash(root, body, extra=''):
    result = subprocess.run(['bash', '-c', HELPERS + '\n' + extra + '\n' + body],
                            env={**os.environ, 'CASE_ROOT': str(root)}, capture_output=True, text=True)
    return result


def package(root):
    root.mkdir(parents=True, exist_ok=True)
    for name in ['index.php', 'function.php', 'table.php']:
        (root / name).write_text('<?php // new release\n')
    (root / 'composer.json').write_text('{}')
    (root / 'version').write_text('0.9.1-dev.1\n')
    (root / 'install.sh').write_text('#!/bin/bash\ntrue\n')
    (root / 'lang').mkdir()
    (root / 'lang/fa.php').write_text('<?php return [];')
    (root / '.hidden').write_text('new hidden asset')
    (root / 'install').mkdir()
    (root / 'install/index.php').write_text('<?php // installer must be removed\n')
    (root / 'cronbot').mkdir()
    (root / 'cronbot/register.php').write_text('<?php // registration fixture\n')
    (root / 'vpnbot/update').mkdir(parents=True)
    (root / 'vpnbot/update/index.php').write_text('<?php // new child code\n')


with tempfile.TemporaryDirectory(prefix='mirza-updater-test.') as temp:
    root = Path(temp)
    # Deterministically reproduce the filesystem race behind ENOTEMPTY: a new
    # entry appears after contents were deleted but before rmdir removes root.
    old = root / 'old-race'; old.mkdir(); (old / 'config.php').write_text('old')
    (old / 'config.php').unlink(); (old / 'new-worker-file').write_text('writer')
    try:
        old.rmdir()
        raise AssertionError('Expected ENOTEMPTY')
    except OSError as exc:
        assert exc.errno == errno.ENOTEMPTY and not (old / 'config.php').exists()

    flat = root / 'flat'; package(flat)
    nested = root / 'nested'; package(nested / 'release')
    for folder, expected in [(flat, flat), (nested, nested / 'release')]:
        result = bash(root, 'mirza_update_package_root "$PACKAGE"',
                      f'PACKAGE={str(folder)!r}')
        assert result.returncode == 0 and result.stdout.strip() == str(expected), result.stderr
    package(nested / 'second')
    assert bash(root, f'mirza_update_package_root {str(nested)!r}').returncode != 0
    junk = root / 'junk'; (junk / 'vendor').mkdir(parents=True)
    assert bash(root, f'mirza_update_package_root {str(junk)!r}').returncode != 0

    cases = ['flat', 'nested', 'copy-failure', 'restore-failure', 'invalid-php', 'rename-failure', 'retire-failure', 'writer-gap']
    for case in cases:
        work = root / case.replace('flat', 'flat-flow').replace('nested', 'nested-flow')
        work.mkdir()
        live = work / 'live'; live.mkdir()
        (live / 'version').write_text('0.5.9\n')
        (live / 'config.php').write_text("<?php\n$domainhosts='';\n// preserved config\n")
        (live / 'users.json').write_text('["kept"]')
        (live / 'lang/override').mkdir(parents=True)
        (live / 'lang/override/fa.php').write_text("<?php return ['custom' => 'فارسی 😀'];\n")
        (live / 'api').mkdir(); (live / 'api/.htaccess').write_text('custom access')
        (live / 'vpnbot/child').mkdir(parents=True)
        (live / 'vpnbot/child/config.php').write_text('<?php // child customization\n')
        (live / 'vpnbot/child/product.json').write_text('{"custom":true}')
        pkg = work / 'package'; package(pkg)
        if case == 'invalid-php':
            (pkg / 'index.php').write_text('<?php invalid syntax !!!')
        with zipfile.ZipFile(work / 'fixture.zip', 'w') as archive:
            for f in pkg.rglob('*'):
                if f.is_file():
                    prefix = 'release/' if case == 'nested' else ''
                    archive.write(f, prefix + str(f.relative_to(pkg)))
        update = UPDATE.replace('/var/www/html/mirzaprobotconfig', str(live))
        update = update.replace('/tmp/mirzaprobot_update', str(work / 'download'))
        update = update.replace('/root/install.sh', str(work / 'installed-installer.sh'))
        update = update.replace('/usr/local/bin/mirza', str(work / 'mirza-link'))
        stubs = r'''
CASE_KIND=CASE_VALUE
clear() { :; }; banner() { :; }; _sec() { :; }; show_menu() { :; }; show_step_error() { :; }
sleep() { :; }
ensure_connectivity() { return 0; }; upgrade_php_if_needed() { return 0; }
get_installed_version() { cat "$CASE_ROOT/live/version"; }
choose_source() { SRC_ZIP_URL='fixture'; SRC_LABEL='fixture'; }
ensure_php_mysql_cli() { PHP_DB_BIN=$(command -v php); }
sudo() {
    if [ "$1" = chown ]; then return 0; fi
    if [ "$1" = mv ] && [ "$CASE_KIND" = retire-failure ] && [ "$4" = "$CASE_ROOT/live" ]; then return 1; fi
    if [ "$1" = mv ] && [ "$CASE_KIND" = rename-failure ] && [[ "$4" = */new ]]; then return 1; fi
    if [ "$1" = mv ] && [ "$CASE_KIND" = writer-gap ] && [[ "$4" = */new ]]; then
        mkdir -p "$CASE_ROOT/live"; printf writer > "$CASE_ROOT/live/recreated"; return 1
    fi
    "$@"
}
backup_before_update() {
    UPDATE_BACKUP_DIR="$CASE_ROOT/full-backup"
    mkdir -p "$UPDATE_BACKUP_DIR"
    cp -a "$1" "$UPDATE_BACKUP_DIR/site"
}
run_step() {
    case "$1" in
        'Ensuring cron is installed and running') return 0 ;;
        'Downloading '*) cp "$CASE_ROOT/fixture.zip" "$CASE_ROOT/download/bot.zip" ;;
        'Installing PHP dependencies (composer)') mkdir -p "$EXTRACTED_DIR/vendor"; printf '<?php // loader\n' > "$EXTRACTED_DIR/vendor/autoload.php" ;;
        'Restoring vpnbots') [ "$CASE_KIND" != restore-failure ] && eval "$2" ;;
        'Updating database tables'|'Registering cron dispatcher'*|'Setting vpnbot webhooks') return 0 ;;
        *) eval "$2" ;;
    esac
}
'''.replace('CASE_VALUE', case)
        if case == 'copy-failure':
            stubs += '\nmove_extracted_files() { return 1; }\n'
        # The original live tree is retained; an open directory fd can still be
        # used by an old worker after publication, without requiring its deletion.
        live_fd = os.open(live, os.O_RDONLY | os.O_DIRECTORY)
        result = subprocess.run(['bash', '-c', HELPERS + update + stubs +
                                 '\nVPNBOT_BACKUP="$CASE_ROOT/child-backup"\nupdate_bot\n'],
                                env={**os.environ, 'CASE_ROOT': str(work)}, capture_output=True, text=True)
        writer_fd = os.open('old-worker.log', os.O_CREAT | os.O_WRONLY, 0o600, dir_fd=live_fd)
        os.write(writer_fd, b'worker still writing'); os.close(writer_fd); os.close(live_fd)
        success = case in ['flat', 'nested']
        assert (result.returncode == 0) == success, (case, result.stdout, result.stderr)
        assert (live / 'config.php').read_text().endswith('// preserved config\n'), case
        assert (live / 'users.json').read_text() == '["kept"]', case
        assert 'فارسی 😀' in (live / 'lang/override/fa.php').read_text(), case
        assert (live / 'vpnbot/child/product.json').read_text() == '{"custom":true}', case
        assert (live / 'api/.htaccess').read_text() == 'custom access', case
        if success:
            assert (live / 'version').read_text().strip() == '0.9.1-dev.1'
            retired = list(work.glob('.mirza-update.*/previous'))
            assert len(retired) == 1 and (retired[0] / 'version').read_text().strip() == '0.5.9'
            assert (retired[0] / 'old-worker.log').read_text() == 'worker still writing'
            assert not (live / 'old-worker.log').exists()
            assert (live / '.hidden').read_text() == 'new hidden asset'
            assert not (live / 'bot.zip').exists()
            assert not (live / 'install').exists()
            assert (work / 'mirza-link').is_symlink()
            assert (live / 'vpnbot/child/index.php').exists()
            assert oct(retired[0].parent.stat().st_mode & 0o777) == '0o700'
        else:
            assert (live / 'version').read_text().strip() == '0.5.9', case
            if case == 'writer-gap':
                saved = list(work.glob('.mirza-update.*/interrupted-writes/recreated'))
                assert len(saved) == 1 and saved[0].read_text() == 'writer'
print('Updater files: ENOTEMPTY reproduction, flat/nested roots, preservation, staged failures and rename rollback passed')
