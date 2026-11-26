// Headless checks for the pump track engine. Run: node pump-track-designer/test/pumptrack-tests.mjs
import fs from 'node:fs';
const src=fs.readFileSync(new URL('../index.html',import.meta.url),'utf8');
const eng=src.split('// ==ENGINE-START==')[1].replace(/^[^\n]*/,'').split('// ==ENGINE-END==')[0];
const m=new Function(eng+'\nreturn {DEG,FEATURES,SIZES,TEMPLATES,buildTrack,pointAt,polyline,project,projectLeg,'+
  'spansOf,findSlot,issuesOf,zAt,profileOf,profZ,profHeight,defaultParams,sizedParams,normParams,statsOf,'+
  'insertNode,removeNode,toLatLon,fromLatLon,toGPX,parseGPX,gpxToDesign,traceTrack,toCSV,parseCSV,profileCSV,'+
  'normGeom,dist,bermRoller,llCentroid,anchorGeo,refPolyline,polyLen,setHipTurn,normHip,setCornerKind,cornerPoint,crc32,zipStore,unzip,packDesign,readBundle,groundAt,zTop,placeAtS};')();

let fails=0;
const ok=(name,cond,info='')=>{console.log((cond?'  pass  ':'  FAIL  ')+name+(info?'   '+info:''));if(!cond)fails++};
const near=(a,b,t)=>Math.abs(a-b)<=t;
const clone=o=>JSON.parse(JSON.stringify(o));

/* ---- geometry ---- */
// 20 x 10 rectangle, every corner r=3: perimeter minus 8 tangent lengths plus a full circle.
// Laid out the old way (corner points); normGeom turns it into circle centres.
const rectOld={closed:true,width:1.5,features:[],nodes:[[0,0],[20,0],[20,10],[0,10]].map(([x,y])=>({x,y,r:3,berm:'berm',bh:1.2,rh:0}))};
const rect=m.normGeom(rectOld);
ok('an old corner-point file becomes circle centres', rect.v===2&&near(rect.nodes[1].x,17,1e-9)&&near(rect.nodes[1].y,3,1e-9)&&rect.nodes[1].turn===1);
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

// radii big enough to make the straights short are fine now: a straight between
// two same-way circles only needs them not to sit inside each other
const big=clone(rect); big.nodes.forEach(n=>n.r=8);
T=m.buildTrack(big);
ok('big radii on a loop are not shrunk', T.corners.every(c=>!c.tight&&c.reff===8));
// two opposite-way circles that overlap have no straight between them: shrunk and flagged
const sb={v:2,closed:false,width:1.5,features:[],nodes:[{x:0,y:0,r:0},{x:10,y:3,r:3,turn:1,berm:'berm'},{x:13,y:3,r:3,turn:-1,berm:'berm'},{x:25,y:0,r:0}]};
T=m.buildTrack(sb);
ok('overlapping S-bend corners are flagged tight', T.corners[1].tight&&T.corners[2].tight&&T.corners[1].reff<3);
let sbJump=0, sbPts=m.polyline(T,0.05,true);
for(let i=1;i<sbPts.length;i++) sbJump=Math.max(sbJump,m.dist(sbPts[i],sbPts[i-1]));
ok('and the shrunk S-bend is still continuous', sbJump<0.06, sbJump.toFixed(3)+' m');
ok('a tight corner raises an issue', m.issuesOf(T,sb,[]).some(i=>i.kind==='tight'));

