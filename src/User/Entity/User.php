<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Baraja\Cms\Helpers;
use Baraja\Doctrine\UUID\UuidIdentifier;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Nette\Security\Passwords;
use Nette\Utils\DateTime;
use Nette\Utils\Strings;
use Nette\Utils\Validators;

/**
 * =Name System user
 *
 * This is basic user table definition for all packages.
 * Current structure is final and developer can not add new columns.
 * If you want add new column, it will be used in all our projects.
 *
 * How to store new specific data?
 *
 * 1. Scalar values set to $data array section with namespace.
 * 2. For complex values create new Doctrine entity with relation here.
 *
 * @ORM\Entity()
 * @ORM\Table(
 *    name="cms__user",
 *    indexes={
 *       @Index(name="core__user_active", columns={"active"})
 *    }
 * )
 */
class User implements CmsUser
{
	use UuidIdentifier;

	/** @ORM\Column(type="string", length=64, unique=true) */
	private string $username;

	/**
	 * User real password stored as BCrypt hash.
	 * More info on https://php.baraja.cz/hashovani
	 *
	 * @ORM\Column(type="string", length=60)
	 */
	private string $password;

	/** @ORM\Column(type="string", length=32, nullable=true) */
	private ?string $firstName;

	/** @ORM\Column(type="string", length=32, nullable=true) */
	private ?string $lastName;

	/** @ORM\Column(type="string", nullable=true, length=50, unique=true) */
	private ?string $nick;

	/** @ORM\Column(type="string", length=128, unique=true) */
	private string $email;

	/**
	 * @var string[]
	 * @ORM\Column(type="json")
	 */
	private array $emails = [];

	/**
	 * @var string[]|null
	 * @ORM\Column(type="json")
	 */
	private ?array $roles = [];

	/**
	 * Super fast storage of given permissions.
	 * When we assign a specific role to a user, we automatically insert all his rights as a simple array.
	 *
	 * @var string[]|null
	 * @ORM\Column(type="json")
	 */
	private ?array $privileges = [];

	/** @ORM\Column(type="string", length=16, nullable=true) */
	private ?string $phone;

	/** @ORM\Column(type="string", length=39) */
	private string $registerIp;

	/** @ORM\Column(type="datetime") */
	private \DateTime $registerDate;

	/** @ORM\Column(type="datetime") */
	private \DateTime $createDate;

	/** @ORM\Column(type="boolean") */
	private bool $active = true;

	/**
	 * @var UserMeta[]|Collection
	 * @ORM\OneToMany(targetEntity="UserMeta", mappedBy="user")
	 */
	private $metas;

	/** @ORM\Column(type="string", nullable=true) */
	private ?string $avatarUrl;

	/**
	 * @var UserLogin[]|Collection
	 * @ORM\OneToMany(targetEntity="UserLogin", mappedBy="user")
	 */
	private $logins;

	/**
	 * @var UserLoginAttempt[]|Collection
	 * @ORM\OneToMany(targetEntity="UserLoginAttempt", mappedBy="user")
	 */
	private $loginAttempts;

	/**
	 * @var UserResetPasswordRequest[]|Collection
	 * @ORM\OneToMany(targetEntity="UserResetPasswordRequest", mappedBy="user")
	 */
	private $passwordResets;

	/**
	 * @var string|resource|null
	 * @ORM\Column(type="binary", nullable=true)
	 */
	private $otpCode;


	public function __construct(string $username, string $password, string $email, string $role = CmsUser::ROLE_USER)
	{
		$this->username = trim(Strings::lower($username));
		$this->password = $password
			? (new Passwords)->hash($password)
			: '---empty-password---';
		$this->setEmail($email);
		$this->addRole(trim($role));
		$this->registerIp = Helpers::userIp();
		$this->registerDate = DateTime::from('now');
		$this->createDate = DateTime::from('now');
		$this->metas = new ArrayCollection;
		$this->logins = new ArrayCollection;
		$this->loginAttempts = new ArrayCollection;
		$this->passwordResets = new ArrayCollection;
	}


