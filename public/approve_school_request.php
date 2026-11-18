<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

require_platform_super_admin();
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit('This legacy approval endpoint is disabled. Review eligible initial Proprietor requests through school_role_requests.php.');