/* ---- dragging a corner moves only that corner ---- */
for(const t of m.TEMPLATES){
  const g0=m.normGeom(t.geom), T0=m.buildTrack(g0);
  g0.nodes.forEach((nd,i)=>{
    const g1=clone(g0); g1.nodes[i].x+=1.7; g1.nodes[i].y-=1.1;
    const T1=m.buildTrack(g1);
    const still=T0.corners.every((c,j)=>j===i||(m.dist(c.P,T1.corners[j].P)<1e-12&&c.reff===T1.corners[j].reff&&c.s===T1.corners[j].s));
    if(!still) ok(`${t.name}: moving corner ${i+1} moves no other corner`,false);
  });
}
ok('moving any corner of any template moves no other corner', true);
const gm=m.normGeom(m.TEMPLATES[0].geom), Tm0=m.buildTrack(gm); gm.nodes[3].x+=2;
const Tm1=m.buildTrack(gm);
ok('the moved corner keeps its radius and its arc stays on its circle',
   near(m.dist(Tm1.arcs.find(a=>a.i===3).M,gm.nodes[3]),Tm0.corners[3].reff,1e-9)&&Tm1.corners[3].reff===Tm0.corners[3].reff);
// dragged across the line of its neighbours, a berm keeps wrapping the same way
const hp={v:2,closed:false,width:1.5,features:[],nodes:[{x:0,y:0,r:0},{x:20,y:4,r:4,turn:1,berm:'berm'},{x:0,y:8,r:0}]};
const sw0=m.buildTrack(hp).corners[1].defl; hp.nodes[1].x=-3;
const sw1=m.buildTrack(hp).corners[1].defl;
ok('a hairpin dragged back past its straights keeps turning the same way', sw0>0&&sw1>0, (sw0/m.DEG).toFixed(0)+'° then '+(sw1/m.DEG).toFixed(0)+'°');
// old hairpins made of two 90° corners on one circle become one 180° corner
const oval=m.normGeom(m.TEMPLATES.find(t=>t.name==='Backyard oval').geom), To=m.buildTrack(oval);
ok('an old two-corner hairpin becomes one corner', oval.nodes.length===2&&To.arcs.every(a=>near(Math.abs(a.defl)/m.DEG,180,1e-6)));
ok('a two-corner loop will not drop to one', m.removeNode(clone(oval),To,0)===false);
// switching to a hip and back keeps the straights where they were
const sq=m.normGeom(m.TEMPLATES.find(t=>t.name==='Empty loop').geom), Ts0=m.buildTrack(sq);
m.setCornerKind(sq,Ts0,1,'hip'); const Ts1=m.buildTrack(sq);
const sameLine=(A,B)=>near(A.dir.x,B.dir.x,1e-6)&&near(A.dir.y,B.dir.y,1e-6)&&Math.abs(A.dir.x*(B.a.y-A.a.y)-A.dir.y*(B.a.x-A.a.x))<1e-3;
ok('a corner made a hip keeps both straights on their lines', sq.nodes[1].berm==='hip'&&sameLine(Ts0.legs[0],Ts1.legs[0])&&sameLine(Ts0.legs[1],Ts1.legs[1]));
m.setCornerKind(sq,Ts1,1,'berm'); const Ts2=m.buildTrack(sq);
ok('and made round again it is the corner it was', near(Ts2.length,Ts0.length,1e-3)&&near(Ts2.corners[1].reff,Ts0.corners[1].reff,1e-9));

// auto berms
const auto=clone(rect); auto.nodes.forEach(n=>n.berm='auto'); auto.nodes[2].x=20.5;
T=m.buildTrack(auto);
ok('auto corner banks a 90 degree turn', T.arcs.find(a=>a.i===1).berm===true);
const shallow=m.normGeom({closed:false,width:1,features:[],nodes:[{x:0,y:0,r:0},{x:10,y:0,r:5,berm:'auto'},{x:20,y:3,r:0}]});
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
ok('whale tail is gone and boner log is now wooden drop', !m.FEATURES.whaletail&&!m.FEATURES.bonerlog&&m.FEATURES.woodendrop.name==='Wooden drop');
const bl=m.profileOf({type:'woodendrop',p:{h:1,deck:2,kick:5}});
const ws=bl.segs.find(s=>s.kind==='wood');
ok('wooden drop deck kicks up by its angle', near(ws.z(ws.u1)-ws.z(ws.u0),2*Math.tan(5*m.DEG),1e-9));
const L=m.sizedParams('tabletop','L'), S=m.sizedParams('tabletop','S');
ok('sizes scale heights but keep angles', L.h>1&&S.h<1&&L.lip===S.lip);
ok('params are clamped to their range', m.normParams('roller',{h:9,n:2.6}).h===1.2&&m.normParams('roller',{n:2.6}).n===3);

