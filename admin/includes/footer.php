            </main>

            <?php if (!empty($currentAdmin)): ?>
                <nav class="mobile-bottom-nav" aria-label="Navigazione mobile rapida">
                    <a class="mobile-bottom-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#overview')) ?>">
                        <span class="mobile-bottom-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5L12 4l9 7.5"/><path d="M5 10.5V20h14v-9.5"/></svg>
                        </span>
                        <span>Home</span>
                    </a>
                    <a class="mobile-bottom-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#registered-bookings')) ?>">
                        <span class="mobile-bottom-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                        </span>
                        <span>Prenotazioni</span>
                    </a>
                    <a class="mobile-bottom-link<?= admin_nav_active(['clienti.php'], $currentPath) ?>" href="<?= e(admin_url('clienti.php')) ?>">
                        <span class="mobile-bottom-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>
                        </span>
                        <span>Clienti</span>
                    </a>
                    <a class="mobile-bottom-link<?= admin_nav_active(['import-interhome-pdf.php','import-interhome-review.php'], $currentPath) ?>" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">
                        <span class="mobile-bottom-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>
                        </span>
                        <span>Import</span>
                    </a>
                    <button class="mobile-bottom-link mobile-bottom-link--button" type="button" id="mobileBottomMore">
                        <span class="mobile-bottom-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.2"/><circle cx="5" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/></svg>
                        </span>
                        <span>Altro</span>
                    </button>
                </nav>
            <?php endif; ?>

            <footer class="admin-footer">
                <div>Podere La Cavallara · Area amministrazione</div>
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/it.js"></script>
    <script src="<?= e(admin_url('assets/js/admin-ui.js')) ?>?v=41"></script>
    <script src="<?= e(admin_url('assets/js/interhome-import.js')) ?>?v=31"></script>

</body>
</html>
