// Carousel Home Page
$("#carousel-home .owl-carousel").on("initialized.owl.carousel", function() {
  setTimeout(function() {
    $("#carousel-home .owl-carousel .owl-item.active .owl-slide-animated").addClass("is-transitioned");
  }, 200);
});

const $owlCarousel = $("#carousel-home .owl-carousel").owlCarousel({
  items: 1,
  loop: true,
  nav: false,
  dots:true,
  autoplay:true,
  autoplayTimeout:5000,
  animateOut:'fadeOut',
  autoplayHoverPause:false,
	responsive:{
        0:{
             dots:false
        },
        767:{
            dots:false
        },
        768:{
             dots:true
        }
    }
});

$('.home-carousel-mobile').owlCarousel({
        items: 1,
        loop: true,
        nav: false,
        dots: false,
        autoplay: true,
        autoplayTimeout: 5000,
        smartSpeed: 700
    });

$owlCarousel.on("changed.owl.carousel", function(e) {
  $(".owl-slide-animated").removeClass("is-transitioned");
  const $currentOwlItem = $(".owl-item").eq(e.item.index);
  $currentOwlItem.find(".owl-slide-animated").addClass("is-transitioned");
});

$owlCarousel.on("resize.owl.carousel", function() {
  setTimeout(function() {
  }, 50);
});

$('.owl-carousel').on('changed.owl.carousel', function(event) {
    $('video').each(function(){
        this.pause();
    });

    var current = $(event.target).find('.owl-item').eq(event.item.index).find('video').get(0);
    if(current) current.play();
});




/*  JS PER MARKER CAROUSEL DEKSTOP */


$(document).ready(function () {
    function animateHeroMarkers() {
        var $slide = $('.hero-markers-slide');
        var $markers = $slide.find('.hero-marker');

        $markers.removeClass('is-visible');

        $markers.each(function(index) {
            var marker = $(this);
            setTimeout(function() {
                marker.addClass('is-visible');
            }, 400 + (index * 450));
        });
    }

    $('.kenburns').on('initialized.owl.carousel changed.owl.carousel', function() {
        setTimeout(function() {
            var $activeSlide = $('.kenburns .owl-item.active .hero-markers-slide');

            $('.hero-marker').removeClass('is-visible');

            if ($activeSlide.length) {
                $activeSlide.find('.hero-marker').each(function(index) {
                    var marker = $(this);
                    setTimeout(function() {
                        marker.addClass('is-visible');
                    }, 300 + (index * 450));
                });
            }
        }, 120);
    });

    animateHeroMarkers();
});

/*   FINE JS PER MARKER CAROUSEL DEKSTOP */

/*  JS PER MARKER CAROUSEL MOBILE */
$(document).ready(function () {

    function animateDesktopMarkers() {
        var $activeSlide = $('.kenburns .owl-item.active .hero-markers-slide');
        $('.hero-markers-slide .hero-marker').removeClass('is-visible');

        if ($activeSlide.length) {
            $activeSlide.find('.hero-marker').each(function(index) {
                var marker = $(this);
                setTimeout(function() {
                    marker.addClass('is-visible');
                }, 300 + (index * 450));
            });
        }
    }

    function animateMobileMarkers() {
        var $activeSlide = $('.home-carousel-mobile .owl-item.active .mobile-markers-slide');
        $('.mobile-markers-slide .mobile-hero-marker').removeClass('is-visible');

        if ($activeSlide.length) {
            $activeSlide.find('.mobile-hero-marker').each(function(index) {
                var marker = $(this);
                setTimeout(function() {
                    marker.addClass('is-visible');
                }, 250 + (index * 400));
            });
        }
    }

    $('.kenburns').on('initialized.owl.carousel changed.owl.carousel', function() {
        setTimeout(function() {
            animateDesktopMarkers();
        }, 120);
    });

    $('.home-carousel-mobile').on('initialized.owl.carousel changed.owl.carousel', function() {
        setTimeout(function() {
            animateMobileMarkers();
        }, 120);
    });

    animateDesktopMarkers();
    animateMobileMarkers();
});
/* FINE  JS PER MARKER CAROUSEL MOBILE */