/* ---- hips ---- */
// a line: in from the west, hip at (30,0), out again
const hipLine=(deg,side,hip)=>{
  const g=m.normGeom({closed:false,width:1.5,features:[],nodes:[{x:0,y:0,r:0},{x:30,y:0,r:3,berm:'hip',hip:Object.assign({gap:3,h:1,lh:1.2},hip)},{x:55,y:0,r:0}]});
  m.setHipTurn(g,1,deg,side); return g;
};
for(const deg of [0,30,60,90]){
  const g=hipLine(deg,1), Th=m.buildTrack(g), h=Th.hips[0];
  ok(`hip at ${deg}°: turns that much, lip to knuckle is the gap`,
     near(h.theta/m.DEG,deg,0.01)&&near(m.dist(h.lip,h.knuckle),3,1e-9), m.dist(h.lip,h.knuckle).toFixed(4)+' m');
}
let gH=hipLine(45,1), TH=m.buildTrack(gH), H=TH.hips[0];
ok('a hip has no fillet', TH.arcs[0].len===0&&TH.corners[1].reff===0&&TH.arcs[0].berm===false);
ok('setHipTurn keeps the next straight its length', near(m.dist(gH.nodes[1],gH.nodes[2]),25,1e-3));
ok('hip turns left for side +1', TH.corners[1].defl>0);
m.setHipTurn(gH,1,45,-1); TH=m.buildTrack(gH);
ok('the Left/Right switch mirrors the turn', near(TH.corners[1].defl/m.DEG,-45,1e-3));
H=TH.hips[0];
ok('hip profile peaks at the lip, lands on the landing height', near(m.zAt(TH,[],TH.arcs[0].s0-H.half),1,1e-6)&&near(m.zAt(TH,[],TH.arcs[0].s0+H.half),1.2,1e-6));
ok('a double hip has a pit at the corner', m.zAt(TH,[],TH.arcs[0].s0)<1e-9);
const gT=hipLine(45,1,{style:'table'}), TT2=m.buildTrack(gT);
ok('a table hip has a deck across the corner', near(m.zAt(TT2,[],TT2.arcs[0].s0),1,1e-9));
ok('a table hip lands at its takeoff height', near(m.zAt(TT2,[],TT2.arcs[0].s0+TT2.hips[0].half),1,1e-6));
const lf=TH.hips[0].pr;
ok('the landing flares out', near(lf.flare(0)*1.5,1.5,1e-9)&&near(lf.flare(lf.len),1.5,1e-9)&&lf.flare(lf.len)>lf.flare(0));
// features are kept clear of a hip
const spH=m.spansOf(TH,gH), sl=m.findSlot(TH,spH,0,29,4,-1);
ok('findSlot keeps a feature off the hip takeoff', sl.ok&&sl.c+2<=TH.legs[0].len-TH.hips[0].needIn+1e-9, 'centre '+sl.c.toFixed(2));
gH.features=[{type:'roller',leg:1,t:.02,p:{}}];
ok('a feature on the hip landing is an overlap', m.issuesOf(TH,gH,m.spansOf(TH,gH)).some(i=>i.kind==='overlap'&&i.node===1));
const short=m.normGeom({closed:false,width:1.5,features:[],nodes:[{x:0,y:0},{x:3,y:0,berm:'hip',hip:{}},{x:20,y:0}]});
ok('a hip without enough straight is reported', m.issuesOf(m.buildTrack(short),short,[]).some(i=>i.kind==='fit'&&i.node===1));
const wide=hipLine(120,1);
ok('a hip past 90° is a warning', m.issuesOf(m.buildTrack(wide),wide,[]).some(i=>i.kind==='hipangle'));
ok('hip settings survive the design file', JSON.stringify(m.normGeom(JSON.parse(JSON.stringify(gT))).nodes[1].hip)===JSON.stringify(m.normHip(gT.nodes[1].hip)));
ok('hip settings are clamped', m.normHip({gap:99,flare:9,style:'x'}).gap===8&&m.normHip({flare:9}).flare===2.5&&m.normHip({style:'x'}).style==='double');
const hcsv=m.parseCSV(m.toCSV(gT,TT2,[])), hcT=m.buildTrack(hcsv);
ok('hip corners round trip through the CSV', hcsv.nodes[1].berm==='hip'&&JSON.stringify(hcsv.nodes[1].hip)===JSON.stringify(m.normHip(gT.nodes[1].hip))&&near(hcT.length,TT2.length,0.01));
ok('a hip appears in GPX waypoints', m.toGPX({_meta:{},geom:gT,cfg:{geo:{lat:51.6,lon:-3.9,rot:0,ele:0}}},TT2,[]).includes('<type>hip</type>'));

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
ok('a new corner starts as a point, so the track does not move', ed.nodes[1].r===0&&ed.nodes[1].rWant===4&&near(T2.length,T.length,1e-9));
ok('insert leaves every feature where it was', before.every((p,i)=>m.dist(p,after[i])<1e-6),
   before.map((p,i)=>m.dist(p,after[i]).toFixed(4)).join(','));
