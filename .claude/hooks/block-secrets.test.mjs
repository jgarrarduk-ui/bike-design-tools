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
  'nice git push',
  'nice -n 10 git push',
  'nohup git push',
  'time git push',
  'time -p git push',
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
  'nice echo ok',
  'time git status',
  'nohup npm test',
];

let failed = 0;
let checks = 0;
function check(command, shouldBlock, options) {
  checks += 1;
  const reason = blockReason(command, options || {});
  const got = Boolean(reason);
  if (got !== shouldBlock) {
    failed += 1;
    console.log((shouldBlock ? 'expected block: ' : 'expected allow: ') + command + (reason ? ' (' + reason + ')' : ''));
  }
}

const previousClaude = process.env.CLAUDECODE;
process.env.CLAUDECODE = '1';
for (const command of blocked) check(command, true);
for (const command of allowed) check(command, false);
check('git push -u origin cursor/hook-allow-cloud-push', true);

const hook = fileURLToPath(new URL('./block-secrets.mjs', import.meta.url));
function hookExit(command) {
  const result = spawnSync(process.execPath, [hook], {
    input: JSON.stringify({ tool_name: 'Bash', tool_input: { command } }),
    encoding: 'utf8',
    env: process.env,
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
if (hookExit('nice git push') !== 2) {
  failed += 1;
  console.log('stdin hook did not exit 2 for nice git push');
}
checks += 4;

if (previousClaude === undefined) delete process.env.CLAUDECODE;
else process.env.CLAUDECODE = previousClaude;

const outside = [
  ['git push -u origin cursor/hook-allow-cloud-push', false],
  ['git push origin feature', false],
  ['nice git push -u origin feature', false],
  ['nice -n 10 git push origin feature', false],
  ['nohup git push origin feature', false],
  ['time git push origin feature', false],
  ['time -p git push origin feature', false],
  ['git push --force', true],
  ['git push --force origin feature', true],
  ['git push -f origin feature', true],
  ['git push -uf origin feature', true],
  ['git push --force-with-lease origin feature', true],
  ['git push --force-with-lease=feature origin feature', true],
  ['git push origin +feature', true],
  ['git push origin +feature:other', true],
  ['git push origin main', true],
  ['git push origin master', true],
  ['git push origin feature:main', true],
  ['git push origin feature:master', true],
  ['git push origin HEAD:refs/heads/main', true],
  ['git push origin :main', true],
  ['nice git push --force', true],
  ['time git push origin main', true],
  ['nohup git push origin master', true],
  ["sh -c 'git push origin feature'", false],
  ["sh -c 'git push origin main'", true],
  ['cat server/.env', true],
  ['cat server/.env.example', false],
  ['cat .envrc', false],
];
for (const [command, shouldBlock] of outside) check(command, shouldBlock);

const feature = { currentBranch: 'cursor/hook-allow-cloud-push', remotes: ['origin'] };
const onMain = { currentBranch: 'main', remotes: ['origin'] };
const onMaster = { currentBranch: 'master', remotes: ['origin'] };
const unresolved = { currentBranch: null, remotes: ['origin'] };
const remoteUnknown = { currentBranch: 'cursor/hook-allow-cloud-push', remotes: null };
const implicit = [
  ['git push --mirror', true, feature],
  ['git push --mirror origin', true, feature],
  ['git push origin --mirror', true, feature],
  ['git push --all', true, feature],
  ['git push --all origin', true, feature],
  ['git push origin --all', true, feature],
  ['git push', false, feature],
  ['git push', true, onMain],
  ['git push', true, onMaster],
  ['git push', true, unresolved],
  ['git push origin', true, onMain],
  ['git push origin', false, feature],
  ['git push origin', true, remoteUnknown],
  ['git push origin HEAD', true, onMain],
  ['git push origin HEAD', true, onMaster],
  ['git push origin HEAD', false, feature],
  ['git push origin HEAD', true, unresolved],
  ['git -c push.default=current push', true, onMain],
  ['git -c push.default=current push', false, feature],
  ['git -c push.default=current push', true, unresolved],
  ['git push origin feature:heads/main', true, feature],
  ['git push origin feature:refs/heads/main', true, feature],
  ['git push origin heads/main', true, feature],
  ['git push origin refs/heads/main', true, feature],
  ['git push origin HEAD:main', true, feature],
  ['git push origin HEAD:master', true, feature],
  ['git push origin HEAD:heads/main', true, feature],
  ['git push origin HEAD:refs/heads/master', true, feature],
  ['git push main', true, feature],
  ['git push feature', false, onMain],
  ['git push origin HEAD:feature', false, onMain],
  ['nice git push', true, onMain],
  ['time git push origin HEAD', true, onMain],
  ["sh -c 'git push'", true, onMain],
  ["sh -c 'git push --mirror origin'", true, feature],
  ['git push', true, { currentBranch: 'feature', pushTarget: 'refs/remotes/origin/main', remotes: ['origin'] }],
  ['git push origin', true, { currentBranch: 'feature', pushTarget: 'refs/remotes/origin/main', remotes: ['origin'] }],
  ['git push', true, { currentBranch: 'feature', pushTarget: 'origin/main', remotes: ['origin'] }],
  ['git push origin', true, { currentBranch: 'feature', pushTarget: 'origin/master', remotes: ['origin'] }],
  ['git push', false, { currentBranch: 'feature', pushTarget: 'refs/remotes/origin/cursor/hook-allow-cloud-push', remotes: ['origin'] }],
  ['git push origin', false, { currentBranch: 'main', pushTarget: 'origin/cursor/hook-allow-cloud-push', remotes: ['origin'] }],
  ['git push', false, { currentBranch: 'feature', pushTarget: null, remotes: ['origin'] }],
  ['git push', true, { currentBranch: 'main', pushTarget: null, remotes: ['origin'] }],
  ['git push origin feature', false, { currentBranch: 'feature', pushTarget: 'refs/remotes/origin/main', remotes: ['origin'] }],
  ['cd && git push', true, feature],
  ['cd - && git push', true, feature],
  ['cd - && git push origin feature', false, onMain],
];
for (const [command, shouldBlock, options] of implicit) check(command, shouldBlock, options);

const cdCalls = [];
function cdRunner(globals, args) {
  cdCalls.push({ globals: globals.slice(), args: args.slice() });
  if (args.includes('@{push}')) {
    if (globals[1] === '/other/repo') return 'refs/remotes/origin/feature';
    if (globals[1] === '/tracks-main') return 'origin/main';
    return null;
  }
  if (args.includes('HEAD')) return 'feature';
  if (args[0] === 'remote') return 'origin';
  return null;
}
check('cd /other/repo && git push', false, { gitRunner: cdRunner });
check('cd /other/repo && git push origin', false, { gitRunner: cdRunner });
check('cd /tracks-main && git push', true, { gitRunner: cdRunner });
check('cd /tracks-main && git push origin', true, { gitRunner: cdRunner });
check('cd /other/repo && git push origin feature', false, { gitRunner: cdRunner });
check('cd /other/repo && git -C /already push', false, {
  gitRunner(globals, args) {
    cdCalls.push({ globals: globals.slice(), args: args.slice() });
    if (globals[1] !== '/already') return 'origin/main';
    if (args.includes('@{push}')) return null;
    if (args.includes('HEAD')) return 'feature';
    return 'origin';
  },
});
const sawOther = cdCalls.some((call) => call.globals[0] === '-C' && call.globals[1] === '/other/repo' && call.args.includes('@{push}'));
const sawTracks = cdCalls.some((call) => call.globals[0] === '-C' && call.globals[1] === '/tracks-main');
const sawAlready = cdCalls.some((call) => call.globals[0] === '-C' && call.globals[1] === '/already');
const replacedExplicit = cdCalls.some((call) => call.globals.includes('/other/repo') && call.globals.includes('/already'));
if (!sawOther || !sawTracks || !sawAlready || replacedExplicit) {
  failed += 1;
  console.log('cd did not pass -C to the branch lookup');
}
checks += 1;
if (hookExit('git push -u origin feature') !== 0) {
  failed += 1;
  console.log('stdin hook blocked a normal branch push outside Claude Code');
}
if (hookExit('git push --force origin feature') !== 2) {
  failed += 1;
  console.log('stdin hook allowed a force push outside Claude Code');
}
if (hookExit('git push origin main') !== 2) {
  failed += 1;
  console.log('stdin hook allowed a push to main outside Claude Code');
}
checks += 3;

process.env.CLAUDECODE = '1';
if (hookExit('git push -u origin feature') !== 2) {
  failed += 1;
  console.log('stdin hook allowed a push while CLAUDECODE=1');
}
checks += 1;
if (previousClaude === undefined) delete process.env.CLAUDECODE;
else process.env.CLAUDECODE = previousClaude;

const total = checks;
if (failed) {
  console.log(failed + ' failed of ' + total);
  process.exit(1);
}
console.log(total + ' hook checks passed');
