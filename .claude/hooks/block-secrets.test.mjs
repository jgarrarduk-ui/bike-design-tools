// Example commands for the Bash guard rail. Not a security proof.
//   node .claude/hooks/block-secrets.test.mjs
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { blockReason } from './block-secrets.mjs';

const blocked = [
  'git push',
  'git push origin main',
  'git push --force',
  "git 'push'",
  'git -C /tmp push',
  '/usr/bin/git push',
  '/opt/homebrew/bin/git push --force',
  'env git push',
  'env FOO=1 git push',
  '/usr/bin/env git push',
  'exec git push',
  'eval git push',
  "eval 'git push origin main'",
  'npx git push',
  'npx --yes git push',
  'npm exec git push',
  'npm exec -- git push',
  'npm test && /usr/bin/git push',
  "sh -c 'env git push'",
  'cat server/.env',
  'cat .env.local',
  'head .env',
  "node -e \"require('fs').readFileSync('server/.env','utf8')\"",
];

const allowed = [
  'git status',
  'git diff',
  'git log --oneline',
  'git show HEAD',
  '/usr/bin/git status',
  'npm test',
  'cd server && npm test',
  'node --test server/test/cors.test.mjs',
  'npx --yes eslint',
  'echo "git push"',
  'git commit -m "save"',
  'git commit -m "never read .env"',
  '/usr/bin/git commit -m "never read .env"',
  'cat server/.env.example',
  'cat .envrc',
  'cat server/.envrc',
  'cat .environment',
  'cat .env_backup',
  'cat .env-old',
  'node test/notch-tests.mjs',
];

let failed = 0;
function check(command, shouldBlock) {
  const reason = blockReason(command);
  const got = Boolean(reason);
  if (got !== shouldBlock) {
    failed += 1;
    console.log((shouldBlock ? 'expected block: ' : 'expected allow: ') + command + (reason ? ' (' + reason + ')' : ''));
  }
}
for (const command of blocked) check(command, true);
for (const command of allowed) check(command, false);

const hook = fileURLToPath(new URL('./block-secrets.mjs', import.meta.url));
function hookExit(command) {
  const result = spawnSync(process.execPath, [hook], {
    input: JSON.stringify({ tool_name: 'Bash', tool_input: { command } }),
    encoding: 'utf8',
  });
  return result.status;
}
if (hookExit('env git push') !== 2) {
  failed += 1;
  console.log('stdin hook did not exit 2 for env git push');
}
if (hookExit('npm test') !== 0) {
  failed += 1;
  console.log('stdin hook did not exit 0 for npm test');
}
if (hookExit('cat .envrc') !== 0) {
  failed += 1;
  console.log('stdin hook did not exit 0 for cat .envrc');
}

const total = blocked.length + allowed.length + 3;
if (failed) {
  console.log(failed + ' failed of ' + total);
  process.exit(1);
}
console.log(total + ' hook checks passed');
