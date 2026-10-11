import fs from 'node:fs';
const src=fs.readFileSync(new URL('../index.html',import.meta.url),'utf8');
const eng=src.split('// ==ENGINE-START==')[1].replace(/^[^\n]*/,'').split('// ==ENGINE-END==')[0];
let m;
try{
  // typeof guards: a name the engine has not defined yet must not throw here
  m=new Function(eng+'\nreturn {solve,sweep,pivotForces,dist,rot,place,lineHit,circles,unit,sub,'+
    'bodiesOf:typeof bodiesOf==="function"?bodiesOf:undefined};')();
}catch(e){
  console.log('  FAIL  engine block does not evaluate: '+e.message);
  process.exit(1);
}
if(typeof m.bodiesOf!=='function'){
  console.log('  FAIL  engine does not export bodiesOf yet (linkage engine not implemented)');
  process.exit(1);
}
const defs=src.split('const DEF=')[1].split('};')[0]+'}';
const DEF=new Function('return '+defs)();

let fails=0;
const ok=(name,cond,info='')=>{console.log((cond?'  pass  ':'  FAIL  ')+name+(info?'   '+info:''));if(!cond)fails++};
const clone=structuredClone;
// a throw from the engine is a failed check, not a crashed file
const sweepOf=(g,c,n)=>{
  try{ return m.sweep(clone(g),clone(c),...(n?[n]:[])) }
  catch(e){ return {frames:[], error:'engine threw: '+e.message} }
};
const sagFrame=(r,c)=>r.frames&&r.frames.length?r.frames[Math.round(c.sag/100*(r.frames.length-1))]:undefined;
const finite=v=>typeof v==='number'&&Number.isFinite(v);
const withId=g=>({...g,ID:clone(DEF.geom.ID)});

/* ---------- geometries (mm, BB origin, x forward, y up) ----------
   Each is the spec's topology. Coordinates were chosen by a scratch kinematic
   search against the spec's motion rules (sweep completes, travel in range),
   not by hand. See the notes at the head of each block. */

// Horst: axle on the coupler C, Horst pivot FP on the chainstay A, rocker R drives shock
const HORST=withId({MP:{x:-20,y:60}, AX:{x:-440,y:20}, FP:{x:-400,y:10}, LP:{x:-60,y:330},
  SP:{x:-20,y:300}, SE:{x:-10,y:346}, SG:{x:152,y:178}});
const HORST_CFG={...DEF.cfg, axleBody:'C', brakeBody:'C', seBody:'R', sgBody:'F', flexStay:0, fax:DEF.cfg.fax};

// Horst with the Horst pivot placed exactly on the axle: must collapse to one pivot
const HORST_FAUX={...HORST, FP:{...HORST.AX}};

// Floating shock: SG rides the chainstay (body A), ahead of MP. (40,40) would not
// sweep with any SE inside the 215-245 mm eye band, so SG sits on the same body
// at a position that does (MP+(27,49), on the forward side of the pivot).
const FLOAT={...HORST, SG:{x:7,y:109}, SE:{x:-1,y:345}};
const FLOAT_CFG={...HORST_CFG, sgBody:'A'};

// Short link, parallel: MP->FP and SP->LP are both (-60,-20) at top-out, equal length.
// Parallel links make the coupler translate, so IC is null at every frame.
// SE on the rocker and SG on the frame were chosen so full stroke is reached
// within 0.3 rad of rocker rotation, inside the solver's 1.6 rad bracket.
const PARA={MP:{x:0,y:60}, FP:{x:-60,y:40}, SP:{x:-30,y:180}, LP:{x:-90,y:160},
  AX:{x:-440,y:20}, SE:{x:-270,y:180}, SG:{x:-262,y:409}, ID:clone(DEF.geom.ID)};
const PARA_CFG={...DEF.cfg, axleBody:'C', brakeBody:'C', seBody:'R', sgBody:'F', flexStay:0};

// Linkless single pivot: the swingarm carries the shock eye, the frame carries SG
// FP, SP and LP are carried along unused, as the app always holds them
const LINKLESS={MP:clone(DEF.geom.MP), AX:clone(DEF.geom.AX), FP:clone(DEF.geom.FP),
  SP:clone(DEF.geom.SP), LP:clone(DEF.geom.LP), SE:{x:-120,y:250},
  SG:clone(DEF.geom.SG), ID:clone(DEF.geom.ID)};
