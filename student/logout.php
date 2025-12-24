<?php
// student/logout.php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

unset($_SESSION['student']);
redirect('student/login.php');
