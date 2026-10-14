// small helper
const $ = sel => document.querySelector(sel);
const $$ = sel => Array.from(document.querySelectorAll(sel));

// DOM ready
document.addEventListener('DOMContentLoaded', () => {
  // Menu toggle for mobile
  const menuToggle = $('.menu-toggle');
  const menu = $('.menu');
  if (menuToggle && menu) {
    menuToggle.addEventListener('click', () => {
      menu.classList.toggle('active');
      menuToggle.classList.toggle('active');
      // show/hide menu in mobile by toggling inline style
      if (menu.classList.contains('active')) {
        menu.style.display = 'flex';
        menu.style.flexDirection = 'column';
        menu.style.position = 'absolute';
        menu.style.right = '12px';
        menu.style.top = '100%';
        menu.style.background = 'white';
        menu.style.padding = '12px';
        menu.style.borderRadius = '10px';
        menu.style.boxShadow = '0 12px 30px rgba(11,51,80,0.08)';
      } else {
        menu.style.display = '';
        menu.style.position = '';
        menu.style.right = '';
        menu.style.top = '';
        menu.style.background = '';
        menu.style.padding = '';
        menu.style.borderRadius = '';
        menu.style.boxShadow = '';
      }
    });
  }

  // Smooth scroll for anchor links
  $$('a[href^="#"]').forEach(a => {
    a.addEventListener('click', (e) => {
      const href = a.getAttribute('href');
      if (!href || href === '#') return;
      const target = document.querySelector(href);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({behavior: 'smooth', block: 'start'});
        // close mobile menu after click
        if (menu && menu.classList.contains('active')) {
          menu.classList.remove('active');
          if (menuToggle) menuToggle.classList.remove('active');
          menu.style.display = '';
        }
      }
    });
  });

  // Back to top button
  const backBtn = document.querySelector('.back-to-top');
  window.addEventListener('scroll', () => {
    if (!backBtn) return;
    if (window.pageYOffset > 300) backBtn.style.display = 'flex';
    else backBtn.style.display = 'none';
  });
  if (backBtn) {
    backBtn.addEventListener('click', () => window.scrollTo({top:0,behavior:'smooth'}));
  }

  // Counters (IntersectionObserver)
  const statEls = $$('.stat-number');
  if (statEls.length) {
    const obs = new IntersectionObserver((entries, ob) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          animateCounter(entry.target, parseFloat(entry.target.dataset.target) || 0);
          ob.unobserve(entry.target);
        }
      });
    }, {threshold: 0.6});
    statEls.forEach(el => obs.observe(el));
  }

  // No contact-message endpoint exists yet; do not claim that a message was sent.
  const contactForm = $('#contactForm');
  const formMessage = $('#formMessage');
  if (contactForm) {
    contactForm.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!formMessage) return;
      formMessage.textContent = 'نموذج التواصل غير متاح حاليًا. يرجى استخدام قنوات التواصل الرسمية.';
      formMessage.className = 'form-message error';
      formMessage.style.display = 'block';
    });
  }
});

// animate counter helper
function animateCounter(el, target) {
  const isFloat = String(target).includes('.');
  let start = 0;
  const duration = 1500;
  const frames = Math.round(duration / 16);
  const increment = target / frames;
  function step() {
    start += increment;
    if (start < target) {
      el.textContent = isFloat ? (Math.round(start*10)/10) : Math.floor(start);
      requestAnimationFrame(step);
    } else {
      el.textContent = target;
    }
  }
  step();
}