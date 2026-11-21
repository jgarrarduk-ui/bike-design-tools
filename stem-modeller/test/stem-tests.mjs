// Headless checks for the Stem Modeller engine. Extracts the block between
// ==ENGINE-START== and ==ENGINE-END== from index.html and runs it with
// new Function, the same way the flex-stay tests do. Exits 1 on any failure.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'index.html'), 'utf8');
const engine = src.split('// ==ENGINE-START==')[1].split('// ==ENGINE-END==')[0].replace(/^[^\n]*/, '');
const E = new Function(engine + '\nreturn {syncGeom, frontEnd, stemPoints, pose, measure, radii, D2R};')();

// DEF from the page, by the same split the flex-stay tests use.
const defSrc = src.split('const DEF=')[1].split('\n};')[0] + '\n}';
const DEF = new Function('return ' + defSrc)();

let pass = 0, fail = 0;
function ok(cond, name, detail = '') {
  if (cond) { pass++; console.log('  ok   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (detail ? '  ' + detail : '')); }
}
const near = (a, b, tol = 1e-6) => Math.abs(a - b) <= tol;
const cfg = (over = {}) => E.syncGeom({ ...structuredClone(DEF.cfg), ...over });
const stem = (over = {}) => ({ name: '', spacers: 0, clamp: 0, rise: 0, length: 0, barDia: 35, ...over });

console.log('geometry chain');
{
  // Same chain and numbers as the suspension designer's shipped default.
  const c = cfg();
  ok(near(c.stack, 654.5403733659439, 1e-9), 'stack matches the suspension designer default', c.stack);
  const c2 = cfg(); c2.stack = 700; E.syncGeom(c2, 'stack');
  const back = E.syncGeom({ ...c2 }).stack;
  ok(near(back, 700, 1e-9), 'typed stack solves head tube length and back', back);
  ok(near(c.drop, (c.rw / 2 + c.tyreR) - c.bbh), 'drop is rear radius minus BB height');
}

console.log('static pose');
for (const [rw, fw] of [[622, 622], [584, 622], [559, 686]]) {
  const c = cfg({ rw, fw });
  const P = E.pose(c, false);
  ok(near(P.beta, 0, 1e-12), `static is level, ${rw}/${fw}`, P.beta);
  ok(near(P.bbh, c.bbh, 1e-9), `static BB height is as typed, ${rw}/${fw}`, P.bbh);
  const m = E.measure(c, stem(), false);
  ok(near(m.reach, c.reach, 1e-9) && near(m.stack, c.stack, 1e-9), `static reach and stack as typed, ${rw}/${fw}`);
  ok(near(m.ha, c.ha, 1e-9), `static head angle as typed, ${rw}/${fw}`);
  ok(near(m.sa, c.sa, 1e-9), `static seat angle as typed, ${rw}/${fw}`);
}

console.log('stem placement');
{
  const c = cfg({ hsUpper: 0 });
  const C = Math.hypot(c.reach, c.stack);
  const m0 = E.measure(c, stem(), false);
  ok(near(m0.B, C, 1e-9) && near(m0.B, m0.C, 1e-9), 'a stem with no stack, rise or length puts the bar on the head tube top');

  const ha = c.ha * E.D2R;
  const r = E.measure(c, stem({ rise: 60 }), false);
  ok(near(r.barStack - m0.barStack, 60 * Math.sin(ha), 1e-9), 'rise lifts the bar along the steerer: stack');
  ok(near(r.barReach - m0.barReach, -60 * Math.cos(ha), 1e-9), 'rise pulls the bar back along the steerer: reach');

  const l = E.measure(c, stem({ length: 50 }), false);
  ok(near(l.barReach - m0.barReach, 50 * Math.sin(ha), 1e-9), 'length is square to the steerer: reach');
  ok(near(l.barStack - m0.barStack, 50 * Math.cos(ha), 1e-9), 'length is square to the steerer: stack');

  // rise starts at the base of the stem: upper cup and spacers sit on the same axis
  const a = E.measure(cfg({ hsUpper: 8 }), stem({ spacers: 10, clamp: 40, rise: 0 }), false);
  const b = E.measure(cfg({ hsUpper: 0 }), stem({ spacers: 0, clamp: 0, rise: 18 }), false);
  ok(near(a.barReach, b.barReach, 1e-9) && near(a.barStack, b.barStack, 1e-9),
     'zero rise sits at the top of the spacers: 8 cup + 10 spacers = 18 of rise');
  ok(near(a.steerAbove, 58), 'steerer above head tube is cup + spacers + clamp');
  const tall = E.measure(cfg({ hsUpper: 8 }), stem({ spacers: 10, clamp: 55, rise: 0 }), false);
  ok(near(tall.barReach, a.barReach, 1e-9) && near(tall.barStack, a.barStack, 1e-9),
     'clamp height does not move the bar');

  // bar shape is recorded only, it must not move the bar centre yet
  const shaped = E.measure(c, stem({ rise: 30, length: 40, barRise: 38, backsweep: 9, upsweep: 5, barWidth: 800 }), false);
  const plain = E.measure(c, stem({ rise: 30, length: 40 }), false);
  ok(near(shaped.B, plain.B, 1e-12), 'bar rise and sweeps do not change the numbers yet');
}

console.log('stem presets');
{
  const pSrc = src.split('const STEM_PRESETS=')[1].split('\n];')[0] + '\n]';
  const PRESETS = new Function('return ' + pSrc)();
  const by = id => PRESETS.find(p => p.id === id);
  ok(PRESETS.length >= 7 && new Set(PRESETS.map(p => p.id)).size === PRESETS.length, 'preset ids are unique');
  ok(PRESETS.every(p => p.name && Number.isFinite(p.rise) && Number.isFinite(p.length)), 'every preset has a name, rise and length');
  ok(by('dereacher').rise === 60 && by('dereacher').length === 10, 'De-Reacher is 60 rise, 10 forward');
  ok(by('dmorv2').rise === 90 && by('dmorv2').length === -15, 'DMOR V2 is 90 rise, 15 back');
  ok(by('dmorv1').rise === 150 && by('dmorv1').length === -15, 'DMOR V1 is 150 rise, 15 back');
  for (const k of ['A', 'B']) {
    const p = by(DEF.stems[k].preset);
    ok(p && p.rise === DEF.stems[k].rise && p.length === DEF.stems[k].length && p.name === DEF.stems[k].name,
       `default stem ${k} matches its preset`);
  }
  const c = cfg();
  const back = E.measure(c, stem({ rise: 90, length: -15 }), false);
  const plumb = E.measure(c, stem({ rise: 90, length: 0 }), false);
  ok(back.barReach < plumb.barReach, 'a negative length puts the bar behind the steerer');
}

console.log('the sketch: standard stem against 60 rise x 10');
{
  const c = cfg();
  const A = E.measure(c, { ...DEF.stems.A }, false), B = E.measure(c, { ...DEF.stems.B }, false);
  ok(B.barStack > A.barStack, 'the riser stem raises the bar', `${A.barStack.toFixed(1)} -> ${B.barStack.toFixed(1)}`);
  ok(B.barReach < A.barReach, 'the riser stem shortens bar reach', `${A.barReach.toFixed(1)} -> ${B.barReach.toFixed(1)}`);
  ok(near(A.C, B.C) && near(A.reach, B.reach), 'C and frame reach do not depend on the stem');
}

console.log('sag');
{
  const c = cfg();
  const s = { ...DEF.stems.A };
  const st = E.measure(c, s, false), sg = E.measure(c, s, true);
  ok(near(st.B, sg.B, 1e-9) && near(st.C, sg.C, 1e-9), 'sag cannot change B or C, the frame is rigid');
  ok(sg.bbh < st.bbh, 'sag lowers the bottom bracket', `${st.bbh.toFixed(1)} -> ${sg.bbh.toFixed(1)}`);
  ok(near(Math.hypot(sg.barReach, sg.barStack), sg.B, 1e-9), 'bar reach and stack at sag still make up B');

  const front = E.measure(cfg({ sag: 0, fsag: 25 }), s, true);
  ok(front.pitch > 0 && front.ha > c.ha, 'fork sag alone pitches the nose down and steepens the head angle',
     `${front.pitch.toFixed(2)} deg`);
  ok(front.reach > st.reach, 'fork sag alone lengthens reach');
  const rear = E.measure(cfg({ sag: 30, fsag: 0 }), s, true);
  ok(rear.pitch < 0 && rear.ha < c.ha, 'rear sag alone pitches the nose up and slackens the head angle',
     `${rear.pitch.toFixed(2)} deg`);
  ok(near(sg.ha - c.ha, sg.sa - c.sa, 1e-9), 'head and seat angle change by the same pitch');

  // both tyres touch the ground after pitching: axle height above ground = radius
  for (const [rw, fw] of [[622, 622], [584, 622]]) {
    const cc = cfg({ rw, fw });
    const P = E.pose(cc, true);
    const fa = P.T(P.FA), ra = P.T(P.RA);
    ok(near(fa.y + P.bbh, P.Rf, 1e-9) && near(ra.y + P.bbh, P.Rr, 1e-9),
       `both tyres on the ground at sag, ${rw}/${fw}`);
  }
  const none = E.measure(cfg({ sag: 0, fsag: 0 }), s, true);
  ok(near(none.pitch, 0, 1e-12) && near(none.reach, st.reach, 1e-9), 'zero sag is the static pose');
}

console.log(`\n${pass} passed, ${fail} failed`);
if (!fail) console.log('all checks pass');
process.exit(fail ? 1 : 0);
