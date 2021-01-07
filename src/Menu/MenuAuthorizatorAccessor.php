<?php

declare(strict_types=1);

namespace Baraja\Cms;


interface MenuAuthorizatorAccessor
{
	public function get(): MenuAuthorizator;
}
