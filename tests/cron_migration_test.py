import os, subprocess, tempfile
from pathlib import Path
with tempfile.TemporaryDirectory() as tmp:
 p=Path(tmp); b=p/'bin'; b.mkdir(); bot=p/'bot'; (bot/'cronbot').mkdir(parents=True)
 for name in ['config.php','function.php','index.php']:(bot/name).write_text('<?php')
 (bot/'cronbot/run.php').write_text('<?php')
 cron=b/'crontab'; cron.write_text('#!/bin/sh\ncat "$CRON_FIXTURE"\n'); cron.chmod(0o700)
 fixture=p/'cron'; fixture.write_text('# comment /cronbot/keep.php\nMAILTO=test@example.test\n* * * * * php /missing/cronbot/gift.php\n* * * * * php '+str(bot)+'/cronbot/gift.php\n')
 r=subprocess.run(['php',str(Path(__file__).resolve().parents[1] / 'cronbot/migrate-crontab.php'),'--dry-run','--roots='+str(bot)],env={**os.environ,'PATH':str(b)+':'+os.environ['PATH'],'CRON_FIXTURE':str(fixture)},capture_output=True,text=True)
 assert r.returncode==0,(r.stdout,r.stderr)
 assert '# comment /cronbot/keep.php' in r.stdout and '/missing/cronbot/gift.php' in r.stdout,r.stdout
 assert str(bot)+'/cronbot/run.php' in r.stdout,r.stdout
 print('PASS: real migration dry-run preserves comments and unknown cronbot lines')
