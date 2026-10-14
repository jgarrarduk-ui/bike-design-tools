// Checks one dev preset JSON against the engine: does it sweep, and is it a
// plausible bike? Usage: node preset-check.mjs ../dev-presets/<key>.json
// Prints metrics and a list of failures; exit code 1 if any.
import fs from 'node:fs';
const src=fs.readFileSync(new URL('../index.html',import.meta.url),'utf8');
const eng=src.split('// ==ENGINE-START==')[1].replace(/^[^\n]*/,'').split('// ==ENGINE-END==')[0];
const m=new Function(eng+'\nreturn {sweep,pivotForces,bodiesOf,dist};')();
// The app's own tube layout (frame() and frontTriangle(), weld mitres included), so a
// boss this checker passes is one the app draws clear of the tube, not 3 degrees off it.
const fnSrc=name=>{const a=src.indexOf('function '+name+'(');let i=src.indexOf('{',a),d=0;
  for(;i<src.length;i++){if(src[i]==='{')d++;else if(src[i]==='}'&&--d===0)break}return src.slice(a,i+1)};
const appTubes=new Function('C',eng+'\n'+fnSrc('frame')+'\n'+fnSrc('frontTriangle')+'\nreturn frontTriangle();');
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
  out.progression=+((F0.lr-L.lr)/L.lr*100).toFixed(1);   // same definition as the app readout
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
  // Frame tubes, exactly as the app draws them.
  const FT=appTubes(c);
  const TUBES={dt:FT.dt.slice(1),st:FT.st.slice(1),tt:FT.tt.slice(1)};
  const segD=(p,a,b)=>{const abx=b.x-a.x,aby=b.y-a.y,L2=abx*abx+aby*aby;
    const t=Math.max(0,Math.min(1,((p.x-a.x)*abx+(p.y-a.y)*aby)/L2));return Math.hypot(p.x-a.x-abx*t,p.y-a.y-aby*t)};
  const clear=(p,k)=>segD(p,TUBES[k][0],TUBES[k][1])-TUBES[k][2]/2-11;   // boss radius 11
  const mounts=P.mounts||{};
  out.tubeClear={};
  const framePts=(B.linkless?[]:['SP']).concat(B.sg==='F'?['SG']:[]);
  for(const p of framePts){
    const mt=mounts[p]||'dt';
    // A boss either clears every tube (>= 3mm) or is a cross-tube boss centred on one
    // (centreline within 6mm, the way a frame builder bores straight through a tube).
    // Anything in between is half in, half out, and cannot be built.
    const cross=Object.keys(TUBES).find(k=>segD(g[p],TUBES[k][0],TUBES[k][1])<6);
    const near=Math.min(...Object.keys(TUBES).filter(k=>k!==cross).map(k=>clear(g[p],k)));
    out.tubeClear[p]=cross?'cross-'+cross:+near.toFixed(1);
    if(near<3) fails.push(`${p} boss half-overlaps a frame tube (clearance ${near.toFixed(1)}mm; need >= 3, or centre it on the tube within 6mm as a cross-tube boss)`);
    // Down-tube mounts start 40mm off the centreline on the shock side (James: clear of
    // the tube, then moved by hand later) -- never a cross-tube boss on the DT.
    if(mt==='dt'){ const u=FT.dtU, off=u.x*g[p].y-u.y*g[p].x;
      out.tubeClear[p+'dtOff']=+off.toFixed(1);
      if(Math.abs(off-40)>2) fails.push(`${p} is ${off.toFixed(1)}mm off the down tube centreline; down-tube mounts sit 40mm off (+-2) on the upper side`); }
    if(mt!=='free'){ const d=cross===mt?0:clear(g[p],mt); if(d>70) fails.push(`${p} is ${d.toFixed(0)}mm off its "${mt}" tube — too far for a welded bracket (<= 70) or mark it free`); }
    else { const d=Math.min(Math.hypot(g[p].x,g[p].y)-25, ...Object.keys(TUBES).map(k=>clear(g[p],k)));
      if(d>70) fails.push(`${p} is "free" but ${d.toFixed(0)}mm from any tube or the BB shell — nothing could carry it`); }
  }
  // Moving pivots must not pass through a frame tube anywhere in the travel.
  const moving=['LP','SE','FP'].concat(B.sg!=='F'?['SG']:[]).filter(p=>!(B.linkless&&(p==='LP'||p==='FP')));
  for(const k of f) for(const p of moving){ if(!k[p]) continue;
    if(p==='FP' && m.dist(k.FP,k.AX)<120) continue;           // dropout area, behind the frame
    const near=Math.min(...Object.keys(TUBES).map(t=>clear(k[p],t)));
    if(near<0){ fails.push(`${p} passes through a frame tube at rise ${k.rise.toFixed(0)} (clearance ${near.toFixed(1)}mm)`); break; } }
  // The shock body must not pass through the down tube.
  const segX=(a,b,c2,d)=>{const r={x:b.x-a.x,y:b.y-a.y},q={x:d.x-c2.x,y:d.y-c2.y},den=r.x*q.y-r.y*q.x;if(Math.abs(den)<1e-9)return false;
    const t=((c2.x-a.x)*q.y-(c2.y-a.y)*q.x)/den,v=((c2.x-a.x)*r.y-(c2.y-a.y)*r.x)/den;return t>0.02&&t<0.98&&v>0&&v<1};
  if(f.some(k=>segX(k.SE,k.SG||g.SG,TUBES.dt[0],TUBES.dt[1]))) fails.push('the shock crosses the down tube somewhere in travel');
  // Bearings and links must be buildable.
  for(const [r,n] of [[out.rotA,'A'],[out.rotC,'C'],[out.rotR,'R']]) if(Math.abs(r)>60) fails.push(`body ${n} rotates ${r}deg over travel (> 60 is not a sensible bearing)`);
  if(!B.linkless){ const ll=[['MP','FP'],['FP','LP'],['SP','LP']].map(([a,b])=>m.dist(g[a],g[b]));
    if(Math.min(...ll)<35) fails.push('a link is shorter than 35mm'); }
  const asMax=Math.max(...f.map(k=>Math.abs(k.as??0)));
  out.asWorst=Math.round(asMax);
  if(asMax>250) fails.push(`anti-squat reaches ${asMax.toFixed(0)}% somewhere in travel (> 250 means a near-singular force line)`);
  if(P.shockForceMax && out.shockForceSag>P.shockForceMax) fails.push('shock force at sag too high');
}
const uniq=[...new Set(fails)];
console.log(JSON.stringify({key:P.key, bodies:B, metrics:out, fails:uniq},null,1));
process.exit(uniq.length?1:0);
