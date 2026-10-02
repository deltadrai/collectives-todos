<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
	$maps = [
		'OCA\\CollectiveTodos\\Tests\\' => __DIR__ . '/',
		'OCA\\CollectiveTodos\\' => __DIR__ . '/../lib/',
		'OCA\\Collectives\\' => '/var/www/html/custom_apps/collectives/lib/',
		'OCA\\Circles\\' => '/var/www/html/apps/circles/lib/',
	];

	foreach ($maps as $prefix => $base) {
		if (str_starts_with($class, $prefix)) {
			$path = str_replace('\\', '/', substr($class, strlen($prefix)));
			$file = $base . $path . '.php';
			if (file_exists($file)) {
				require $file;
				return;
			}
		}
	}
});
