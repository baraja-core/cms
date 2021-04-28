<?php

declare(strict_types=1);

namespace Baraja\DoctrineConfiguration;


use Baraja\Doctrine\EntityManager;
use Baraja\DynamicConfiguration\Storage;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

/**
 * @internal
 */
final class DoctrineStorage implements Storage
{
	public function __construct(
		private EntityManager $entityManager,
	) {
	}


	/**
	 * @return array<string, string>
	 */
	public function loadAll(): array
	{
		$data = $this->entityManager->getRepository(Option::class)
			->createQueryBuilder('option')
			->select('PARTIAL option.{id, key, value}')
			->getQuery()
			->getArrayResult();

		$return = [];
		foreach ($data as $item) {
			$return[$item['key']] = $item['value'];
		}

		return $return;
	}


	public function get(string $key): ?string
	{
		$option = $this->entityManager->getRepository(Option::class)
			->createQueryBuilder('option')
			->select('PARTIAL option.{id, value}')
			->where('option.key = :key')
			->setParameter('key', $key)
			->setMaxResults(1)
			->getQuery()
			->getArrayResult();

		return isset($option[0]['value']) === true
			? $option[0]['value']
			: null;
	}


	/**
	 * @param array<int, string> $keys
	 * @return array<string, string|null>
	 */
	public function getMultiple(array $keys): array
	{
		if ($keys === []) {
			return [];
		}

		/** @var string[][] $options */
		$options = $this->entityManager->getRepository(Option::class)
			->createQueryBuilder('option')
			->select('PARTIAL option.{id, key, value}')
			->where('option.key IN (:keys)')
			->setParameter('keys', $keys)
			->getQuery()
			->getArrayResult();

		$return = [];
		foreach ($options as $option) {
			$return[$option['key']] = $option['value'] ?: null;
		}

		return $return;
	}


	public function save(string $key, string $value): void
	{
		try {
			$option = $this->getOptionEntity($key);
		} catch (NoResultException | NonUniqueResultException) {
			$this->entityManager->persist($option = new Option($key, $value));
		}
		$option->setValue($value);
		$this->entityManager->flush();
	}


	public function remove(string $key): void
	{
		try {
			$option = $this->getOptionEntity($key);
		} catch (NoResultException | NonUniqueResultException) {
			return;
		}

		try {
			$this->entityManager->remove($option);
			$this->entityManager->flush();
			$this->entityManager->clear($option);
		} catch (MappingException $e) {
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	private function getOptionEntity(string $key): Option
	{
		return $this->entityManager->getRepository(Option::class)
			->createQueryBuilder('option')
			->where('option.key = :key')
			->setParameter('key', $key)
			->setMaxResults(1)
			->getQuery()
			->getSingleResult();
	}
}
