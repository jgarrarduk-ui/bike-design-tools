#!/usr/bin/env node
// Guard rail, not a security boundary. It catches common spellings only.
// Deny rules in .claude/settings.json are the other layer and have the
// same limit. Globs (cat server/.e*), quote-splitting (cat server/.e''nv),
// and a program that opens the file itself (dotenv) are not covered.
//
// Blocks:
//   - git push while CLAUDECODE is set (Claude Code). The same spellings as
//     before: flags (git -C … push), an absolute binary (/usr/bin/git push),
//     and the command after env, exec, eval, npx, npm exec, nice, nohup, or time.
//   - outside Claude Code, a force push (--force, -f, --force-with-lease,
//     +refspec), a mirror or all-branch push, or a push whose destination
//     is main or master. A destination of heads/main or refs/heads/main
//     counts. A push with no explicit non-main destination resolves
//     @{push} (so an upstream of main is refused even when the local
//     branch is not), and falls back to the current branch when there is
//     no upstream. A preceding cd is the directory that resolution uses.
//     The push is refused if that destination is main or master or cannot
//     be resolved.
//   - a command that names .env or .env.<suffix>, other than .env.example.
//     .envrc, .env_backup and .env-old are not secret files.
//
// Runs as `node` on PATH. If node is missing, Claude Code treats any exit
// other than 2 as fail-open and the normal permission prompt still applies.
// Exit 0 does not approve the command. Unparseable input exits 0.
// Checks: node .claude/hooks/block-secrets.test.mjs

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const WRAPPERS = new Set([
  'timeout', 'time', 'nice', 'nohup', 'stdbuf', 'command', 'builtin', 'xargs', 'sudo',
  'env', 'exec', 'eval', 'npx', 'npm',
]);

function wordsOf(command) {
  const out = [];
  const re = /'([^']*)'|"([^"]*)"|`([^`]*)`|(\S+)/g;
  let match;
  while ((match = re.exec(command))) {
    const chunk = match[1] ?? match[2] ?? match[3] ?? match[4];
    for (const part of chunk.split(/\s+/)) {
      if (part) out.push(part);
    }
  }
  return out;
}

function baseName(token) {
  const cleaned = token.replace(/^[0-9]*>+/, '').replace(/[<>]/g, '');
  return cleaned.split(/[/\\]/).pop() || '';
}

function namesSecretEnv(token) {
  if (token.startsWith('$')) return false;
  const base = baseName(token);
  if (base === '.env.example') return false;
  return base === '.env' || base.startsWith('.env.');
}

