// Rewrites the non-flex PRESETS entries in ../index.html from ../dev-presets/*.json,
// so the JSON files stay the one source of truth while the presets are in dev.
// A preset that fails preset-check.mjs is still written, with " (draft)" on its name.
// Usage: node sync-presets.mjs
import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
const here=new URL('.',import.meta.url).pathname, root=here+'../';
const ORDER=['sp-direct','sp-tt','sp-yoke','sp-high','ldsp-ss','ldsp-tt','ldsp-high','split','split-float',
  'horst-rocker','horst-stay','horst-float','horst-high','vpp-upper','vpp-lower','dw-upper','dw-lower','sl-float',
  'moto-unitrak','moto-prolink','moto-floater','moto-unit'];
const lit=v=>JSON.stringify(v).replace(/"([A-Za-z_]\w*)":/g,'$1:').replace(/"/g,"'");
const q=s=>"'"+String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";
const entries=[];
for(const key of ORDER){
  const f=root+'dev-presets/'+key+'.json';
  if(!fs.existsSync(f)){ console.log('missing',key); continue; }
  const P=JSON.parse(fs.readFileSync(f,'utf8'));
  let pass=true; try{ execFileSync('node',[here+'preset-check.mjs',f]); }catch{ pass=false; }
  const name=P.name.replace(/ \(draft\)$/,'')+(pass?'':' (draft)');
  entries.push(`  { name:${q(name)},
    note:${q(P.note||'')},
    family:${q(P.family)},
    geom:${lit(P.geom)},
    cfg:${lit(P.cfg)},
    axleRel:${lit(P.axleRel||[])},
    labels:${lit(P.labels||{})},
    mounts:${lit(P.mounts||{})} }`);
  console.log((pass?'ok    ':'DRAFT ')+key);
}
const html=fs.readFileSync(root+'index.html','utf8');
const start=html.indexOf('const PRESETS=[');
const end=html.indexOf('\n];',start);
const body=html.slice(start,end);
// keep the flex entries (family:'flex') exactly as they are
const flexEnd=body.lastIndexOf("family:'flex'");
const cut=body.indexOf('\n  {',flexEnd);           // start of the first non-flex entry
if(cut<0) throw new Error('could not find the end of the flex entries');
const out=html.slice(0,start)+body.slice(0,cut)+'\n'+entries.join(',\n')+html.slice(end);
fs.writeFileSync(root+'index.html',out);