const LINKLESS_CFG={...DEF.cfg, linkless:1, flexStay:0, seBody:'A', sgBody:'F'};

// Mirror of LINKLESS across x = MP.x. Shock shortens on positive phi here, the
// opposite of the unmirrored case, so it exercises the sign detection.
const mx=p=>({x:2*LINKLESS.MP.x-p.x, y:p.y});
const LINKLESS_MIRROR={MP:LINKLESS.MP, AX:mx(LINKLESS.AX), FP:mx(LINKLESS.FP),
  SP:mx(LINKLESS.SP), LP:mx(LINKLESS.LP), SE:mx(LINKLESS.SE), SG:mx(LINKLESS.SG), ID:LINKLESS.ID};

// Split-pivot / brake check: DEF with the flex pivot locked on the axle
const SPLIT_A={...DEF.cfg, flexStay:0, brakeBody:'A'};
const SPLIT_C={...DEF.cfg, flexStay:0, brakeBody:'C'};

/* ---------- 1. bodiesOf defaults ---------- */
{
  const d0=m.bodiesOf(undefined), d1=m.bodiesOf({}), d2=m.bodiesOf(false);
  const dflt=b=>b&&b.axle==='A'&&b.brake==='A'&&b.se==='C'&&b.sg==='F'&&
    (b.linkless===false||b.linkless===0)&&(b.flex===true||b.flex===1);
  ok('bodiesOf defaults for undefined, {} and false', dflt(d0)&&dflt(d1)&&dflt(d2),
     JSON.stringify(d0));
  const t=m.bodiesOf(true), s=m.bodiesOf({shockMount:1});
  ok('bodiesOf(true) puts the shock eye on the rocker', !!t&&t.se==='R', t?'se='+t.se:'');
  ok('bodiesOf({shockMount:1}) puts the shock eye on the rocker', !!s&&s.se==='R', s?'se='+s.se:'');
}

/* ---------- 2. backward compatibility ---------- */
{
  const base=sweepOf(DEF.geom,DEF.cfg);
  const expl=sweepOf(DEF.geom,{...DEF.cfg,axleBody:'A',brakeBody:'A',seBody:'C',sgBody:'F'});
  let same=!base.error&&!expl.error&&base.frames.length===expl.frames.length;
  let worst=0;
  if(same) base.frames.forEach((k,i)=>{
    const q=expl.frames[i];
    for(const key of ['rise','as','ar','lr']){
      const a=k[key], b=q[key];
      if(a==null||b==null){ if(a!==b) same=false; continue; }
      worst=Math.max(worst,Math.abs(a-b));
    }
  });
  ok('explicit default body tags reproduce the shipped sweep', same && worst<1e-9,
     'worst diff '+worst.toExponential(1));
}

/* ---------- 3. identity at top-out ---------- */
{
  const cases=[
    ['shipped flex-stay', DEF.geom, DEF.cfg],
    ['Horst', HORST, HORST_CFG],
    ['short link, parallel', PARA, PARA_CFG],
    ['linkless single pivot', LINKLESS, LINKLESS_CFG],
    ['floating shock (Horst)', FLOAT, FLOAT_CFG],
    ['split pivot, brake on C', DEF.geom, SPLIT_C],
  ];
  for(const [name,g,c] of cases){
    const r=sweepOf(g,c);
    const f0=r.frames&&r.frames[0];
    let bad=[];
    if(!f0) bad.push('no frame 0');
    else for(const key of ['AX','SE','SG','LP','FP']){
      if(g[key]==null || f0[key]==null) continue;            // linkless has no LP/FP
      const d=m.dist(f0[key],g[key]);
      if(!(d<1e-6)) bad.push(key+' off by '+d.toExponential(1));
    }
    ok('frame 0 is the drawn geometry: '+name, bad.length===0, bad.join(', ')||'');
  }
}

