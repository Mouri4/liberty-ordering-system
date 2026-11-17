<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PastryLiberty — Baked Fresh, Every Layer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,400;1,9..144,500;1,9..144,600&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
/* =========================================================
   PASTRYLIBERTY — DESIGN SYSTEM
   Palette: Espresso #2B1B14, Cream #FBF3E7, Butter #F3E1BE,
            Jam #A72032, Gold #C8973F, Cocoa #6B4226, Muted #8A7563
   Type: Fraunces (display) / Manrope (body) / Space Mono (ticket/eyebrow)
   Signature: rotating "cake stand" hero + a baking-timer scroll ring
   ========================================================= */
:root{
  --espresso:#2B1B14;
  --espresso-2:#3a2417;
  --cream:#FBF3E7;
  --cream-2:#F7ECDA;
  --butter:#F3E1BE;
  --jam:#A72032;
  --jam-2:#8c1a29;
  --gold:#C8973F;
  --gold-light:#E7C77E;
  --cocoa:#6B4226;
  --ink:#2B1B14;
  --muted:#8A7563;
  --line:rgba(43,27,20,0.12);
  --shadow:rgba(43,27,20,0.18);
  --radius-lg:1.8rem;
  --radius-md:1.2rem;
  --ease:cubic-bezier(.16,.8,.3,1);
}

*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;scroll-padding-top:9rem;}
body{
  font-family:'Manrope',sans-serif;
  background:var(--cream);
  color:var(--ink);
  overflow-x:hidden;
  -webkit-font-smoothing:antialiased;
}
img{display:block;max-width:100%;}
a{text-decoration:none;color:inherit;}
ul{list-style:none;}
button{font-family:inherit;background:none;cursor:pointer;}
::selection{background:var(--gold-light);color:var(--espresso);}

.eyebrow{
  font-family:'Space Mono',monospace;
  font-size:1.15rem;
  letter-spacing:.25em;
  text-transform:uppercase;
  display:inline-flex;
  align-items:center;
  gap:.9rem;
}
.eyebrow::before{
  content:'';
  width:2.2rem;height:1px;
  background:currentColor;
  display:inline-block;
}

.section{
  padding:clamp(5rem,9vw,9rem) clamp(2rem,7vw,6.5rem);
  position:relative;
}
.section-head{
  max-width:64rem;
  margin:0 auto clamp(3rem,6vw,5rem);
  text-align:center;
}
.section-head h2{
  font-family:'Fraunces',serif;
  font-weight:500;
  font-size:clamp(3rem,5.2vw,4.6rem);
  line-height:1.05;
  margin-top:1.2rem;
  letter-spacing:-.01em;
}
.section-head h2 em{
  font-style:italic;
  font-weight:400;
  color:var(--jam);
}
.section-head p{
  font-size:1.6rem;
  color:var(--muted);
  max-width:48rem;
  margin:1.4rem auto 0;
  line-height:1.65;
}
.section-head.left{ text-align:left; margin-left:0; }

/* =========================== NAV =========================== */
nav{
  position:fixed; inset:0 0 auto 0;
  z-index:200;
  display:flex; align-items:center; justify-content:space-between;
  padding:2.2rem clamp(2rem,6vw,6rem);
  transition:background .4s var(--ease), padding .4s var(--ease), box-shadow .4s var(--ease), backdrop-filter .4s var(--ease);
}
nav.scrolled{
  background:rgba(251,243,231,.85);
  backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px);
  padding:1.3rem clamp(2rem,6vw,6rem);
  box-shadow:0 1px 0 var(--line), 0 1.5rem 3rem -2rem var(--shadow);
}
nav .logo{
  font-family:'Fraunces',serif;
  font-style:italic;
  font-weight:600;
  font-size:2.0rem;
  color:#fff;
  display:flex; align-items:center; gap:.9rem;
  transition:color .4s var(--ease);
}
nav .logo .mark{
  width:3.4rem;height:3.4rem;
  border-radius:50%;
  background:var(--jam);
  display:flex;align-items:center;justify-content:center;
  color:var(--butter);
  font-family:'Fraunces',serif; font-style:normal; font-weight:700; font-size:1.7rem;
  flex-shrink:0;
  box-shadow:0 0 0 3px rgba(255,255,255,.35);
}
nav.scrolled .logo{ color:var(--espresso); }

.nav-right{ display:flex; align-items:center; gap:clamp(1.6rem,3vw,3.2rem); }

