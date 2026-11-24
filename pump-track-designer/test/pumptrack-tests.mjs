// Headless checks for the pump track engine. Run: node pump-track-designer/test/pumptrack-tests.mjs
import fs from 'node:fs';
const src=fs.readFileSync(new URL('../index.html',import.meta.url),'utf8');
const eng=src.split('// ==ENGINE-START==')[1].replace(/^[^\n]*/,'').split('// ==ENGINE-END==')[0];
const m=new Function(eng+'\nreturn {DEG,FEATURES,SIZES,TEMPLATES,buildTrack,pointAt,polyline,project,projectLeg,'+
  'spansOf,findSlot,issuesOf,zAt,profileOf,profZ,profHeight,defaultParams,sizedParams,normParams,statsOf,'+
  'insertNode,removeNode,toLatLon,fromLatLon,toGPX,parseGPX,gpxToDesign,traceTrack,toCSV,parseCSV,profileCSV,'+
  'normGeom,dist,bermRoller,llCentroid,anchorGeo,refPolyline,polyLen};')();

let fails=0;
const ok=(name,cond,info='')=>{console.log((cond?'  pass  ':'  FAIL  ')+name+(info?'   '+info:''));if(!cond)fails++};
const near=(a,b,t)=>Math.abs(a-b)<=t;
const clone=o=>JSON.parse(JSON.stringify(o));

/* ---- geometry ---- */
// 20 x 10 rectangle, every corner r=3: perimeter minus 8 tangent lengths plus a full circle
const rect={closed:true,width:1.5,features:[],nodes:[[0,0],[20,0],[20,10],[0,10]].map(([x,y])=>({x,y,r:3,berm:'berm',bh:1.2,rh:0}))};
let T=m.buildTrack(rect);
const want=2*(20+10)-8*3+2*Math.PI*3;
ok('filleted loop length is exact', near(T.length,want,1e-9), T.length.toFixed(4)+' vs '+want.toFixed(4));
ok('four straights and four arcs', T.legs.length===4&&T.arcs.length===4);
ok('straights lose one tangent length at each end', near(T.legs[0].len,14,1e-9)&&near(T.legs[1].len,4,1e-9));
let maxJump=0, pts=m.polyline(T,0.05,true);
for(let i=1;i<pts.length;i++) maxJump=Math.max(maxJump,m.dist(pts[i],pts[i-1]));
ok('centreline is continuous', maxJump<0.06, 'largest step '+maxJump.toFixed(3)+' m');
let maxTurn=0;
for(let s=0.01;s<T.length;s+=0.01){
  const a=m.pointAt(T,s-0.01).dir, b=m.pointAt(T,s).dir;
  maxTurn=Math.max(maxTurn,Math.acos(Math.max(-1,Math.min(1,a.x*b.x+a.y*b.y))));
}
ok('heading is smooth (no kinks at tangent points)', maxTurn<0.01/3+1e-3, (maxTurn/m.DEG).toFixed(3)+' deg per 10 mm');
const pA=m.pointAt(T,0), pB=m.pointAt(T,T.length);
ok('loop closes on itself', m.dist(pA,pB)<1e-9);
const corner=T.arcs.find(a=>a.i===1);
ok('arc mid point is at radius r from its centre', near(m.dist(corner.M,corner.C),3,1e-9));
const pj=m.project(T,{x:10,y:-2});
ok('project finds the bottom straight', near(pj.s,7,1e-9)&&near(pj.d,2,1e-9), 's '+pj.s.toFixed(3));
ok('project gives right-hand offsets as negative', pj.side<0);

// radii too big for the straights are shrunk and flagged
const big=clone(rect); big.nodes.forEach(n=>n.r=8);
T=m.buildTrack(big);
ok('oversized corners are flagged tight', T.corners.every(c=>c.tight));
ok('and shrunk to fit their straights', T.legs.every(l=>l.len>-1e-9), T.legs.map(l=>l.len.toFixed(2)).join('/'));
ok('a tight corner raises an issue', m.issuesOf(T,big,[]).some(i=>i.kind==='tight'));

// auto berms
const auto=clone(rect); auto.nodes.forEach(n=>n.berm='auto'); auto.nodes[2].x=20.5;
T=m.buildTrack(auto);
ok('auto corner banks a 90 degree turn', T.arcs.find(a=>a.i===1).berm===true);
const shallow={closed:false,width:1,features:[],nodes:[{x:0,y:0,r:0},{x:10,y:0,r:5,berm:'auto'},{x:20,y:3,r:0}]};
ok('auto corner leaves a gentle bend flat', m.buildTrack(shallow).arcs[0].berm===false);

