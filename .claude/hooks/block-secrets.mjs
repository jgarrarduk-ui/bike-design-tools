#!/usr/bin/env node
// PreToolUse hook for Bash. Blocks two things the permission rules miss:
//   - git push, including flags before the subcommand (git -C … push)
//     and an absolute binary (/usr/bin/git push)
//   - a shell command that names a .env file other than .env.example
// Exit 0 leaves the normal permission prompt in place. Exit 2 blocks.
// A JSON parse failure exits 0 so a broken hook does not stop the session.
// This does not approve any command.

import fs from 'node:fs';

const WRAPPERS = new Set([
  'timeout', 'time', 'nice', 'nohup', 'stdbuf', 'command', 'builtin', 'xargs', 'sudo',
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
  const re = /(?:^|[\s'"`(=])((?:[A-Za-z0-9._~-]+[/\\])*?\.env(?:\.[A-Za-z0-9_-]+)*)/g;
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

function skipWrappers(words) {
  let i = 0;
  while (i < words.length && /^[A-Za-z_][A-Za-z0-9_]*=/.test(words[i])) i += 1;
  while (i < words.length && WRAPPERS.has(baseName(words[i]))) {
    const wrapped = baseName(words[i]);
    i += 1;
    if (wrapped === 'timeout' && /^\d/.test(words[i] || '')) i += 1;
    if (wrapped === 'sudo') {
      while (i < words.length && words[i].startsWith('-')) i += 1;
    }
  }
  return i;
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
    if (commandPushes(wordsOf(segment))) {
      return 'Blocked: git push is not allowed from Claude Code. Push from your own terminal.';
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
