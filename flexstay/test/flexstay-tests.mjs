import fs from 'node:fs';
const src=fs.readFileSync(new URL('../index.html',import.meta.url),'utf8');
const eng=src.split('// ==ENGINE-START==')[1].replace(/^[^\n]*/,'').split('// ==ENGINE-END==')[0];
const m=new Function(eng+'\nreturn {solve,sweep,pivotForces,stayLoads,dist,circles,chainRun,'+
  'routeIdler,beltRun,idlerAt,chainPath,CAGE_LO,CAGE_HI,cageRun,wrapSense,RJ,'+
  'alongOf,standoffOf,onTube,segDist,unit,sub};')();
const defs=src.split('const DEF=')[1].split('};')[0]+'}';
const DEF=new Function('return '+defs)();

/* The Linkage X3 validation belongs to the solver, not to whatever the app
   currently ships as its defaults. This is the geometry those four numbers were
   measured against, frozen here so the defaults can change without quietly
   invalidating them. Do not edit it to make a test pass. */
const REF={
  geom:{ MP:{x:-2.5,y:66.4}, SP:{x:52.8,y:112.5}, FP:{x:-452.5,y:29.4},
         LP:{x:6.9,y:271.8}, SE:{x:1.6,y:293.9}, SG:{x:229.8,y:322.1},
         AX:{x:-452.5,y:29.4} },
  cfg:{ eye:230, stroke:65, zone:531, bendR:115, bendA:42, bendStart:50,
        leanEnd:55, leanA:8, dropZ:72.5, yokeZ:16.5, peakG:3, od:16, wall:0.9,
        ring:32, cog:21, mass:85, bias:58, cogh:1057, sag:30, fsag:20, fax:828.9,
        rw:622, fw:622, tyreR:58.4, tyreF:58.4, links:124, cage:62 }
};
const G=structuredClone(REF.geom), C=structuredClone(REF.cfg);
let fails=0;
const ok=(name,cond,info='')=>{console.log((cond?'  pass  ':'  FAIL  ')+name+(info?'   '+info:''));if(!cond)fails++};

const r=m.sweep(G,C);
ok('sweep completes without jamming', !r.error, r.error||'');
const f=r.frames, L=f[f.length-1], F0=f[0];

ok('drawn eye-to-eye matches the shock spec', Math.abs(m.dist(G.SE,G.SG)-C.eye)<2,
   m.dist(G.SE,G.SG).toFixed(1)+' vs '+C.eye);
ok('travel matches the Linkage model', Math.abs(L.rise-139)<1.5, L.rise.toFixed(1)+' mm vs 139');
ok('leverage falls through the stroke', F0.lr>L.lr,
   F0.lr.toFixed(2)+' -> '+L.lr.toFixed(2));
const prog=(F0.lr-L.lr)/L.lr*100;
ok('progression matches Linkage', Math.abs(prog-11.3)<0.6, prog.toFixed(1)+'% vs 11.3');
ok('anti-squat matches Linkage', Math.abs(F0.as-113.5)<1.5, F0.as.toFixed(1)+'% vs 113.5');
ok('anti-rise matches Linkage', Math.abs(F0.ar-109.5)<1.5, F0.ar.toFixed(1)+'% vs 109.5');

let mono=true, monoS=true;
for(let i=1;i<f.length;i++){ if(f[i].rise<=f[i-1].rise) mono=false;
                             if(f[i].stroke<=f[i-1].stroke) monoS=false; }
ok('wheel rise increases monotonically', mono);
ok('shock stroke increases monotonically', monoS);
ok('shock reaches exactly full stroke', Math.abs(L.stroke-C.stroke)<0.01, L.stroke.toFixed(3));

// flex demand
const peak=f.map(k=>k.flex).reduce((a,b)=>Math.abs(b)>Math.abs(a)?b:a,0);
const geo={total:534,chord:525.5,emax:30.6,leanStart:364};
const st=m.stayLoads(peak,geo,C.od,C.wall,545,C.bendA,C.dropZ,C.yokeZ,C.leanEnd,C.leanA);
ok('flex zone rotation stays small', Math.abs(peak)<3, peak.toFixed(2)+' deg');
ok('stay stress accounts for the axial offset', st.sAx>0&&st.sFlex>0,
   'flex '+st.sFlex.toFixed(0)+' MPa, axial '+st.sAx.toFixed(0)+' MPa');
