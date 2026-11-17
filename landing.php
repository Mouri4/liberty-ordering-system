<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PastryLiberty</title>
    <link rel="stylesheet" href="landing.css"/>
    <style>
         :root {
     --pink: #f2a0b0;
     --pink-light: #fde8ed;
     --pink-mid: #ffadbf;
     --cream: #f5ede0;
     --tan: #e8d5b7;
     --brown: #c8a97a;
     --dark-brown: #7a5c3a;
     --text: #4a3728;
     --white: #ffffff;
     --card-bg: #eedfc4;
     --shadow: rgba(122, 92, 58, .15);
     --green: #127312;
 }

 * {
     margin: 0;
     padding: 0;
     box-sizing: border-box;
     font-family: Verdana, Geneva, Tahoma, sans-serif;
     outline: none;
     border: none;
     text-decoration: none;
     text-transform: capitalize;
     transition: .2s linear;
     color: inherit;
 }

 html {
     font-size: 62.5%;
     scroll-behavior: smooth;
     scroll-padding-top: 6rem;
     overflow-x: hidden;
     scrollbar-width: none;
 }

 .content {
     padding: 1rem 9%;
 }

 nav {
     position: fixed;
     top: 0;
     left: 0;
     right: 0;
     background-color: #12731200;
     padding: 2rem 9%;
     display: flex;
     align-items: center;
     justify-content: space-between;
     z-index: 99;
     box-shadow: 0 0 0 rgba(0,0,0,0);
     transition: background-color .35s ease, box-shadow .35s ease, padding .35s ease, backdrop-filter .35s ease;
 }

 nav.scrolled {
     background-color: rgba(255, 255, 255, 0.72);
     backdrop-filter: blur(14px);
     -webkit-backdrop-filter: blur(14px);
     padding: 1.2rem 9%;
     box-shadow: 0 .5rem 1.5rem var(--shadow);
 }

 nav.scrolled .navigation { border-color: var(--tan); }
 nav.scrolled .logo { color: var(--dark-brown); text-shadow: none; }

 .navigation a { position: relative; }
 .navigation a::after {
     content: '';
     position: absolute;
     left: 1rem;
     right: 1rem;
     bottom: -2px;
     height: 2px;
     background: currentColor;
     transform: scaleX(0);
     transform-origin: left;
     transition: transform .25s ease;
 }
 .navigation a:hover::after,
 .navigation a.current::after { transform: scaleX(1); }
 .navigation a.current { color: var(--pink-mid); font-weight: bold; }
 nav.scrolled .navigation a.current { color: hsl(10, 50%, 45%); }

 nav .logo {
     font-size: 2rem;
     font-weight: 900;
     color: white;
 }

 .navigation {
     list-style-type: none;
     display: flex;
     gap: 3rem;
     font-size: 1.5rem;
     border: 2px solid white;
     padding: 0.5% 1.4%;
     border-radius: 1rem;
 }

 .navigation a {
     color: black;
 }

 .navigation a:hover {
     transform: scale(1.1);
     color: white;
     background-color:#e84343;
     padding: .7rem;
     border-radius: 1rem;
 }

 .login-method {
     list-style-type: none;
     display: flex;
     gap: 1.5rem;
 }

 .login-method a {
     border-radius: 2rem;
     background-color: hsl(10, 50%, 50%);
     padding: 1rem;
     font-size: 1.3rem;
     cursor: pointer;
     border: 2px solid white;
     color: black;
 }

 .login-method a:hover {
     transform: scale(1.1);
 }

 #signin {
     background-color: white;
     border: 1px solid black;
 }

 #signin:hover {
     color: hsl(10, 50%, 50%);
 }

 /* From Uiverse.io by PriyanshSinghBisht */
 .home {
     width: 100%;
     height: 105vh;
     /* Add your background pattern here */
     --s: 90px;
     /* change size */
     --c-half-left: hsl(10, 50%, 50%);
     /* has( color-value, shadow, brightness ) */
     --c-half-right: hsl(40, 50%, 80%);
     --c-bottom: hsl(180, 50%, 50%);

     background: conic-gradient(var(--c-half-left) 0deg,
             var(--c-half-left) 120deg,
             var(--c-bottom) 120deg,
             var(--c-bottom) 240deg,
             var(--c-half-right) 240deg);
     background-size: var(--s);
     display: flex;
     flex-direction: column;
 }


 #home .content {
     margin-top: 4%;
     width: 100%;
     margin-bottom: 4%;
 }

 .home .content h2 {
     -webkit-text-stroke: 1.5px black;
     font-size: 6rem;
     font-weight: bold;
     color: white;
 }

 #home .content span {
     font-size: 3rem;
     background: linear-gradient(to right, #000000, #000000);
     -webkit-background-clip: text;
     /* Required for Chrome, Safari, and Edge */
     background-clip: text;
     color: transparent;
     padding: 1rem 0;
     font-weight: bolder;
     line-height: 1.5;
     margin-left: 0.2%;
 }

 #home .content p {
     font-size: 1.5rem;
     color: black;
     padding: 1rem 0;
     line-height: 1.5;
 }

 #home .content .btn {
     display: inline-block;
     margin-top: 1rem;
     padding: .9rem 3.5rem;
     cursor: pointer;
     background-color: hsl(10, 50%, 50%);
     border: 2px solid white;
     border-radius: 2rem;
     font-size: 1.3rem;
     font-weight: bold;
 }

 .marquee-section {
     margin-top: 4%;
     overflow: hidden;
     background: var(--color-background-primary);
     border-top: 0.5px solid var(--color-border-tertiary);
     border-bottom: 0.5px solid var(--color-border-tertiary);
     height: 240px;
     width: 100%;
 }

 .marquee-wrapper {
     display: flex;
     height: 140%;
     padding: 1%;
     gap: 0;
     overflow: hidden;
     mask-image: linear-gradient(to right, transparent 0%, black 8%, black 92%, transparent 100%);
     -webkit-mask-image: linear-gradient(to right, transparent 0%, black 8%, black 92%, transparent 100%);
 }

 .marquee-track {
     display: flex;
     gap: 16px;
     animation: scroll-left 38s linear infinite;
     flex-shrink: 0;
     will-change: transform;
 }

 @keyframes scroll-left {
     from {
         transform: translateX(0);
     }

     to {
         transform: translateX(-50%);
     }
 }

 .food-card {
     flex-shrink: 0;
     width: 220px;
     height: 60%;
     background: var(--color-background-secondary);
     border: 0.5px solid var(--color-border-tertiary);
     border-radius: var(--border-radius-lg);
     overflow: hidden;
     cursor: pointer;
     transition: border-color 0.2s, transform 0.2s;
     border-radius: 10px;
     box-shadow: 5px 5px 6px rgb(36, 36, 36);
 }

 .food-img-wrap {
     width: 100%;
     height: 100%;
     overflow: hidden;
     background: var(--color-background-tertiary);
     position: relative;
 }

 .food-img-wrap img {
     width: 100% !important;
     height: 100% !important;
     object-fit: cover;
     object-position: center;
     transition: transform 0.4s ease;
     display: block !important;
     position: absolute;
     /* NEW: Absolute positioning */
     top: 0;
     left: 0;

 }

 /* ─── LIQUID GRADIENT DIV ─── */
 .liquid-hero {
     position: relative;
     width: 100%;
     height: 105vh;
     overflow: hidden;
     isolation: isolate;
     cursor: pointer;
     display: flex;
     align-items: center;
 }

 /* Base warm canvas */
 .liquid-hero::before {
     content: '';
     position: absolute;
     inset: 0;
     background: linear-gradient(135deg, #f5c97a, #e8956d, #c45e3e);
     z-index: 0;
 }

 /* Liquid blobs layer */
 .blobs {
     position: absolute;
     inset: 0;
     z-index: 1;
     filter: blur(48px) contrast(1.2);
     overflow: hidden;
 }

 .blob {
     position: absolute;
     border-radius: 50%;
     mix-blend-mode: screen;
     opacity: 0.85;
     will-change: transform;
 }

 .blob-1 {
     width: 420px;
     height: 420px;
     background: radial-gradient(circle, #fde68a, #f59e0b);
     top: -80px;
     left: -60px;
     animation: drift1 9s ease-in-out infinite;
 }

 .blob-2 {
     width: 360px;
     height: 360px;
     background: radial-gradient(circle, #fca5a5, #e84343);
     top: 80px;
     right: -80px;
     animation: drift2 11s ease-in-out infinite;
 }

 .blob-3 {
     width: 300px;
     height: 300px;
     background: radial-gradient(circle, #fdba74, #c2410c);
     bottom: -60px;
     left: 120px;
     animation: drift3 8s ease-in-out infinite;
 }

 .blob-4 {
     width: 260px;
     height: 260px;
     background: radial-gradient(circle, #fef08a, #eab308);
     top: 20px;
     left: 280px;
     animation: drift4 13s ease-in-out infinite;
 }

 .blob-5 {
     width: 200px;
     height: 200px;
     background: radial-gradient(circle, #fecdd3, #fb7185);
     bottom: 20px;
     right: 60px;
     animation: drift5 10s ease-in-out infinite;
 }

 /* Glossy film overlay */
 .gloss {
     position: absolute;
     inset: 0;
     z-index: 2;
     background: linear-gradient(160deg,
             rgba(255, 255, 255, 0.18) 0%,
             rgba(255, 255, 255, 0.04) 40%,
             transparent 60%);
     pointer-events: none;
 }

 /* Noise grain texture */
 .grain {
     position: absolute;
     inset: 0;
     z-index: 3;
     opacity: 0.06;
     background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
     background-size: 180px 180px;
     pointer-events: none;
 }

 /* Content sitting on top */
 .content-2 {
     margin-left: 4%;
     width: 50%;
     position: absolute;
     z-index: 4;
     display: flex;
     flex-direction: column;
     padding: 2rem;
 }

.content-3{
    z-index: 5;
    width: 40%;
    height: 80vh;
    overflow: hidden;
    margin-left: auto;
    margin-top: 5%;
    margin-right: 5%;
}

.content-3 img{
    height: 100%;
    width: 100%;
    max-height: 100%;
    max-width: 100%;
    object-fit: contain;

}

@keyframes heroIn {
    from { opacity: 0; transform: translateY(3rem); }
    to   { opacity: 1; transform: translateY(0); }
}
.content-2 { animation: heroIn .9s cubic-bezier(.16,.8,.3,1) both; }
.content-3 { animation: heroIn 1.1s cubic-bezier(.16,.8,.3,1) .15s both; }
.content-2 .cta { animation: heroIn .9s cubic-bezier(.16,.8,.3,1) .35s both; }

 .eyebrow {
     font-family: 'Courier New', Courier, monospace;
     font-size: 11px;
     letter-spacing: 0.25em;
     text-transform: uppercase;
     color: rgba(255, 255, 255, 0.7);
     margin-bottom: 14px;
 }

 .headline {
     font-size: 7rem;
     font-weight: 400;
     color: #fff;
     line-height: 1.1;
     text-shadow: 0 2px 24px rgba(120, 30, 0, 0.35);
     margin-bottom: 16px;
     letter-spacing: -0.01em;
 }

 .subline {
     font-size: 16px;
     color: rgba(255, 255, 255, 0.82);
     font-family: Georgia, serif;
     font-style: italic;
     margin-bottom: 28px;
     max-width: 380px;
     line-height: 1.6;
 }

 .cta {
     display: inline-block;
     padding: 13px 36px;
     background: rgba(255, 255, 255, 0.15);
     border: 1.5px solid rgba(255, 255, 255, 0.55);
     border-radius: 40px;
     color: #fff;
     font-family: 'Courier New', Courier, monospace;
     font-size: 12px;
     letter-spacing: 0.18em;
     text-transform: uppercase;
     text-decoration: none;
     backdrop-filter: blur(8px);
     transition: background 0.3s, border-color 0.3s;
     width: 25%;
 }

 .cta:hover {
     background: rgba(255, 255, 255, 0.28);
     border-color: rgba(255, 255, 255, 0.9);
 }

 /* ─── KEYFRAMES ─── */
 @keyframes drift1 {

     0%,
     100% {
         transform: translate(0, 0) scale(1);
     }

     33% {
         transform: translate(60px, 40px) scale(1.08);
     }

     66% {
         transform: translate(-30px, 70px) scale(0.94);
     }
 }

 @keyframes drift2 {

     0%,
     100% {
         transform: translate(0, 0) scale(1);
     }

     40% {
         transform: translate(-70px, -50px) scale(1.1);
     }

     70% {
         transform: translate(30px, 60px) scale(0.92);
     }
 }

 @keyframes drift3 {

     0%,
     100% {
         transform: translate(0, 0) scale(1);
     }

     30% {
         transform: translate(50px, -60px) scale(1.12);
     }

     65% {
         transform: translate(-40px, -20px) scale(0.96);
     }
 }

 @keyframes drift4 {

     0%,
     100% {
         transform: translate(0, 0) scale(1);
     }

     45% {
         transform: translate(-50px, 50px) scale(1.05);
     }

     75% {
         transform: translate(60px, -30px) scale(0.9);
     }
 }

 @keyframes drift5 {

     0%,
     100% {
         transform: translate(0, 0) scale(1);
     }

     35% {
         transform: translate(40px, -45px) scale(1.15);
     }

     70% {
         transform: translate(-55px, 30px) scale(0.88);
     }
 }

 #about {
     margin: 2rem 0 2rem;
 }

 #about h2 {
     font-size: 5rem;
     text-align: center;
 }

 #about .content {
     display: flex;
     gap: 3rem;
     align-items: center;
     flex-wrap: wrap;
     padding: 2rem 0;
     padding-bottom: 3rem;
     justify-content: center;
 }

 #about .content div {
     width: 40%;
 }

 #about .content img {
     width: 100%;
     border-radius: 1rem;
     box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .1);
     height: 100%;
     object-fit: cover;
 }

 #about .content .text h3 {
     font-size: 3rem;
     padding: 1rem 0;
 }

 #about .content .text p {
     font-size: 1.5rem;
     padding: 1rem 0;
 }

 #about .content .text .btn {
     display: inline-block;
     margin-top: 1rem;
     padding: .9rem 3.5rem;
     cursor: pointer;
     background-color: #999;
     border-radius: 2rem;
     font-size: 1.3rem;
     font-weight: bold;
 }

 #icons {
     background-color: #eee;
     display: flex;
     flex-wrap: wrap;
     gap: 1.5rem;
     padding-top: 3rem;
     padding-bottom: 3rem;

 }

 #icons .box {
     background: #fff;
     border: 1rem solid rgba(0, 0, 0, .1);
     padding: 2rem;
     display: flex;
     align-items: center;
     flex: 1 1 25rem;
     gap: 3rem;
 }

 #icons .box img {
     width: 10%;
 }

 #products {
     margin: 2rem 0 2rem;
 }

 #products h2 {
     font-size: 4rem;
     text-align: center;
     background-color: hsl(10, 50%, 50%);
     margin-bottom: 2rem;
 }

 #products h2 span {
     color: hsl(40, 50%, 80%);
 }

 #products .container {
     display: flex;
     border-radius: 2rem;
     gap: 3rem;
     flex-wrap: wrap;
     margin-left: 2.8%;
 }

 #products .container .box {
     padding: 1rem;
     border-radius: 5px;
     width: 28rem;
     height: 30rem;
     justify-content: center;
     text-align: center;
     box-shadow: 0 .5rem 1.5rem rgba(0, 0, 0, .1);
 }

