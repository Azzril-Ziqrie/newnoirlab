<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/logos/logo-bona1.png">
    <title>FAQ - Bonafest 2027</title>
    
    <!-- Memanggil Global CSS milikmu -->
    <link rel="stylesheet" href="global.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&display=swap');

        /* Base & Reset */
        :root {
            --bg-color: #ffffff;
            --text-primary: #0a0a0a;
            --text-secondary: #555555;
            --border-color: #eaeaea;
            --accent-color: #000000;
            --radius-lg: 24px;
            --radius-md: 16px;
            --radius-sm: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'JetBrains Mono', monospace;
            background-color: var(--bg-color);
            color: var(--text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .faq-nav {
            display: none;
        }
        
        /* Container Utama */
        main {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            padding: 60px 24px 100px;
        }

        .faq-header {
            text-align: center;
            margin-bottom: 48px;
        }

        .faq-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: -1px;
            margin-bottom: 16px;
        }

        .faq-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        /* Kolom Pencarian (Sticky) */
        .search-container {
            position: sticky;
            top: 90px;
            z-index: 90;
            margin-bottom: 40px;
        }

        .search-input-wrapper {
            position: relative;
            width: 100%;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.05);
            border-radius: 50px;
        }

        .search-input-wrapper input {
            width: 100%;
            padding: 20px 24px 20px 60px;
            font-size: 1.05rem;
            border: 1px solid var(--border-color);
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            outline: none;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .search-input-wrapper input:focus {
            border-color: var(--accent-color);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
        }

        .search-input-wrapper svg {
            position: absolute;
            left: 24px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            stroke: var(--text-secondary);
            fill: none;
            stroke-width: 2;
        }

        /* Kategori & List FAQ */
        .faq-category {
            margin-bottom: 48px;
        }

        .faq-category h2 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: none;
            letter-spacing: -0.5px;
        }
        
        .faq-category-desc {
            color: var(--text-secondary);
            margin-bottom: 24px;
            font-size: 0.85rem;
            line-height: 1.6;
        }
        
        /* Item FAQ Bergaya Accordion (Pop-up inline) */
        .faq-item {
            border-bottom: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .faq-question {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 0;
            background: none;
            border: none;
            text-align: left;
            font-size: 1rem;
            font-weight: 400;
            color: var(--text-primary);
            cursor: pointer;
            transition: color 0.2s ease;
            font-family: inherit;
        }
        
        .faq-question:hover {
            color: var(--text-secondary);
        }
        
        .faq-icon {
            width: 24px;
            height: 24px;
            position: relative;
            flex-shrink: 0;
            margin-left: 16px;
        }
        
        .faq-icon::before,
        .faq-icon::after {
            content: '';
            position: absolute;
            background: var(--text-primary);
            transition: transform 0.3s ease;
        }
        
        .faq-icon::before {
            display: none;
        }
        
        .faq-icon::after {
            content: '';
            position: absolute;
            top: 6px;
            left: 8px;
            width: 8px;
            height: 8px;
            border-right: 1px solid var(--text-primary);
            border-bottom: 1px solid var(--text-primary);
            transform: rotate(45deg);
            transition: transform 0.3s ease;
        }
        
        .faq-item.active .faq-icon::after {
            transform: rotate(-135deg);
        }
        
        .faq-answer {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.7;
        }

        .faq-answer-inner {
            padding-bottom: 24px;
        }

        /* Khusus untuk list dalam jawaban (seperti rute transportasi) */
        .faq-answer-inner ul, 
        .faq-answer-inner ol {
            margin-left: 20px;
            margin-top: 12px;
            margin-bottom: 12px;
        }

        .faq-answer-inner li {
            margin-bottom: 8px;
        }

        .faq-answer-inner strong {
            color: var(--text-primary);
        }

        .no-results {
            text-align: center;
            padding: 40px 0;
            color: var(--text-secondary);
            display: none;
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .faq-nav { padding: 16px 20px; }
            main { padding: 40px 20px 80px; }
            .faq-header h1 { font-size: 2rem; }
            .search-container { top: 70px; }
            .search-input-wrapper input { font-size: 1rem; padding: 16px 20px 16px 50px; }
            .search-input-wrapper svg { left: 20px; }
            .faq-category h2 { font-size: 0.9rem; }
            .faq-question { font-size: 12px; padding: 20px 0; }
        }
    </style>
</head>
<body>

    <?php include 'loader.php'; ?>

    <div id="header-placeholder"></div>

    <main>
        <div class="faq-header">
        </div>

        <!-- Fitur Search -->
        <div class="search-container">
            <div class="search-input-wrapper">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="searchInput" placeholder="Search for questions or keywords...">
            </div>
        </div>

        <div id="noResults" class="no-results">
            We couldn't find any questions matching your search.
        </div>

        <div class="faq-content">
            <!-- Kategori 1: TICKETS -->
            <div class="faq-category">
                <h2>TICKETS</h2>
                
                <div class="faq-item">
                    <button class="faq-question">
                        WHEN WILL 2026 TICKETS BE RELEASED?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            Tickets will go on sale 17th October 2026. Visit our website <a href="https://www.noirlabcollective.com" target="_blank" style="color:var(--text-primary); font-weight:600;">noirlabcollective.com</a> to keep an eye out for details.
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        WHAT DATES ARE BONAFEST 2026?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            Bonafest 2026 takes places 7-8th November 2026.
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        CAN UNDER 18S BUY A TICKET?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            Yes. The person who books the under 18 tickets (the lead booker) will have their name printed on the under 18 ticket. The lead booker must accompany the under 18 at the festival and will be asked for a photo ID on entry. Maximum of four under 18s per adult.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kategori 2: FESTIVAL INFO -->
            <div class="faq-category">
                <h2>FESTIVAL INFO</h2>
                
                <div class="faq-item">
                    <button class="faq-question">
                        WHERE IS THE FESTIVAL?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            We are nestled in New Ciputri Bogor, Kp. Neglasari, RT 04/RW 06, Tapos I, Tenjolaya, Bogor Regency, West Java.
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        HOW DO I GET TO BONAFEST?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            <strong>Getting to New Ciputri Bogor (Kp. Neglasari, Tapos I, Tenjolaya, Bogor Regency)</strong><br><br>
                            Google Maps Location: <em>NEW CIPUTRI BOGOR</em><br>
                            You can easily reach New Ciputri Bogor from the Jabodetabek area using private or public transportation.
                            <br><br>
                            <strong>By Car or Motorcycle (Private Transport)</strong>
                            <ul>
                                <li><strong>Take the Toll Road:</strong> Drive along the Jagorawi Toll Road towards Bogor and take the Baranangsiang (Bogor Toll Gate) exit.</li>
                                <li><strong>Head to Ciapus:</strong> Continue driving towards Ciapus / Pancasan.</li>
                                <li><strong>Follow the Main Road:</strong> Stay on the main road heading toward Tenjolaya / Tapos I.</li>
                                <li><strong>Arrive at Location:</strong> Follow the local signs to Kp. Neglasari until you reach New Ciputri Bogor.</li>
                            </ul>
                            <em>Note: The road near the destination is narrow and hilly, so make sure your vehicle is in good condition.</em>
                            <br><br>
                            <strong>By Public Transport</strong>
                            <ul>
                                <li><strong>Take the Train:</strong> Take the KRL Commuter Line to Bogor Station.</li>
                                <li><strong>Take a local minibus (Angkot):</strong> From Bogor Station, head to BTM (Bogor Trade Mall) and take the angkot toward Ciapus / Tenjolaya. Alternatively, go to Laladon Terminal and take the angkot bound for Tenjolaya.</li>
                                <li><strong>Take a local motorbike taxi (Ojek):</strong> Get off at the main Tenjolaya stop and take an ojek directly to New Ciputri Bogor.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        CAN I BRING A TENT?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            Yes, but we've a limited amount of space so please be considerate campers and only bring one tent with size 2x2 M.
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        HOW FAR IS THE CAR PARK FROM THE CAMPING SITES?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            The festival area is just a quick 2-minute walk from the car park.
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        WHAT CAN’T WE BRING?
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            To keep Bonafest safe and fun, random entry checks will take place at New Ciputri. Please note: Illegal drugs, weapons, and fireworks are not permitted anywhere on the grounds.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kategori 3: ACCESSIBILITY -->
            <div class="faq-category">
                <h2>ACCESSIBILITY</h2>
                <p class="faq-category-desc">Bonafest 2026 is all about creating an inclusive festival in Bogor. We are dedicated to supporting our attendees with access needs so everyone can enjoy the festival experience together. Please check out our accessibility details below before booking your tickets!</p>

                <div class="faq-item">
                    <button class="faq-question">
                        ACCESS TICKET AND PASSES
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            Further details regarding access tickets and companion passes will be announced shortly. Please contact our team directly for specific accessibility arrangements prior to booking.
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        ACCESSIBLE CAMPSITE AND FACILITY
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            We are preparing dedicated zones equipped with accessible pathways, tailored facilities, and proximity to main stages to ensure comfort and ease of movement for all attendees.
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question">
                        SUSTAINABILITY AND ACCESSIBILITY
                        <span class="faq-icon"></span>
                    </button>
                    <div class="faq-answer">
                        <div class="faq-answer-inner">
                            Our environmental efforts run hand in hand with our accessibility goals. We strive to create sustainable festival infrastructure that remains fully inclusive without compromising the natural beauty of the Bogor landscape.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="navbar.js"></script>
    <script>
        // Logika Akordion (Pop-up/Expand)
        const faqItems = document.querySelectorAll('.faq-item');

        faqItems.forEach(item => {
            const questionBtn = item.querySelector('.faq-question');
            
            questionBtn.addEventListener('click', () => {
                const isActive = item.classList.contains('active');
                
                // Menutup semua item lain yang terbuka (opsional, hapus jika ingin bisa buka banyak sekaligus)
                faqItems.forEach(otherItem => {
                    otherItem.classList.remove('active');
                    otherItem.querySelector('.faq-answer').style.maxHeight = null;
                    otherItem.querySelector('.faq-answer').style.opacity = 0;
                });

                if (!isActive) {
                    item.classList.add('active');
                    const answer = item.querySelector('.faq-answer');
                    answer.style.maxHeight = answer.scrollHeight + "px";
                    answer.style.opacity = 1;
                }
            });
        });

        // Logika Live Search
        const searchInput = document.getElementById('searchInput');
        const categories = document.querySelectorAll('.faq-category');
        const noResults = document.getElementById('noResults');

        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            let totalVisibleItems = 0;

            categories.forEach(category => {
                const items = category.querySelectorAll('.faq-item');
                let visibleItemsInCategory = 0;

                items.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        item.style.display = 'block';
                        visibleItemsInCategory++;
                        totalVisibleItems++;
                    } else {
                        item.style.display = 'none';
                        item.classList.remove('active'); // Tutup jika disembunyikan
                    }
                });

                // Sembunyikan judul kategori jika tidak ada pertanyaan yang cocok di dalamnya
                if (visibleItemsInCategory === 0) {
                    category.style.display = 'none';
                } else {
                    category.style.display = 'block';
                }
            });

            // Tampilkan pesan jika tidak ada hasil sama sekali
            if (totalVisibleItems === 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        });
    </script>
</body>
</html>