/* ---- feature profiles ---- */
for(const type of Object.keys(m.FEATURES)){
  const pr=m.profileOf({type,p:m.defaultParams(type)});
  ok(`${type}: starts and ends at ground level`, near(m.profZ(pr,0),0,0.01)&&near(m.profZ(pr,pr.len),0,0.01),
     m.profZ(pr,0).toFixed(3)+' / '+m.profZ(pr,pr.len).toFixed(3));
}
const tt=m.profileOf({type:'tabletop',p:{h:1,deck:3,lip:35,land:25}});
ok('table top is as tall as asked', near(m.profHeight(tt),1,0.005));
const up=tt.segs[0], e=1e-4, slope=(up.z(up.u1)-up.z(up.u1-e))/e;
ok('table top lip leaves at the lip angle', near(Math.atan(slope)/m.DEG,35,0.3), (Math.atan(slope)/m.DEG).toFixed(2)+' deg');
const dn=tt.segs[2]; let maxDn=0;
for(let u=dn.u0;u<dn.u1;u+=0.01) maxDn=Math.max(maxDn,(dn.z(u)-dn.z(u+0.01))/0.01);
ok('table top landing is no steeper than the landing angle', Math.atan(maxDn)/m.DEG<=25.05, (Math.atan(maxDn)/m.DEG).toFixed(2)+' deg');
const db=m.profileOf({type:'double',p:{h:1.1,gap:3,lh:1.2,lip:40,land:30}});
const gs=db.segs.find(s=>s.kind==='gap');
ok('double gap is measured lip to knuckle', near(gs.u1-gs.u0,3,1e-9)&&near(m.profZ(db,gs.u0),1.1,0.01)&&near(m.profZ(db,gs.u1),1.2,0.01));
ok('double pit reaches the ground', near(m.profZ(db,(gs.u0+gs.u1)/2),0,1e-9));
const r3=m.profileOf({type:'roller',p:{h:.5,L:4,n:3}});
ok('roller set is count x length', near(r3.len,12,1e-9)&&near(m.profZ(r3,2),.5,1e-9)&&near(m.profZ(r3,6),.5,1e-9)&&near(m.profZ(r3,4),0,1e-9));
const wt=m.profileOf({type:'whaletail',p:{flare:2.5}});
ok('whale tail fans out to its flare', near(wt.flare(0),1,1e-9)&&near(wt.flare(wt.len),2.5,1e-9));
const bl=m.profileOf({type:'bonerlog',p:{h:1,deck:2,kick:5}});
const ws=bl.segs.find(s=>s.kind==='wood');
ok('boner log deck kicks up by its angle', near(ws.z(ws.u1)-ws.z(ws.u0),2*Math.tan(5*m.DEG),1e-9));
const L=m.sizedParams('tabletop','L'), S=m.sizedParams('tabletop','S');
ok('sizes scale heights but keep angles', L.h>1&&S.h<1&&L.lip===S.lip);
ok('params are clamped to their range', m.normParams('roller',{h:9,n:2.6}).h===1.2&&m.normParams('roller',{n:2.6}).n===3);

/* ---- features on the track ---- */
const g=clone(rect); g.features=[{type:'roller',leg:0,t:.5,p:{h:.5,L:4,n:1}}];
T=m.buildTrack(g); let sp=m.spansOf(T,g);
ok('feature centre sits at t of its straight', near(sp[0].s0+2,T.legs[0].s0+7,1e-9));
ok('elevation peaks at the roller crest', near(m.zAt(T,sp,T.legs[0].s0+7),.5,1e-9)&&m.zAt(T,sp,T.legs[0].s0+1)===0);
let slot=m.findSlot(T,sp,0,7,4,-1);
ok('a new feature is pushed clear of an existing one', slot.ok&&Math.abs(slot.c-7)>=4-1e-9, 'centre '+slot.c.toFixed(2));
ok('findSlot reports no room when a straight is full', !m.findSlot(T,sp,1,2,6,-1).ok);
g.features.push({type:'tabletop',leg:0,t:.5,p:{}});
sp=m.spansOf(T,g);
ok('overlapping features are reported', m.issuesOf(T,g,sp).some(i=>i.kind==='overlap'));
g.features=[{type:'roller',leg:0,t:.05,p:{}}]; sp=m.spansOf(T,m.normGeom(g));
ok('a feature hanging off its straight is reported', m.issuesOf(T,g,sp).some(i=>i.kind==='fit'));
// wrap: a roller hanging back over s=0 on a loop still shows in the profile end
g.features=[{type:'roller',leg:0,t:0,p:{h:.5,L:4}}]; sp=m.spansOf(T,g);
ok('loop profile wraps past the start line', m.zAt(T,sp,T.length-1)>0.2);