#products .container .box img{
    max-width: 100%;
    max-height: 90%;
    height: 100%;
    width: 100%;
    object-fit: cover;
}

 #products .container .box pre{
    font-weight: 900;
    font-size: 1.5rem;
 }

 /* ===== REVIEWS SECTION ===== */
 #reviews {
     margin: 2rem 0;
     background-color: var(--pink-light);
     padding-top: 4rem;
     padding-bottom: 4rem;
 }

 #reviews h2 {
     font-size: 4rem;
     text-align: center;
     margin-bottom: .5rem;
 }

 #reviews h2 span {
     color: var(--pink-mid);
 }

 #reviews .subtitle {
     text-align: center;
     font-size: 1.5rem;
     color: #999;
     margin-bottom: 3rem;
     display: block;
     text-transform: none;
 }

 #reviews .container {
     display: flex;
     flex-wrap: wrap;
     gap: 2.5rem;
     justify-content: center;
 }

 #reviews .review-card {
     background-color: var(--white);
     border-radius: 1.5rem;
     padding: 2.5rem;
     width: 30rem;
     box-shadow: 0 .5rem 1.5rem var(--shadow);
     display: flex;
     flex-direction: column;
     gap: 1.5rem;
     transition: transform .2s linear, box-shadow .2s linear;
 }

 #reviews .review-card:hover {
     transform: translateY(-.5rem);
     box-shadow: 0 1rem 2rem var(--shadow);
 }

 #reviews .review-card .stars {
     display: flex;
     gap: .4rem;
 }

 #reviews .review-card .stars span {
     color: var(--pink);
     font-size: 1.8rem;
     text-transform: none;
 }

 #reviews .review-card .review-text {
     font-size: 1.4rem;
     color: #666;
     line-height: 1.7;
     text-transform: none;
     font-style: italic;
 }

 #reviews .review-card .reviewer {
     display: flex;
     align-items: center;
     gap: 1.5rem;
     margin-top: auto;
     padding-top: 1.5rem;
     border-top: 1px solid var(--tan);
 }

 #reviews .review-card .reviewer .avatar {
     width: 5rem;
     height: 5rem;
     border-radius: 50%;
     background-color: var(--pink);
     display: flex;
     align-items: center;
     justify-content: center;
     font-size: 2rem;
     font-weight: bold;
     color: var(--white);
     flex-shrink: 0;
 }

 #reviews .review-card .reviewer .info h4 {
     font-size: 1.5rem;
     font-weight: bold;
     color: var(--text);
 }

 #reviews .review-card .reviewer .info p {
     font-size: 1.2rem;
     color: #aaa;
     text-transform: none;
 }

 /* ===== FOOTER ===== */
 #footer {
     display: flex;
     flex-wrap: wrap;
     /* already wrappable */
     gap: 4rem;
     /* reduced from 20rem so columns can breathe */
     box-shadow: 0 .5rem 1rem var(--shadow);
     padding: 3rem 9%;
 }

 /* Each column grows/shrinks but won't collapse below 14rem */
 #footer .container {
     flex: 1 1 14rem;
 }

 #footer .container .box {
     flex: 1 1 25rem;
 }

 #footer .container .box h3 {
     color: #6e6e6e;
     font-size: 2.5rem;
     padding: 1rem 0;
 }

 #footer .container .box a {
     display: block;
     color: #aaaaaa;
     font-size: 1.5rem;
     padding: 1rem 0;
 }

 #footer .container .box a:hover {
     color: var(--pink);
     text-decoration: underline;
 }

 .copyright {
     text-align: center;
     font-size: 1.5rem;
 }

 .copyright h4 {
     font-weight: lighter;
 }

 /* ===== SCROLL REVEAL ===== */
 .reveal {
     opacity: 0;
     transform: translateY(3.2rem);
     transition: opacity .7s cubic-bezier(.16,.8,.3,1), transform .7s cubic-bezier(.16,.8,.3,1);
 }
 .reveal.active {
     opacity: 1;
     transform: translateY(0);
 }
 .reveal-stagger.active .reveal-child {
     opacity: 1;
     transform: translateY(0);
 }
 .reveal-child {
     opacity: 0;
     transform: translateY(3.2rem);
     transition: opacity .6s cubic-bezier(.16,.8,.3,1), transform .6s cubic-bezier(.16,.8,.3,1);
 }
 .reveal-child:nth-child(1) { transition-delay: .05s; }
 .reveal-child:nth-child(2) { transition-delay: .15s; }
 .reveal-child:nth-child(3) { transition-delay: .25s; }
 .reveal-child:nth-child(4) { transition-delay: .35s; }
 .reveal-child:nth-child(5) { transition-delay: .45s; }
 .reveal-child:nth-child(6) { transition-delay: .55s; }

 /* ===== PRODUCT CARD INTERACTION ===== */
 #products .container .box {
     transition: transform .35s cubic-bezier(.16,.8,.3,1), box-shadow .35s ease;
     overflow: hidden;
 }
 #products .container .box:hover {
     transform: translateY(-.8rem) scale(1.02);
     box-shadow: 0 1.5rem 2.5rem rgba(122,92,58,.25);
 }
 #products .container .box img {
     transition: transform .5s ease;
 }
 #products .container .box:hover img {
     transform: scale(1.12);
 }

 /* ===== ICON BOX HOVER ===== */
 #icons .box {
     transition: transform .3s ease, box-shadow .3s ease, border-color .3s ease;
 }
 #icons .box:hover {
     transform: translateY(-.5rem);
     box-shadow: 0 1rem 1.5rem rgba(122,92,58,.15);
     border-color: var(--pink);
 }

 /* ===== MARQUEE PAUSE ON HOVER ===== */
 .marquee-wrapper:hover .marquee-track { animation-play-state: paused; }
 .food-card { transition: border-color .2s, transform .3s ease, box-shadow .3s ease; }
 .food-card:hover { transform: translateY(-.6rem) scale(1.04); box-shadow: 8px 10px 16px rgba(36,36,36,.35); }

 /* ===== ABOUT IMAGE TILT ===== */
 #about .content img { transform-style: preserve-3d; will-change: transform; }
 .content-3 { perspective: 1200px; }
 .content-3 img { transform-style: preserve-3d; will-change: transform; transition: transform .1s linear; }

 /* ===== RIPPLE BUTTONS ===== */
 .cta, .login-method a, #about .content .text .btn {
     position: relative;
     overflow: hidden;
 }
 .ripple {
     position: absolute;
     border-radius: 50%;
     background: rgba(255,255,255,.55);
     transform: scale(0);
     animation: ripple-anim .6s ease-out forwards;
     pointer-events: none;
 }
 @keyframes ripple-anim {
     to { transform: scale(2.6); opacity: 0; }
 }

 /* ===== BACK TO TOP ===== */
 #backToTop {
     position: fixed;
     right: 2.4rem;
     bottom: 2.4rem;
     width: 4.6rem;
     height: 4.6rem;
     border-radius: 50%;
     background: hsl(10, 50%, 50%);
     color: #fff;
     display: flex;
     align-items: center;
     justify-content: center;
     font-size: 1.8rem;
     cursor: pointer;
     z-index: 98;
     opacity: 0;
     transform: translateY(1.5rem) scale(.85);
     pointer-events: none;
     transition: opacity .3s ease, transform .3s ease, background .3s ease;
     box-shadow: 0 .6rem 1.5rem rgba(0,0,0,.25);
 }
 #backToTop.visible { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
 #backToTop:hover { background: hsl(10, 60%, 42%); transform: translateY(-.3rem) scale(1.05); }

 /* ===== REDUCED MOTION ===== */
 @media (prefers-reduced-motion: reduce) {
     html { scroll-behavior: auto; }
     .reveal, .reveal-child { opacity: 1; transform: none; transition: none; }
     .blob, .marquee-track, #backToTop, .content-3 img { animation: none !important; transition: none !important; }
     * { animation-duration: .01ms !important; animation-iteration-count: 1 !important; }
 }

 /* ===== HAMBURGER MENU ===== */
 .hamburger {
     display: none;
     flex-direction: column;
     gap: .5rem;
     cursor: pointer;
     padding: .5rem;
 }

 .hamburger span {
     display: block;
     width: 2.5rem;
     height: .3rem;
     background-color: var(--text);
     border-radius: 5px;
     transition: .3s ease;
 }

 .hamburger.active span:nth-child(1) {
     transform: translateY(.8rem) rotate(45deg);
 }

 .hamburger.active span:nth-child(2) {
     opacity: 0;
 }

 .hamburger.active span:nth-child(3) {
     transform: translateY(-.8rem) rotate(-45deg);
 }

 /* ===== RESPONSIVE — TABLET (max 991px) ===== */
 @media (max-width: 991px) {
     html {
         font-size: 55%;
     }

     nav {
         padding: 1.5rem 5%;
     }

     section {
         padding: 2rem 5%;
     }

     .home .content h2 {
         font-size: 4.5rem;
     }

     #about .content div {
         width: 100%;
     }

     #about .content img {
         max-height: 30rem;
     }

     #footer {
         gap: 3rem;
         padding: 2.5rem 5%;
     }

     #footer .container {
         flex: 1 1 16rem;
     }

     #reviews .review-card {
         width: calc(50% - 1.25rem);
     }
 }

 /* ===== RESPONSIVE — MOBILE (max 768px) ===== */
 @media (max-width: 768px) {
     html {
         font-size: 50%;
     }

     /* --- NAV --- */
     nav {
         padding: 1.5rem 4%;
         flex-wrap: wrap;
         gap: 0;
         position: relative;
         align-items: center;
     }

     nav .logo {
         font-size: 2.5rem;
         flex: 1;
         /* logo takes remaining space, pushes hamburger right */
     }

     .hamburger {
         display: flex;
     }

     /* nav links — full-width dropdown */
     .navigation {
         display: none;
         flex-direction: column;
         width: 100%;
         gap: 0;
         padding: 1rem 0 0;
         border-top: 1px solid var(--tan);
         margin-top: 1rem;
     }

     .navigation.open {
         display: flex;
     }

     .navigation li a {
         display: block;
         padding: .9rem 1rem;
         border-radius: .5rem;
         color: #999;
     }

     /* Sign In / Sign Up — LEFT-aligned row below nav links */
     .login-method {
         display: none;
         flex-direction: row;
         /* side-by-side buttons */
         flex-wrap: wrap;
         justify-content: flex-start;
         /* anchored to LEFT */
         align-items: center;
         width: 100%;
         gap: 1rem;
         padding: 1rem 0 .5rem;
     }

     .login-method.open {
         display: flex;
     }

     .login-method li {
         flex: 0 0 auto;
         /* don't stretch buttons */
     }

     .login-method a {
         display: inline-block;
         text-align: center;
         padding: .9rem 2.5rem;
     }

     /* --- HOME --- */
     .home {
         padding-top: 12rem;
         align-items: flex-start;
     }

     .home .content h2 {
         font-size: 3.5rem;
     }

     #home .content span {
         font-size: 2rem;
     }

     /* --- ABOUT --- */
     #about h2 {
         font-size: 3.5rem;
     }

     #about .content div {
         width: 100%;
     }

     /* --- ICONS --- */
     #icons .box {
         flex: 1 1 100%;
     }

     #icons .box img {
         width: 15%;
     }

     /* --- PRODUCTS --- */
     #products .container {
         justify-content: center;
     }

     #products .container .box {
         width: 100%;
         height: auto;
         min-height: 20rem;
     }

     /* --- REVIEWS --- */
     #reviews .review-card {
         width: 100%;
     }

     #reviews h2 {
         font-size: 3rem;
     }

     /* --- FOOTER — 2-column grid on mobile --- */
     #footer {
         gap: 2rem;
         padding: 2rem 4%;
     }

     #footer .container {
         flex: 1 1 42%;
         /* 2 columns: Quick Links + Login | Locations + Contact */
         min-width: 13rem;
     }
 }

 /* ===== RESPONSIVE — SMALL MOBILE (max 480px) ===== */
 @media (max-width: 480px) {
     html {
         font-size: 45%;
     }

     nav .logo {
         font-size: 2.2rem;
     }

     .home .content h2 {
         font-size: 3rem;
     }

     #home .content span {
         font-size: 1.8rem;
     }

     #about h2,
     #products h2,
     #reviews h2 {
         font-size: 2.8rem;
     }

     #about .content .text h3 {
         font-size: 2.2rem;
     }

     /* single column footer on very small screens */
     #footer .container {
         flex: 1 1 100%;
     }

     #footer .container .box h3 {
         font-size: 2rem;
     }
 }
    </style>
