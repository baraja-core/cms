<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Baraja\Doctrine\UUID\UuidIdentifier;
use Doctrine\ORM\Mapping as ORM;
use Nette\Utils\DateTime;
use Nette\Utils\Random;

/**
 * @ORM\Entity()
 * @ORM\Table(name="cms__user_reset_password_request")
 */
class UserResetPasswordRequest
{
	use UuidIdentifier;

	/** @ORM\ManyToOne(targetEntity="User", inversedBy="passwordResets") */
	private User $user;

	/** @ORM\Column(type="string", length=40, unique=true) */
	private string $token;

	/** @ORM\Column(type="datetime") */
	private \DateTime $insertedDate;

	/** @ORM\Column(type="datetime") */
	private \DateTime $expireDate;

	/** @ORM\Column(type="boolean") */
	private bool $expired = false;


	public function __construct(User $user, string $expireTime = '30 minutes')
	{
		$this->user = $user;
		$this->token = Random::generate(40);
		$this->insertedDate = DateTime::from('now');
		$this->expireDate = DateTime::from('now + ' . (trim($expireTime) ?: '30 minutes'));
	}


	public function __toString(): string
	{
		return $this->getToken();
	}


	public function getUser(): User
	{
		return $this->user;
	}


	public function getToken(): string
	{
		return $this->token;
	}


	public function getExpireDate(): \DateTime
	{
		return $this->expireDate;
	}


	public function isExpired(): bool
	{
		return $this->expired === true || $this->getExpireDate()->getTimestamp() < \time();
	}


	public function setExpired(): void
	{
		$this->expired = true;
	}
}
