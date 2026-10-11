/**
 * Collectives Todos landing page animations.
 *
 * All initial hidden states are applied by GSAP itself, so the page remains
 * fully readable with JavaScript disabled. Animations run only when the user
 * has not requested reduced motion.
 */
'use strict';

const { gsap } = window;
const hasGsap = typeof gsap !== 'undefined';

/**
 * Entrance animation for the hero section: title, copy, buttons and the
 * demo Todos card slide in staggered from below.
 */
function heroEntrance() {
    const heroItems = document.querySelectorAll('#top section > div > div');
    const demoCard = document.querySelector('#todo-demo');

    gsap.set([...heroItems], { y: 32, autoAlpha: 0 });
    gsap.set(demoCard, { y: 48, autoAlpha: 0, rotate: -2 });

    const tl = gsap.timeline({ defaults: { ease: 'power3.out', duration: 0.9 } });
    tl.to(heroItems, { y: 0, autoAlpha: 1, stagger: 0.15 }, 0.1)
        .to(demoCard, { y: 0, autoAlpha: 1, rotate: 0 }, 0.4);

    return tl;
}

/**
 * The demo Todos card checks its items off one by one, resets and loops.
 */
function todoDemoLoop() {
    const items = document.querySelectorAll('.demo-item');
    if (items.length === 0) {
        return null;
    }

    items.forEach((item) => {
        const check = item.querySelector('.demo-check');
        const text = item.querySelector('.demo-text');
        const box = item.querySelector('.demo-box');
        const length = check.getTotalLength();
        gsap.set(check, { strokeDasharray: length, strokeDashoffset: length });
        gsap.set(box, { backgroundColor: 'rgba(0,0,0,0)' });
        gsap.set(text, { opacity: 1 });
    });

    const tl = gsap.timeline({ repeat: -1, repeatDelay: 1.6 });

    items.forEach((item, index) => {
        const check = item.querySelector('.demo-check');
        const text = item.querySelector('.demo-text');
        const box = item.querySelector('.demo-box');
        tl.to(check, { strokeDashoffset: 0, duration: 0.35, ease: 'power2.inOut' }, 0.5 + index * 0.7)
            .to(box, { backgroundColor: 'rgba(0,130,201,0.12)', duration: 0.25 }, '<')
            .to(text, { opacity: 0.45, duration: 0.25 }, '<')
            .to(item, { x: 4, duration: 0.2, yoyo: true, repeat: 1, ease: 'power1.inOut' }, '<');
    });

    const hold = 0.5 + items.length * 0.7 + 1;
    tl.to(items, {
        opacity: 0,
        duration: 0.3,
        onComplete: () => items.forEach((item) => {
            gsap.set(item, { opacity: 1 });
            const check = item.querySelector('.demo-check');
            const text = item.querySelector('.demo-text');
            const box = item.querySelector('.demo-box');
            const length = check.getTotalLength();
            gsap.set(check, { strokeDashoffset: length });
            gsap.set(box, { backgroundColor: 'rgba(0,0,0,0)' });
            gsap.set(text, { opacity: 1 });
        }),
    }, hold);

    return tl;
}

/**
 * Scroll-triggered reveals for section headers, feature/requirement cards
 * and screenshot figures, plus a subtle hover lift on screenshots.
 */
function scrollReveals() {
    gsap.registerPlugin(ScrollTrigger);

    gsap.utils.toArray('.reveal').forEach((el) => {
        gsap.from(el, {
            y: 24,
            autoAlpha: 0,
            duration: 0.8,
            ease: 'power3.out',
            scrollTrigger: { trigger: el, start: 'top 85%' },
        });
    });

    gsap.utils.toArray('.reveal-card').forEach((el) => {
        gsap.from(el, {
            y: 32,
            autoAlpha: 0,
            duration: 0.7,
            ease: 'power3.out',
            scrollTrigger: { trigger: el, start: 'top 90%' },
        });
    });

    document.querySelectorAll('.shot').forEach((figure) => {
        const img = figure.querySelector('img');
        figure.addEventListener('mouseenter', () => {
            gsap.to(img, { scale: 1.03, duration: 0.4, ease: 'power2.out' });
        });
        figure.addEventListener('mouseleave', () => {
            gsap.to(img, { scale: 1, duration: 0.4, ease: 'power2.out' });
        });
    });
}

function init() {
    if (!hasGsap) {
        return;
    }

    const mm = gsap.matchMedia();

    // Full motion: entrance, demo loop, scroll reveals.
    mm.add('(prefers-reduced-motion: no-preference)', () => {
        gsap.registerPlugin(ScrollTrigger);
        heroEntrance();
        todoDemoLoop();
        scrollReveals();
    });

    // Reduced motion: leave the page fully visible, no timelines.
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
