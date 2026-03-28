<?php
require_once __DIR__ . '/includes/auth.php';
logout_admin();
set_flash('success', 'Logout effettuato.');
header('Location: ' . admin_url('login.php'));
exit;
