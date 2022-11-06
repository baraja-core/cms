<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\Shorts;
use Baraja\AdminBar\User\User;

final class AdminBarUser implements User
{
	public function __construct(
		private \Baraja\CAS\User $user,
	) {
	}


	public function getName(): ?string
	{
		$name = trim($this->user->getIdentity()?->getName() ?? '');

		return $name !== '' ? Shorts::process($name, 16) : null;
	}


	public function isAdmin(): bool
	{
		return true;
	}


	public function getAvatarUrl(): ?string
	{
		return $this->user->getIdentity()?->getAvatarUrl();
	}


	public function isLoggedIn(): bool
	{
		return $this->user->isLoggedIn();
	}
}
