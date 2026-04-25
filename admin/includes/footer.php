            </main>

            <footer class="admin-footer">
                <div>Podere La Cavallara · Area amministrazione</div>
            </footer>

            <?php if (!empty($currentAdmin)): ?>
            <?php
                $mobileDocumentsUrl = admin_url('anagrafica.php?month=' . rawurlencode(date('Y-m')) . '&day=' . rawurlencode(date('Y-m-d')) . '&new=1&mobile_documents=1');
            ?>
                <nav class="mobile-bottom-nav" aria-label="Navigazione rapida mobile">
                    <a class="mobile-bottom-nav__link mobile-bottom-nav__link--home" href="<?= e(admin_url('index.php#overview')) ?>" data-mobile-nav-item="home">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11.5L12 4l9 7.5"></path><path d="M5.5 10.5V20h13V10.5"></path></svg>
                        <span class="mobile-bottom-nav__label">Home</span>
                    </a>
                    <a class="mobile-bottom-nav__link" href="<?= e(admin_url('index.php#registered-bookings')) ?>" data-mobile-nav-item="prenotazioni">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3.5" width="16" height="17" rx="2.5"></rect><path d="M8 3.5v17"></path><path d="M16 3.5v17"></path><path d="M4 9.5h16"></path><path d="M4 14.5h16"></path></svg>
                        <span class="mobile-bottom-nav__label">Prenotazioni</span>
                    </a>
                    <a class="mobile-bottom-nav__link" href="<?= e($mobileDocumentsUrl) ?>" data-mobile-nav-item="documenti">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"></path><path d="M14 3v5h5"></path><path d="M9 13h6"></path><path d="M9 17h6"></path><path d="M9 9h1"></path></svg>
                        <span class="mobile-bottom-nav__label">Documenti</span>
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
    <script src="<?= e(admin_url('assets/js/admin-ui.js')) ?>?v=49"></script>
    <script src="<?= e(admin_url('assets/js/interhome-import.js')) ?>?v=31"></script>
    <?php if ($currentPath === 'anagrafica.php'): ?>
        <script src="<?= e(admin_url('assets/js/anagrafica.js')) ?>?v=87"></script>
    <?php endif; ?>

</body>
</html>