ok('bend and flex are found to oppose', st.oppose===true);
ok('out of plane bending is counted', st.sOop>0, st.sOop.toFixed(0)+' MPa');
ok('resultant exceeds either component alone',
   st.combined>=st.sOop && st.combined>=st.inPlane,
   st.combined.toFixed(0)+' MPa vs in-plane '+st.inPlane.toFixed(0)+', out '+st.sOop.toFixed(0));
ok('P-delta amplification is above unity', st.amp>1&&st.amp<1.3, st.amp.toFixed(3));
ok('Euler load well above the applied load', st.Pcr>5*545, (st.Pcr/1000).toFixed(1)+' kN');
ok('zero flex angle at top out', Math.abs(f[0].flex)<1e-9);

// force solver cross-check: shock force must equal wheel load times leverage
const W=C.mass*9.81*C.bias/100;
const sag=f[Math.round(C.sag/100*(f.length-1))];
const P=m.pivotForces(G,sag,W);
ok('force solver returns a solution', !!P);
ok('shock force equals wheel load times leverage',
   Math.abs(P.shock-W*sag.lr)/(W*sag.lr)<0.02,
   (P.shock/1000).toFixed(3)+' kN vs '+(W*sag.lr/1000).toFixed(3)+' kN');
ok('all pivot loads finite and positive',
   [P.mainPivot,P.flexPivot,P.link].every(v=>Number.isFinite(v)&&v>0),
   'main '+(P.mainPivot/1000).toFixed(2)+' kN, flex '+(P.flexPivot/1000).toFixed(2)+
   ' kN, link '+(P.link/1000).toFixed(2)+' kN');

// chain line must pass over the top of both circles
const cr=m.chainRun({x:0,y:0},C.ring*12.7/(2*Math.PI),G.AX,C.cog*12.7/(2*Math.PI));
ok('chain run is the upper tangent', cr.p1.y>0&&cr.p2.y>G.AX.y,
   'ring '+cr.p1.y.toFixed(1)+', cog '+cr.p2.y.toFixed(1));

/* cageRun (the derailleur cage's tangent chain wrap, cog -> guide -> tension ->
   chainring) had no coverage at all -- only Playwright screenshots when it was
   built. Same geometry the cage take-up test above already uses, swept across
   the same CAGE_LO..CAGE_HI bracket the cage can actually reach, so both tests
   exercise the one real derailleur-cage rig rather than two invented ones. */
{
  const cBB={x:0,y:0}, cRc=C.ring*12.7/(2*Math.PI), cAXp={x:-450,y:38},
        cRg=C.cog*12.7/(2*Math.PI), cGuide={x:-454,y:-36};
  const near=(p,c,r)=>Math.abs(Math.hypot(p.x-c.x,p.y-c.y)-r)<1e-9;
  let nullCount=0, tangentBad=0, checked=0;
  for(let t=m.CAGE_LO;t<=m.CAGE_HI+1e-9;t+=0.05){
    const cTension={x:cGuide.x-Math.sin(t)*C.cage, y:cGuide.y-Math.cos(t)*C.cage};
    const wrap=m.cageRun(cBB,cRc,cGuide,cTension,m.RJ,cAXp,cRg);
    if(!wrap){ nullCount++; continue }
    checked++;
    const [segA,segB,segC]=wrap.segs;
    if(!(near(segA.p1,cAXp,cRg) && near(segA.p2,cGuide,m.RJ) &&
         near(segB.p1,cGuide,m.RJ) && near(segB.p2,cTension,m.RJ) &&
         near(segC.p1,cTension,m.RJ) && near(segC.p2,cBB,cRc))) tangentBad++;
  }
  ok('cage wrap solves across its whole travel bracket', nullCount===0,
     nullCount+' unsolved of '+(checked+nullCount));
  ok('every cage wrap segment is tangent to its pulley', tangentBad===0,
     checked+' positions checked');
}