/* ---------- 4. faux bar equals flex stay when FP already sits on AX ---------- */
{
  const flex=sweepOf(DEF.geom,DEF.cfg);
  const faux=sweepOf(DEF.geom,{...DEF.cfg,flexStay:0});
  let worst=0, same=!flex.error&&!faux.error&&flex.frames.length===faux.frames.length;
  if(same) flex.frames.forEach((k,i)=>{
    const q=faux.frames[i];
    for(const key of ['rise','as','ar','lr']){
      if(k[key]==null||q[key]==null){ if(k[key]!==q[key]) same=false; continue }
      worst=Math.max(worst,Math.abs(k[key]-q[key]));
    }
    worst=Math.max(worst,m.dist(k.AX,q.AX));
  });
  ok('faux bar (flexStay 0, FP on AX) matches flex stay frame for frame', same && worst<1e-9,
     'worst diff '+worst.toExponential(1));
}

/* ---------- 5. Horst with FP on AX collapses to a single pivot ---------- */
{
  const r=sweepOf(HORST_FAUX,HORST_CFG);
  const f=r.frames||[];
  const c0=f.length?m.dist(f[0].AX,HORST.MP):NaN;
  const circ=f.length>0 && f.every(k=>Math.abs(m.dist(k.AX,HORST.MP)-c0)<1e-6);
  ok('Horst axle path is a circle about MP when FP is on AX', !r.error && circ,
     r.error?r.error:'radius '+c0.toFixed(3)+' mm');
}

/* ---------- 6. real Horst sanity ---------- */
let HR=null, HS=null;
{
  HR=sweepOf(HORST,HORST_CFG);
  ok('Horst sweep completes', !HR.error, HR.error||'');
  const L=HR.frames&&HR.frames.at(-1);
  ok('Horst travel between 80 and 220 mm', !!L&&L.rise>80&&L.rise<220,
     L?L.rise.toFixed(1)+' mm':'no frames');
  const f0=HR.frames&&HR.frames[0];
  const ic0=f0&&f0.IC, want=m.lineHit(HORST.MP,HORST.FP,HORST.SP,HORST.LP);
  ok('Horst frame 0 IC equals MP-FP x SP-LP at rest',
     !!ic0&&!!want&&m.dist(ic0,want)<1e-6, ic0&&want?m.dist(ic0,want).toExponential(1)+' mm':'missing');
  HS=sagFrame(HR,HORST_CFG);
  ok('Horst anti-squat is finite at sag', !!HS&&finite(HS.as), HS?HS.as+' %':'no sag frame');
}

/* ---------- 7. short link, parallel at top-out ---------- */
{
  const r=sweepOf(PARA,PARA_CFG);
  ok('parallel short link sweeps', !r.error, r.error||'');
  const f0=r.frames&&r.frames[0];
  ok('parallel short link: frame 0 IC is null', !!f0&&f0.IC==null, f0?String(f0.IC):'no frame');
  const u=f0&&f0.ICdir;
  const want=m.unit(m.sub(PARA.FP,PARA.MP));
  const cr=u?Math.abs(u.x*want.y-u.y*want.x):NaN;
  ok('parallel short link: ICdir is a unit vector along MP->FP',
     !!u && Math.abs(Math.hypot(u.x,u.y)-1)<1e-9 && cr<1e-9, 'cross '+(Number.isFinite(cr)?cr.toExponential(1):'n/a'));
  ok('parallel short link: frame 0 anti-squat is finite', !!f0&&finite(f0.as), f0?String(f0.as):'');
  const allFinite=(r.frames||[]).every(k=>finite(k.rise)&&finite(k.as));
  ok('parallel short link: every frame finite', allFinite && (r.frames||[]).length>2);
}

