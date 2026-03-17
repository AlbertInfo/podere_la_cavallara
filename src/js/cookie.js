document.addEventListener("DOMContentLoaded", function () {
    const consent = localStorage.getItem("cookie_consent");

    if (!consent) {
        document.getElementById("cookie-banner").style.display = "block";
    } else {
        applyConsent(JSON.parse(consent));
    }
});

function acceptCookies() {
    const consent = {
        analytics: true,
        preferences: true
    };
    localStorage.setItem("cookie_consent", JSON.stringify(consent));
    applyConsent(consent);
    hideBanner();
}

function rejectCookies() {
    const consent = {
        analytics: false,
        preferences: false
    };
    localStorage.setItem("cookie_consent", JSON.stringify(consent));
    hideBanner();
}

function openPreferences() {
    const modal = new bootstrap.Modal(document.getElementById('cookieModal'));
    modal.show();
}

function savePreferences() {
    const consent = {
        analytics: document.getElementById("analyticsCookies").checked,
        preferences: document.getElementById("preferenceCookies").checked
    };

    localStorage.setItem("cookie_consent", JSON.stringify(consent));
    applyConsent(consent);
    hideBanner();

    const modal = bootstrap.Modal.getInstance(document.getElementById('cookieModal'));
    modal.hide();
}

function hideBanner() {
    document.getElementById("cookie-banner").style.display = "none";
}

function applyConsent(consent) {
    // 👉 Esempio: attiva Google Analytics solo se consentito
    if (consent.analytics) {
        loadAnalytics();
    }

    // 👉 Preferenze lingua
    if (consent.preferences) {
        console.log("Preferenze abilitate");
    }
}

function loadAnalytics() {
    console.log("Analytics attivati");

    // ESEMPIO Google Analytics
    /*
    var script = document.createElement('script');
    script.src = "https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID";
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'GA_MEASUREMENT_ID');
    */
}
