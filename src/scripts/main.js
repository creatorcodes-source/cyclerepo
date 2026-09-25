/**
 * Veloce Cycles - Main Application Controller
 * 
 * Manages:
 * - Live public response counter retrieval
 * - FAQ accordion toggling
 * - Bike model category filters
 * - Dynamic UI interactions
 */

document.addEventListener('DOMContentLoaded', () => {

    // 0. Auto-hydrate Brand & Social Links from VELOCE_CONFIG
    if (typeof VELOCE_CONFIG !== 'undefined') {
        // Brand Names
        document.querySelectorAll('[data-brand="short"]').forEach(el => el.textContent = VELOCE_CONFIG.brandShort);
        document.querySelectorAll('[data-brand="full"]').forEach(el => el.textContent = VELOCE_CONFIG.brandFullName);
        document.querySelectorAll('[data-brand="initial"]').forEach(el => el.textContent = (VELOCE_CONFIG.brandShort || 'V')[0]);

        // Social Links (Facebook & Instagram)
        document.querySelectorAll('[data-social="facebook"]').forEach(el => {
            if (el.tagName.toLowerCase() === 'a') el.href = VELOCE_CONFIG.social.facebook;
        });
        document.querySelectorAll('[data-social="instagram"]').forEach(el => {
            if (el.tagName.toLowerCase() === 'a') el.href = VELOCE_CONFIG.social.instagram;
        });
        document.querySelectorAll('[data-contact="email"]').forEach(el => {
            if (el.tagName.toLowerCase() === 'a') el.href = 'mailto:' + VELOCE_CONFIG.social.email;
            el.textContent = VELOCE_CONFIG.social.email;
        });
        document.querySelectorAll('[data-contact="phone"]').forEach(el => {
            el.textContent = VELOCE_CONFIG.social.phone;
        });
        document.querySelectorAll('[data-contact="address"]').forEach(el => {
            el.textContent = VELOCE_CONFIG.social.address;
        });
    }

    // 1. Live Public Response Counter
    const heroCountEl = document.getElementById('hero-response-count');
    const navCountEl = document.getElementById('nav-response-count');
    const surveyCountEl = document.getElementById('survey-header-count');

    const updateCountDisplays = (count) => {
        const formatted = Number(count).toLocaleString();
        if (heroCountEl) heroCountEl.textContent = formatted;
        if (navCountEl) navCountEl.textContent = formatted;
        if (surveyCountEl) surveyCountEl.textContent = formatted;
    };

    const fetchLiveResponseCount = async () => {
        try {
            const res = await fetch('server/response-count.php', { cache: 'no-cache' });
            if (res.ok) {
                const data = await res.json();
                if (data && data.count) {
                    updateCountDisplays(data.count);
                }
            }
        } catch (err) {
            // Silently fall back to static default if offline
            console.debug('Response count fetch skipped:', err);
        }
    };

    fetchLiveResponseCount();

    // Listen for real-time survey submission event from survey.js
    window.addEventListener('veloce:count-updated', (e) => {
        if (e.detail && e.detail.count) {
            updateCountDisplays(e.detail.count);
        }
    });

    // 2. FAQ Accordion
    const faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(item => {
        const trigger = item.querySelector('.faq-trigger');
        const content = item.querySelector('.faq-content');

        trigger?.addEventListener('click', () => {
            const isActive = item.classList.contains('active');

            // Close all other open items
            faqItems.forEach(other => {
                if (other !== item) {
                    other.classList.remove('active');
                    const otherContent = other.querySelector('.faq-content');
                    if (otherContent) otherContent.style.maxHeight = null;
                }
            });

            // Toggle current item
            if (isActive) {
                item.classList.remove('active');
                if (content) content.style.maxHeight = null;
            } else {
                item.classList.add('active');
                if (content) content.style.maxHeight = content.scrollHeight + 'px';
            }
        });
    });

    // 3. Bike Model Category Filters
    const tabButtons = document.querySelectorAll('.models-tabs .tab-btn');
    const bikeCards = document.querySelectorAll('.models-grid .bike-card');

    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            tabButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const category = btn.dataset.category;

            bikeCards.forEach(card => {
                if (category === 'all' || card.dataset.category === category) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });

    // 4. Test Ride Booking & Contact Form Modal / Quick handler
    const contactForm = document.getElementById('contact-form');
    const contactSuccess = document.getElementById('contact-success-msg');

    contactForm?.addEventListener('submit', (e) => {
        e.preventDefault();
        const submitBtn = contactForm.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending Message...';
        }
        setTimeout(() => {
            if (contactSuccess) contactSuccess.style.display = 'block';
            contactForm.reset();
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Message Sent ✓';
            }
        }, 800);
    });

    // 5. Source Code & Design Asset Protection Layer
    // (Prevents right-click inspect, devtools shortcuts, and print screen grabs)
    document.addEventListener('contextmenu', (e) => {
        // Allow right click ONLY if clicking inside a form text input
        const tag = e.target.tagName.toLowerCase();
        if (tag !== 'input' && tag !== 'textarea') {
            e.preventDefault();
            return false;
        }
    });

    document.addEventListener('keydown', (e) => {
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const ctrlKey = isMac ? e.metaKey : e.ctrlKey;

        // F12 (DevTools)
        if (e.key === 'F12' || e.keyCode === 123) {
            e.preventDefault();
            return false;
        }

        // Ctrl+Shift+I / Ctrl+Shift+J / Ctrl+Shift+C (DevTools / Console / Inspector)
        if (ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c')) {
            e.preventDefault();
            return false;
        }

        // Ctrl+U / Cmd+Option+U (View Source)
        if (ctrlKey && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }

        // Ctrl+S (Save Page)
        if (ctrlKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            return false;
        }

        // Ctrl+P (Print / PDF)
        if (ctrlKey && (e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            return false;
        }
    });

    // PrintScreen key interceptor
    window.addEventListener('keyup', (e) => {
        if (e.key === 'PrintScreen' || e.keyCode === 44) {
            try {
                navigator.clipboard.writeText('');
            } catch (err) {
                // Clipboard write permission fallback
            }
        }
    });
});