/* ---------- 8. linkless single pivot ---------- */
let LG=null, L8=null;
{
  LG=sweepOf(LINKLESS,LINKLESS_CFG);
  ok('linkless single pivot sweeps through the stroke', !LG.error, LG.error||'');
  const fr=LG.frames||[];
  const d0=fr.length?m.dist(fr[0].AX,LINKLESS.MP):NaN;
  ok('linkless: every frame keeps the axle the same distance from MP',
     fr.length>0 && fr.every(k=>Math.abs(m.dist(k.AX,LINKLESS.MP)-d0)<1e-6), 'radius '+d0.toFixed(3));
  ok('linkless: IC is the main pivot in every frame',
     fr.length>0 && fr.every(k=>k.IC&&k.IC.x===LINKLESS.MP.x&&k.IC.y===LINKLESS.MP.y));
  L8=sagFrame(LG,LINKLESS_CFG);
  const W=500;
  const P=L8?m.pivotForces(LINKLESS,L8,W,LINKLESS_CFG):null;
  ok('linkless: shock and main pivot forces are finite',
     !!P&&finite(P.shock)&&finite(P.mainPivot), P?'shock '+P.shock.toFixed(0)+', main '+P.mainPivot.toFixed(0):'no solution');
  if(P){
    // Sign convention, fixed by pivotForces: the wheel load is applied as
    // (0,-Fw) on the axle body, and the joint/shock unknowns are the forces on
    // the axle body. vec.* holds the negated reactions (see the V() flip in
    // pivotForces), so on the axle body shock + main + load = 0 gives
    // vec.shock + vec.main = (0,-Fw). Tightened from the earlier either-sign
    // check, which could not catch a flipped arrow; the engine prints -5.0e+2.
    const sx=P.vec.shock.x+P.vec.main.x, sy=P.vec.shock.y+P.vec.main.y;
    const tol=1e-6*W;
    ok('linkless: shock, ground load and main pivot reaction balance',
       Math.abs(sx)<tol && Math.abs(sy+W)<tol,
       'sum ('+sx.toExponential(1)+', '+sy.toExponential(1)+') vs Fw '+W);
  }
}

/* ---------- 9. force solve for Horst ---------- */
{
  const W=DEF.cfg.mass*9.81*DEF.cfg.bias/100;
  const P=HS?m.pivotForces(HORST,HS,W,HORST_CFG):null;
  ok('Horst pivot forces solve', !!P);
  const keys=['shock','mainPivot','flexPivot','linkPivot','secondPivot'];
  ok('Horst pivot forces are finite for shock, main, flex, link and second pivot',
     !!P&&keys.every(k=>finite(P[k])), P?keys.map(k=>k+' '+(finite(P[k])?P[k].toFixed(0):'NaN')).join(', '):'');
}

/* ---------- 10. floating shock on the axle body ---------- */
{
  const r=sweepOf(FLOAT,FLOAT_CFG);
  ok('floating shock sweeps', !r.error, r.error||'');
  const fr=r.frames||[];
  const moved=fr.length>1 && m.dist(fr.at(-1).SG,fr[0].SG)>1;
  ok('floating shock: the shock end moves through the travel', moved,
     fr.length>1?m.dist(fr.at(-1).SG,fr[0].SG).toFixed(1)+' mm':'');
  const d0=fr.length?m.dist(fr[0].SG,FLOAT.MP):NaN;
  ok('floating shock: SG stays the same distance from MP (rides body A)',
     fr.length>0 && fr.every(k=>Math.abs(m.dist(k.SG,FLOAT.MP)-d0)<1e-6), 'radius '+d0.toFixed(2));
}

/* ---------- 11. brake body changes anti-rise, not anti-squat ---------- */
{
  const a=sweepOf(DEF.geom,SPLIT_A), c=sweepOf(DEF.geom,SPLIT_C);
  const sa=sagFrame(a,SPLIT_A), sc=sagFrame(c,SPLIT_C);
  ok('brake body on A or C: both sweep', !a.error&&!c.error, (a.error||'')+' '+(c.error||''));
  ok('brake body does not change anti-squat at sag',
     !!sa&&!!sc&&Math.abs(sa.as-sc.as)<1e-9, sa&&sc?sa.as.toFixed(3)+' vs '+sc.as.toFixed(3):'');
  ok('brake body changes anti-rise at sag by more than 0.5 points',
     !!sa&&!!sc&&Math.abs(sa.ar-sc.ar)>0.5, sa&&sc?Math.abs(sa.ar-sc.ar).toFixed(2)+' points':'');
}

/* ---------- 12. sign detection: shock shortens on positive phi ---------- */
{
  const r=sweepOf(LINKLESS_MIRROR,LINKLESS_CFG);
  const last=r.frames&&r.frames.at(-1);
  ok('mirrored linkless sweeps', !r.error, r.error||'');
  ok('mirrored linkless travel is positive', !!last&&last.rise>0, last?last.rise.toFixed(1)+' mm':'no frames');
}

console.log(fails? '\n'+fails+' FAILURES' : '\nall checks pass');
process.exit(fails?1:0);