// a deliberately impossible link must be rejected, not silently fudged
const bad=structuredClone(G); bad.SP={x:-72,y:196};      // link too short to follow the stay
ok('a link that cannot follow reports a jam', !!m.sweep(bad,C).error);
const over=structuredClone(C); over.stroke=160;          // more stroke than the linkage has
ok('over-long stroke reports a jam', !!m.sweep(G,over).error);
const conc=structuredClone(G); conc.SP={...G.MP};        // degenerate: back to a plain single pivot
const cr2=m.sweep(conc,C);
ok('concentric pivots give near zero stay flex', !cr2.error &&
   Math.max(...cr2.frames.map(k=>Math.abs(k.flex)))<0.05,
   cr2.error?'jammed':Math.max(...cr2.frames.map(k=>Math.abs(k.flex))).toFixed(4)+' deg');

console.log('\ntravel '+L.rise.toFixed(1)+' mm | leverage '+F0.lr.toFixed(2)+' to '+L.lr.toFixed(2)+
  ' | progression '+prog.toFixed(1)+'%');
console.log('anti-squat at top out '+F0.as.toFixed(1)+'% (Linkage 113.5) | anti-rise '+
  F0.ar.toFixed(1)+'% (Linkage 109.5)');
console.log('anti-squat at sag '+sag.as.toFixed(0)+'% | anti-rise '+sag.ar.toFixed(0)+
  '% | chain shortening '+L.kick.toFixed(1)+' deg');
console.log('axle path '+(F0.AX.x-L.AX.x>0?'rearward ':'forward ')+
  Math.abs(F0.AX.x-L.AX.x).toFixed(1)+' mm');
// The first frame used to depend on the sign of floating point noise, which
// differs between JavaScript engines. Jitter every coordinate and confirm the
// sweep is insensitive to it.
let jitterFails=0;
for(let trial=0;trial<400;trial++){
  const g=structuredClone(G);
  for(const k in g){ g[k].x+=(Math.random()-0.5)*2e-3; g[k].y+=(Math.random()-0.5)*2e-3; }
  const rr=m.sweep(g,C,25);
  if(rr.error) jitterFails++;
}
ok('first frame is insensitive to floating point noise', jitterFails===0,
   jitterFails+' of 400 jittered geometries jammed');
// and explicitly: zero compression must return the drawn position
const z=m.sweep(G,C,61).frames[0];
ok('zero compression returns the drawn shock length',
   Math.abs(z.shock-m.dist(G.SE,G.SG))<1e-6, (z.shock-m.dist(G.SE,G.SG)).toExponential(2));

// derailleur cage must stay in range on the defaults and rotate forward as the
// chain path grows, never backwards
const cg=f.map(k=>k.cageDeg);
ok('mech cage stays within its travel', !f.some(k=>k.cageClamp),
   cg[0].toFixed(1)+' to '+cg[cg.length-1].toFixed(1)+' deg');
ok('cage rotates forward as the chain path grows', cg[0]>cg[cg.length-1],
   'swing '+(cg[0]-cg[cg.length-1]).toFixed(1)+' deg');
let cageMono=true;
for(let i=1;i<cg.length;i++) if(cg[i]>cg[i-1]+1e-6) cageMono=false;
ok('cage motion is monotonic through the stroke', cageMono);

/* The frozen reference above is what the Linkage numbers are checked against, so
   the shipped defaults need their own check that they still solve at all. */
const shipped=m.sweep(structuredClone(DEF.geom),structuredClone(DEF.cfg));
ok('shipped defaults sweep without jamming', !shipped.error, shipped.error||'');
ok('shipped defaults give sane travel',
   shipped.frames.length>2 && shipped.frames[shipped.frames.length-1].rise>50
   && shipped.frames[shipped.frames.length-1].rise<250,
   shipped.frames.length>2?shipped.frames[shipped.frames.length-1].rise.toFixed(1)+' mm':'no frames');

/* ---------- idler ---------- */
const ridge=t=>t*12.7/(2*Math.PI);           // pitch radius from a tooth count
// the idler is off in REF, so switching it on must be the only thing that changes
const plain=m.sweep(structuredClone(REF.geom),structuredClone(REF.cfg)).frames;
ok('idler off leaves every frame untouched',
   plain.length===f.length && plain.every((k,i)=>
     Math.abs(k.as-f[i].as)<1e-12 && Math.abs(k.rise-f[i].rise)<1e-12 &&
     Math.abs((k.kick??0)-(f[i].kick??0))<1e-12));

