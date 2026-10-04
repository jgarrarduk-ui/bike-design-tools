// Notch template engine checks: node test/notch-tests.mjs
import fs from 'node:fs';
const src=fs.readFileSync(new URL('../frame-designer.html',import.meta.url),'utf8');
const eng=src.split('// ==NOTCH-START==')[1].split('// ==NOTCH-END==')[0];
const m=new Function(eng+'\nreturn {v3,notchCut,notchTemplate,notchClosedForm,PdfPage,buildPdf,PT};')();

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
  const T2=m.notchTemplate({b:B,u:U,a:recv(90),branchOD:38.1,branchWT:1,recvOD:50});
  const r=38.1/2-1, want=25-Math.sqrt(25*25-r*r);
  ok('smaller tube at 90°: depth R - sqrt(R²-r²)', Math.abs(T2.depth-want)<1e-9, T2.depth.toFixed(3)+' vs '+want.toFixed(3));
  ok('wrap uses outside circumference', Math.abs(T2.wrap-Math.PI*38.1)<1e-9, T2.wrap.toFixed(2));
  const T3=m.notchTemplate({b:B,u:U,a:recv(90),branchOD:38.1,branchWT:1,recvOD:50,cutTo:'od'});
  ok('cut to OD is deeper than cut to ID', T3.depth>T2.depth, T3.depth.toFixed(2)+' > '+T2.depth.toFixed(2));
  const T4=m.notchTemplate({b:B,u:U,a:recv(60),branchOD:32,branchWT:0.8,recvOD:46.5});
  ok('reports the acute angle between axes', Math.abs(T4.angle-60)<1e-9);
  const T5=m.notchTemplate({b:B,u:U,a:recv(120),branchOD:32,branchWT:0.8,recvOD:46.5});
  ok('60° and 120° give the same template turned half way round',
     T5.t.every((t,i)=>Math.abs(t-T4.t[(i+T4.n/2)%T4.n])<1e-9));
  const T6=m.notchTemplate({b:B,u:U,a:recv(90),branchOD:60,branchWT:1,recvOD:50});
  ok('branch wider than receiver is flagged', T6.ok===false);
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

// 4. PDF is structurally sound and drawn at 1:1
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

console.log(fails?`\n${fails} failed`:'\nall passed');
process.exit(fails?1:0);
