<?php

declare(strict_types=1);

// Redirect admin root to users list.
// Teams integration overview: /admin/teams.php
header('Location: /admin/users.php');
exit;
