/**
 * Collectives Todos landing page animations.
 *
 * All initial hidden states are applied by GSAP itself, so the page remains
 * fully readable with JavaScript disabled. Autonomous animations run only
 * when the user has not requested reduced motion; the scroll-scrubbed demo
 * video runs in both modes because it only moves while the user scrolls.
 * Loaded as a classic deferred script (not type="module") so it also works
 * when the page is opened directly from disk (file://).
 */
'use strict';

const { gsap } = window;
const hasGsap = typeof gsap !== 'undefined';

/**
 * Entrance animation for the hero section: title, copy, buttons and the
 * demo Todos card slide in staggered from below.
 */
function heroEntrance() {
    // Scoped to the hero section: a broader selector here once grabbed the
    // .reveal heading blocks of the other sections too, whose scroll-triggered
    // from() tweens then captured opacity 0 as their end value and made the
    // headings vanish for good when their trigger fired.
    const heroItems = document.querySelectorAll('#hero > div > div');
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
 * The demo video is played by scrolling: the section pins at the top of the
 * viewport and the playhead follows the scroll position across the length of
 * the recording. The scrub runs in both motion modes because it is user-
 * driven; reduced motion additionally keeps the native controls.
 */
function demoVideoScrub(keepControls) {
    const wrap = document.getElementById('demo-pin');
    const video = document.getElementById('demo-video');
    if (!wrap || !video) {
        if (video) {
            video.controls = true;
        }
        return;
    }

    video.controls = !!keepControls;
    video.pause();

    ScrollTrigger.create({
        trigger: wrap,
        start: 'top top',
        // One second of recording per 100px of scrolling, sized once the
        // duration is known (refreshed on loadedmetadata below).
        end: () => '+=' + Math.round((isFinite(video.duration) ? video.duration : 30) * 100),
        pin: true,
        anticipatePin: 1,
        onUpdate: (self) => {
            if (isFinite(video.duration)) {
                video.currentTime = self.progress * video.duration;
            }
        },
    });

    video.addEventListener('loadedmetadata', () => ScrollTrigger.refresh(), { once: true });
}

/**
 * The scroll-down hint on the hero's bottom edge: a light ring flashes
 * outward from the circle and the chevron bobs, until the user scrolls
 * past the hero. The pulse is an autonomous looping animation, so it runs
 * only without reduced motion.
 */
function scrollHint() {
    const hint = document.getElementById('scroll-hint');
    const chevron = hint ? hint.querySelector('svg') : null;
    if (!hint || !chevron) {
        return null;
    }

    gsap.fromTo(hint,
        { boxShadow: '0 8px 24px rgba(34,45,50,0.18), 0 0 0 0 rgba(0,130,201,0.45)' },
        {
            boxShadow: '0 8px 24px rgba(34,45,50,0.18), 0 0 0 16px rgba(0,130,201,0)',
            duration: 1.4,
            ease: 'power2.out',
            repeat: -1,
            repeatDelay: 0.5,
        });

    gsap.fromTo(chevron, { y: -2 }, {
        y: 3,
        duration: 0.9,
        ease: 'power1.inOut',
        yoyo: true,
        repeat: -1,
    });
}

/**
 * Clicking the scroll-down hint scrolls to the features section, smoothly
 * unless the user prefers reduced motion. Runs in both motion modes.
 */
function scrollHintClick() {
    const hint = document.getElementById('scroll-hint');
    const target = document.getElementById('features');
    if (!hint || !target) {
        return;
    }
    hint.addEventListener('click', (event) => {
        event.preventDefault();
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' });
    });
}

/**
 * Scroll-triggered reveals for section headers, feature/requirement cards
 * and screenshot figures, plus a hover effect that grows the screenshots
 * past their frames.
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
            // Raise the card above its neighbours and grow the screenshot
            // past its frame while it shrinks back in.
            gsap.set(figure, { zIndex: 10 });
            gsap.to(img, {
                scale: 1.06,
                boxShadow: '0 16px 40px rgba(34,45,50,0.22)',
                duration: 0.4,
                ease: 'power2.out',
            });
        });
        figure.addEventListener('mouseleave', () => {
            gsap.to(img, {
                scale: 1,
                boxShadow: '0 0 0 rgba(34,45,50,0)',
                duration: 0.4,
                ease: 'power2.out',
                onComplete: () => gsap.set(figure, { clearProps: 'zIndex' }),
            });
        });
    });
}

function init() {
    const demoVideo = document.getElementById('demo-video');

    if (!hasGsap) {
        if (demoVideo) {
            demoVideo.controls = true;
        }
        return;
    }

    gsap.registerPlugin(ScrollTrigger);

    // The video scrub is user-driven — it only moves while scrolling, like a
    // seek bar — so it runs in both motion modes. Reduced motion keeps the
    // native controls on top of the scrub. It is created before the scroll
    // reveals so its pin spacing is in place when they measure.
    demoVideoScrub(window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    const mm = gsap.matchMedia();

    // The hint's click behavior is user-initiated, so it runs in both modes.
    scrollHintClick();

    // Autonomous animations (entrance, demo loop, scroll reveals, hint pulse)
    // only run when the user has not requested reduced motion.
    mm.add('(prefers-reduced-motion: no-preference)', () => {
        heroEntrance();
        todoDemoLoop();
        scrollHint();
        scrollReveals();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
