<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Baraja\Network\Ip;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cms__user_login')]
class UserLogin
{
	#[ORM\Id]
	#[ORM\Column(type: 'integer', unique: true, options: ['unsigned' => true])]
	#[ORM\GeneratedValue]
	protected int $id;

	#[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'logins')]
	private User $user;

	#[ORM\Column(type: 'string', length: 39, nullable: true)]
	private string $ip;

	#[ORM\Column(type: 'string', length: 128, nullable: true)]
	private ?string $hostname;

	#[ORM\Column(type: 'string', length: 255, nullable: true)]
	private ?string $userAgent;

	#[ORM\Column(type: 'datetime')]
	private \DateTimeInterface $loginDatetime;


	public function __construct(User $user)
	{
		$this->user = $user;
		$this->ip = Ip::get();
		$this->hostname = $_SERVER['HTTP_HOST'] ?? null;
		$this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
		$this->loginDatetime = new \DateTime;
	}


	public function getId(): int
	{
		return $this->id;
	}


	public function getUser(): User
	{
		return $this->user;
	}


	public function getIp(): string
	{
		return $this->ip;
	}


	public function getHostname(): ?string
	{
		return $this->hostname;
	}


	public function getUserAgent(): ?string
	{
		return $this->userAgent;
	}


	public function getLoginDatetime(): \DateTimeInterface
	{
		return $this->loginDatetime;
	}
}
