<?php

declare(strict_types=1);

namespace Baraja\Cms;


interface ContextAccessor
{
	public function get(): Context;
}
