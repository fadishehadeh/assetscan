<?php
session_start();
session_destroy();
header('Location: /asset-manager/index.php');
exit;
