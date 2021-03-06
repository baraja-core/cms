<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Baraja\Doctrine\Identifier\IdentifierUnsigned;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[ORM\Entity]
#[ORM\Table(name: 'cms__user_meta')]
#[UniqueConstraint(name: 'cms__user_meta_user_key', columns: ['user_id', 'key'])]
class UserMeta
{
	use IdentifierUnsigned;

	#[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'metas')]
	private User $user;

	#[ORM\Column(name: '`key`', type: 'string', length: 64)]
	private string $key;

	#[ORM\Column(name: '`value`', type: 'text', nullable: true)]
	private ?string $value;


	public function __construct(User $user, string $key, ?string $value)
	{
		$this->user = $user;
		$this->key = $key;
		$this->value = trim($value ?? '') ?: null;
	}


	public function getUser(): User
	{
		return $this->user;
	}


	public function getKey(): string
	{
		return $this->key;
	}


	public function getValue(): ?string
	{
		return $this->value;
	}


	public function setValue(?string $value): void
	{
		$this->value = $value;
	}
}
