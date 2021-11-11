<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\Shorts;
use Baraja\AdminBar\User\AdminIdentity;
use Baraja\AdminBar\User\User;
use Nette\Security\IIdentity;

final class AdminBarUser implements User
{
	public function __construct(
		private \Nette\Security\User $user,
	) {
	}


	public function getName(): ?string
	{
		$identity = $this->getIdentity();
		if ($identity === null) {
			return null;
		}
		$name = null;
		if ($identity instanceof AdminIdentity) {
			$name = $identity->getName();
		} elseif (method_exists($identity, 'getName')) {
			$name = (string) $identity->getName();
		}

		return $name !== '' ? Shorts::process($name ?? 'Admin', 16) : null;
	}


	public function isAdmin(): bool
	{
		return true;
	}


	public function getAvatarUrl(): ?string
	{
		if ($this->getIdentity() === null) {
			return null;
		}
		if ($this->getIdentity() instanceof AdminIdentity) {
			return $this->getIdentity()->getAvatarUrl();
		}

		return null;
	}


	public function isLoggedIn(): bool
	{
		return $this->user->isLoggedIn();
	}


	private function getIdentity(): ?IIdentity
	{
		return $this->user->getIdentity();
	}
}