.navigation{ display:flex; gap:2.8rem; font-size:1.45rem; font-weight:600; }
.navigation a{ position:relative; color:#fff; padding:.4rem 0; transition:color .3s; }
nav.scrolled .navigation a{ color:var(--cocoa); }
.navigation a::after{
  content:''; position:absolute; left:0; right:0; bottom:-2px; height:2px;
  background:currentColor; transform:scaleX(0); transform-origin:left;
  transition:transform .3s var(--ease);
}
.navigation a:hover::after, .navigation a.current::after{ transform:scaleX(1); }
.navigation a.current{ color:var(--gold-light); }
nav.scrolled .navigation a.current{ color:var(--jam); }

/* timer scroll-progress ring — signature element */
.timer-ring{ position:relative; width:4.2rem; height:4.2rem; flex-shrink:0; display:grid; place-items:center; }
.timer-ring svg{ transform:rotate(-90deg); width:100%; height:100%; }
.timer-ring circle{ fill:none; stroke-width:3; }
.timer-ring .track{ stroke:rgba(255,255,255,.3); }
nav.scrolled .timer-ring .track{ stroke:var(--line); }
.timer-ring .prog{ stroke:var(--gold-light); stroke-linecap:round; stroke-dasharray:113; stroke-dashoffset:113; transition:stroke-dashoffset .1s linear, stroke .4s; }
nav.scrolled .timer-ring .prog{ stroke:var(--jam); }
.timer-ring::after{
  content:'🍰'; position:absolute; font-size:1.5rem; filter:grayscale(0);
}

.login-method{ display:flex; gap:1.0rem; }
.login-method a{
  border-radius:3rem; padding:1rem 1rem; font-size:1.35rem; font-weight:700;
  border:1.5px solid rgba(255,255,255,.7); color:#fff;
  position:relative; overflow:hidden;
}
nav.scrolled .login-method a{ border-color:var(--line); color:var(--cocoa); }
#signup{ background:var(--jam); border-color:var(--jam); color:#fff !important; }
#signup:hover{ background:var(--jam-2); transform:translateY(-2px); }
#signin:hover{ background:rgba(255,255,255,.15); }
nav.scrolled #signin:hover{ background:var(--butter); }

.hamburger{ display:none; flex-direction:column; gap:.5rem; cursor:pointer; z-index:210; }
.hamburger span{ width:2.6rem; height:.28rem; border-radius:2px; background:#fff; transition:.3s; }
nav.scrolled .hamburger span{ background:var(--espresso); }
.hamburger.active span:nth-child(1){ transform:translateY(.83rem) rotate(45deg); }
.hamburger.active span:nth-child(2){ opacity:0; }
.hamburger.active span:nth-child(3){ transform:translateY(-.83rem) rotate(-45deg); }

/* =========================== HERO =========================== */
.hero{
  position:relative; min-height:100vh; width:100%;
  background:radial-gradient(120% 90% at 15% 10%, #45291a 0%, var(--espresso) 45%, #1c110b 100%);
  overflow:hidden;
  display:flex; align-items:center;
  padding-top:9rem;
}
.hero .grain{
  position:absolute; inset:0; opacity:.05; pointer-events:none; z-index:1;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='4'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  background-size:180px 180px;
}
.hero .glow{
  position:absolute; border-radius:50%; filter:blur(90px); z-index:0; opacity:.55; will-change:transform;
}
.hero .glow-1{ width:44rem;height:44rem; background:radial-gradient(circle,var(--jam),transparent 70%); top:-14rem; right:-8rem; animation:float1 14s ease-in-out infinite; }
.hero .glow-2{ width:34rem;height:34rem; background:radial-gradient(circle,var(--gold),transparent 70%); bottom:-10rem; left:10%; animation:float2 16s ease-in-out infinite; }
@keyframes float1{ 0%,100%{transform:translate(0,0)} 50%{transform:translate(-3rem,3rem)} }
@keyframes float2{ 0%,100%{transform:translate(0,0)} 50%{transform:translate(3rem,-2rem)} }

.hero-grid{
  position:relative; z-index:3; width:100%;
  display:grid; grid-template-columns:1.05fr .95fr; align-items:center; gap:2rem;
  padding:2rem clamp(2rem,6vw,6rem) 6rem;
}
.hero-copy .eyebrow{ color:var(--gold-light); }
.hero-copy h1{
  font-family:'Fraunces',serif; font-weight:500; color:var(--cream);
  font-size:clamp(4.4rem,7vw,7.4rem); line-height:.98; letter-spacing:-.02em;
  margin:1.6rem 0 2.2rem;
}
.hero-copy h1 em{ font-style:italic; font-weight:400; color:var(--gold-light); display:block; }
.hero-copy p{ font-size:1.75rem; color:rgba(251,243,231,.78); max-width:46rem; line-height:1.65; margin-bottom:3.2rem; }

.hero-ctas{ display:flex; align-items:center; gap:2rem; flex-wrap:wrap; }
.btn{
  position:relative; overflow:hidden;
  display:inline-flex; align-items:center; gap:.9rem;
  padding:1.5rem 3.2rem; border-radius:5rem; font-weight:700; font-size:1.45rem;
  transition:transform .35s var(--ease), box-shadow .35s var(--ease), background .35s;
}
.btn-primary{ background:var(--jam); color:#fff; box-shadow:0 1.2rem 2.6rem -1rem rgba(167,32,50,.7); }
.btn-primary:hover{ transform:translateY(-3px); box-shadow:0 1.8rem 3.2rem -1rem rgba(167,32,50,.85); }
.btn-ghost{ color:var(--cream); border:1.5px solid rgba(251,243,231,.4); }
.btn-ghost:hover{ background:rgba(251,243,231,.1); border-color:rgba(251,243,231,.7); }
.btn-ghost svg{ transition:transform .35s var(--ease); }
.btn-ghost:hover svg{ transform:translateX(4px); }

.ripple{ position:absolute; border-radius:50%; background:rgba(255,255,255,.5); transform:scale(0); animation:rippleAnim .6s ease-out forwards; pointer-events:none; }
@keyframes rippleAnim{ to{ transform:scale(2.8); opacity:0; } }

/* the cake stand — signature hero visual */
.stand-wrap{ position:relative; display:flex; align-items:center; justify-content:center; height:56rem; perspective:1400px; }
.stand-ring{
  position:absolute; width:44rem; height:44rem; border-radius:50%;
  border:1px dashed rgba(251,243,231,.28);
  animation:spin 34s linear infinite;
}
.stand-ring::before, .stand-ring::after{
  content:''; position:absolute; width:.8rem; height:.8rem; border-radius:50%; background:var(--gold-light);
}
.stand-ring::before{ top:-.4rem; left:50%; transform:translateX(-50%); }
.stand-ring::after{ bottom:-.4rem; left:50%; transform:translateX(-50%); }
@keyframes spin{ to{ transform:rotate(360deg); } }

.plate{
  position:relative; width:38rem; height:38rem; border-radius:50%;
  background:radial-gradient(circle at 38% 32%, #fffdf8, #efe1c4 62%, #d9c69b 100%);
  box-shadow:0 3rem 6rem -1.5rem rgba(0,0,0,.55), inset 0 0 0 1.4rem rgba(255,255,255,.55);
  display:flex; align-items:center; justify-content:center;
  animation:heroPop 1s var(--ease) both .15s;
}
.plate img{
  width:78%; height:78%; object-fit:cover; border-radius:50%;
  box-shadow:0 1.6rem 3rem -1rem rgba(0,0,0,.4);
  transition:transform .15s linear;
}
.crumbs{ position:absolute; inset:0; pointer-events:none; z-index:4; }
.crumb{ position:absolute; border-radius:50%; background:var(--gold-light); opacity:.85; animation:drift 7s ease-in-out infinite; }
@keyframes drift{ 0%,100%{ transform:translateY(0) rotate(0deg);} 50%{ transform:translateY(-1.6rem) rotate(180deg);} }
@keyframes heroPop{ from{ opacity:0; transform:scale(.85); } to{ opacity:1; transform:scale(1); } }

.scroll-cue{
  position:absolute; bottom:2.6rem; left:50%; transform:translateX(-50%);
  z-index:4; display:flex; flex-direction:column; align-items:center; gap:.8rem;
  color:rgba(251,243,231,.65); font-family:'Space Mono',monospace; font-size:1.05rem; letter-spacing:.2em; text-transform:uppercase;
}
.scroll-cue .stick{ width:2px; height:3.6rem; background:linear-gradient(rgba(251,243,231,0),rgba(251,243,231,.7)); position:relative; overflow:hidden; }
.scroll-cue .stick::after{ content:''; position:absolute; top:-100%; left:0; width:100%; height:100%; background:var(--gold-light); animation:cueDrop 2s ease-in-out infinite; }
@keyframes cueDrop{ 0%{ top:-100%; } 60%,100%{ top:100%; } }

/* heroIn */
@keyframes heroIn{ from{ opacity:0; transform:translateY(2.6rem); } to{ opacity:1; transform:translateY(0); } }
.hero-copy .eyebrow{ animation:heroIn .8s var(--ease) both; }
.hero-copy h1{ animation:heroIn .9s var(--ease) .1s both; }
.hero-copy p{ animation:heroIn .9s var(--ease) .2s both; }
.hero-ctas{ animation:heroIn .9s var(--ease) .3s both; }

/* =========================== MARQUEE / DISPLAY CASE =========================== */
.case-section{ background:var(--espresso); padding:2.4rem 0; position:relative; border-top:1px solid rgba(251,243,231,.08); border-bottom:1px solid rgba(251,243,231,.08); }
.case-track-wrap{ overflow:hidden; mask-image:linear-gradient(to right,transparent,black 6%,black 94%,transparent); -webkit-mask-image:linear-gradient(to right,transparent,black 6%,black 94%,transparent); }
.case-track{ display:flex; gap:1.8rem; width:max-content; animation:caseScroll 42s linear infinite; }
.case-track:hover{ animation-play-state:paused; }
@keyframes caseScroll{ from{ transform:translateX(0); } to{ transform:translateX(-50%); } }
.case-card{
  flex-shrink:0; width:19rem; height:24rem; border-radius:1.4rem; overflow:hidden; position:relative;
  box-shadow:0 1.5rem 2.5rem -1rem rgba(0,0,0,.4);
  transition:transform .4s var(--ease);
}
.case-card:hover{ transform:translateY(-.8rem); }
.case-card img{ width:100%; height:100%; object-fit:cover; transition:transform .5s var(--ease); }
.case-card:hover img{ transform:scale(1.1); }
.case-card .tag{
  position:absolute; left:1.2rem; bottom:1.2rem; z-index:2;
  font-family:'Space Mono',monospace; font-size:1.1rem; letter-spacing:.08em;
  color:#fff; background:rgba(43,27,20,.55); backdrop-filter:blur(6px);
  padding:.6rem 1.1rem; border-radius:2rem; text-transform:capitalize;
}
.case-card::after{ content:''; position:absolute; inset:0; background:linear-gradient(to top,rgba(0,0,0,.55),transparent 55%); }

/* =========================== ABOUT / PROCESS =========================== */
#about{ background:var(--cream); }
.about-grid{ display:grid; grid-template-columns:.9fr 1.1fr; gap:clamp(3rem,6vw,7rem); align-items:center; max-width:130rem; margin:0 auto; }
.about-visual{ position:relative; perspective:1400px; }
.about-visual .frame{
  border-radius:var(--radius-lg); overflow:hidden; box-shadow:0 3rem 5rem -2rem var(--shadow);
  transform-style:preserve-3d; will-change:transform; transition:transform .1s linear;
}
.about-visual img{ width:100%; height:52rem; object-fit:cover; }
.about-badge{
  position:absolute; bottom:-2.4rem; right:-2.4rem; z-index:3;
  background:var(--jam); color:#fff; border-radius:50%; width:12rem; height:12rem;
  display:flex; align-items:center; justify-content:center; text-align:center;
  font-family:'Fraunces',serif; font-style:italic; font-size:1.5rem; line-height:1.3;
  box-shadow:0 1.5rem 2.5rem -1rem rgba(167,32,50,.55);
  animation:spin 24s linear infinite;
}
.about-badge span{ display:block; transform:rotate(0deg); animation:counterSpin 24s linear infinite; }
@keyframes counterSpin{ to{ transform:rotate(-360deg); } }

.about-copy .eyebrow{ color:var(--jam); }
.about-copy h2{
  font-family:'Fraunces',serif; font-weight:500; font-size:clamp(3.2rem,4.6vw,4.8rem); line-height:1.05;
  margin:1.4rem 0 2rem; letter-spacing:-.01em;
}
.about-copy h2 em{ font-style:italic; font-weight:400; color:var(--jam); }
.about-copy > p{ font-size:1.65rem; color:var(--muted); line-height:1.7; margin-bottom:3.4rem; max-width:52rem; }

.process-list{ display:flex; flex-direction:column; gap:0; }
.process-item{
  display:grid; grid-template-columns:5.5rem 1fr; gap:1.8rem;
  padding:2.2rem 0; border-top:1px solid var(--line);
}
.process-item:last-child{ border-bottom:1px solid var(--line); }
.process-item .num{ font-family:'Fraunces',serif; font-style:italic; font-size:2.8rem; color:var(--gold); }
.process-item h4{ font-size:1.9rem; font-weight:700; margin-bottom:.6rem; }
.process-item p{ font-size:1.45rem; color:var(--muted); line-height:1.6; }

/* =========================== FEATURES =========================== */
#features{ background:var(--butter); }
.feature-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:2rem; max-width:130rem; margin:0 auto; }
.feature-card{
  background:var(--cream); border-radius:var(--radius-md); padding:3rem 2.4rem;
  box-shadow:0 1rem 2rem -1.2rem var(--shadow); border:1px solid var(--line);
  transition:transform .35s var(--ease), box-shadow .35s var(--ease), border-color .35s;
}
.feature-card:hover{ transform:translateY(-.8rem); box-shadow:0 2rem 3rem -1.5rem var(--shadow); border-color:var(--gold); }
.feature-card .icon{
  width:5.6rem; height:5.6rem; border-radius:1.4rem; background:var(--jam); color:#fff;
  display:flex; align-items:center; justify-content:center; margin-bottom:1.8rem;
}
.feature-card .icon svg{ width:2.6rem; height:2.6rem; }
.feature-card h4{ font-size:1.85rem; font-weight:800; margin-bottom:.9rem; }
.feature-card p{ font-size:1.4rem; color:var(--muted); line-height:1.6; }

/* =========================== BEST SELLERS =========================== */
#products{ background:var(--cream); }
.product-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:2.6rem; max-width:130rem; margin:0 auto; }
.product-card{
  background:#fff; border-radius:var(--radius-lg); overflow:hidden;
  box-shadow:0 1.2rem 2.5rem -1.5rem var(--shadow); border:1px solid var(--line);
  transition:transform .4s var(--ease), box-shadow .4s var(--ease);
  display:flex; flex-direction:column;
}
.product-card:hover{ transform:translateY(-1rem); box-shadow:0 2.5rem 4rem -2rem var(--shadow); }
.product-media{ position:relative; height:26rem; overflow:hidden; }
.product-media img{ width:100%; height:100%; object-fit:cover; transition:transform .6s var(--ease); }
.product-card:hover .product-media img{ transform:scale(1.12) rotate(1deg); }
.badge{
  position:absolute; top:1.4rem; left:1.4rem; z-index:2;
  font-family:'Space Mono',monospace; font-size:1.05rem; letter-spacing:.05em;
  padding:.6rem 1.2rem; border-radius:2rem; color:#fff;
}
.badge.hot{ background:var(--jam); }
.badge.new{ background:var(--cocoa); }
.badge.top{ background:var(--gold); color:var(--espresso); }
.price-tag{
  position:absolute; top:1.4rem; right:-.4rem; z-index:2;
  background:var(--cream); color:var(--ink); font-family:'Space Mono',monospace; font-weight:700;
  font-size:1.5rem; padding:.7rem 1.3rem .7rem 1.6rem; border-radius:.4rem 0 0 .4rem;
  box-shadow:0 .6rem 1.2rem -.4rem rgba(0,0,0,.35);
  transform:rotate(2deg);
}
.product-body{ padding:2.2rem 2.2rem 2.4rem; display:flex; flex-direction:column; gap:1rem; flex:1; }
.product-body h4{ font-family:'Fraunces',serif; font-weight:600; font-size:2.1rem; }
.stars-row{ display:flex; gap:.3rem; color:var(--gold); font-size:1.4rem; }
.product-body .desc{ font-size:1.35rem; color:var(--muted); line-height:1.55; flex:1; }
.product-cta{
  margin-top:.6rem; display:inline-flex; align-items:center; justify-content:center; gap:.8rem;
  padding:1.1rem 1.6rem; border-radius:3rem; background:var(--espresso); color:var(--cream);
  font-weight:700; font-size:1.35rem; position:relative; overflow:hidden;
  transition:background .3s;
}
.product-cta:hover{ background:var(--jam); }

.products-footer{ text-align:center; margin-top:4.5rem; }

/* =========================== REVIEWS =========================== */
#reviews{ background:var(--espresso); color:var(--cream); }
#reviews .section-head .eyebrow{ color:var(--gold-light); }
#reviews .section-head h2{ color:var(--cream); }
#reviews .section-head h2 em{ color:var(--gold-light); }
#reviews .section-head p{ color:rgba(251,243,231,.65); }

.review-track{
  display:flex; gap:2.2rem; overflow-x:auto; scroll-snap-type:x mandatory; padding-bottom:1.5rem;
  scrollbar-width:none;
}
.review-track::-webkit-scrollbar{ display:none; }
.review-card{
  scroll-snap-align:start; flex:0 0 clamp(30rem,34vw,38rem);
  background:rgba(251,243,231,.06); border:1px solid rgba(251,243,231,.14);
  border-radius:var(--radius-lg); padding:3rem;
  display:flex; flex-direction:column; gap:1.8rem;
  backdrop-filter:blur(6px);
  transition:transform .35s var(--ease), background .35s;
}
.review-card:hover{ transform:translateY(-.6rem); background:rgba(251,243,231,.1); }
.review-card .stars span{ color:var(--gold-light); font-size:1.7rem; }
.review-card .review-text{ font-size:1.42rem; line-height:1.75; font-family:'Fraunces',serif; font-style:italic; font-weight:400; color:rgba(251,243,231,.92); }
.reviewer{ display:flex; align-items:center; gap:1.4rem; margin-top:auto; padding-top:1.6rem; border-top:1px solid rgba(251,243,231,.14); }
.reviewer .avatar{
  width:4.6rem; height:4.6rem; border-radius:50%; background:var(--jam); color:#fff;
  display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.6rem; flex-shrink:0;
}
.reviewer h5{ font-size:1.45rem; font-weight:700; }
.reviewer p{ font-size:1.2rem; color:rgba(251,243,231,.55); }

.review-nav{ display:flex; justify-content:center; gap:1.2rem; margin-top:3rem; }
.review-nav button{
  width:4.4rem; height:4.4rem; border-radius:50%; border:1px solid rgba(251,243,231,.3); color:var(--cream);
  display:flex; align-items:center; justify-content:center; transition:.3s;
}
.review-nav button:hover{ background:var(--jam); border-color:var(--jam); }

/* =========================== CTA BANNER =========================== */
.cta-banner{
  position:relative; margin:0; overflow:hidden; min-height:56rem; display:flex; align-items:center;
  background:var(--espresso);
}
.cta-banner img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:.55; }
.cta-banner::before{ content:''; position:absolute; inset:0; background:linear-gradient(90deg,rgba(43,27,20,.96) 20%, rgba(43,27,20,.55) 75%); }
.cta-inner{ position:relative; z-index:2; max-width:66rem; padding:0 clamp(2rem,6vw,6rem); }
.cta-inner .eyebrow{ color:var(--gold-light); }
.cta-inner h2{
  font-family:'Fraunces',serif; font-weight:500; color:var(--cream);
  font-size:clamp(3.4rem,5.2vw,5.6rem); line-height:1.05; margin:1.6rem 0 2rem; letter-spacing:-.01em;
}
.cta-inner h2 em{ font-style:italic; font-weight:400; color:var(--gold-light); }
.cta-inner p{ font-size:1.7rem; color:rgba(251,243,231,.78); line-height:1.65; margin-bottom:3rem; max-width:48rem; }

/* =========================== FOOTER =========================== */
footer.site-footer{ background:#20140d; color:rgba(251,243,231,.75); padding:6rem clamp(2rem,6vw,6rem) 3rem; }
.footer-grid{ display:grid; grid-template-columns:1.4fr repeat(3,1fr); gap:4rem; max-width:130rem; margin:0 auto; padding-bottom:4rem; border-bottom:1px solid rgba(251,243,231,.1); }
.footer-brand .logo{ font-family:'Fraunces',serif; font-style:italic; font-weight:600; font-size:2.6rem; color:var(--cream); display:flex; align-items:center; gap:.9rem; margin-bottom:1.4rem; }
.footer-brand p{ font-size:1.4rem; line-height:1.7; max-width:32rem; color:rgba(251,243,231,.55); }
.social-row{ display:flex; gap:1.2rem; margin-top:2rem; }
.social-row a{
  width:4rem; height:4rem; border-radius:50%; border:1px solid rgba(251,243,231,.2);
  display:flex; align-items:center; justify-content:center; transition:.3s;
}
.social-row a:hover{ background:var(--jam); border-color:var(--jam); transform:translateY(-3px); }
.footer-col h5{ font-family:'Space Mono',monospace; font-size:1.2rem; letter-spacing:.15em; text-transform:uppercase; color:var(--gold-light); margin-bottom:1.8rem; }
.footer-col a, .footer-col p{ display:block; font-size:1.42rem; padding:.7rem 0; color:rgba(251,243,231,.65); transition:.25s; }
.footer-col a:hover{ color:var(--cream); padding-left:.4rem; }
.copyright{ max-width:130rem; margin:3rem auto 0; display:flex; justify-content:space-between; flex-wrap:wrap; gap:1rem; font-size:1.25rem; color:rgba(251,243,231,.4); }

/* =========================== BACK TO TOP =========================== */
#backToTop{
  position:fixed; right:2.6rem; bottom:2.6rem; width:5.2rem; height:5.2rem; border-radius:50%;
  background:var(--jam); color:#fff; display:flex; align-items:center; justify-content:center;
  font-size:2rem; cursor:pointer; z-index:150; opacity:0; transform:translateY(1.4rem) scale(.85);
  pointer-events:none; transition:opacity .3s,transform .3s,background .3s;
  box-shadow:0 1rem 2rem -.6rem rgba(0,0,0,.4);
}
#backToTop.visible{ opacity:1; transform:translateY(0) scale(1); pointer-events:auto; }
#backToTop:hover{ background:var(--jam-2); transform:translateY(-.3rem) scale(1.05); }

/* =========================== SCROLL REVEAL =========================== */
.reveal{ opacity:0; transform:translateY(3.4rem); transition:opacity .8s var(--ease), transform .8s var(--ease); }
.reveal.active{ opacity:1; transform:translateY(0); }
.reveal-stagger .reveal-child{ opacity:0; transform:translateY(3.2rem); transition:opacity .7s var(--ease), transform .7s var(--ease); }
.reveal-stagger.active .reveal-child{ opacity:1; transform:translateY(0); }
.reveal-stagger .reveal-child:nth-child(1){ transition-delay:.05s; }
.reveal-stagger .reveal-child:nth-child(2){ transition-delay:.14s; }
.reveal-stagger .reveal-child:nth-child(3){ transition-delay:.23s; }
.reveal-stagger .reveal-child:nth-child(4){ transition-delay:.32s; }
.reveal-stagger .reveal-child:nth-child(5){ transition-delay:.41s; }
.reveal-stagger .reveal-child:nth-child(6){ transition-delay:.5s; }

@media (prefers-reduced-motion: reduce){
  *{ animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; }
  html{ scroll-behavior:auto; }
  .reveal,.reveal-child{ opacity:1; transform:none; }
}

/* =========================== RESPONSIVE =========================== */
@media (max-width:1200px){
  .about-grid{ grid-template-columns:1fr; }
  .about-visual .frame img{ height:42rem; }
  .feature-grid{ grid-template-columns:repeat(2,1fr); }
  .product-grid{ grid-template-columns:repeat(2,1fr); }
  .footer-grid{ grid-template-columns:1fr 1fr; }
}
@media (max-width:980px){
  .hero-grid{ grid-template-columns:1fr; text-align:center; padding-top:1rem; }
  .hero-copy{ order:2; }
  .hero-copy p{ margin-left:auto; margin-right:auto; }
  .hero-ctas{ justify-content:center; }
  .stand-wrap{ height:38rem; order:1; }
  .plate{ width:28rem; height:28rem; }
  .stand-ring{ width:32rem; height:32rem; }
  .navigation, .login-method{ display:none; }
  .navigation.open, .login-method.open{
    display:flex; position:fixed; top:0; right:0; height:100vh; width:min(32rem,82vw);
    background:var(--espresso); flex-direction:column; padding:11rem 3rem 3rem; gap:1rem; z-index:205;
    box-shadow:-2rem 0 4rem rgba(0,0,0,.4);
  }
  .navigation.open a{ color:var(--cream); font-size:1.9rem; padding:1rem 0; }
  .login-method.open{ top:auto; height:auto; bottom:0; padding:2rem 3rem 4rem; background:#20140d; }
  .login-method.open a{ width:100%; text-align:center; }
  .hamburger{ display:flex; }
  .timer-ring{ display:none; }
}
@media (max-width:768px){
  html{ font-size:92%; }
  .section{ padding:4.5rem 2rem; }
  .about-badge{ display:none; }
  .feature-grid{ grid-template-columns:1fr; }
  .product-grid{ grid-template-columns:1fr; }
  .footer-grid{ grid-template-columns:1fr 1fr; gap:3rem; }
  .cta-banner{ min-height:46rem; text-align:center; }
  .cta-inner{ margin:0 auto; }
}
@media (max-width:480px){
  .hero-copy h1{ font-size:4rem; }
  .footer-grid{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<!-- ================= NAV ================= -->
<nav id="siteNav">
  <a href="#hero" class="logo"><span class="mark">LF</span>LibertyFoodmart</a>

  <div class="hamburger" id="hamburger" onclick="toggleMenu()">
    <span></span><span></span><span></span>
  </div>

  <ul class="navigation" id="navigation">
    <li><a href="#hero" class="current">Home</a></li>
    <li><a href="#about">About</a></li>
    <li><a href="#products">Menu</a></li>
    <li><a href="#reviews">Reviews</a></li>
  </ul>

  <div class="nav-right">
    <div class="timer-ring" title="Reading progress">
      <svg viewBox="0 0 40 40">
        <circle class="track" cx="20" cy="20" r="18"></circle>
        <circle class="prog" id="progCircle" cx="20" cy="20" r="18"></circle>
      </svg>
    </div>
    <ul class="login-method" id="login-method">
      <li><a id="signin" href="customer-login.php">Sign In</a></li>
      <li><a id="signup" href="customer-register.php">Sign Up</a></li>
    </ul>
  </div>
</nav>

<!-- ================= HERO ================= -->
<section class="hero" id="hero">
  <div class="grain"></div>
  <div class="glow glow-1"></div>
  <div class="glow glow-2"></div>

  <div class="hero-grid">
    <div class="hero-copy">
      <span class="eyebrow">Calamba, Philippines — Baked Fresh Daily</span>
      <h1>Every Layer<br><em>Tells a Story</em></h1>
      <p>Hand-crafted cakes, pastries, and breads made from scratch each morning — ready for pickup, delivery, or the celebration you're planning.</p>
      <div class="hero-ctas">
        <a href="customer-login.php" class="btn btn-primary">Order Now</a>
        <a href="#products" class="btn btn-ghost">See the Menu
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      </div>
    </div>

    <div class="stand-wrap">
      <div class="stand-ring"></div>
      <div class="plate" id="plate">
        <img src="src/biskwit.jpeg" alt="Loaded chocolate drip cake, PastryLiberty signature">
      </div>
      <div class="crumbs" id="crumbs"></div>
    </div>
  </div>

  <div class="scroll-cue"><span>Scroll</span><div class="stick"></div></div>
</section>

<!-- ================= DISPLAY CASE MARQUEE ================= -->
<section class="case-section">
  <div class="case-track-wrap">
    <div class="case-track" id="caseTrack"></div>
  </div>
</section>

<!-- ================= ABOUT / PROCESS ================= -->
<section class="section" id="about">
  <div class="about-grid">
    <div class="about-visual reveal">
      <div class="frame" id="tiltFrame">
        <img src="src/log-image.jpg" alt="Chocolate mousse cake topped with fresh berries">
      </div>
      <div class="about-badge"><span>Made Fresh · Daily Batches ·</span></div>
    </div>

    <div class="about-copy">
      <span class="eyebrow">Our Process</span>
      <h2>From Our Oven<br>to <em>Your Table</em></h2>
      <p>No mixes, no shortcuts. Every order starts before sunrise in our Calamba kitchen and travels straight to your door with the same care it left the oven.</p>

      <div class="process-list reveal-stagger">
        <div class="process-item reveal-child">
          <span class="num">01</span>
          <div><h4>Mixed &amp; Baked</h4><p>Real butter, fresh eggs, and cacao — measured and mixed each morning, never the night before.</p></div>
        </div>
        <div class="process-item reveal-child">
          <span class="num">02</span>
          <div><h4>Hand Decorated</h4><p>Piped, layered, and finished by hand. No two cakes leave our counter looking exactly alike.</p></div>
        </div>
        <div class="process-item reveal-child">
          <span class="num">03</span>
          <div><h4>Delivered With Care</h4><p>Boxed to survive the ride and arrive looking like it just left the kitchen table.</p></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= FEATURES ================= -->
<section class="section" id="features">
  <div class="section-head reveal">
    <span class="eyebrow" style="color:var(--jam)">Why PastryLiberty</span>
    <h2>Baking Made <em>Easy</em> For You</h2>
  </div>

  <div class="feature-grid reveal-stagger">
    <div class="feature-card reveal-child">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-6 9 6M4 10v9a1 1 0 001 1h4v-6h6v6h4a1 1 0 001-1v-9"/></svg></div>
      <h4>Pickup or Delivery</h4>
      <p>Choose curbside pickup at our Calamba counter or doorstep delivery around the city.</p>
    </div>
    <div class="feature-card reveal-child">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></div>
      <h4>Same-Day Ordering</h4>
      <p>Order before 2PM and pick up the very same day on select cakes and pastries.</p>
    </div>
    <div class="feature-card reveal-child">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-4.35-9.5-8.5C1 9 2.5 5.5 6 5c2-.3 3.6.8 6 3.2C14.4 5.8 16 4.7 18 5c3.5.5 5 4 3.5 7.5C19 16.65 12 21 12 21z"/></svg></div>
      <h4>Custom Celebration Cakes</h4>
      <p>Tell us the occasion and the flavor — we'll design something worth the candles.</p>
    </div>
    <div class="feature-card reveal-child">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></div>
      <h4>Pay Your Way</h4>
      <p>Cash on delivery, card, or GCash — whatever's easiest when your order arrives.</p>
    </div>
  </div>
</section>

<!-- ================= BEST SELLERS ================= -->
<section class="section" id="products">
  <div class="section-head reveal">
    <span class="eyebrow" style="color:var(--jam)">The Counter</span>
    <h2>Best <em>Sellers</em></h2>
    <p>The six that keep customers coming back — reordered every week by families across Calamba.</p>
  </div>

  <div class="product-grid reveal-stagger">

    <div class="product-card reveal-child">
      <div class="product-media">
        <span class="badge hot">🔥 Bestseller</span>
        <span class="price-tag">₱850</span>
        <img src="src/best-cake.jpg" alt="Loaded chocolate drip cake with wafer sticks and chocolate bars">
      </div>
      <div class="product-body">
        <h4>Loaded Chocolate Drip</h4>
        <div class="stars-row">★★★★★</div>
        <p class="desc">Fudge cake, whipped ganache, and a full drip of melted chocolate topped with wafers.</p>
        <a href="customer-login.php" class="product-cta">Add to Order</a>
      </div>
    </div>

    <div class="product-card reveal-child">
      <div class="product-media">
        <span class="badge top">★ Classic</span>
        <span class="price-tag">₱780</span>
        <img src="src/black-forestv2.webp" alt="Classic black forest cake with cherries and whipped cream">
      </div>
      <div class="product-body">
        <h4>Classic Black Forest</h4>
        <div class="stars-row">★★★★★</div>
        <p class="desc">Chocolate sponge, kirsch-soaked cherries, and clouds of fresh whipped cream.</p>
        <a href="customer-login.php" class="product-cta">Add to Order</a>
      </div>
    </div>

    <div class="product-card reveal-child">
      <div class="product-media">
        <span class="badge new">✦ Rich</span>
        <span class="price-tag">₱720</span>
        <img src="src/red-velvet-cake.jpg" alt="Midnight dark chocolate cake slice with glossy ganache">
      </div>
      <div class="product-body">
        <h4>Red Velvet</h4>
        <div class="stars-row">★★★★★</div>
        <p class="desc">Deep, bittersweet cocoa layers finished in a mirror-glossy chocolate glaze.</p>
        <a href="customer-login.php" class="product-cta">Add to Order</a>
      </div>
    </div>

    <div class="product-card reveal-child">
      <div class="product-media">
        <span class="badge hot">🔥 Party Pick</span>
        <span class="price-tag">₱950</span>
        <img src="src/tiramisu-cake.jpg" alt="Rosette-piped chocolate birthday cake">
      </div>
      <div class="product-body">
        <h4>Tiramisu Cake</h4>
        <div class="stars-row">★★★★★</div>
        <p class="desc">Hand-piped chocolate rosettes over soft vanilla sponge — a party favorite.</p>
        <a href="customer-login.php" class="product-cta">Add to Order</a>
      </div>
    </div>

    <div class="product-card reveal-child">
      <div class="product-media">
        <span class="badge new">✦ Flaky</span>
        <span class="price-tag">₱180</span>
        <img src="src/walnutv3.jpg" alt="Vanilla mille-feuille pastry with piped cream layers">
      </div>
      <div class="product-body">
        <h4>Walnut</h4>
        <div class="stars-row">★★★★☆</div>
        <p class="desc">Crisp puff pastry layered with vanilla bean cream, finished with a marbled glaze.</p>
        <a href="customer-login.php" class="product-cta">Add to Order</a>
      </div>
    </div>

    <div class="product-card reveal-child">
      <div class="product-media">
        <span class="badge top">★ Elegant</span>
        <span class="price-tag">₱890</span>
        <img src="src/log-image.jpg" alt="Chocolate mousse cake with fresh berries on top">
      </div>
      <div class="product-body">
        <h4>Berry Chocolate Mousse</h4>
        <div class="stars-row">★★★★★</div>
        <p class="desc">Silky chocolate mousse over a nutty crust, crowned with frozen forest berries.</p>
        <a href="customer-login.php" class="product-cta">Add to Order</a>
      </div>
    </div>

  </div>

  <div class="products-footer reveal">
    <a href="customer-login.php" class="btn btn-primary">View Full Menu</a>
  </div>
</section>

<!-- ================= REVIEWS ================= -->
<section class="section" id="reviews">
  <div class="section-head reveal">
    <span class="eyebrow">Kind Words</span>
    <h2>Customer <em>Reviews</em></h2>
    <p>What our sweet customers are saying, straight from delivery notes and pickup counters.</p>
  </div>

  <div class="review-track" id="reviewTrack">
    <div class="review-card">
      <div class="stars"><span>★★★★★</span></div>
      <p class="review-text">"The chocolate cake was absolutely divine! Every bite felt like a warm hug. I've never tasted anything so moist and rich from a local bakery. Will definitely be ordering again!"</p>
      <div class="reviewer"><div class="avatar">JS</div><div><h5>Jhon Salvador</h5><p>Regular Customer</p></div></div>
    </div>

    <div class="review-card">
      <div class="stars"><span>★★★★★</span></div>
      <p class="review-text">"Ordered a custom birthday cake and it looked even better than I imagined. The cream cheese frosting was the perfect sweetness. All my guests kept asking where I got it!"</p>
      <div class="reviewer"><div class="avatar">JM</div><div><h5>Jhoncen Taulo</h5><p>Birthday Order</p></div></div>
    </div>

    <div class="review-card">
      <div class="stars"><span>★★★★☆</span></div>
      <p class="review-text">"The croissants and pandesal are fresh every morning. Delivery was on time and packaging was so cute. The sourdough bread is now a weekly staple in our home!"</p>
      <div class="reviewer"><div class="avatar">M</div><div><h5>Marianne Zamora</h5><p>Delivery Customer</p></div></div>
    </div>

    <div class="review-card">
      <div class="stars"><span>★★★★★</span></div>
      <p class="review-text">"Pastry Liberty never disappoints! The ube ensaymada is my personal favorite. Soft, fluffy, and generously topped. Staff is always kind and GCash payment is super convenient."</p>
      <div class="reviewer"><div class="avatar">J</div><div><h5>Jhienaive Lao</h5><p>Loyal Customer</p></div></div>
    </div>

    <div class="review-card">
      <div class="stars"><span>★★★★★</span></div>
      <p class="review-text">"Best pastries in town! I ordered their assorted pastry box for a meeting and everyone was blown away. Presentation was beautiful and the taste even better. 10/10!"</p>
      <div class="reviewer"><div class="avatar">S</div><div><h5>Sofia</h5><p>Corporate Order</p></div></div>
    </div>

    <div class="review-card">
      <div class="stars"><span>★★★★☆</span></div>
      <p class="review-text">"Great value for the price! The strawberry shortcake was a hit at our family gathering. Very affordable but the quality is absolutely top-notch. Love this place!"</p>
      <div class="reviewer"><div class="avatar">JR</div><div><h5>Justin Rainier</h5><p>Family Order</p></div></div>
    </div>
  </div>

  <div class="review-nav">
    <button id="revPrev" aria-label="Previous reviews"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></button>
    <button id="revNext" aria-label="Next reviews"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></button>
  </div>
</section>

<!-- ================= CTA BANNER ================= -->
<section class="cta-banner reveal">
  <img src="src/log-image2.jpg" alt="Chocolate cake dusted with cocoa powder">
  <div class="cta-inner">
    <span class="eyebrow">Celebrating Something?</span>
    <h2>Let's Bake It<br><em>Together</em></h2>
    <p>From birthdays to baptisms, tell us the date and the flavor — we'll handle the rest, right down to the candles.</p>
    <a href="customer-login.php" class="btn btn-primary">Design My Cake</a>
  </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="site-footer">
  <div class="footer-grid">
    <div class="footer-brand">
      <a href="#hero" class="logo"><span class="mark" style="width:3.4rem;height:3.4rem;">PL</span>PastryLiberty</a>
      <p>Small-batch cakes and pastries baked fresh every morning in Calamba, Philippines. Every layer, made by hand.</p>
      <div class="social-row">
        <a href="#" aria-label="Facebook"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M13 22v-9h3l.5-4H13V6.5C13 5.4 13.4 5 14.6 5H16V1.1C15.6 1 14.4 0.9 13 0.9c-3 0-5 1.8-5 5.2V9H5v4h3v9h5z"/></svg></a>
        <a href="#" aria-label="Instagram"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg></a>
        <a href="#" aria-label="TikTok"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M16 3c.5 2.3 2 3.8 4.5 4v3c-1.7 0-3.2-.5-4.5-1.4V15a6 6 0 11-6-6c.3 0 .7 0 1 .1v3.1a3 3 0 103 3V3h2z"/></svg></a>
      </div>
    </div>

    <div class="footer-col">
      <h5>Quick Links</h5>
      <a href="#hero">Home</a>
      <a href="#about">About</a>
      <a href="#products">Menu</a>
      <a href="#reviews">Reviews</a>
    </div>

    <div class="footer-col">
      <h5>Account</h5>
      <a href="customer-login.php">Sign In</a>
      <a href="customer-register.php">Sign Up</a>
    </div>

    <div class="footer-col">
      <h5>Contact</h5>
      <a href="tel:+639123456789">+63 912-345-6789</a>
      <a href="mailto:hello@pastryliberty.ph">hello@pastryliberty.ph</a>
      <p>Calamba, Laguna, Philippines</p>
    </div>
  </div>

  <div class="copyright">
    <span>Pastry Liberty © 2026. All Rights Reserved.</span>
    <span>Baked fresh, delivered warm.</span>
  </div>
</footer>

<div id="backToTop" aria-label="Back to top" role="button" tabindex="0">↑</div>

<script>
/* ===== MOBILE MENU ===== */
function toggleMenu(){
  document.getElementById('hamburger').classList.toggle('active');
  document.getElementById('navigation').classList.toggle('open');
  document.getElementById('login-method').classList.toggle('open');
}

/* ===== DISPLAY CASE MARQUEE DATA ===== */
const caseItems = [
  { name: 'Chocolate Drip', img: 'src/best-cake.jpg' },
  { name: 'Black Forest', img: 'src/black-forestv2.webp' },
  { name: 'Mille-Feuille', img: 'src/biskwit.jpeg' },
  { name: 'Assorted Donuts', img: 'src/donuts.jpg' },
  { name: 'Tiramisu Cake', img: 'src/tiramisu-cake.jpg' },
  { name: 'Berry Mousse', img: 'src/log-image.jpg' },
  { name: 'Walnut Bread', img: 'src/walnutv3.jpg' },
  { name: 'Crown Celebration', img: 'src/log-image3.jpg' },
];
const track = document.getElementById('caseTrack');
const cardsHTML = [...caseItems, ...caseItems].map(it =>
  `<div class="case-card"><img src="${it.img}" alt="${it.name}" loading="lazy"><span class="tag">${it.name}</span></div>`
).join('');
track.innerHTML = cardsHTML;

/* ===== NAV: scroll state, scrollspy, timer ring ===== */
const navEl = document.getElementById('siteNav');
const backToTop = document.getElementById('backToTop');
const navLinks = document.querySelectorAll('.navigation a[href^="#"]');
const spySections = Array.from(navLinks).map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
const progCircle = document.getElementById('progCircle');
const CIRC = 2 * Math.PI * 18;
progCircle.style.strokeDasharray = CIRC;

function onScroll(){
  const scrolled = window.scrollY > 60;
  navEl.classList.toggle('scrolled', scrolled);
  backToTop.classList.toggle('visible', window.scrollY > 500);

  const docH = document.documentElement.scrollHeight - window.innerHeight;
  const pct = docH > 0 ? window.scrollY / docH : 0;
  progCircle.style.strokeDashoffset = CIRC - (pct * CIRC);

  let currentIndex = -1;
  spySections.forEach((sec, i) => { if (sec.getBoundingClientRect().top <= 140) currentIndex = i; });
  navLinks.forEach((a, i) => a.classList.toggle('current', i === currentIndex));
}
window.addEventListener('scroll', onScroll, { passive: true });
onScroll();

backToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
backToTop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') window.scrollTo({ top: 0, behavior: 'smooth' }); });

/* ===== SCROLL REVEAL ===== */
const io = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting){ entry.target.classList.add('active'); io.unobserve(entry.target); }
  });
}, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });
document.querySelectorAll('.reveal, .reveal-stagger').forEach(el => io.observe(el));

