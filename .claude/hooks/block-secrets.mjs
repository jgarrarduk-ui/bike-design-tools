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
//     +refspec) or a push whose destination is main or master.
//   - a command that names .env or .env.<suffix>, other than .env.example.
//     .envrc, .env_backup and .env-old are not secret files.
//
// Runs as `node` on PATH. If node is missing, Claude Code treats any exit
// other than 2 as fail-open and the normal permission prompt still applies.
// Exit 0 does not approve the command. Unparseable input exits 0.
// Checks: node .claude/hooks/block-secrets.test.mjs

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

function pushArguments(words) {
  if (gitSubcommand(words) === 'push') {
    const i = skipWrappers(words);
    const subIndex = skipFlags(words, i + 1);
    return words.slice(subIndex + 1);
  }
  const i = skipWrappers(words);
  const bin = baseName(words[i] || '');
  if (bin === 'sh' || bin === 'bash' || bin === 'zsh' || bin === 'dash') {
    let j = i + 1;
    while (j < words.length && words[j].startsWith('-') && !words[j].startsWith('--')) {
      if (words[j].includes('c')) return pushArguments(words.slice(j + 1));
      j += 1;
    }
    if (words[j] === '-c') return pushArguments(words.slice(j + 1));
  }
  return null;
}

function pushIsForced(args) {
  for (const word of args) {
    if (word === '--force' || word === '-f' || word.startsWith('--force-with-lease')) return true;
    if (word.startsWith('-') && !word.startsWith('--') && word.slice(1).includes('f')) return true;
    if (word.startsWith('+')) return true;
  }
  return false;
}

function destinationRef(spec) {
  let ref = spec;
  if (ref.startsWith('+')) ref = ref.slice(1);
  if (ref.includes('://')) return '';
  const colon = ref.indexOf(':');
  if (colon === -1) return ref;
  return ref.slice(colon + 1);
}

function pushTargetsMain(args) {
  for (const word of args) {
    if (word.startsWith('-')) continue;
    const name = destinationRef(word).replace(/^refs\/heads\//, '');
    if (name === 'main' || name === 'master') return true;
  }
  return false;
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

export function blockReason(command) {
  if (typeof command !== 'string' || !command.trim()) return '';
  for (const segment of splitSegments(command)) {
    const words = wordsOf(segment);
    if (commandPushes(words)) {
      if (insideClaudeCode()) {
        return 'Blocked: git push is not allowed from Claude Code. Push from your own terminal.';
      }
      const args = pushArguments(words) || [];
      if (pushIsForced(args)) {
        return 'Blocked: force push is not allowed.';
      }
      if (pushTargetsMain(args)) {
        return 'Blocked: pushing to main or master is not allowed.';
      }
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
