<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Doctrine\ORM\Mapping as ORM;
use Nette\Utils\Random;

#[ORM\Entity]
#[ORM\Table(name: 'cms__user_reset_password_request')]
class UserResetPasswordRequest
{
	#[ORM\Id]
	#[ORM\Column(type: 'integer', unique: true, options: ['unsigned' => true])]
	#[ORM\GeneratedValue]
	protected int $id;

	#[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'passwordResets')]
	private User $user;

	#[ORM\Column(type: 'string', length: 40, unique: true)]
	private string $token;

	#[ORM\Column(type: 'datetime')]
	private \DateTimeInterface $insertedDate;

	#[ORM\Column(type: 'datetime')]
	private \DateTimeInterface $expireDate;

	#[ORM\Column(type: 'boolean')]
	private bool $expired = false;


	public function __construct(User $user, string $expireTime = '30 minutes')
	{
		$this->user = $user;
		$this->token = Random::generate(40);
		$this->insertedDate = new \DateTime('now');
		$this->expireDate = new \DateTime(sprintf('now + %s', $expireTime));
	}


	public function __toString(): string
	{
		return $this->getToken();
	}


	public function getId(): int
	{
		return $this->id;
	}


	public function getUser(): User
	{
		return $this->user;
	}


	public function getToken(): string
	{
		return $this->token;
	}


	public function getExpireDate(): \DateTimeInterface
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


	public function getInsertedDate(): \DateTimeInterface
	{
		return $this->insertedDate;
	}
}