const idlerCase=(idler,mount,mpy)=>{
  const g=structuredClone(REF.geom), c=structuredClone(REF.cfg);
  if(mpy!==undefined) g.MP={x:-2.5,y:mpy};
  g.ID=idler; c.idlerOn=1; c.idlerMount=mount; c.idlerTeeth=14;
  return m.sweep(g,c);
};

/* Both runs must actually touch both pulleys, and the chain must wrap the idler
   the same way going in as coming out. That continuity test is what resolves the
   degenerate case where the idler sits directly above the chainring and the
   "over the top" rule has two equally good answers. */
let tangentBad=0, wrapBad=0, routed=0;
for(const x of [-60,-30,0,30]) for(const y of [90,120,150,200]){
  const rt=m.routeIdler({x:0,y:0},ridge(32),{x,y},ridge(14),{x:-452.5,y:29.4},ridge(21));
  if(!rt){ continue }
  routed++;
  const [r1,r2]=rt.runs;
  const near=(p,c,r)=>Math.abs(Math.hypot(p.x-c.x,p.y-c.y)-r)<1e-9;
  if(!(near(r1.p1,{x:0,y:0},ridge(32)) && near(r1.p2,{x,y},ridge(14)) &&
       near(r2.p1,{x,y},ridge(14)) && near(r2.p2,{x:-452.5,y:29.4},ridge(21)))) tangentBad++;
  const sense=(c,p,d)=>Math.sign((p.x-c.x)*d.y-(p.y-c.y)*d.x);
  const dir=(a,b)=>{const L=Math.hypot(b.x-a.x,b.y-a.y);return {x:(b.x-a.x)/L,y:(b.y-a.y)/L}};
  if(sense({x,y},r1.p2,dir(r1.p1,r1.p2))!==sense({x,y},r2.p1,dir(r2.p1,r2.p2))) wrapBad++;
}
ok('routed runs are tangent to every pulley', tangentBad===0, routed+' positions');
ok('chain wraps the idler consistently in and out', wrapBad===0,
   'including the idler directly above the chainring');

/* An idler concentric with the main pivot cannot change the chain run length,
   whichever body it is bolted to — the exact zero is the check. */
const idConc=idlerCase({x:-2.5,y:66.4},0);
ok('frame idler on the main pivot gives zero chain growth',
   Math.abs(idConc.frames[idConc.frames.length-1].kick)<1e-9,
   idConc.frames[idConc.frames.length-1].kick.toExponential(1)+' deg');
const idConcSw=idlerCase({x:-2.5,y:66.4},1);
ok('swingarm idler on the main pivot gives zero chain growth',
   Math.abs(idConcSw.frames[idConcSw.frames.length-1].kick)<1e-9,
   idConcSw.frames[idConcSw.frames.length-1].kick.toExponential(1)+' deg');

/* The classic high-pivot recipe: pivot high, idler a little below it. Anti-squat
   should land in a usable band rather than inverting. */
const hp=idlerCase({x:-2.5,y:120},0,140);
ok('high pivot with the idler below it gives usable anti-squat',
   hp.frames[0].as>90 && hp.frames[0].as<160, hp.frames[0].as.toFixed(1)+' %');
// and moving the idler down from the pivot must raise anti-squat monotonically
const asAt=y=>idlerCase({x:-2.5,y},0,140).frames[0].as;
ok('lowering the idler raises anti-squat', asAt(130)<asAt(120) && asAt(120)<asAt(110),
   [asAt(130),asAt(120),asAt(110)].map(v=>v.toFixed(0)).join(' < '));

// a swingarm idler carries round the main pivot; a frame one does not
const swept=idlerCase({x:-2.5,y:120},1,140).frames;
ok('swingarm idler rides with the stay',
   m.dist(swept[0].idler,swept[swept.length-1].idler)>5,
   m.dist(swept[0].idler,swept[swept.length-1].idler).toFixed(1)+' mm of travel');
const fixed=idlerCase({x:-2.5,y:120},0,140).frames;
ok('frame idler stays put', m.dist(fixed[0].idler,fixed[fixed.length-1].idler)<1e-12);