	public function __toString(): string
	{
		return $this->getName();
	}


	/**
	 * @return string[]
	 */
	public function getRoles(): array
	{
		return $this->roles ?? [];
	}


	public function isAdmin(): bool
	{
		return $this->containRole('admin');
	}


	public function containRole(string $role): bool
	{
		foreach ($this->getRoles() as $roleItem) {
			if ($roleItem === $role) {
				return true;
			}
		}

		return false;
	}


	public function containPrivilege(string $privilege): bool
	{
		foreach ($this->getPrivileges() as $privilegeItem) {
			if ($privilegeItem === $privilege) {
				return true;
			}
		}

		return false;
	}


	/**
	 * @return string[]
	 */
	public function getData(): array
	{
		return $this->getMetaData();
	}


	public function getSalutation(): ?string
	{
		if (trim($name = $this->getName()) !== '') {
			return Strings::firstUpper($name); // TODO: Currently not supported
		}

		return null;
	}


	public function getFirstName(): ?string
	{
		return $this->firstName;
	}


	public function setFirstName(?string $firstName): void
	{
		$this->firstName = $firstName;
	}


	public function getLastName(): ?string
	{
		return $this->lastName;
	}


	public function setLastName(?string $lastName): void
	{
		$this->lastName = $lastName;
	}


	public function getUsername(): string
	{
		return $this->username;
	}


	public function setUsername(string $username): void
	{
		$this->username = $username;
	}


	public function getPassword(): string
	{
		return $this->password;
	}


	public function setPassword(string $password): void
	{
		if (trim($password) === '') {
			throw new \InvalidArgumentException('User (id: "' . $this->getId() . '") password can not be empty.');
		}

		$this->password = (new Passwords)->hash($password);
	}


	/**
	 * Set password as legacy MD5/SHA1 or other crypt.
	 * Never store passwords in a readable form!
	 *
	 * @param string $password
	 * @internal never use it for new users! Back compatibility only!
	 */
	public function setLegacyRawPassword(string $password): void
	{
		$this->password = $password ?: '---empty-password---';
		throw new \RuntimeException('The password was passed unsafely. Please catch this exception if it was intended.');
	}


	public function passwordVerify(string $password): bool
	{
		return (new Passwords)->verify($password, $this->password)
			|| md5($password) === $this->password
			|| sha1(md5($password)) === $this->password;
	}


	final public function resetRoles(): void
	{
		$this->roles = [];
	}


	public function addRole(string $role): void
	{
		if ($this->roles === null) {
			$this->roles = [];
		}
		$this->roles[] = strtolower($role);
		$this->roles = \array_unique($this->roles);
	}


	public function resetPrivileges(): void
	{
		$this->privileges = [];
	}


	/**
	 * @return string[]
	 */
	public function getPrivileges(): array
	{
		return array_filter($this->privileges ?? [], fn (string $item): bool => trim($item) !== '');
	}


	public function addPrivilege(string $permission): void
	{
		if ($this->privileges === null) {
			$this->privileges = [];
		}
		$this->privileges[] = $permission;
		$this->privileges = \array_unique($this->privileges);
	}


	/**
	 * Return primary user e-mail.
	 */
	public function getEmail(): string
	{
		return $this->email;
	}


	public function setEmail(string $email): void
	{
		if (Validators::isEmail($email) === false) {
			throw new \InvalidArgumentException('Invalid user email "' . $email . '".');
		}
		$this->email = $email;
	}


	/**
	 * @return string[]
	 */
	public function getEmails(): array
	{
		return array_unique(array_merge([$this->email], $this->emails));
	}


