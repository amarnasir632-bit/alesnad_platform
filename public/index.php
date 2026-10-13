<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_once dirname(__DIR__) . '/app/Http/Response.php';
require_once dirname(__DIR__) . '/app/Http/Controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/Http/Controllers/AccountController.php';
require_once dirname(__DIR__) . '/app/Http/Controllers/AcademicController.php';
require_once dirname(__DIR__) . '/app/Services/PrivateStorage.php';
require_once dirname(__DIR__) . '/app/Services/ContentService.php';
require_once dirname(__DIR__) . '/app/Http/Controllers/ContentController.php';
require_once dirname(__DIR__) . '/app/Http/LegacyWebController.php';
require_once dirname(__DIR__) . '/app/Http/Router.php';

dispatch_request();
