<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


interface UserManagerAccessor
{
	public function get(): UserManager;
}