ok('insert moves later features to the new straight', ed.features[1].leg===1&&ed.features[2].leg===3);
m.removeNode(ed,T2,1);
const T3=m.buildTrack(ed), back=m.spansOf(T3,ed).map(s=>m.pointAt(T3,s.s0+s.pr.len/2));
ok('remove undoes insert', before.every((p,i)=>m.dist(p,back[i])<1e-6)&&ed.nodes.length===4);
const tri=clone(rect); tri.nodes.pop(); T=m.buildTrack(tri);
ok('a three-corner loop can drop to two round corners', m.removeNode(clone(tri),T,0)===true);
tri.nodes[2].r=0;
ok('but not when one left would be a sharp point', m.removeNode(tri,T,0)===false);

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
ok('GPX is well formed enough to read back', parsed.pts.length>200&&parsed.wpts.length===dj.features.length+T.hips.length);
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

// two lines in one design: one <trk> each, and the design inside reopens both
const l2=m.normGeom(m.TEMPLATES.find(t=>t.name==='Empty loop').geom), T2l=m.buildTrack(l2);
const two={_meta:{name:'Hill'},lines:[{name:'Jump line',color:'#f472b6',geom:dj},{name:'Pump track',color:null,geom:l2}],active:1,geom:l2,cfg:{geo}};
const gx2=m.toGPX(two,[{name:'Jump line',T,spans:sp},{name:'Pump track',T:T2l,spans:[]}],[],'2026-01-01T00:00:00Z');
ok('a two-line GPX has a track for each line', (gx2.match(/<trk>/g)||[]).length===2&&gx2.includes('<name>Jump line</name>')&&gx2.includes('<name>Pump track</name>'));
const back3=m.gpxToDesign(m.parseGPX(gx2));
ok('and reopens with both lines', back3.lines.length===2&&back3.lines[0].color==='#f472b6'&&back3.active===1);

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
const oldCsv='kind,ref,type,x_m,y_m,radius_m,corner\ntrack,,loop\nnode,N1,,0,0,3,berm\nnode,N2,,20,0,3,berm\nnode,N3,,20,10,3,berm\nnode,N4,,0,10,3,berm\n';
ok('an old CSV of corner points opens as the same track', near(m.buildTrack(m.parseCSV(oldCsv)).length,want,1e-6));
ok('a hand-written CSV with missing columns opens', hg.nodes.length===2&&near(hs[0].s0,5,1e-9)&&hs[0].pr.p.h===1.4);
ok('profile CSV has a row every 0.25 m', m.profileCSV(T,sp,null).trim().split('\n').length>T.length/0.25);

