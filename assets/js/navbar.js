// Header situs: dibuat dari satu daftar link, dipakai semua halaman lewat #header-placeholder.
document.addEventListener("DOMContentLoaded", () => {
    const NAV_LINKS = [
        { href: "./#program", label: "Program" },
        { href: "index-event", label: "Event" },
        { href: "./#tentang", label: "Tentang" },
        { href: "archive", label: "Arsip" },
        { href: "bonafest", label: "Bonafest" },
    ];
    const CTA = { href: "index-event", label: "Tiket" };
    const STATUS_TEXT = "Transyogie, Indonesia";
    const MOBILE_BREAKPOINT = 900;

    const placeholder = document.getElementById("header-placeholder");
    if (!placeholder) return;

    const renderLink = ({ href, label }, index) => `<a href="${href}" style="--i:${index}">${label}</a>`;
    const renderCta = (extraClass = "") => `<a class="nav-cta ${extraClass}" href="${CTA.href}">${CTA.label}</a>`;
    const renderStatus = () => `<span class="status">${STATUS_TEXT}</span>`;

    placeholder.innerHTML = `
    <header id="site-header">
        <a class="logo" href="./" aria-label="Noirlab Collective"><img src="assets/logos/logo.png" alt="Noirlab"></a>
        <nav id="site-nav" aria-label="Menu utama">
            ${NAV_LINKS.map(renderLink).join("")}
            <div class="nav-sheet-foot">${renderCta()}${renderStatus()}</div>
        </nav>
        <div class="header-actions">
            ${renderStatus()}
            ${renderCta()}
            <button class="menu-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="site-nav"></button>
        </div>
    </header>`;

    const header = placeholder.querySelector("#site-header");
    const toggle = placeholder.querySelector(".menu-toggle");
    const links = [...placeholder.querySelectorAll("#site-nav > a")];

    const setMenuOpen = (open) => {
        header.classList.toggle("open", open);
        document.documentElement.classList.toggle("nav-open", open);
        toggle.setAttribute("aria-expanded", String(open));
    };

    const currentPageName = () => location.pathname.split("/").pop().replace(/\.(php|html)$/, "");

    const markCurrentPage = () => {
        const page = currentPageName();
        links.forEach((link) => {
            const target = link.getAttribute("href");
            if (!target.startsWith("./#") && target === page) link.classList.add("active-page");
        });
    };

    // Di beranda, tautan #bagian menyala sesuai bagian yang sedang dibaca.
    const markCurrentSection = () => {
        const anchors = new Map();
        links.forEach((link) => {
            const section = link.hash && document.querySelector(link.hash);
            if (section) anchors.set(section, link);
        });
        if (!anchors.size) return;
        const observer = new IntersectionObserver((entries) => {
            entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                anchors.forEach((link, section) => link.classList.toggle("active-page", section === entry.target));
            });
        }, { rootMargin: "-45% 0px -50% 0px" });
        anchors.forEach((_, section) => observer.observe(section));
    };

    toggle.addEventListener("click", () => setMenuOpen(!header.classList.contains("open")));
    placeholder.querySelectorAll("#site-nav a").forEach((link) => link.addEventListener("click", () => setMenuOpen(false)));
    addEventListener("keydown", (event) => { if (event.key === "Escape") setMenuOpen(false); });
    addEventListener("resize", () => { if (innerWidth > MOBILE_BREAKPOINT) setMenuOpen(false); });

    markCurrentPage();
    markCurrentSection();
});
