<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/platform_docs_section.php';

$user = require_platform_super_admin();

render_header('Project documentation');
?>
<section class="panel">
    <p class="eyebrow">SUPER ADMIN DOCUMENTATION</p>
    <h1>CONNEZER Project Documentation &amp; Security Information</h1>
    <p class="muted">Independent documentation view for Platform Super Admin. <a href="platform_admin.php">Return to Platform administration</a></p>
</section>
<?php
render_platform_documentation_section(false);
render_footer();
