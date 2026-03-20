document.addEventListener('DOMContentLoaded', function () {
    const whatsapp = document.querySelector('.whatsapp-cta');
    const hero = document.querySelector('.hero, .page-hero, .banner-hero, .hero-section');

    function placeWhatsapp() {
      if (!whatsapp) return;

      if (window.innerWidth <= 767 && hero) {
        if (whatsapp.parentElement !== hero) {
          hero.appendChild(whatsapp);
        }
      } else {
        if (whatsapp.parentElement !== document.body) {
          document.body.appendChild(whatsapp);
        }
      }
    }

    placeWhatsapp();

    setTimeout(() => {
      whatsapp.classList.add('is-visible');
    }, 180);

    window.addEventListener('resize', placeWhatsapp);
  });

  document.addEventListener('DOMContentLoaded', function () {
    const whatsapp = document.querySelector('.whatsapp-cta');
    if (whatsapp) {
      setTimeout(() => {
        whatsapp.classList.add('is-visible');
      }, 150);
    }
  });

  