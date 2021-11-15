<?php

declare(strict_types=1);

namespace Baraja\DoctrineConfiguration;


use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class OptionRepository extends EntityRepository
{
	/**
	 * @return array<int, array{id: int, key: string, value: string}>
	 */
	public function loadAll(): array
	{
		/** @var array<int, array{id: int, key: string, value: string}> $data */
		$data = $this->createQueryBuilder('option')
			->select('PARTIAL option.{id, key, value}')
			->getQuery()
			->getArrayResult();

		return $data;
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getAsEntity(string $key): Option
	{
		$option = $this->createQueryBuilder('option')
			->where('option.key = :key')
			->setParameter('key', $key)
			->setMaxResults(1)
			->getQuery()
			->getSingleResult();
		assert($option instanceof Option);

		return $option;
	}


	public function getAsArray(string $key): ?string
	{
		/** @var array<int, array{id: int, value: string|null}> $option */
		$option = $this->createQueryBuilder('option')
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
	 * @return array<int, array{key: string, value: string}>
	 */
	public function getMultiple(array $keys): array
	{
		/** @var array<int, array{key: string, value: string}> $options */
		$options = $this->createQueryBuilder('option')
			->select('PARTIAL option.{id, key, value}')
			->where('option.key IN (:keys)')
			->setParameter('keys', $keys)
			->getQuery()
			->getArrayResult();

		return $options;
	}


	public function isOptionExist(): bool
	{
		$count = $this->createQueryBuilder('option')
			->select('COUNT(option.id)')
			->getQuery()
			->getSingleScalarResult();

		return is_numeric($count) && $count > 0;
	}
}
