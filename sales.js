const navCont = document.getElementById("nav-cont");
    const overlay = document.getElementById("overlay");

function openMenu(){
        navCont.classList.add("active");
        overlay.classList.add("active");
}

function closeMenu(){
        navCont.classList.remove("active");
        overlay.classList.remove("active");
}