</head>
<body>
    <nav>
        <a href="#" class="logo">PastryLiberty</a>

        <div class="hamburger" id="hamburger" onclick="toggleMenu()">
            <span></span>
            <span></span>
            <span></span>
        </div>

        <ul class="navigation" id="navigation">
            <li><a href="#one-scrl">Home</a></li>
            <li><a href="#home">About</a></li>
            <li><a href="#products">Products</a></li>
            <li><a href="#reviews">Reviews</a></li>
        </ul>

        <ul class="login-method" id="login-method">
            <li><a id="signin" href="customer-login.php">Sign In</a></li>
            <li><a id="signup" href="customer-register.php">Sign Up</a></li>    
        </ul>
    </nav>
    
    <!-- ░░ LIQUID GRADIENT HERO DIV ░░ -->
  <div class="liquid-hero">
    <!-- Animated blobs -->
    <div class="blobs">
      <div class="blob blob-1"></div>
      <div class="blob blob-2"></div>
      <div class="blob blob-3"></div>
      <div class="blob blob-4"></div>
      <div class="blob blob-5"></div>
    </div>

    <!-- Gloss & grain overlays -->
    <div class="gloss"></div>
    <div class="grain"></div>

    <!-- Your content goes here -->
    <div class="content-2" id="one-scrl"> 
      <h1 class="headline">Take a Bite, <br>Feel the Delight</h1>
      <p class="subline">Hand-crafted croissants, tarts & cakes — made fresh every morning.</p>
      <a href="customer-login.php" class="cta">Shop Now</a>
    </div>

    <div class="content-3">
        <img src="src/land-choc.png" alt="">
    </div>
  </div>

    <section class="home" id="home" > 
        <div class="content reveal">
            <h2>Why Choose Us?</h2>
            <span>Flavors Worth Every Layer</span>
            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Accusamus esse neque velit omnis similique veniam dignissimos ipsum vitae suscipit, accusantium nesciunt sapiente doloribus magnam animi voluptatibus soluta deleniti sequi a!</p>
        </div>

        <section class="marquee-section" >
            <div class="marquee-wrapper" id="marqueeWrapper">
                <div class="marquee-track" id="track1"></div>
            </div>
        </section>
    </section>

    <section class="icons" id="icons">
        <div class="box reveal">
            <img src="src/pickup.png" alt="">
            <div>
                <h3>Pickup/Delivery</h3>
                <p>You can choose between pickup or delivery</p>
            </div>
        </div>
    </section>

    <section class="products" id="products">
        <h2 class="reveal">Best <span>Seller</span></h2>
        <div class="container reveal-stagger">
            
            <div class="box reveal-child">
                <img src="src/black-forestv2.webp" alt="">
                <pre>Black Forest         ⭐⭐⭐⭐⭐</pre>
            </div>
            <div class="box reveal-child">
                <img src="src/redv2.jpg" alt="">
                <pre>Red Velvet            ⭐⭐⭐⭐⭐</pre>
            </div>
            <div class="box reveal-child">
                <img src="src/walnutv3.jpg" alt="">
                <pre>Choc Bread            ⭐⭐⭐⭐⭐</pre>
            </div>
            <div class="box reveal-child">
                <img src="src/maitim-na-cake.jpg" alt="">
                <pre>Choc Cake           ⭐⭐⭐⭐⭐</pre>
            </div>
        </div>
    </section>

    <!-- ===== REVIEWS SECTION ===== -->
    <section class="reviews" id="reviews">
        <h2 class="reveal">Customer <span>Reviews</span></h2>
        <span class="subtitle reveal">What our sweet customers are saying</span>

        <div class="container reveal-stagger">

            <div class="review-card reveal-child">
                <div class="stars">
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                </div>
                <p class="review-text">"The chocolate cake was absolutely divine! Every bite felt like a warm hug. I've never tasted anything so moist and rich from a local bakery. Will definitely be ordering again!"</p>
                <div class="reviewer">
                    <div class="avatar">JS</div>
                    <div class="info">
                        <h4>Jhon Salvador</h4>
                        <p>Regular Customer</p>
                    </div>
                </div>
            </div>

            <div class="review-card reveal-child">
                <div class="stars">
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                </div>
                <p class="review-text">"Ordered a custom birthday cake and it looked even better than I imagined. The cream cheese frosting was the perfect sweetness. All my guests kept asking where I got it from!"</p>
                <div class="reviewer">
                    <div class="avatar">JM</div>
                    <div class="info">
                        <h4>Jhoncen Taulo</h4>
                        <p>Birthday Order</p>
                    </div>
                </div>
            </div>

            <div class="review-card reveal-child">
                <div class="stars">
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span style="color: #ccc;">&#9733;</span>
                </div>
                <p class="review-text">"The croissants and pandesal are fresh every morning. Delivery was on time and packaging was so cute. The sourdough bread is now a weekly staple in our home — highly recommended!"</p>
                <div class="reviewer">
                    <div class="avatar">M</div>
                    <div class="info">
                        <h4>Marianne Zamora</h4>
                        <p>Delivery Customer</p>
                    </div>
                </div>
            </div>

            <div class="review-card reveal-child">
                <div class="stars">
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                </div>
                <p class="review-text">"Pastry Liberty never disappoints! The ube ensaymada is my personal favorite. Soft, fluffy, and generously topped. The staff is always kind and the GCash payment is super convenient."</p>
                <div class="reviewer">
                    <div class="avatar">J</div>
                    <div class="info">
                        <h4>Jhienaive Lao</h4>
                        <p>Loyal Customer</p>
                    </div>
                </div>
            </div>

            <div class="review-card reveal-child">
                <div class="stars">
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                </div>
                <p class="review-text">"Best pastries in town! I ordered their assorted pastry box for a meeting and everyone was blown away. The presentation was beautiful and the taste even better. 10/10 would recommend!"</p>
                <div class="reviewer">
                    <div class="avatar">S</div>
                    <div class="info">
                        <h4>Sofia</h4>
                        <p>Corporate Order</p>
                    </div>
                </div>
            </div>

            <div class="review-card reveal-child">
                <div class="stars">
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span>&#9733;</span>
                    <span style="color: #ccc;">&#9733;</span>
                </div>
                <p class="review-text">"Great value for the price! The strawberry shortcake was a hit at our family gathering. Very affordable compared to other bakeries but the quality is absolutely top-notch. Love this place!"</p>
                <div class="reviewer">
                    <div class="avatar">JR</div>
                    <div class="info">
                        <h4>Justin Rainier</h4>
                        <p>Family Order</p>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <section class="footer reveal-stagger" id="footer">
        <div class="container reveal-child">
            <div class="box">
                <h3>Quick Links</h3>
                <a href="#">home</a>
                <a href="#">about</a>
                <a href="#">products</a>
                <a href="#">reviews</a>
            </div>
        </div>
        <div class="container reveal-child">
            <div class="box">
                <h3>Login Links</h3>
                <a href="#">Sign in</a>
                <a href="#">sign up</a>
            </div>
        </div>
        <div class="container reveal-child">
            <div class="box">
                <h3>Locations</h3>
                <a href="#">Philippines</a>
                <a href="#">USA</a>
                <a href="#">Korea</a>
                <a href="#">China</a>
            </div>
        </div>
        <div class="container reveal-child">
            <div class="box">
                <h3>Contact Info</h3>
                <a href="#">+63 912-345-6789</a>
                <a href="#">example@gmail.com</a>
                <a href="#">Calamba, Philippines</a>
            </div>
        </div>
    </section>

    <section class="copyright">
        <h4>Pastry Liberty 2026 | All Rights Reserved</h4>
    </section>

    <!-- Back to top -->
    <div id="backToTop" aria-label="Back to top" role="button" tabindex="0">↑</div>

    <script>
        function toggleMenu() {
            const hamburger = document.getElementById('hamburger');
            const nav = document.getElementById('navigation');
            const login = document.getElementById('login-method');
            hamburger.classList.toggle('active');
            nav.classList.toggle('open');
            login.classList.toggle('open');
        }

        const foods = [
  { name: "chocnut cake", img: "src/best-cake.jpg" },
  { name: "black forest", img: "src/black-forestv2.webp" },
  { name: "Choc Peanut", img: "src/Chocolate-Peanut-Butter-Cake.jpg" },
  { name: "Sour Dough", img: "src/sourdou.jpg" },
  { name: "bisc", img: "src/blackes-cake.jpg" },
  { name: "Red Velvet", img: "src/red-velvet-cake.jpg" },
  { name: "Tiramisu Cake", img: "src/tiramisu-cake.jpg" },
  { name: "Wallnut", img: "src/walnutv3.jpg" },
  { name: "Strawberry Cake", img: "src/donuts.jpg" },
  { name: "Biskwit", img: "src/biskwit.jpeg" },
];