// berm with a roller in it
const br=clone(rect); br.nodes[1].rh=.4; T=m.buildTrack(br);
const a1=T.arcs.find(a=>a.i===1), bro=m.bermRoller(a1);
ok('berm roller peaks mid-corner', near(m.zAt(T,[],bro.s0+bro.len/2),.4,1e-9));

/* ---- editing keeps features in place ---- */
const ed=clone(rect); ed.features=[{type:'roller',leg:0,t:.25,p:{}},{type:'roller',leg:0,t:.75,p:{}},{type:'roller',leg:2,t:.5,p:{}}];
T=m.buildTrack(ed);
const before=m.spansOf(T,ed).map(s=>m.pointAt(T,s.s0+s.pr.len/2));
const ni=m.insertNode(ed,T,0,7);
const T2=m.buildTrack(ed), after=m.spansOf(T2,ed).map(s=>m.pointAt(T2,s.s0+s.pr.len/2));
ok('insert splits the straight at the point', ni===1&&ed.nodes.length===5&&near(ed.nodes[1].x,10,1e-9));
ok('insert leaves every feature where it was', before.every((p,i)=>m.dist(p,after[i])<1e-6),
   before.map((p,i)=>m.dist(p,after[i]).toFixed(4)).join(','));
ok('insert moves later features to the new straight', ed.features[1].leg===1&&ed.features[2].leg===3);
m.removeNode(ed,T2,1);
const T3=m.buildTrack(ed), back=m.spansOf(T3,ed).map(s=>m.pointAt(T3,s.s0+s.pr.len/2));
ok('remove undoes insert', before.every((p,i)=>m.dist(p,back[i])<1e-6)&&ed.nodes.length===4);
const tri=clone(rect); tri.nodes.pop(); T=m.buildTrack(tri);
ok('a loop will not drop below three corners', m.removeNode(tri,T,0)===false);

/* ---- GPS ---- */
const geo={lat:51.6,lon:-3.9,rot:30,ele:12};
const ll=m.toLatLon(geo,{x:120,y:-45}), back2=m.fromLatLon(geo,ll);
ok('lat/lon conversion round trips', near(back2.x,120,1e-6)&&near(back2.y,-45,1e-6));
const north=m.toLatLon({lat:51.6,lon:-3.9,rot:90},{x:0,y:100});
ok('plan rotation turns plan-up to the bearing', north.lon>-3.9&&near(north.lat,51.6,1e-7));

for(const t of m.TEMPLATES){
  const geom=m.normGeom(t.geom), Tt=m.buildTrack(geom), st=m.spansOf(Tt,geom), iss=m.issuesOf(Tt,geom,st);
  ok(`template "${t.name}" builds clean`, Tt.ok&&iss.length===0&&st.every(Boolean), iss.map(i=>i.msg).join('; '));
}
const dj=m.normGeom(m.TEMPLATES.find(t=>t.name==='Dirt jump line').geom);
T=m.buildTrack(dj); sp=m.spansOf(T,dj);
const design={_meta:{name:'Line & <test>'},geom:dj,cfg:{geo}};
const gpx=m.toGPX(design,T,sp,'2026-01-01T00:00:00Z');
const parsed=m.parseGPX(gpx);
ok('GPX is well formed enough to read back', parsed.pts.length>200&&parsed.wpts.length===dj.features.length);
ok('GPX escapes the name', gpx.includes('Line &amp; &lt;test&gt;'));
ok('GPX track starts at the plan origin offset', near(m.fromLatLon(geo,parsed.pts[0]).x,m.pointAt(T,0).x,0.01));
ok('GPX heights follow the profile', Math.max(...parsed.pts.map(p=>p.ele))>12+2);
const rt=m.gpxToDesign(parsed);
ok('our own GPX reopens exactly', JSON.stringify(rt.geom)===JSON.stringify(dj));
// a foreign GPX (no extension) is traced; length within 3 %
const foreign=gpx.replace(/<extensions>[\s\S]*<\/extensions>/,'');
const tr=m.gpxToDesign(m.parseGPX(foreign),1.2), Ttr=m.buildTrack(tr.geom);
ok('foreign GPX is traced to a similar length', Math.abs(Ttr.length-T.length)/T.length<0.03,
   Ttr.length.toFixed(1)+' vs '+T.length.toFixed(1)+' m, '+tr.geom.nodes.length+' nodes');
