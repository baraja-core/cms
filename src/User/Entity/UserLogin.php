<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Baraja\Cms\Helpers;
use Baraja\Doctrine\UUID\UuidIdentifier;
use Doctrine\ORM\Mapping as ORM;
use Nette\SmartObject;
use Nette\Utils\DateTime;

/**
 * @ORM\Entity()
 * @ORM\Table(name="cms__user_login")
 */
class UserLogin
{
	use UuidIdentifier;
	use SmartObject;

	/**
	 * @var User
	 * @ORM\ManyToOne(targetEntity="User", inversedBy="logins")
	 */
	private $user;

	/**
	 * @var string
	 * @ORM\Column(type="string", length=39, nullable=true)
	 */
	private $ip;

	/**
	 * @var string|null
	 * @ORM\Column(type="string", length=128, nullable=true)
	 */
	private $hostname;

	/**
	 * @var string|null
	 * @ORM\Column(type="string", nullable=true)
	 */
	private $userAgent;

	/**
	 * @var \DateTime
	 * @ORM\Column(type="datetime")
	 */
	private $loginDatetime;


	public function __construct(User $user)
	{
		$this->user = $user;
		$this->ip = Helpers::userIp();
		$this->hostname = $_SERVER['HTTP_HOST'] ?? null;
		$this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
		$this->loginDatetime = DateTime::from('now');
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


	public function getLoginDatetime(): \DateTime
	{
		return $this->loginDatetime;
	}
}
