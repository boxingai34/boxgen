import{c as i}from"./createLucideIcon-KcOb1w2O.js";/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const r=i("BookmarkCheckIcon",[["path",{d:"m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z",key:"169p4p"}],["path",{d:"m9 10 2 2 4-4",key:"1gnqz4"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const k=i("BookmarkIcon",[["path",{d:"m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z",key:"1fy3hk"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const u=i("Undo2Icon",[["path",{d:"M9 14 4 9l5-5",key:"102s5s"}],["path",{d:"M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11",key:"f3b9sd"}]]),s={pixiv:{nama:"Pixiv",pendek:"Pixiv",titik:"bg-sky-500"},deviantart:{nama:"DeviantArt",pendek:"DA",titik:"bg-emerald-500"},danbooru:{nama:"Danbooru",pendek:"Danbooru",titik:"bg-blue-600"},safebooru:{nama:"Safebooru",pendek:"Safebooru",titik:"bg-teal-500"},rule34:{nama:"Rule34",pendek:"R34",titik:"bg-lime-500"},twitter:{nama:"X / Twitter",pendek:"X",titik:"bg-zinc-400"}},b=[{nilai:"",label:"Semua"},{nilai:"bukan",label:"Non-AI"},{nilai:"ai",label:"AI"},{nilai:"?",label:"Belum jelas"}],m=[{nilai:"",label:"Semua rating"},{nilai:"aman",label:"Aman"},{nilai:"r15",label:"R-15"},{nilai:"r18",label:"R-18"}];function c(a){return a==="e"?"R-18":a==="q"?"R-15":""}function d(a,n){return n==="r18"&&a==="e"||n==="r15"&&(a==="e"||a==="q")}const o=["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"];function p(a,n=Date.now()){if(!a)return"";const t=new Date(a),e=Math.max(0,(n-t.getTime())/1e3);return e<60?"baru saja":e<3600?`${Math.floor(e/60)} mnt lalu`:e<86400?`${Math.floor(e/3600)} jam lalu`:e<86400*7?`${Math.floor(e/86400)} hr lalu`:`${t.getDate()} ${o[t.getMonth()]} ${t.getFullYear()}`}export{r as B,b as K,m as R,s as S,u as U,k as a,d,c as l,p as s};
