<?php
/** logout.php — destroy the session and return home */
require_once __DIR__ . '/includes/functions.php';
boot_session();
logout_user();
boot_session();
flash('success', 'You have been logged out.');
redirect(APP_URL . '/index.php');