/* ---- design file ---- */
const old=m.normGeom({closed:true,nodes:[{x:0,y:0},{x:10,y:0},{x:5,y:8}],features:[{type:'nope',leg:0},{type:'roller',leg:9,t:3}]});
ok('an old or odd file is normalised, not rejected', old.width===1.5&&old.features.length===1&&old.features[0].leg===2&&old.features[0].t===1);

/* ---- ground line ---- */
const ln=m.normGeom(m.TEMPLATES.find(t=>t.name==='Empty line').geom);   // 40 m straight
let TG=m.buildTrack(ln);
ok('a new design has flat ground', ln.ground.z0===0&&ln.ground.z1===0&&ln.ground.pts.length===0&&m.groundAt(TG,20)===0);
ln.ground.z1=-5; TG=m.buildTrack(ln);
ok('the ground runs straight from start to end', near(m.groundAt(TG,20),-2.5,1e-9)&&near(m.groundAt(TG,40),-5,1e-9));
ln.ground.pts.push({leg:0,t:.25,z:2}); TG=m.buildTrack(ln);
ok('a ground point bends the line through it', near(m.groundAt(TG,10),2,1e-9)&&near(m.groundAt(TG,5),1,1e-9)&&near(m.groundAt(TG,25),-1.5,1e-9));
ln.features=[{type:'roller',leg:0,t:.75,p:{h:.5,L:4,n:1}}];
const spG=m.spansOf(TG,ln);
ok('features sit on top of the ground', near(m.zTop(TG,spG,30),m.groundAt(TG,30)+.5,1e-9));
const lpG=m.normGeom(m.TEMPLATES.find(t=>t.name==='Empty loop').geom); lpG.ground={z0:1,z1:9,pts:[{leg:1,t:.5,z:3}]};
const lg=m.normGeom(lpG), TL=m.buildTrack(lg);
ok('a loop ends at its start height', near(m.groundAt(TL,0),1,1e-9)&&near(m.groundAt(TL,TL.length),1,1e-9)&&near(m.groundAt(TL,TL.length+.001),1,1e-3));
const gs0=m.placeAtS(TL,TL.legs[1].s0+TL.legs[1].len/2);
ok('placeAtS finds the straight and how far along', gs0.leg===1&&near(gs0.t,.5,1e-9));
const sAt=(T,g)=>g.ground.pts.map(q=>q.leg!=null?T.legs[q.leg].s0+q.t*T.legs[q.leg].len:T.arcs.find(a=>a.i===q.corner).s0+q.t*T.arcs.find(a=>a.i===q.corner).len);
lg.ground.pts.push({corner:2,t:.5,z:4});
const TL1=m.buildTrack(lg), pos0=sAt(TL1,lg).map(s=>m.pointAt(TL1,s));
m.insertNode(lg,TL1,1,2); const TL2=m.buildTrack(lg), pos1=sAt(TL2,lg).map(s=>m.pointAt(TL2,s));
ok('adding a corner leaves the ground points where they were', pos0.every((p,i)=>m.dist(p,pos1[i])<1e-6)&&lg.ground.pts[1].corner===3);
m.removeNode(lg,TL2,3); const TL3=m.buildTrack(lg);
const L1d=TL2.legs[2].len, L2d=TL2.legs[3].len;
ok('deleting a corner moves its ground point to where the corner was', lg.ground.pts[1].leg===2&&near(lg.ground.pts[1].t,L1d/(L1d+L2d),1e-9)&&lg.ground.pts.length===2);
const gcsv=m.parseCSV(m.toCSV(ln,TG,spG)), TGc=m.buildTrack(gcsv);
ok('the ground line round trips through the CSV', gcsv.ground.z1===-5&&gcsv.ground.pts.length===1&&near(m.groundAt(TGc,10),2,1e-6));
const gg=m.parseGPX(m.toGPX({_meta:{},geom:ln,cfg:{geo:{lat:51.6,lon:-3.9,rot:0,ele:100}}},TG,spG));
ok('GPX heights include the ground', near(gg.pts[gg.pts.length-1].ele,95,0.01)&&near(gg.pts[0].ele,100,0.01));
ok('profile CSV has ground, feature and total columns', m.profileCSV(TG,spG,null).startsWith('chainage_m,x_m,y_m,ground_m,feature_m,z_m'));
const su0=m.profileOf({type:'stepup',p:{h:.8,gap:0,lh:1.3,deck:2,lip:45,land:25}});
ok('a step up can have no gap', su0.p.gap===0&&!su0.segs.some(q=>q.kind==='gap')&&near(m.profZ(su0,su0.len),0,0.01));

