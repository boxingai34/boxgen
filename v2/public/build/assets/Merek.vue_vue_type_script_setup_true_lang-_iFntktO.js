import{Q as g,d as _,b as s,e as a,F as c,h as n,g as l,t as d,l as h}from"./app-DJpX3jZU.js";/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const f=t=>t.replace(/([a-z0-9])([A-Z])/g,"$1-$2").toLowerCase();/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */var r={xmlns:"http://www.w3.org/2000/svg",width:24,height:24,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor","stroke-width":2,"stroke-linecap":"round","stroke-linejoin":"round"};/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const b=({size:t,strokeWidth:e=2,absoluteStrokeWidth:o,color:i,iconNode:m,name:p,class:A,...u},{slots:k})=>g("svg",{...r,width:t||r.width,height:t||r.height,stroke:i||r.stroke,"stroke-width":o?Number(e)*24/Number(t):e,class:["lucide",`lucide-${f(p??"icon")}`],...u},[...m.map(w=>g(...w)),...k.default?[k.default()]:[]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const I=(t,e)=>(o,{slots:i})=>g(b,{...o,iconNode:e,name:t},i),y={class:"inline-flex items-center gap-3"},C={class:"sr-only"},B=["src"],j=["src"],v={class:"leading-tight"},N={class:"block text-[15px] font-semibold tracking-tight"},$={key:0,class:"jp block text-[10px] tracking-[0.22em] text-muted-foreground"},J=_({__name:"Merek",props:{merek:{},tinggi:{default:"h-8"},denganJp:{type:Boolean,default:!0}},setup(t){return(e,o)=>(a(),s("span",y,[e.merek.logo_dark||e.merek.logo_light?(a(),s(c,{key:0},[n("span",C,d(e.merek.name),1),e.merek.logo_dark?(a(),s("img",{key:0,src:e.merek.logo_dark,alt:"",width:"360",height:"120",decoding:"async",class:h(["w-auto",[e.tinggi,e.merek.logo_light?"hidden dark:block":""]])},null,10,B)):l("",!0),e.merek.logo_light?(a(),s("img",{key:1,src:e.merek.logo_light,alt:"",width:"402",height:"120",decoding:"async",class:h(["w-auto",[e.tinggi,e.merek.logo_dark?"block dark:hidden":""]])},null,10,j)):l("",!0)],64)):(a(),s(c,{key:1},[o[0]||(o[0]=n("span",{class:"hanko !h-9 !min-w-9 text-[11px]","aria-hidden":"true"},"拳",-1)),n("span",v,[n("span",N,d(e.merek.name),1),e.denganJp&&e.merek.jp?(a(),s("span",$,d(e.merek.jp),1)):l("",!0)])],64))]))}});export{J as _,I as c};
