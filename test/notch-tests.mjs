// Notch template engine checks: node test/notch-tests.mjs
import fs from 'node:fs';
const src=fs.readFileSync(new URL('../frame-designer.html',import.meta.url),'utf8');
const eng=src.split('// ==NOTCH-START==')[1].split('// ==NOTCH-END==')[0];
const m=new Function(eng+'\nreturn {v3,notchCut,notchTemplate,notchClosedForm,PdfPage,buildPdf,PT,layoutNotchPages,notchHeaderMetrics};')();

let fails=0;
const ok=(name,cond,info='')=>{console.log((cond?'  pass  ':'  FAIL  ')+name+(info?'   '+info:''));if(!cond)fails++};
const B=[0,0,1], U=[1,0,0];                       // branch along z, 0° line along x
const recv=th=>[Math.sin(th*Math.PI/180),0,Math.cos(th*Math.PI/180)];

// 1. general 3D solve agrees with the textbook closed form
let worst=0;
for(const th of [25,40,58.5,68,72,90,110,140])
  for(const [rho,R] of [[15.2,23.25],[18.05,25],[16.475,25],[10,10],[5,30]])
    for(const e of [0,2.5,-4]){
      if(rho+Math.abs(e)>R) continue;
      const t=m.notchCut({b:B,u:U,a:recv(th),c:[0,e,0],rho,R,n:72});
      for(let i=0;i<=72;i++) worst=Math.max(worst,Math.abs(t[i]-m.notchClosedForm(rho,R,th,i*5,e)));
    }
ok('3D solver matches closed form (angles 25-140, offsets)', worst<1e-9, 'max err '+worst.toExponential(1)+' mm');

// 2. hand-checkable cases
{
  const T=m.notchTemplate({b:B,u:U,a:recv(90),branchOD:50,branchWT:0,recvOD:50});
  ok('equal tubes at 90°: depth is the radius', Math.abs(T.depth-25)<1e-9, T.depth.toFixed(4));
  const T2=m.notchTemplate({b:B,u:U,a:recv(90),branchOD:38.1,branchWT:1,recvOD:50,cutTo:'id'});
  const r=38.1/2-1, want=25-Math.sqrt(25*25-r*r);
  ok('smaller tube at 90°: depth R - sqrt(R²-r²)', Math.abs(T2.depth-want)<1e-9, T2.depth.toFixed(3)+' vs '+want.toFixed(3));
  ok('wrap uses outside circumference', Math.abs(T2.wrap-Math.PI*38.1)<1e-9, T2.wrap.toFixed(2));
  const T3=m.notchTemplate({b:B,u:U,a:recv(90),branchOD:38.1,branchWT:1,recvOD:50,cutTo:'od'});
  ok('cut to OD is deeper than cut to ID at 90°', T3.depth>T2.depth, T3.depth.toFixed(2)+' > '+T2.depth.toFixed(2));
  const T4=m.notchTemplate({b:B,u:U,a:recv(60),branchOD:32,branchWT:0.8,recvOD:46.5});
  ok('reports the acute angle between axes', Math.abs(T4.angle-60)<1e-9);
  const T5=m.notchTemplate({b:B,u:U,a:recv(120),branchOD:32,branchWT:0.8,recvOD:46.5});
  ok('60° and 120° give the same template turned half way round',
     T5.t.every((t,i)=>Math.abs(t-T4.t[(i+T4.n/2)%T4.n])<1e-9));
  const T6=m.notchTemplate({b:B,u:U,a:recv(90),branchOD:60,branchWT:1,recvOD:50});
  ok('branch wider than receiver is flagged', T6.ok===false);
  for(const th of [90,86,64.8,45]){
    const Te=m.notchTemplate({b:B,u:U,a:recv(th),branchOD:38.1,branchWT:0.9,recvOD:38.1});
    ok('equal diameters at '+th+'° give a full template', Te.ok && Te.t.every(Number.isFinite), 'depth '+Te.depth.toFixed(2));
  }
}

// 3. every cut point sits on the receiving tube's surface, and just past it is clear
{
  const a=m.v3.unit(recv(68)), rho=15.2, R=23.25;
  const t=m.notchCut({b:B,u:U,a,rho,R,n:360});
  const v=m.v3.cross(U,B);
  let onErr=0, clear=true;
  t.forEach((ti,i)=>{
    const ph=i*Math.PI/180;
    const d=m.v3.add(m.v3.mul(U,Math.cos(ph)),m.v3.mul(v,Math.sin(ph)));
    const dist=z=>{const P=m.v3.add(m.v3.mul(d,rho),m.v3.mul(B,z));
      const q=m.v3.sub(P,m.v3.mul(a,m.v3.dot(P,a))); return Math.hypot(...q);};
    onErr=Math.max(onErr,Math.abs(dist(ti)-R));
    for(let z=ti+0.05; z<ti+40; z+=0.5) if(dist(z)<R) clear=false;
  });
  ok('cut points lie on the receiving surface', onErr<1e-9, onErr.toExponential(1));
  ok('tube body beyond the cut never enters the receiving tube', clear);
}

