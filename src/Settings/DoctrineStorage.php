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
		/** @var array<int, array{id: int, key: string, value: string}> $data */
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
		/** @var array<int, array{id: int, value: string|null}> $option */
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
	 * @return array<string, string>
	 */
	public function getMultiple(array $keys): array
	{
		if ($keys === []) {
			return [];
		}

		/** @var array<int, array{key: string, value: string}> $options */
		$options = $this->entityManager->getRepository(Option::class)
			->createQueryBuilder('option')
			->select('PARTIAL option.{id, key, value}')
			->where('option.key IN (:keys)')
			->setParameter('keys', $keys)
			->getQuery()
			->getArrayResult();

		$return = [];
		foreach ($options as $option) {
			$return[$option['key']] = $option['value'];
		}

		return $return;
	}


	public function save(string $key, string $value): void
	{
		try {
			$option = $this->getOptionEntity($key);
		} catch (NoResultException | NonUniqueResultException) {
			$option = new Option($key, $value);
			$this->entityManager->persist($option);
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
			throw new \RuntimeException($e->getMessage(), 500, $e);
		}
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	private function getOptionEntity(string $key): Option
	{
		$option = $this->entityManager->getRepository(Option::class)
			->createQueryBuilder('option')
			->where('option.key = :key')
			->setParameter('key', $key)
			->setMaxResults(1)
			->getQuery()
			->getSingleResult();
		assert($option instanceof Option);

		return $option;
	}
}