/* ===== RIPPLE ===== */
document.querySelectorAll('.btn, .login-method a, .product-cta').forEach(btn => {
  btn.addEventListener('click', function(e){
    const rect = this.getBoundingClientRect();
    const ripple = document.createElement('span');
    const size = Math.max(rect.width, rect.height);
    ripple.className = 'ripple';
    ripple.style.width = ripple.style.height = size + 'px';
    ripple.style.left = (e.clientX - rect.left - size/2) + 'px';
    ripple.style.top = (e.clientY - rect.top - size/2) + 'px';
    this.appendChild(ripple);
    ripple.addEventListener('animationend', () => ripple.remove());
  });
});

/* ===== HERO PLATE TILT ===== */
const standWrap = document.querySelector('.stand-wrap');
const plateImg = document.querySelector('.plate img');
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (standWrap && plateImg && !reduceMotion){
  standWrap.addEventListener('mousemove', e => {
    const rect = standWrap.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width - 0.5;
    const y = (e.clientY - rect.top) / rect.height - 0.5;
    plateImg.style.transform = `rotateY(${x*10}deg) rotateX(${-y*10}deg) scale(1.03)`;
  });
  standWrap.addEventListener('mouseleave', () => { plateImg.style.transform = 'rotateY(0) rotateX(0) scale(1)'; });
}

