document.addEventListener('DOMContentLoaded', function () {
    const supportedLanguages = ['it', 'en', 'de'];
    const languageLinks = document.querySelectorAll('.language-link');

    if (!languageLinks.length) return;

    languageLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();

            const selectedLang = this.dataset.lang;

            if (!supportedLanguages.includes(selectedLang)) return;

            localStorage.setItem('site_language', selectedLang);

            const newUrl = buildLanguageUrl(selectedLang, supportedLanguages);
            window.location.href = newUrl;
        });
    });
});

function getBasePath() {
    const path = window.location.pathname;

    if (path.startsWith('/podere_la_cavallara/')) {
        return '/podere_la_cavallara/';
    }

    return '/';
}

function buildLanguageUrl(selectedLang, supportedLanguages) {
    const currentUrl = new URL(window.location.href);
    const basePath = getBasePath();

    let relativePath = currentUrl.pathname;

    if (basePath !== '/' && relativePath.startsWith(basePath)) {
        relativePath = relativePath.substring(basePath.length);
    } else if (basePath === '/' && relativePath.startsWith('/')) {
        relativePath = relativePath.substring(1);
    }

    let pathParts = relativePath.split('/').filter(Boolean);

    if (pathParts.length === 0) {
        return basePath + selectedLang + '/' + currentUrl.search + currentUrl.hash;
    }

    if (supportedLanguages.includes(pathParts[0])) {
        pathParts[0] = selectedLang;
    } else {
        pathParts.unshift(selectedLang);
    }

    return basePath + pathParts.join('/') + currentUrl.search + currentUrl.hash;
}