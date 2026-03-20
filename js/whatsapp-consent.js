(function () {
  "use strict";

  function getPopups() {
    return document.querySelectorAll(".whatsapp-cta");
  }

  function showPopups() {
    getPopups().forEach(el => el.classList.add("whatsapp-enabled"));
  }

  function hidePopups() {
    getPopups().forEach(el => el.classList.remove("whatsapp-enabled"));
  }

  function hasConsent() {
    try {
      const raw = localStorage.getItem("cookie_consent");
      if (!raw) return false;

      const consent = JSON.parse(raw);

      // 👉 MOSTRA SOLO SE PREFERENZE ATTIVE
      return consent.preferences === true;

    } catch (e) {
      return false;
    }
  }

  function update() {
    if (hasConsent()) {
      showPopups();
    } else {
      hidePopups();
    }
  }

  document.addEventListener("DOMContentLoaded", update);

  // 🔥 AGGANCIO DIRETTO AL TUO COOKIE.JS
  window.addEventListener("cookieConsentUpdated", update);
})();