function starSVG(filled) {
  const c = filled ? '#EF9F27' : 'var(--color-border-tertiary)';
  return `<svg class="star-fill" viewBox="0 0 10 10"><polygon points="5,1 6.2,3.8 9.5,4 7,6.3 7.9,9.5 5,8 2.1,9.5 3,6.3 0.5,4 3.8,3.8" fill="${c}"/></svg>`;
}

function cardHTML(food) {
  const badgeClass = { hot: 'badge-hot', new: 'badge-new', top: 'badge-top' }[food.badge];
  const badgeText = { hot: '🔥 Hot', new: '✦ New', top: '★ Top' }[food.badge];
  const stars = Array.from({length:5}, (_,i) => starSVG(i < Math.round(parseFloat(food.stars)))).join('');
  return `<div class="food-card">
    <div class="food-img-wrap">
      <img src="${food.img}" alt="${food.name}" loading="lazy"/>
    </div>
  </div>`;
}

const allCards = [...foods, ...foods].map(cardHTML).join('');
document.getElementById('track1').innerHTML = allCards;
document.getElementById('track2') && (document.getElementById('track2').innerHTML = allCards);
    </script>

    <script>
        /* ===== NAV: scroll state + scrollspy ===== */
        const navEl = document.querySelector('nav');
        const backToTop = document.getElementById('backToTop');
        const navLinks = document.querySelectorAll('.navigation a[href^="#"]');
        const spySections = Array.from(navLinks)
            .map(a => document.querySelector(a.getAttribute('href')))
            .filter(Boolean);

        function onScroll() {
            const scrolled = window.scrollY > 60;
            navEl.classList.toggle('scrolled', scrolled);
            backToTop.classList.toggle('visible', window.scrollY > 500);

            let currentIndex = -1;
            spySections.forEach((sec, i) => {
                const rect = sec.getBoundingClientRect();
                if (rect.top <= 120) currentIndex = i;
            });
            navLinks.forEach((a, i) => a.classList.toggle('current', i === currentIndex));
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        backToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
        backToTop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') window.scrollTo({ top: 0, behavior: 'smooth' }); });

        /* ===== SCROLL REVEAL ===== */
        const revealTargets = document.querySelectorAll('.reveal, .reveal-stagger');
        const io = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });
        revealTargets.forEach(el => io.observe(el));

        /* ===== RIPPLE EFFECT ===== */
        document.querySelectorAll('.cta, .login-method a, #about .content .text .btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                const rect = this.getBoundingClientRect();
                const ripple = document.createElement('span');
                const size = Math.max(rect.width, rect.height);
                ripple.className = 'ripple';
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
                ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
                this.appendChild(ripple);
                ripple.addEventListener('animationend', () => ripple.remove());
            });
        });

        /* ===== HERO IMAGE TILT ===== */
        const tiltWrap = document.querySelector('.content-3');
        const tiltImg = document.querySelector('.content-3 img');
        if (tiltWrap && tiltImg && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            tiltWrap.addEventListener('mousemove', (e) => {
                const rect = tiltWrap.getBoundingClientRect();
                const x = (e.clientX - rect.left) / rect.width - 0.5;
                const y = (e.clientY - rect.top) / rect.height - 0.5;
                tiltImg.style.transform = `rotateY(${x * 14}deg) rotateX(${-y * 14}deg) scale(1.04)`;
            });
            tiltWrap.addEventListener('mouseleave', () => {
                tiltImg.style.transform = 'rotateY(0deg) rotateX(0deg) scale(1)';
            });
        }
    </script>
</body>
</html>