	public function addEmail(string $email): void
	{
		if (Validators::isEmail($email) === false) {
			throw new \InvalidArgumentException('Invalid user email "' . $email . '".');
		}
		if (\in_array($email, $this->emails, true) === true) {
			return;
		}
		array_unshift($this->emails, $email);
		$this->emails = \array_unique(array_values($this->emails));
	}


	/**
	 * Remove e-mail from user e-mail array. Return true if all was success.
	 *
	 * E-mail can not be removed if the user has none -> return false, because user must have at least one.
	 */
	public function removeEmail(string $email): bool
	{
		$newEmailList = [];
		foreach ($this->emails as $_email) {
			if ($_email !== $email) {
				$newEmailList[] = $_email;
			}
		}
		if ($newEmailList === []) {
			return false;
		}

		$this->emails = $newEmailList;

		return true;
	}


	public function getRegisterDate(): \DateTime
	{
		return $this->registerDate;
	}


	public function setRegisterDate(\DateTime $registerDate): void
	{
		$this->registerDate = $registerDate;
	}


	public function getCreateDate(): \DateTime
	{
		return $this->createDate;
	}


	public function setCreateDate(\DateTime $createDate): void
	{
		$this->createDate = $createDate;
	}


	/**
	 * @return string[]
	 */
	public function getMetaData(): array
	{
		$return = [];
		foreach ($this->metas as $meta) {
			if (($value = $meta->getValue()) !== null) {
				$return[$meta->getKey()] = $value;
			}
		}

		return $return;
	}


	/**
	 * @return UserLoginAttempt[]|Collection
	 */
	public function getLoginAttempts()
	{
		return $this->loginAttempts;
	}


	/**
	 * @return UserResetPasswordRequest[]|Collection
	 */
	public function getPasswordResets()
	{
		return $this->passwordResets;
	}


	public function addLogin(UserLogin $login): void
	{
		$this->logins[] = $login;
	}


	public function getOtpCode(): ?string
	{
		if ($this->otpCode === null) {
			return null;
		}
		if (is_resource($this->otpCode) === true) {
			return (string) stream_get_contents($this->otpCode);
		}

		return (string) $this->otpCode;
	}


	public function setOtpCode(?string $otpCode): void
	{
		$this->otpCode = $otpCode;
	}


	public function isActive(): bool
	{
		return $this->active;
	}


	public function setActive(bool $active): void
	{
		$this->active = $active;
	}


	public function getAvatarUrl(): ?string
	{
		return $this->avatarUrl;
	}


	public function setAvatarUrl(?string $avatarUrl): void
	{
		if ($avatarUrl !== null && Validators::isUrl($avatarUrl) === false) {
			throw new \InvalidArgumentException('Avatar URL "' . $avatarUrl . '" must be valid absolute URL.');
		}

		$this->avatarUrl = $avatarUrl;
	}


	public function getName(bool $reverse = false): string
	{
		if ($this->getFirstName() === null && $this->getLastName() === null) {
			return Strings::firstUpper((string) preg_replace('/^(.*)@.*$/', '$1', $this->getUsername()));
		}
		if (($name = $this->getFirstName() ?? '') || $this->getLastName() !== null) {
			$name = $reverse === true
				? $this->getLastName() . ($name !== '' ? ', ' : '') . $name
				: $name . ($name !== '' ? ' ' : '') . $this->getLastName();
		}

		return trim($name, ', ');
	}


	public function getPhone(): ?string
	{
		return $this->phone;
	}


	public function setPhone(?string $phone, int $region = 420): void
	{
		$this->phone = $phone ? Helpers::fixPhone($phone, $region) : null;
	}


	/**
	 * @return UserLogin[]|Collection
	 */
	public function getLogins()
	{
		return $this->logins;
	}


	public function getRegisterIp(): string
	{
		return $this->registerIp ?? '127.0.0.1';
	}


	public function setRegisterIp(string $registerIp): void
	{
		$this->registerIp = $registerIp;
	}
}