/* ===== ABOUT IMAGE TILT ===== */
const tiltFrame = document.getElementById('tiltFrame');
if (tiltFrame && !reduceMotion){
  tiltFrame.addEventListener('mousemove', e => {
    const rect = tiltFrame.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width - 0.5;
    const y = (e.clientY - rect.top) / rect.height - 0.5;
    tiltFrame.style.transform = `rotateY(${x*6}deg) rotateX(${-y*6}deg)`;
  });
  tiltFrame.addEventListener('mouseleave', () => { tiltFrame.style.transform = 'rotateY(0) rotateX(0)'; });
}

/* ===== FLOATING CRUMBS ===== */
const crumbsWrap = document.getElementById('crumbs');
if (crumbsWrap){
  for (let i=0;i<10;i++){
    const c = document.createElement('span');
    c.className = 'crumb';
    const size = 4 + Math.random()*7;
    c.style.width = c.style.height = size + 'px';
    c.style.left = (10 + Math.random()*80) + '%';
    c.style.top = (10 + Math.random()*80) + '%';
    c.style.animationDelay = (Math.random()*6) + 's';
    c.style.animationDuration = (5 + Math.random()*5) + 's';
    c.style.opacity = 0.4 + Math.random()*0.5;
    crumbsWrap.appendChild(c);
  }
}

/* ===== REVIEWS CAROUSEL BUTTONS ===== */
const reviewTrack = document.getElementById('reviewTrack');
document.getElementById('revNext').addEventListener('click', () => reviewTrack.scrollBy({ left: 400, behavior: 'smooth' }));
document.getElementById('revPrev').addEventListener('click', () => reviewTrack.scrollBy({ left: -400, behavior: 'smooth' }));
</script>
</body>
</html>