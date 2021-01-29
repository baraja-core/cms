<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\AdminBar\AdminIdentity;
use Baraja\AdminBar\Shorts;
use Baraja\AdminBar\User;
use Nette\Security\IIdentity;

final class AdminBarUser implements User
{
	private ?IIdentity $identity;


	public function __construct(?IIdentity $identity)
	{
		$this->identity = $identity;
	}


	public function getName(): ?string
	{
		if ($this->identity === null) {
			return null;
		}
		$name = null;
		if ($this->identity instanceof AdminIdentity) {
			$name = $this->identity->getName();
			if ($name === null) {
				$name = 'Admin';
			}
		} elseif (method_exists($this->identity, 'getName')) {
			$name = (string) $this->identity->getName() ?: null;
		}

		return $name ? Shorts::process($name, 16) : null;
	}


	public function isAdmin(): bool
	{
		return true;
	}


	public function getAvatarUrl(): ?string
	{
		if ($this->identity === null) {
			return null;
		}
		if ($this->identity instanceof AdminIdentity) {
			return $this->identity->getAvatarUrl();
		}

		return null;
	}


	public function isLoggedIn(): bool
	{
		return $this->identity !== null;
	}
}