/* ---- design bundle (.zip) ---- */
ok('crc32 matches the standard check value', m.crc32(new TextEncoder().encode('123456789'))===0xCBF43926);
const bin=new Uint8Array(3000).map((_,i)=>(i*37)&255);
const zz=m.unzip(m.zipStore([{name:'a.txt',data:'hello é'},{name:'dir/b.bin',data:bin}]));
ok('zip round trips text and binary', zz.length===2&&new TextDecoder().decode(zz[0].data)==='hello é'&&
   zz[1].name==='dir/b.bin'&&zz[1].data.every((b,i)=>b===bin[i]));
const bad=m.zipStore([{name:'a.txt',data:'hello'}]); bad[30+5]^=1;
let threw=false; try{ m.unzip(bad); }catch(e){ threw=/damaged/.test(e.message); }
ok('a damaged zip entry is refused', threw);
const dsg={_meta:{name:'Site A'},geom:m.normGeom(rect),cfg:{geo,bg:{src:'data:image/jpeg;base64,xx',x:1,y:2,w:40,rot:0,opacity:.6},
  gpx:{name:'walk',pts:[{lat:51.6,lon:-3.9},{lat:51.6001,lon:-3.9001}]}}};
const photo={name:'IMG 001.jpg',data:bin}, gpxIn={name:'walk.gpx',text:'<gpx>original</gpx>'};
const zb=m.packDesign(dsg,photo,gpxIn), rb=m.readBundle(m.unzip(zb));
ok('a saved zip holds the design, the original photo and the original GPX',
   rb.design._meta.name==='Site A'&&rb.photo&&rb.photo.name==='IMG 001.jpg'&&rb.photo.data.every((b,i)=>b===bin[i])&&
   rb.gpx&&rb.gpx.text==='<gpx>original</gpx>');
ok('the photo is kept as a file, not inside the JSON', !rb.design.cfg.bg.src&&rb.design.cfg.bg.file==='photo/IMG 001.jpg'&&rb.design.cfg.bg.w===40);
const zn=m.readBundle(m.unzip(m.packDesign(dsg,null,null)));
ok('with no original GPX the trace is written out from its points', zn.gpx&&m.parseGPX(zn.gpx.text).pts.length===2);
ok('with no original photo the embedded copy stays in the JSON', zn.design.cfg.bg.src==='data:image/jpeg;base64,xx'&&!zn.photo);
const nested=m.unzip(m.zipStore(m.unzip(zb).map(f=>({name:'Site A/'+f.name,data:f.data}))));
ok('a bundle re-zipped inside a folder still opens', m.readBundle(nested).photo.name==='IMG 001.jpg');

/* ---- quantities ---- */
const st=m.statsOf(m.buildTrack(m.normGeom(rect)),rect,[]);
const bermVol=4*(Math.PI*3/2)*(0.5*1.2*1.5+0.5*1.2*1.2);
ok('dirt estimate for bare berms is the bank formula', near(st.volume,bermVol,1e-6), st.volume.toFixed(2)+' m3');

console.log(fails?`\n${fails} FAILURES`:'\nall checks pass');
process.exit(fails?1:0);
