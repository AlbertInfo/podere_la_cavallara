const supportedLanguages = ['it', 'en', 'de'];
const defaultLanguage = 'en';

// 🔥 Calcolo automatico basePath
function getBasePath() {
    let path = window.location.pathname;

    // Rimuove index.html se presente
    path = path.replace('index.html', '');

    // Se siamo in root → "/"
    if (path === '/' || path === '') {
        return '/';
    }

    // Altrimenti prende la prima parte del path
    let segments = path.split('/').filter(Boolean);

    // es: ["podere_la_cavallara"]
    return '/' + segments[0] + '/';
}

const basePath = getBasePath();

// 🔍 Controllo lingua salvata
let savedLang = localStorage.getItem('site_language');

if (savedLang && supportedLanguages.includes(savedLang)) {
    redirectTo(savedLang);
} else {
    let browserLang = navigator.language || navigator.userLanguage;
    let langCode = browserLang ? browserLang.split('-')[0] : defaultLanguage;

    if (!supportedLanguages.includes(langCode)) {
        langCode = defaultLanguage;
    }

    redirectTo(langCode);
}

// 🚀 Redirect
function redirectTo(lang) {
    window.location.href = basePath + lang + '/';
}