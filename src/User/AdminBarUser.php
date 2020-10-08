<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\User;

final class AdminBarUser implements User
{
	public function getName(): ?string
	{
		return 'Baraja';
	}


	public function isAdmin(): bool
	{
		return true;
	}


	public function getAvatarUrl(): ?string
	{
		return null;
	}


	public function isLoggedIn(): bool
	{
		return true;
	}
}
