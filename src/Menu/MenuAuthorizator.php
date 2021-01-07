<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\User\UserManagerAccessor;
use Baraja\Doctrine\EntityManager;
use Nette\Security\User as NetteUser;

final class MenuAuthorizator
{
	private const ALWAYS_ALLOWED = ['homepage' => true, 'cms' => true, 'error' => true];

	private ?string $id;

	/** @var int[] */
	private array $roles;

	/** @var int[] */
	private array $privileges;


	public function __construct(EntityManager $entityManager, NetteUser $userService, UserManagerAccessor $userManager)
	{
		if ($userService->isLoggedIn() === false) {
			$this->id = null;
			$this->roles = [];
			$this->privileges = [];

			return;
		}

		/** @var mixed[] $user */
		$user = $entityManager->getRepository($userManager->get()->getDefaultEntity())
			->createQueryBuilder('user')
			->select('PARTIAL user.{id, roles, privileges}')
			->where('user.id = :id')
			->setParameter('id', (string) $userService->getId())
			->setMaxResults(1)
			->getQuery()
			->getArrayResult();

		if (isset($user[0])) {
			$this->id = (string) ($user[0]['id'] ?? '');
			$this->roles = array_flip($user[0]['roles'] ?? []);
			$this->privileges = array_flip($user[0]['privileges'] ?? []);
		} else {
			throw new \InvalidArgumentException('User does not exist or is not logged in.');
		}
	}


	public function getId(): ?string
	{
		return $this->id;
	}


	/**
	 * @return string[]
	 */
	public function getRoles(): array
	{
		return $this->roles;
	}


	/**
	 * @return string[]
	 */
	public function getPrivileges(): array
	{
		return $this->privileges;
	}


	public function isAllowedPlugin(string $name): bool
	{
		return isset($this->roles['admin']) || isset(self::ALWAYS_ALLOWED[$name]) || isset($this->privileges['plugin-' . $name]);
	}


	public function isAllowedComponent(string $pluginName, string $componentName): bool
	{
		return isset($this->roles['admin']) || isset(self::ALWAYS_ALLOWED[$pluginName]) || ($this->isAllowedPlugin($pluginName) && isset($this->privileges['component-' . $componentName]));
	}


	public function isAdmin(): bool
	{
		return isset($this->roles['admin']);
	}
}
