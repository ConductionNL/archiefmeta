<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Hooks;

interface Emitter {
	public function listen($scope, $method, callable $callback);

	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
