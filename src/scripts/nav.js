/**
 * Veloce Cycles - Navigation & Scroll Controller
 */

document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('.header');
    const mobileToggle = document.querySelector('.mobile-toggle');
    const mobileDrawer = document.querySelector('.mobile-nav-drawer');
    const mobileBackdrop = document.querySelector('.mobile-nav-backdrop');
    const closeDrawerBtn = document.querySelector('.close-drawer-btn');
    const navLinks = document.querySelectorAll('.nav-link, .mobile-nav-links a');

    // Header scroll background toggle
    const handleScroll = () => {
        if (window.scrollY > 40) {
            header?.classList.add('scrolled');
        } else {
            header?.classList.remove('scrolled');
        }
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
    handleScroll();

    // Mobile Drawer Controls
    const openDrawer = () => {
        mobileDrawer?.classList.add('open');
        mobileBackdrop?.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    const closeDrawer = () => {
        mobileDrawer?.classList.remove('open');
        mobileBackdrop?.classList.remove('open');
        document.body.style.overflow = '';
    };

    mobileToggle?.addEventListener('click', openDrawer);
    closeDrawerBtn?.addEventListener('click', closeDrawer);
    mobileBackdrop?.addEventListener('click', closeDrawer);

    // Smooth scroll for nav anchor links
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');
            if (href && href.startsWith('#')) {
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    closeDrawer();
                    const headerHeight = header ? header.offsetHeight : 80;
                    const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - headerHeight;
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            }
        });
    });
});
