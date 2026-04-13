            </main>

            <?php if ($currentAdmin): ?>
                <nav class="admin-mobile-nav" aria-label="Navigazione rapida mobile">
                    <a class="admin-mobile-nav__item<?= $mobileNavSection === 'home' ? ' is-home-active is-active' : '' ?>" href="<?= e(admin_url('index.php')) ?>">
                        <span class="admin-mobile-nav__icon">⌂</span>
                        <span class="admin-mobile-nav__label">Home</span>
                    </a>
                    <a class="admin-mobile-nav__item<?= $mobileNavSection === 'prenotazioni' ? ' is-active' : '' ?>" href="<?= e(admin_url('index.php#registered-bookings')) ?>">
                        <span class="admin-mobile-nav__icon">▦</span>
                        <span class="admin-mobile-nav__label">Prenotazioni</span>
                    </a>
                    <a class="admin-mobile-nav__item<?= $mobileNavSection === 'clienti' ? ' is-active' : '' ?>" href="<?= e(admin_url('clienti.php')) ?>">
                        <span class="admin-mobile-nav__icon">◌</span>
                        <span class="admin-mobile-nav__label">Clienti</span>
                    </a>
                    <a class="admin-mobile-nav__item<?= $mobileNavSection === 'import' ? ' is-active' : '' ?>" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">
                        <span class="admin-mobile-nav__icon">↥</span>
                        <span class="admin-mobile-nav__label">Importa</span>
                    </a>
                    <button class="admin-mobile-nav__item<?= $mobileNavSection === 'other' ? ' is-active' : '' ?>" type="button" data-open-sidebar>
                        <span class="admin-mobile-nav__icon">☰</span>
                        <span class="admin-mobile-nav__label">Altro</span>
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
    <script src="<?= e(admin_url('assets/js/admin-ui.js')) ?>?v=31"></script>
    <script src="<?= e(admin_url('assets/js/interhome-import.js')) ?>?v=31"></script>

</body>
</html>
