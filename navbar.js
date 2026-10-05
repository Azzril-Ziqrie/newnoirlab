document.addEventListener("DOMContentLoaded", function() {
    // Definisi template HTML Navigasi tunggal
    const headerTemplate = `
    <header>
        <div class="logo-container">
            <a href="index.html">
                <img src="assets/logos/logo.png" alt="Noirlab Logo">
            </a>
        </div>
        <nav>
            <a href="program.html" id="link-program">Program</a>
            <a href="subsnoir-merch.html" id="link-subsnoir-merch">Subsnoir Merch</a>
            <a href="noair-radio.html" id="link-noair-radio">Noair Radio</a>
            <a href="about.html" id="link-about">About</a>
            <a href="archive.html" id="link-archive">Archive</a>
            <a href="index-event" id="link-archive">Event</a>
            <a href="bonafest" id="link-archive">Bonafest</a>
        </nav>
        <div class="status-container">
            <div class="status-text">
                <div>Transyogie</div>
                <div>Indonesia</div>
            </div>
            <div class="status-indicator"></div>
        </div>
    </header>
    `;

    // Suntikkan ke placeholder yang ada di setiap halaman
    const placeholder = document.getElementById('header-placeholder');
    if (placeholder) {
        placeholder.innerHTML = headerTemplate;
    }

    // Logika otomatis mendeteksi halaman aktif berdasarkan nama file URL
    const currentFilename = window.location.pathname.split("/").pop();
    let activeLinkId = "";

    if (currentFilename === "program.html") activeLinkId = "link-program";
    else if (currentFilename === "subsnoir-merch.html") activeLinkId = "link-subsnoir-merch";
    else if (currentFilename === "noair-radio.html") activeLinkId = "link-noair-radio";
    else if (currentFilename === "about.html") activeLinkId = "link-about";
    else if (currentFilename === "archive.html") activeLinkId = "link-archive";

    if (activeLinkId) {
        const activeLink = document.getElementById(activeLinkId);
        if (activeLink) {
            activeLink.classList.add('active-page');
        }
    }
});