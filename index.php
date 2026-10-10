<?php
require_once __DIR__ . '/includes/bootstrap.php';
redirect(currentUser() ? 'dashboard.php' : 'login.php');