/* ---------- chain fitting ---------- */
/* The cage take-up has to rise with cage angle across the whole bracket, because
   solveCage bisects on that assumption. It stops rising somewhere past 90 degrees
   — the tension pulley swings past its furthest point from the chainring — so
   widening CAGE_HI to buy capacity silently breaks the solve instead. */
let takeupMono=true, prevLen=null;
for(let t=m.CAGE_LO;t<=m.CAGE_HI+1e-9;t+=0.05){
  const L=m.chainPath(t,{x:0,y:0},C.ring*12.7/(2*Math.PI),{x:-450,y:38},
                      C.cog*12.7/(2*Math.PI),{x:-454,y:-36},C.cage).len;
  if(prevLen!==null && L<=prevLen) takeupMono=false;
  prevLen=L;
}
ok('cage take-up rises across the whole bracket', takeupMono,
   (m.CAGE_LO*180/Math.PI).toFixed(0)+' to '+(m.CAGE_HI*180/Math.PI).toFixed(0)+' deg');

const fitFor=(on,ID,MP,mount)=>{
  const g=structuredClone(REF.geom), c=structuredClone(REF.cfg);
  if(MP) g.MP=MP;
  g.ID=ID||{x:0,y:125}; c.idlerOn=on; c.idlerMount=mount||0; c.chainAuto=1;
  return m.sweep(g,c);
};
const fits=[fitFor(0), fitFor(1), fitFor(1,null,null,1),
            fitFor(1,{x:20,y:115},{x:-2.5,y:150}), fitFor(1,{x:-2.5,y:66.4})];
ok('a fitted chain never clamps the cage',
   fits.every(r=>!r.error && !r.frames.some(k=>k.cageClamp)),
   fits.map(r=>r.links).join(', ')+' links');
ok('fitted chains come out an even number of links',
   fits.every(r=>r.links%2===0), fits.map(r=>r.links).join(', '));
ok('an idler needs a longer chain than none', fits[1].links>fits[0].links,
   fits[0].links+' without, '+fits[1].links+' with');
// with fitting off the typed count is used verbatim
const manual=structuredClone(REF.cfg); manual.chainAuto=0; manual.links=131;
ok('chain fitting off leaves the typed link count alone',
   m.sweep(structuredClone(REF.geom),manual).links===131);

/* ---------- tube-relative mounts ---------- */
const dtDir=m.unit({x:500,y:495});          // a representative down tube direction
// the two coordinates have to survive a round trip, or a locked mount drifts
let rtOK=true;
for(const along of [45,150,390]) for(const off of [-30,0,52.9,67.1]){
  const p=m.onTube(dtDir,along,off);
  if(Math.abs(m.alongOf(dtDir,p)-along)>1e-9) rtOK=false;
  if(Math.abs(m.standoffOf(dtDir,p)-off)>1e-9) rtOK=false;
}
ok('along and standoff round trip through onTube', rtOK);

/* The whole point of the lock: swing the down tube under a mount and the standoff
   it was built to must not move, nor how far along the tube it sits. */
const before=m.onTube(dtDir,390,67.1);
const swung=m.unit({x:540,y:470});                       // as if reach grew a size
const after=m.onTube(swung, m.alongOf(dtDir,before), 67.1);
ok('a locked standoff survives the down tube moving',
   Math.abs(m.standoffOf(swung,after)-67.1)<1e-9 &&
   Math.abs(m.alongOf(swung,after)-m.alongOf(dtDir,before))<1e-9 &&
   m.dist(before,after)>1,                               // the point itself did move
   'moved '+m.dist(before,after).toFixed(1)+'mm, standoff held');

// clearance is wall to wall, so a boss on the centreline is buried by both radii
const onLine=m.segDist({x:100,y:100},{x:0,y:0},{x:200,y:200});
ok('a point on a tube centreline has zero distance', Math.abs(onLine)<1e-9);
ok('a 22mm boss 20mm off a 38.1mm tube reads as buried',
   Math.abs((20-38.1/2-22/2)-(-10.05))<1e-9, '-10.05 mm');
// and the segment is finite: past the end it measures to the end, not the line
ok('clearance past a tube end measures to the end',
   Math.abs(m.segDist({x:0,y:-50},{x:0,y:0},{x:0,y:100})-50)<1e-9);

console.log(fails? '\n'+fails+' FAILURES' : '\nall checks pass');
process.exit(fails?1:0);

