<?php
require_once __DIR__ . '/includes/bootstrap.php';
logoutUser();
header('Location: login.php');
exit;
