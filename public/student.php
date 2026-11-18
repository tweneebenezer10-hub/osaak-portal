<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_role('student');
render_header('Student dashboard');
page_title('ASSOCIATION PORTAL', 'Welcome, ' . $user['full_name'], 'Your account access is managed securely by the association.');
render_association_shortcuts($user);
?>
<?php render_footer(); ?>
