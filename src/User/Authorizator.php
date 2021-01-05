<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\Cms\User\Entity\User;
use Nette\Security\Authorizator as NetteAuthorizator;
use Nette\Security\UserStorage;

final class Authorizator implements NetteAuthorizator
{
	public const ALIASES = ['default' => 'list', 'detail' => 'overview'];

	private UserStorage $userStorage;

	/** @var mixed[][] */
	private array $map;


	/**
	 * @param mixed[][] $map
	 */
	public function __construct(UserStorage $userStorage, array $map)
	{
		$this->userStorage = $userStorage;
		$this->map = $map;
	}


	public function isAllowed($role, $resource, $privilege): bool
	{
		if (\is_string($role) === false) {
			throw new \RuntimeException('Role must be a string.');
		}
		if (\is_string($resource) === false) {
			throw new \RuntimeException('Resource (or plugin name) must be a string.');
		}
		if ($role === 'admin') { // Admin is superuser
			return true;
		}

		return $this->strictAllowed((string) $role, (string) $resource, $privilege);
	}


	/**
	 * @return mixed[][]
	 * @internal for API
	 */
	public function getMap(): array
	{
		return $this->map;
	}


	private function strictAllowed(string $role, string $plugin, ?string $privilege = null): bool
	{
		if (isset($this->map[$plugin]) === false) {
			return false;
		}

		$privilege = $this->normalizePrivilege($privilege);
		if (\in_array($role, $this->map[$plugin]['roles'], true)) { // is user in allowed role?
			foreach ($this->map[$plugin]['privileges'] as $privilegeItem) {
				if ($privilegeItem['name'] === $privilege) {
					return true;
				}
				if (isset(self::ALIASES[$privilege]) === true && $privilegeItem['name'] === self::ALIASES[$privilege]) {
					return true;
				}
			}

			return false;
		}

		return $this->checkPrivilege($plugin, $privilege);
	}


	private function checkPrivilege(string $plugin, string $privilege): bool
	{
		$user = $this->userStorage->getState()[1];

		if (!$user instanceof User) {
			return false;
		}

		return \in_array($plugin . '_' . $privilege, $user->getPrivileges(), true);
	}


	/**
	 * Convert given privilege to privilege name which should be checked.
	 * Default and detail privilege is allowed in case of user can show "list".
	 */
	private function normalizePrivilege(?string $privilege): string
	{
		if (($privilege = $privilege ?? 'list') === 'default' || $privilege === 'detail') {
			return 'list';
		}

		return (string) preg_replace_callback('/[A-Z0-9]/', static function (array $match): string {
			return '-' . strtolower($match[0]);
		}, $privilege);
	}
}
