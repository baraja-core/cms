<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\CAS\User;

final class MenuAuthorizator
{
	private const AlwaysAllowed = ['homepage' => true, 'cms' => true, 'error' => true];

	/** @var array<string, int> */
	private array $roles;


	public function __construct(User $userService)
	{
		$this->roles = array_flip($userService->getIdentity()?->getRoles() ?? []);
	}


	/**
	 * @return array<int, string>
	 */
	public function getRoles(): array
	{
		return array_keys($this->roles);
	}


	public function isAllowedPlugin(string $name): bool
	{
		return isset($this->roles['admin']) || isset(self::AlwaysAllowed[$name]);
	}


	public function isAllowedComponent(string $pluginName, string $componentName): bool
	{
		return isset($this->roles['admin'])
			|| isset(self::AlwaysAllowed[$pluginName])
			|| $this->isAllowedPlugin($pluginName);
	}
}