// 4. full-wall cut: no point across the wall thickness enters the receiving tube
{
  const wall={b:B,u:U,branchOD:38.1,branchWT:1,recvOD:46.5};
  const interferes=(T,th)=>{
    const a=m.v3.unit(recv(th)), v=m.v3.cross(U,B); let worst=0;
    for(let i=0;i<T.n;i+=2){
      const ph=2*Math.PI*i/T.n;
      const d=m.v3.add(m.v3.mul(U,Math.cos(ph)),m.v3.mul(v,Math.sin(ph)));
      for(let k=0;k<=200;k++){
        const r=18.05+k/200, P=m.v3.add(m.v3.mul(d,r),m.v3.mul(B,T.t[i]));
        const q=m.v3.sub(P,m.v3.mul(a,m.v3.dot(P,a)));
        worst=Math.max(worst, T.R-Math.hypot(...q));   // >0: inside the receiving tube
      }
    }
    return worst;
  };
  const W=m.notchTemplate({...wall,a:recv(64.8)});
  const I=m.notchTemplate({...wall,a:recv(64.8),cutTo:'id'});
  const O=m.notchTemplate({...wall,a:recv(64.8),cutTo:'od'});
  ok('full wall is the default', W.t.every((t,i)=>t===m.notchTemplate({...wall,a:recv(64.8),cutTo:'wall'}).t[i]));
  ok('full wall never cuts less than ID or OD', W.t.every((t,i)=>t>=I.t[i]-1e-12&&t>=O.t[i]-1e-12));
  ok('full wall clears the receiving tube at 64.8°', interferes(W,64.8)<1e-4, interferes(W,64.8).toExponential(1)+' mm');
  ok('ID-only interferes at the heel at 64.8°', interferes(I,64.8)>0.3, interferes(I,64.8).toFixed(2)+' mm');
  ok('OD-only interferes at the sides at 64.8°', interferes(O,64.8)>0.3, interferes(O,64.8).toFixed(2)+' mm');
  ok('heel follows the outer edge', Math.abs(W.t[0]-O.t[0])<1e-9 && W.t[0]-I.t[0]>0.4,
     W.t[0].toFixed(2)+' vs ID '+I.t[0].toFixed(2));
  const W90=m.notchTemplate({...wall,a:recv(90)}), I90=m.notchTemplate({...wall,a:recv(90),cutTo:'id'});
  ok('at 90° full wall equals ID', W90.t.every((t,i)=>Math.abs(t-I90.t[i])<1e-9));
  ok('full wall clears at 90° and 30°', interferes(W90,90)<1e-4 && interferes(m.notchTemplate({...wall,a:recv(30)}),30)<1e-4);
}

// 5. PDF is structurally sound and drawn at 1:1
{
  const pg=new m.PdfPage(210,297); pg.stroke(0.3,0).rect(10,10,100,50).S().text(10,8,'Ø32 × 0.8 (test) 68°',8);
  const bytes=m.buildPdf([pg,pg]);
  const txt=new TextDecoder('latin1').decode(bytes);
  ok('starts with a PDF header', txt.startsWith('%PDF-1.4'));
  const xref=+txt.match(/startxref\n(\d+)/)[1];
  ok('startxref points at the xref table', txt.slice(xref,xref+4)==='xref');
  const offs=[...txt.slice(xref).matchAll(/^(\d{10}) 00000 n $/gm)].map(x=>+x[1]);
  ok('every xref offset points at its object', offs.every((o,i)=>txt.slice(o).startsWith((i+1)+' 0 obj')));
  ok('two pages', /\/Count 2/.test(txt));
  ok('100 mm is 283.46 pt', /28\.35 813\.54 m 311\.81 813\.54 l/.test(txt));
  ok('Ø, × and ° are WinAnsi escapes', /\\330\d\d \\327 0\.8 \\\(test\\\) 68\\260/.test(txt) || /\(\\33032 \\327 0\.8 \\\(test\\\) 68\\260\)/.test(txt));
}

// 6. notch header: logo top-right, scale bars still exactly 50 mm and below it
{
  const logo={w:566, h:82, rgb:new Uint8Array(566*82*3)};
  logo.rgb[0]=10; logo.rgb[1]=20; logo.rgb[2]=30;
  const pages=m.layoutNotchPages([], {title:'Notch templates', sub:'HT 68°', foot:['foot'], logo});
  const bytes=m.buildPdf(pages);
  const txt=new TextDecoder('latin1').decode(bytes);
  ok('logo xobject on the page', /\/Subtype \/Image \/Width 566 \/Height 82/.test(txt));
  ok('every page draws the logo', pages.every(pg=>pg.ops.some(op=>op.includes('/Logo Do'))));
  const bar=50*m.PT;
  const horiz=pages.map(pg=>{
    const hit=pg.ops.find(op=>/ m /.test(op)&&/ l$/.test(op)&&op.split(' l').length===2);
    // the 50 mm horizontal bar is the first line whose endpoints differ by 50 mm in x
    const lines=pg.ops.filter(op=>/ m /.test(op)&&/ l$/.test(op));
    const parsed=lines.map(op=>{
      const n=op.match(/[\d.]+/g).map(Number);
      return {x1:n[0], y1:n[1], x2:n[2], y2:n[3]};
    });
    const h=parsed.find(p=>Math.abs((p.x2-p.x1)-bar)<0.02 && Math.abs(p.y1-p.y2)<0.02);
    const v=parsed.find(p=>Math.abs(p.x1-p.x2)<0.02 && Math.abs((p.y1-p.y2)-bar)<0.02);
    const img=pg.ops.find(op=>op.includes('/Logo Do'));
    const im=img.match(/[\d.]+/g).map(Number); // w 0 0 h x y
    return {h, v, imgTop:im[5]+im[3], imgBottom:im[5], pageH:pg.h*m.PT};
  });
  ok('horizontal bar is 50 mm on every page', horiz.every(p=>p.h));
  ok('vertical bar is 50 mm on every page', horiz.every(p=>p.v));
  ok('scale bars sit below the logo', horiz.every(p=>p.h.y1 < p.imgBottom-0.5));
  ok('logo is in the top-right corner', horiz.every(p=>{
    const pg=pages[0];
    return p.imgTop > pg.h*m.PT-20*m.PT;
  }));
}

console.log(fails?`\n${fails} failed`:'\nall passed');
process.exit(fails?1:0);
