            </main>

            <footer class="admin-footer">
                <div>Podere La Cavallara · Area amministrazione</div>
            </footer>

            <?php if (!empty($currentAdmin)): ?>
                <nav class="mobile-bottom-nav" aria-label="Navigazione rapida mobile">
                    <a class="mobile-bottom-nav__link mobile-bottom-nav__link--home" href="<?= e(admin_url('index.php#overview')) ?>" data-mobile-nav-item="home">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11.5L12 4l9 7.5"></path><path d="M5.5 10.5V20h13V10.5"></path></svg>
                        <span class="mobile-bottom-nav__label">Home</span>
                    </a>
                    <a class="mobile-bottom-nav__link" href="<?= e(admin_url('index.php#registered-bookings')) ?>" data-mobile-nav-item="prenotazioni">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3.5" width="16" height="17" rx="2.5"></rect><path d="M8 3.5v17"></path><path d="M16 3.5v17"></path><path d="M4 9.5h16"></path><path d="M4 14.5h16"></path></svg>
                        <span class="mobile-bottom-nav__label">Prenotazioni</span>
                    </a>
                    <a class="mobile-bottom-nav__link" href="<?= e(admin_url('clienti.php')) ?>" data-mobile-nav-item="clienti">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path><circle cx="10" cy="7" r="4"></circle><path d="M21 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        <span class="mobile-bottom-nav__label">Clienti</span>
                    </a>
                    <a class="mobile-bottom-nav__link" href="<?= e(admin_url('import-interhome-pdf.php')) ?>" data-mobile-nav-item="importa">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4"></path><path d="M7.5 8.5L12 4l4.5 4.5"></path><path d="M4 16.5v2A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5v-2"></path></svg>
                        <span class="mobile-bottom-nav__label">Importa</span>
                    </a>
                    <button class="mobile-bottom-nav__link mobile-bottom-nav__link--menu" type="button" data-mobile-nav-item="menu" data-toggle-sidebar aria-controls="adminSidebar" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
                        <span class="mobile-bottom-nav__label">Altro</span>
                    </button>
                </nav>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/it.js"></script>
    <script src="<?= e(admin_url('assets/js/admin-ui.js')) ?>?v=47"></script>
    <script src="<?= e(admin_url('assets/js/interhome-import.js')) ?>?v=31"></script>
    <?php if ($currentPath === 'anagrafica.php'): ?>
        <script src="<?= e(admin_url('assets/js/anagrafica.js')) ?>?v=1"></script>
    <?php endif; ?>

</body>
</html>