ok('foreign GPX waypoints come back as features', tr.geom.features.length===dj.features.length);
const loopG=m.normGeom(m.TEMPLATES[0].geom), Tl=m.buildTrack(loopG);
const fl=m.gpxToDesign(m.parseGPX(m.toGPX({_meta:{},geom:loopG,cfg:{geo}},Tl,m.spansOf(Tl,loopG)).replace(/<extensions>[\s\S]*<\/extensions>/,'')),2);
ok('a traced loop is detected as closed', fl.geom.closed===true, Math.abs(m.buildTrack(fl.geom).length-Tl.length).toFixed(2)+' m length error');

/* ---- GPX trace layer ---- */
const tracePts=m.parseGPX(foreign).pts;
for(const rot of [0,35]){
  const ag=m.anchorGeo(tracePts,{x:12,y:-7},rot), c=m.fromLatLon(ag,m.llCentroid(tracePts));
  ok(`anchorGeo puts the trace middle on the track (rot ${rot})`, near(c.x,12,1e-6)&&near(c.y,-7,1e-6));
}
const refXY=m.refPolyline(geo,tracePts), refLen=m.polyLen(refXY);
ok('trace layer keeps true scale', Math.abs(refLen-T.length)<0.5, refLen.toFixed(2)+' vs '+T.length.toFixed(2)+' m');
ok('trace layer lands where its design was', m.dist(refXY[0],m.pointAt(T,0))<0.01);
const moved=m.refPolyline(Object.assign({},geo,{lat:geo.lat+0.0001}),tracePts);
ok('moving the site location moves the trace, not its shape', near(m.polyLen(moved),refLen,1e-3)&&near(m.dist(refXY[0],moved[0]),11.12,0.02));   // 0.0001 deg of latitude

/* ---- dimensions CSV ---- */
for(const t of m.TEMPLATES){
  const geom=m.normGeom(t.geom), Tt=m.buildTrack(geom), csv=m.toCSV(geom,Tt,m.spansOf(Tt,geom));
  const g2=m.parseCSV(csv), T2b=m.buildTrack(g2);
  const pa=m.spansOf(Tt,geom).map(s=>s.s0), pb=m.spansOf(T2b,g2).map(s=>s.s0);
  ok(`CSV round trip: ${t.name}`, g2.nodes.length===geom.nodes.length&&g2.closed===geom.closed&&
     near(T2b.length,Tt.length,0.01)&&pa.every((s,i)=>near(s,pb[i],0.01)));
}
const hand='kind,ref,type,x_m,y_m,radius_m,corner,leg,start_m,height_m\ntrack,,line\nnode,N1,,0,0\nnode,N2,,30,0\nfeature,F1,tabletop,,,,,1,5,1.4\n';
const hg=m.parseCSV(hand), hT=m.buildTrack(hg), hs=m.spansOf(hT,hg);
ok('a hand-written CSV with missing columns opens', hg.nodes.length===2&&near(hs[0].s0,5,1e-9)&&hs[0].pr.p.h===1.4);
ok('profile CSV has a row every 0.25 m', m.profileCSV(T,sp,null).trim().split('\n').length>T.length/0.25);

/* ---- design file ---- */
const old=m.normGeom({closed:true,nodes:[{x:0,y:0},{x:10,y:0},{x:5,y:8}],features:[{type:'nope',leg:0},{type:'roller',leg:9,t:3}]});
ok('an old or odd file is normalised, not rejected', old.width===1.5&&old.features.length===1&&old.features[0].leg===2&&old.features[0].t===1);

/* ---- quantities ---- */
const st=m.statsOf(m.buildTrack(m.normGeom(rect)),rect,[]);
const bermVol=4*(Math.PI*3/2)*(0.5*1.2*1.5+0.5*1.2*1.2);
ok('dirt estimate for bare berms is the bank formula', near(st.volume,bermVol,1e-6), st.volume.toFixed(2)+' m3');

console.log(fails?`\n${fails} FAILURES`:'\nall checks pass');
process.exit(fails?1:0);
