<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Baraja\Doctrine\UUID\UuidIdentifier;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * @ORM\Entity()
 * @ORM\Table(
 *     name="cms__user_meta",
*      uniqueConstraints={
 *         @UniqueConstraint(name="cms__user_meta_user_key", columns={"user_id", "key"})
 *     },
 *     indexes={
 *         @Index(name="cms__user_meta_user_key_value", columns={"user_id", "key", "value"})
 *     }
 * )
 */
class UserMeta
{
	use UuidIdentifier;

	/** @ORM\ManyToOne(targetEntity="User", inversedBy="metas") */
	private User $user;

	/** @ORM\Column(type="string", name="`key`", length=64) */
	private string $key;

	/** @ORM\Column(type="text", name="`value`", nullable=true) */
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
