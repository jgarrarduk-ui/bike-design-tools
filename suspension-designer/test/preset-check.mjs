// Checks one dev preset JSON against the engine: does it sweep, and is it a
// plausible bike? Usage: node preset-check.mjs ../dev-presets/<key>.json
// Prints metrics and a list of failures; exit code 1 if any.
import fs from 'node:fs';
const src=fs.readFileSync(new URL('../index.html',import.meta.url),'utf8');
const eng=src.split('// ==ENGINE-START==')[1].replace(/^[^\n]*/,'').split('// ==ENGINE-END==')[0];
const m=new Function(eng+'\nreturn {sweep,pivotForces,bodiesOf,dist};')();
const DEF=new Function('return '+src.split('const DEF=')[1].split('};')[0]+'}')();

const P=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const g=Object.assign(structuredClone(DEF.geom), structuredClone(P.geom||{}));
const c=Object.assign(structuredClone(DEF.cfg), structuredClone(P.cfg||{}));
if(P.cfg && P.cfg.flexStay===undefined) c.flexStay=0;
const B=m.bodiesOf(c);
if(B.flex) g.FP={...g.AX};
const T=P.targets||{};
const want={travel:T.travel||[135,165], prog:T.prog||[5,35], as:T.as||[80,140]};
const fails=[], out={};
const r=m.sweep(g,c);
if(r.error){ fails.push('sweep: '+r.error+' (got '+r.frames.length+' frames)'); }
else {
  const f=r.frames, F0=f[0], L=f[f.length-1], sag=f[Math.round(c.sag/100*(f.length-1))];
  const Rr=c.rw/2+c.tyreR, ground=g.AX.y-Rr;
  out.travel=+L.rise.toFixed(1);
  out.lrTop=+F0.lr.toFixed(2); out.lrBottom=+L.lr.toFixed(2);
  out.progression=+((F0.lr-L.lr)/F0.lr*100).toFixed(1);
  out.asSag=sag.as==null?null:+sag.as.toFixed(0); out.arSag=sag.ar==null?null:+sag.ar.toFixed(0);
  out.eyeTopOut=+m.dist(g.SE,g.SG).toFixed(1);
  out.rotA=+L.rotA.toFixed(1); out.rotC=+L.rotC.toFixed(1); out.rotR=+L.rotR.toFixed(1);
  out.axlePathRearward=+(F0.AX.x-L.AX.x).toFixed(1);
  const P2=m.pivotForces(g,sag,c.mass*9.81*c.bias/100,c);
  out.shockForceSag=P2?Math.round(P2.shock):null;
  const inr=(v,[a,b])=>v>=a&&v<=b;
  if(!inr(out.travel,want.travel)) fails.push(`travel ${out.travel} outside ${want.travel}`);
  if(!inr(out.progression,want.prog)) fails.push(`progression ${out.progression}% outside ${want.prog}`);
  if(out.asSag==null||!inr(out.asSag,want.as)) fails.push(`anti-squat at sag ${out.asSag}% outside ${want.as}`);
  if(Math.abs(out.eyeTopOut-c.eye)>3) fails.push(`eye-to-eye at top-out ${out.eyeTopOut} vs cfg.eye ${c.eye} (must be within 3mm)`);
  if(!f.every(k=>k.lr>0)) fails.push('leverage goes non-positive somewhere (shock not monotonic)');
  // Frame-fixed points and anything not riding near the axle must stay clear of the rear tyre.
  const fixed=['MP'].concat(B.linkless?[]:['SP']).concat(B.sg==='F'?['SG']:[]);
  for(const k of f){
    for(const p of fixed){ const d=m.dist(g[p],k.AX)-Rr; if(d<15){ fails.push(`${p} only ${d.toFixed(0)}mm from tyre at rise ${k.rise.toFixed(0)}`); break; } }
    if(!B.linkless && B.axle!=='R'){ const d=m.dist(k.LP,k.AX)-Rr; if(d<15 && P.lpNearAxleOK!==true){ fails.push(`LP only ${d.toFixed(0)}mm from tyre at rise ${k.rise.toFixed(0)}`); } }
    const pts=[k.SE,k.SG,k.LP,k.FP].filter(Boolean);
    if(pts.some(p=>p.y<ground+120)) { fails.push('a pivot or shock end drops within 120mm of the ground'); }
  }
  if(P.shockForceMax && out.shockForceSag>P.shockForceMax) fails.push('shock force at sag too high');
}
const uniq=[...new Set(fails)];
console.log(JSON.stringify({key:P.key, bodies:B, metrics:out, fails:uniq},null,1));
process.exit(uniq.length?1:0);
