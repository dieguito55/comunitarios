import { createIcons, icons } from 'lucide';

createIcons({ icons, attrs: { 'stroke-width': 1.8 } });

const loadMediaSlot = (slot) => {
    const file = slot.dataset.file;
    if (!file) return;
    const image = new Image();
    image.onload = () => {
        slot.style.backgroundImage = `url("/media/${file}")`;
        slot.classList.add('loaded');
    };
    image.src = `/media/${file}`;
};

const mediaSlots = document.querySelectorAll('[data-file]');
if (!('IntersectionObserver' in window)) mediaSlots.forEach(loadMediaSlot);
else {
    const mediaObserver = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        loadMediaSlot(entry.target);
        mediaObserver.unobserve(entry.target);
    }), { rootMargin: '500px 0px' });
    mediaSlots.forEach(slot => mediaObserver.observe(slot));
}

const header = document.querySelector('[data-header]');
const menuToggle = document.querySelector('.menu-toggle');
const mobileMenu = document.querySelector('.mobile-nav');
const modal = document.querySelector('[data-video-modal]');
const player = document.querySelector('[data-video-player]');
const modalTitle = document.querySelector('[data-video-title]');
const progressBar = document.querySelector('[data-scroll-progress]');
const backToTop = document.querySelector('[data-back-to-top]');
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let scrollFrame;
const onScroll = () => {
    if (scrollFrame) return;
    scrollFrame = requestAnimationFrame(() => {
        const distance = document.documentElement.scrollHeight - window.innerHeight;
        const progress = distance > 0 ? Math.min(window.scrollY / distance, 1) : 0;
        header?.classList.toggle('scrolled', window.scrollY > 16);
        backToTop?.classList.toggle('visible', window.scrollY > Math.max(420, window.innerHeight * .65));
        progressBar?.style.setProperty('transform', `scaleX(${progress})`);
        scrollFrame = null;
    });
};
onScroll();
window.addEventListener('scroll', onScroll, { passive: true });
window.addEventListener('resize', onScroll, { passive: true });

backToTop?.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
});

menuToggle?.addEventListener('click', () => {
    const open = menuToggle.getAttribute('aria-expanded') === 'true';
    menuToggle.setAttribute('aria-expanded', String(!open));
    mobileMenu.hidden = open;
});

mobileMenu?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    mobileMenu.hidden = true;
    menuToggle?.setAttribute('aria-expanded', 'false');
}));

document.querySelectorAll('[data-video-file]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
        const file = trigger.dataset.videoFile;
        if (!file || !modal || !player) return;

        const source = player.querySelector('source');
        if (source) {
            source.src = `/media/${file}`;
            player.load();
        }

        if (modalTitle) modalTitle.textContent = trigger.dataset.videoLabel || 'Video de Comunitarios';
        modal.hidden = false;
        document.body.classList.add('modal-open');
        player.play().catch(() => {});
    });
});

document.querySelector('[data-close-video]')?.addEventListener('click', () => {
    player?.pause();
    modal.hidden = true;
    document.body.classList.remove('modal-open');
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal && !modal.hidden) {
        player?.pause();
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    }
});

const reveals = document.querySelectorAll('.reveal');
if (reduced || !('IntersectionObserver' in window)) reveals.forEach(el => el.classList.add('visible'));
else {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); }
    }), { threshold: .12 });
    reveals.forEach(el => observer.observe(el));
}
