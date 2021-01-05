<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Nette\Security\IIdentity;

interface AuthenticationService
{
	public function authentication(string $username, string $password): IIdentity;
}
