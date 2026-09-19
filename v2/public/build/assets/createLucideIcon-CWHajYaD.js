import{d as _,b as s,e as a,F as c,h as n,g as l,t as d,l as h,V as g}from"./app-D-thgQOU.js";const f={class:"inline-flex items-center gap-3"},b={class:"sr-only"},y=["src"],C=["src"],B={class:"leading-tight"},j={class:"block text-[15px] font-semibold tracking-tight"},v={key:0,class:"jp block text-[12px] tracking-[0.22em] text-muted-foreground"},F=_({__name:"Merek",props:{merek:{},tinggi:{default:"h-8"},denganJp:{type:Boolean,default:!0}},setup(t){return(e,o)=>(a(),s("span",f,[e.merek.logo_dark||e.merek.logo_light?(a(),s(c,{key:0},[n("span",b,d(e.merek.name),1),e.merek.logo_dark?(a(),s("img",{key:0,src:e.merek.logo_dark,alt:"",width:"360",height:"120",decoding:"async",class:h(["w-auto",[e.tinggi,e.merek.logo_light?"hidden dark:block":""]])},null,10,y)):l("",!0),e.merek.logo_light?(a(),s("img",{key:1,src:e.merek.logo_light,alt:"",width:"402",height:"120",decoding:"async",class:h(["w-auto",[e.tinggi,e.merek.logo_dark?"block dark:hidden":""]])},null,10,C)):l("",!0)],64)):(a(),s(c,{key:1},[o[0]||(o[0]=n("span",{class:"hanko !h-9 !min-w-9 text-xs","aria-hidden":"true"},"拳",-1)),n("span",B,[n("span",j,d(e.merek.name),1),e.denganJp&&e.merek.jp?(a(),s("span",v,d(e.merek.jp),1)):l("",!0)])],64))]))}});/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const N=t=>t.replace(/([a-z0-9])([A-Z])/g,"$1-$2").toLowerCase();/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */var r={xmlns:"http://www.w3.org/2000/svg",width:24,height:24,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor","stroke-width":2,"stroke-linecap":"round","stroke-linejoin":"round"};/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const V=({size:t,strokeWidth:e=2,absoluteStrokeWidth:o,color:i,iconNode:m,name:p,class:$,...u},{slots:k})=>g("svg",{...r,width:t||r.width,height:t||r.height,stroke:i||r.stroke,"stroke-width":o?Number(e)*24/Number(t):e,class:["lucide",`lucide-${N(p??"icon")}`],...u},[...m.map(w=>g(...w)),...k.default?[k.default()]:[]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const I=(t,e)=>(o,{slots:i})=>g(V,{...o,iconNode:e,name:t},i);export{F as _,I as c};
