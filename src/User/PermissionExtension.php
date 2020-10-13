<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\Cms\CmsExtension;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;

final class PermissionExtension extends CompilerExtension
{
	private const ALLOWED_CONFIG_KEYS = ['role' => 1, 'roles' => 1, 'privileges' => 1];


	/**
	 * @return string[]
	 */
	public static function mustBeDefinedAfter(): array
	{
		return [CmsExtension::class];
	}


	public function beforeCompile(): void
	{
		/** @var ServiceDefinition $authorizator */
		$authorizator = $this->getContainerBuilder()->getDefinitionByType(Authorizator::class);
		$authorizator->addSetup('?->setMap(?)', ['@self', $this->validatePermissions()]);
	}


	/**
	 * Check if permissions are in format:
	 *
	 * plugin: [
	 *    role: 'admin',
	 *    privileges:
	 *       - overview
	 *       -
	 *          name: history
	 *          description: User login history overview.
	 * ]
	 *
	 * @return mixed[]
	 */
	private function validatePermissions(): array
	{
		$return = [];

		foreach ($this->config as $resource => $config) {
			foreach (array_keys($config) as $configKey) {
				if (isset(self::ALLOWED_CONFIG_KEYS[$configKey]) === false) {
					throw new \RuntimeException('Permissions: Unexpected config key "' . $configKey . '". Did you mean "' . implode('", "', self::ALLOWED_CONFIG_KEYS) . '"?');
				}
			}
			if (\is_string($resource) === false) {
				throw new \RuntimeException('Resource "' . $resource . '" must be string.');
			}

			$return[$resource] = [
				'resource' => $resource,
				'roles' => array_unique(array_merge($this->formatRoles($config['role'] ?? []), $this->formatRoles($config['roles'] ?? []))),
				'privileges' => $this->formatPrivileges($config['privileges'] ?? []),
			];
		}

		return $return;
	}


	/**
	 * Accept role or roles in multiple formats:
	 * - 'admin' (string)
	 * - 'admin, moderator' (string)
	 * - ['admin', 'moderator'] (array of strings)
	 *
	 * @param string|string[]|mixed[] $haystack
	 * @return string[]
	 */
	private function formatRoles($haystack): array
	{
		if ($haystack === '' || $haystack === []) {
			return [];
		}

		$return = [];
		if (\is_string($haystack) === true) {
			foreach (explode(',', $haystack) as $role) {
				$return[] = trim($role);
			}
		} elseif (\is_array($haystack) === true) {
			foreach ($haystack as $role) {
				if (\is_string($role) === false) {
					throw new \RuntimeException('Role must be a string, but type "' . \gettype($role) . '" given.');
				}
				$return[] = trim($role);
			}
		} else {
			throw new \RuntimeException('Role must be a string or array of strings, but type "' . \gettype($haystack) . '" given.');
		}

		return $return;
	}


	/**
	 * Validate given privileges.
	 * Privilege must be string if snake-case format.
	 *
	 * @param string[]|mixed[] $privileges
	 * @return string[][]|null[][]
	 */
	private function formatPrivileges(array $privileges): array
	{
		$return = [];

		foreach ($privileges as $privilege) {
			if (\is_array($privilege) === true) {
				if (isset($privilege['name']) === true && \is_string($privilege['name']) === true) {
					$this->checkPrivilegeString($privilege['name']);
					$return[] = [
						'name' => $privilege['name'],
						'description' => trim($privilege['description'] ?? '') ?: null,
					];
				} else {
					throw new \RuntimeException('Privilege must define key "name" as non-empty string.');
				}
			} else {
				if (\is_string($privilege) === false) {
					throw new \RuntimeException('Privilege must be a string, but type "' . \gettype($privilege) . '" given.');
				}
				$this->checkPrivilegeString($privilege);
				$return[] = [
					'name' => $privilege,
					'description' => null,
				];
			}
		}

		return $return;
	}


	private function checkPrivilegeString(string $privilege): void
	{
		if ($privilege === '') {
			throw new \RuntimeException('Privilege can not be empty string.');
		}
		if (preg_match('/^([a-z]+)([A-Z])([a-z]*)$/', $privilege, $parser)) {
			throw new \RuntimeException('Privilege "' . $privilege . '" does not match valid format (can not use camelCase). Did you mean "' . $parser[1] . '-' . strtolower($parser[2]) . $parser[3] . '"?');
		}
		if (strtolower($privilege) !== $privilege) {
			throw new \RuntimeException('Privilege "' . $privilege . '" must use lower characters only. Did you mean "' . strtolower($privilege) . '"?');
		}
	}
}
