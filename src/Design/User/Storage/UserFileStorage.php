<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Nette\Utils\FileSystem;
use Nette\Utils\Finder;

final class UserFileStorage implements UserStorage
{

	/**
	 * @var string
	 */
	private $basePath;

	/**
	 * @param string $basePath
	 */
	public function __construct(string $basePath)
	{
		$this->basePath = $basePath;
	}

	/**
	 * @param string $id
	 * @return User
	 * @throws UserManagerException
	 */
	public function load(string $id): User
	{
		$this->processBasePath();
		$path = $this->basePath . '/' . $id . '.json';

		if (\is_file($path) === false) {
			UserManagerException::userDoesNotExist($id);
		}

		return new User($id, json_decode(FileSystem::read($path), true));
	}

	/**
	 * @param string|null $primaryRole
	 * @param mixed[] $data
	 * @return User
	 * @throws UserManagerException
	 */
	public function create(?string $primaryRole = null, array $data = []): User
	{
		$this->processBasePath();

		do {
			$id = Helpers::uuid();
			$path = $this->basePath . '/' . $id . '.json';
		} while (is_file($path) === true);

		$data['id'] = $id;

		if ($primaryRole !== null) {
			$data['roles'] = [$primaryRole];
		}

		FileSystem::write($path, json_encode($data, JSON_PRETTY_PRINT));

		return $this->load($id);
	}

	/**
	 * @param User $user
	 * @param string $key
	 * @param mixed $value
	 */
	public function setValue(User $user, string $key, $value): void
	{
		$this->processBasePath();
		$path = $this->basePath . '/' . $user->getId() . '.json';
		$user->{$key} = $value;
		$data = array_merge(json_decode(FileSystem::read($path), true), $user->getData());
		FileSystem::write($path, json_encode($data, JSON_PRETTY_PRINT));
	}

	/**
	 * @param mixed $haystack
	 * @param string|null $key
	 * @param int $limit
	 * @return User[]
	 * @throws UserManagerException
	 */
	public function findByValue($haystack, ?string $key = null, int $limit = 10): array
	{
		$this->processBasePath();
		$return = [];

		foreach (Finder::find('*.json')->in($this->basePath) as $path => $info) {
			$data = json_decode(FileSystem::read($path), true);

			if ($key === null) {
				foreach ($data as $value) {
					if ($value === $haystack) {
						$return[] = $this->load($data['id']);
						break;
					}
				}
			} elseif (isset($data[$key]) === true) {
				$return[] = $this->load($data['id']);
			}

			if (\count($return) >= $limit) {
				break;
			}
		}

		return $return;
	}

	private function processBasePath(): void
	{
		static $checked = false;

		if ($checked === false) {
			FileSystem::createDir($this->basePath);
			$this->basePath = rtrim($this->basePath, '/');
			$checked = true;
		}
	}

}