function chunkNamesSecretEnv(chunk) {
  // Boundary after ".env" so .envrc / .env_backup / .env-old are not .env.
  // .env.example and .env.local still match; namesSecretEnv allows the example.
  const re = /(?:^|[\s'"`(=])((?:[A-Za-z0-9._~-]+[/\\])*?\.env(?![A-Za-z0-9_-])(?:\.[A-Za-z0-9_-]+)*)/g;
  let match;
  while ((match = re.exec(chunk))) {
    if (namesSecretEnv(match[1])) return true;
  }
  return false;
}

function skipFlags(words, index) {
  let i = index;
  while (i < words.length && words[i].startsWith('-')) {
    const flag = words[i];
    i += 1;
    if (flag.includes('=') || flag.startsWith('--')) continue;
    const needsValue = flag === '-C' || flag === '-c' || flag === '--git-dir' || flag === '--work-tree';
    if (needsValue && i < words.length && !words[i].startsWith('-')) i += 1;
  }
  return i;
}

function isAssignment(token) {
  return /^[A-Za-z_][A-Za-z0-9_]*=/.test(token);
}

function skipWrapperFlags(words, index, wrapped) {
  let i = index;
  if (wrapped === 'timeout') {
    while (i < words.length && words[i].startsWith('-')) i += 1;
    if (/^\d/.test(words[i] || '')) i += 1;
    return i;
  }
  if (wrapped === 'nice' || wrapped === 'nohup' || wrapped === 'time') {
    while (i < words.length && words[i].startsWith('-')) {
      const flag = words[i];
      i += 1;
      const takesValue = (wrapped === 'nice' && (flag === '-n' || flag === '--adjustment'))
        || (wrapped === 'time' && (flag === '-f' || flag === '-o' || flag === '--format' || flag === '--output'));
      if (takesValue && i < words.length && !words[i].startsWith('-')) i += 1;
    }
    return i;
  }
  if (wrapped === 'sudo' || wrapped === 'exec' || wrapped === 'env' || wrapped === 'npx' || wrapped === 'npm') {
    while (i < words.length && (words[i].startsWith('-') || (wrapped === 'env' && isAssignment(words[i])))) {
      const flag = words[i];
      if (isAssignment(flag)) {
        i += 1;
        continue;
      }
      i += 1;
      const takesValue = flag === '-u' || flag === '--unset' || flag === '-a'
        || flag === '--package' || flag === '-p' || flag === '--call'
        || flag === '--prefix' || flag === '-C' || flag === '-g' || flag === '--group';
      if (takesValue && i < words.length && !words[i].startsWith('-') && !isAssignment(words[i])) i += 1;
    }
  }
  return i;
}

function skipWrappers(words) {
  let i = 0;
  for (;;) {
    while (i < words.length && isAssignment(words[i])) i += 1;
    if (i >= words.length || !WRAPPERS.has(baseName(words[i]))) return i;
    const wrapped = baseName(words[i]);
    i += 1;
    i = skipWrapperFlags(words, i, wrapped);
  }
}

function gitSubcommand(words) {
  const i = skipWrappers(words);
  if (baseName(words[i] || '') !== 'git') return '';
  return words[skipFlags(words, i + 1)] || '';
}

function commandPushes(words) {
  if (gitSubcommand(words) === 'push') return true;
  const i = skipWrappers(words);
  const bin = baseName(words[i] || '');
  if (bin === 'sh' || bin === 'bash' || bin === 'zsh' || bin === 'dash') {
    let j = i + 1;
    while (j < words.length && words[j].startsWith('-') && !words[j].startsWith('--')) {
      if (words[j].includes('c')) return commandPushes(words.slice(j + 1));
      j += 1;
    }
    if (words[j] === '-c') return commandPushes(words.slice(j + 1));
  }
  return false;
}

function insideClaudeCode() {
  const value = process.env.CLAUDECODE;
  return typeof value === 'string' && value !== '';
}

function pushContext(words) {
  if (gitSubcommand(words) === 'push') {
    const i = skipWrappers(words);
    const subIndex = skipFlags(words, i + 1);
    return { args: words.slice(subIndex + 1), gitWords: words.slice(i) };
  }
  const i = skipWrappers(words);
  const bin = baseName(words[i] || '');
  if (bin === 'sh' || bin === 'bash' || bin === 'zsh' || bin === 'dash') {
    let j = i + 1;
    while (j < words.length && words[j].startsWith('-') && !words[j].startsWith('--')) {
      if (words[j].includes('c')) return pushContext(words.slice(j + 1));
      j += 1;
    }
    if (words[j] === '-c') return pushContext(words.slice(j + 1));
  }
  return null;
}

function gitGlobals(gitWords) {
  const globals = [];
  let j = 1;
  while (j < gitWords.length && gitWords[j].startsWith('-')) {
    const flag = gitWords[j];
    if (flag === '-C' || flag === '--git-dir' || flag === '--work-tree') {
      const value = gitWords[j + 1];
      if (!value || value.startsWith('-')) return null;
      globals.push(flag, value);
      j += 2;
      continue;
    }
    if (flag.startsWith('--git-dir=') || flag.startsWith('--work-tree=')) {
      globals.push(flag);
      j += 1;
      continue;
    }
    if (flag === '-c') {
      if (!gitWords[j + 1] || gitWords[j + 1].startsWith('-')) return null;
      j += 2;
      continue;
    }
    j += 1;
  }
  return globals;
}

function gitOutput(globals, args, options) {
  if (options && typeof options.gitRunner === 'function') {
    try {
      const out = options.gitRunner(globals, args);
      if (out === null || out === undefined) return null;
      return String(out).trim();
    } catch {
      return null;
    }
  }
  try {
    return execFileSync('git', globals.concat(args), {
      encoding: 'utf8',
      timeout: 5000,
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
  } catch {
    return null;
  }
}

function branchFromGit(gitWords, options) {
  if (!gitWords) return null;
  const globals = gitGlobals(gitWords);
  if (!globals) return null;
  const out = gitOutput(globals, ['rev-parse', '--abbrev-ref', 'HEAD'], options);
  if (!out || out === 'HEAD') return null;
  return out;
}

function pushTargetFromGit(gitWords, options) {
  if (!gitWords) return null;
  const globals = gitGlobals(gitWords);
  if (!globals) return null;
  const out = gitOutput(
    globals,
    ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{push}'],
    options,
  );
  if (!out || out === '@{push}') return null;
  return out;
}

function remotesFromGit(gitWords, options) {
  if (!gitWords) return null;
  const globals = gitGlobals(gitWords);
  if (!globals) return null;
  const out = gitOutput(globals, ['remote'], options);
  if (out === null) return null;
  return out.split('\n').map((line) => line.trim()).filter(Boolean);
}

function flagTakesValue(flag) {
  if (flag.includes('=')) return false;
  return flag === '-o' || flag === '--push-option' || flag === '--repo'
    || flag === '--receive-pack' || flag === '--exec' || flag === '--recurse-submodules';
}

function parsePushArgs(args) {
  let mirror = false;
  let all = false;
  let forced = false;
  const positionals = [];
  for (let i = 0; i < args.length; i += 1) {
    const word = args[i];
    if (word === '--') {
      positionals.push(...args.slice(i + 1));
      break;
    }
    if (word.startsWith('-')) {
      if (word === '--mirror') mirror = true;
      if (word === '--all') all = true;
      if (word === '--force' || word === '-f' || word.startsWith('--force-with-lease')) forced = true;
      if (!word.startsWith('--') && word.slice(1).includes('f')) forced = true;
      if (flagTakesValue(word)) {
        if (!args[i + 1] || args[i + 1].startsWith('-')) return { error: true };
        i += 1;
      }
      continue;
    }
    if (word.startsWith('+')) forced = true;
    positionals.push(word);
  }
  return { mirror, all, forced, positionals, error: false };
}

function looksLikeRefspec(word) {
  if (word.includes('://')) return false;
  return word.startsWith('+') || word.includes(':') || word === 'HEAD'
    || word.startsWith('refs/') || word.startsWith('heads/');
}

function branchName(ref) {
  let name = ref;
  if (name.startsWith('+')) name = name.slice(1);
  if (name.startsWith('refs/heads/')) name = name.slice('refs/heads/'.length);
  if (name.startsWith('heads/')) name = name.slice('heads/'.length);
  return name;
}

function isProtectedBranch(name) {
  return name === 'main' || name === 'master';
}

// @{push} is origin/main, or refs/remotes/origin/main when abbrev-ref is not applied.
function pushDestinationBranch(ref) {
  let name = String(ref || '').trim();
  if (name.startsWith('+')) name = name.slice(1);
  const remoteRef = name.match(/^refs\/remotes\/[^/]+\/(.+)$/);
  if (remoteRef) return remoteRef[1];
  if (name.startsWith('refs/heads/') || name.startsWith('heads/')) return branchName(name);
  if (!name.startsWith('refs/') && name.includes('/')) return name.slice(name.indexOf('/') + 1);
  return branchName(name);
}

function refspecInfo(spec) {
  let ref = spec;
  if (ref.startsWith('+')) ref = ref.slice(1);
  const colon = ref.indexOf(':');
  if (colon === -1) {
    if (ref === 'HEAD') return { implicit: true, name: '' };
    return { implicit: false, name: branchName(ref) };
  }
  const dest = ref.slice(colon + 1);
  if (dest === 'HEAD') return { implicit: true, name: '' };
  return { implicit: false, name: branchName(dest) };
}

function classifyPositionals(positionals, options, gitWords) {
  let refspecs;
  if (positionals.length === 0) {
    return { needsBranch: true, targetsProtected: false };
  }
  if (positionals.length === 1) {
    const only = positionals[0];
    if (only.includes('://')) return { needsBranch: true, targetsProtected: false };
    if (looksLikeRefspec(only)) {
      refspecs = [only];
    } else {
      const remotes = Object.prototype.hasOwnProperty.call(options, 'remotes')
        ? options.remotes
        : remotesFromGit(gitWords, options);
      if (!Array.isArray(remotes)) return { error: true };
      if (remotes.includes(only)) return { needsBranch: true, targetsProtected: false };
      refspecs = [only];
    }
  } else if (looksLikeRefspec(positionals[0])) {
    refspecs = positionals;
  } else {
    refspecs = positionals.slice(1);
  }
  let needsBranch = refspecs.length === 0;
  for (const spec of refspecs) {
    const info = refspecInfo(spec);
    if (info.implicit) {
      needsBranch = true;
      continue;
    }
    if (isProtectedBranch(info.name)) return { targetsProtected: true, needsBranch: false };
  }
  return { needsBranch, targetsProtected: false };
}

const MAIN_PUSH = 'Blocked: pushing to main or master is not allowed.';
const UNRESOLVED = 'Blocked: the current branch could not be resolved, so the push was refused.';

function implicitDestination(options, gitWords) {
  const injectedPush = Object.prototype.hasOwnProperty.call(options, 'pushTarget');
  const injectedBranch = Object.prototype.hasOwnProperty.call(options, 'currentBranch');
  if (injectedPush) {
    if (typeof options.pushTarget === 'string' && options.pushTarget !== '') {
      return pushDestinationBranch(options.pushTarget);
    }
  } else if (!injectedBranch) {
    const fromGit = pushTargetFromGit(gitWords, options);
    if (typeof fromGit === 'string' && fromGit !== '') return pushDestinationBranch(fromGit);
  }
  const branch = injectedBranch ? options.currentBranch : branchFromGit(gitWords, options);
  if (typeof branch !== 'string' || branch === '' || branch === 'HEAD') return null;
  return branchName(branch);
}

function outsidePushReason(args, options, gitWords) {
  const parsed = parsePushArgs(args);
  if (parsed.error) return 'Blocked: the push could not be parsed, so it was refused.';
  if (parsed.mirror) return 'Blocked: a mirror push is not allowed.';
  if (parsed.all) return 'Blocked: pushing all branches is not allowed.';
  if (parsed.forced) return 'Blocked: force push is not allowed.';
  const spec = classifyPositionals(parsed.positionals, options, gitWords);
  if (spec.error) return UNRESOLVED;
  if (spec.targetsProtected) return MAIN_PUSH;
  if (!spec.needsBranch) return '';
  if (!gitWords) return UNRESOLVED;
  const branch = implicitDestination(options, gitWords);
  if (typeof branch !== 'string' || branch === '' || branch === 'HEAD') return UNRESOLVED;
  if (isProtectedBranch(branchName(branch))) return MAIN_PUSH;
  return '';
}

function cdDirectory(segment) {
  const words = wordsOf(segment);
  let i = 0;
  while (i < words.length && isAssignment(words[i])) i += 1;
  if (baseName(words[i] || '') !== 'cd') return undefined;
  const dest = words[i + 1];
  if (!dest || dest === '-' || dest.startsWith('-') || words[i + 2]) return null;
  return dest;
}

function gitWordsHaveDir(gitWords) {
  for (let j = 1; j < gitWords.length; j += 1) {
    const flag = gitWords[j];
    if (!flag.startsWith('-')) return false;
    if (flag === '-C' || flag === '--git-dir' || flag === '--work-tree') return true;
    if (flag.startsWith('--git-dir=') || flag.startsWith('--work-tree=')) return true;
  }
  return false;
}

function applyWorkDir(gitWords, dir) {
  if (!Array.isArray(gitWords) || !gitWords.length || gitWordsHaveDir(gitWords)) return gitWords;
  const out = gitWords.slice();
  out.splice(1, 0, '-C', dir);
  return out;
}

function splitSegments(command) {
  const parts = [];
  let current = '';
  let quote = '';
  for (let i = 0; i < command.length; i += 1) {
    const ch = command[i];
    if (quote) {
      current += ch;
      if (ch === quote) quote = '';
      continue;
    }
    if (ch === '"' || ch === "'" || ch === '`') {
      quote = ch;
      current += ch;
      continue;
    }
    const two = command.slice(i, i + 2);
    if (two === '&&' || two === '||') {
      parts.push(current);
      current = '';
      i += 1;
      continue;
    }
    if (ch === ';' || ch === '|' || ch === '\n') {
      parts.push(current);
      current = '';
      continue;
    }
    current += ch;
  }
  parts.push(current);
  return parts;
}

function withoutCommitMessage(segment) {
  if (gitSubcommand(wordsOf(segment)) !== 'commit') return segment;
  return segment.replace(/-m\s+(?:'[^']*'|"[^"]*"|`[^`]*`|\S+)/g, ' ');
}

export function blockReason(command, options = {}) {
  if (typeof command !== 'string' || !command.trim()) return '';
  const pushOptions = options && typeof options === 'object' ? options : {};
  let workDir;
  for (const segment of splitSegments(command)) {
    const cd = cdDirectory(segment);
    if (cd !== undefined) workDir = cd;
    const words = wordsOf(segment);
    if (commandPushes(words)) {
      if (insideClaudeCode()) {
        return 'Blocked: git push is not allowed from Claude Code. Push from your own terminal.';
      }
      const context = pushContext(words) || { args: [], gitWords: words };
      let gitWords = context.gitWords;
      if (workDir === null) gitWords = null;
      else if (typeof workDir === 'string' && workDir !== '') gitWords = applyWorkDir(gitWords, workDir);
      const reason = outsidePushReason(context.args, pushOptions, gitWords);
      if (reason) return reason;
    }
    const scanned = withoutCommitMessage(segment);
    if (chunkNamesSecretEnv(scanned) || wordsOf(scanned).some(namesSecretEnv)) {
      return 'Blocked: do not read .env files. server/.env.example is the env reference.';
    }
  }
  return '';
}

if (process.argv[1] && process.argv[1].endsWith('block-secrets.mjs') && process.env.HOOK_SELFTEST !== '1') {
  let raw = '';
  try {
    raw = fs.readFileSync(0, 'utf8');
  } catch {
    process.exit(0);
  }
  let input;
  try {
    input = JSON.parse(raw);
  } catch {
    process.exit(0);
  }
  const command = input && input.tool_input && input.tool_input.command;
  const reason = blockReason(command);
  if (!reason) process.exit(0);
  process.stderr.write(reason + '\n');
  process.exit(2);
}
