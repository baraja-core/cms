<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Nette\Security\IIdentity;

class User implements IIdentity
{

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string[]
	 */
	private $roles = [];

	/**
	 * @var mixed[]
	 */
	private $data;

	/**
	 * @param string $id
	 * @param mixed[] $data
	 */
	public function __construct(string $id, array $data = [])
	{
		$this->id = $id;

		if (isset($data['roles']) === true) {
			$this->roles = $data['roles'];
			unset($data['roles']);
		}

		unset($data['id']);

		$this->data = $data;
	}

	/**
	 * @return string
	 */
	public function getId(): string
	{
		return $this->id;
	}

	/**
	 * @return mixed[]
	 */
	public function getRoles(): array
	{
		return $this->roles;
	}

	/**
	 * @return mixed[]
	 */
	public function getData(): array
	{
		return $this->data;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function &__get(string $key)
	{
		return $this->data[$key];
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 */
	public function __set(string $key, $value): void
	{
		$this->data[$key] = $value;
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public function __isset(string $key): bool
	{
		return isset($this->data[$key]);
	}

}