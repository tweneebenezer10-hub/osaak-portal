<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (empty($_SESSION['pending_login_user_id']) || empty($_SESSION['pending_login_totp'])) {
    flash('error', 'No authenticator verification is pending.');
    redirect('index.php');
}
redirect('verify_login.php');
