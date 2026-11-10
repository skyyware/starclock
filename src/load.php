<?php
declare(strict_types=1);

foreach (['Schedule', 'Adapter', 'Store', 'MailPoetAdapter', 'Engine', 'Admin', 'Diagnostics', 'Plugin'] as $starclockClass) {
    require_once __DIR__ . '/' . $starclockClass . '.php';
}
unset($starclockClass);
