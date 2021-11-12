<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[ORM\Entity]
#[ORM\Table(name: 'cms__user_meta')]
#[UniqueConstraint(name: 'cms__user_meta_user_key', columns: ['user_id', 'key'])]
class UserMeta
{
	#[ORM\Id]
	#[ORM\Column(type: 'integer', unique: true, options: ['unsigned' => true])]
	#[ORM\GeneratedValue]
	protected int $id;

	#[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'metas')]
	private User $user;

	#[ORM\Column(name: '`key`', type: 'string', length: 64)]
	private string $key;

	#[ORM\Column(name: '`value`', type: 'text', nullable: true)]
	private ?string $value;


	public function __construct(User $user, string $key, ?string $value)
	{
		$value = trim($value ?? '');
		$this->user = $user;
		$this->key = $key;
		$this->value = $value !== '' ? $value : null;
	}


	public function getId(): int
	{
		return $this->id;
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
