<?php
/* Bot tools landing — redirects to status */
require_once __DIR__ . '/_common.php';
botmgr_require_admin();
header('Location: status.php');
exit;
