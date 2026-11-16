<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../vendor/autoload.php';

// The nextcloud/ocp package ships the public API without an autoloader.
spl_autoload_register(static function (string $class): void {
	foreach (['OCP\\', 'NCU\\'] as $prefix) {
		if (str_starts_with($class, $prefix)) {
			$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
});

require_once __DIR__ . '/stubs/oca_files_events.php';
require_once __DIR__ . '/stubs/oc_hooks_emitter.php';
