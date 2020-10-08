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

	/** @var EntityManager */
	private $entityManager;


	public function __construct(EntityManager $entityManager)
	{
		$this->entityManager = $entityManager;
	}


	/**
	 * @return string[]
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
	 * @param string[] $keys
	 * @return string[]|null[]
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
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->entityManager->persist($option = new Option($key, $value));
		}

		$this->entityManager->flush($option->setValue($value));
	}


	public function remove(string $key): void
	{
		try {
			$option = $this->getOptionEntity($key);
		} catch (NoResultException | NonUniqueResultException $e) {
			return;
		}

		try {
			$this->entityManager->remove($option)->flush($option)->clear($option);
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
