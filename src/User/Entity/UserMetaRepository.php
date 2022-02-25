<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class UserMetaRepository extends EntityRepository
{
	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function load(int $userId, string $key): UserMeta
	{
		$meta = $this->createQueryBuilder('meta')
			->where('meta.user = :userId')
			->andWhere('meta.key = :key')
			->setParameter('userId', $userId)
			->setParameter('key', $key)
			->setMaxResults(1)
			->getQuery()
			->getSingleResult();
		assert($meta instanceof UserMeta);

		return $meta;
	}


	/**
	 * @return array<int, UserMeta>
	 */
	public function loadAll(int $userId): array
	{
		/** @var array<int, UserMeta> $metas */
		$metas = $this->createQueryBuilder('meta')
			->select('meta, PARTIAL user.{id}')
			->join('meta.user', 'user')
			->where('meta.user = :userId')
			->setParameter('userId', $userId)
			->getQuery()
			->getResult();

		return $metas;
	}


	/**
	 * @param array<int, int> $userIds
	 * @param array<int, string> $keys
	 * @return array<int, array<string, string>>
	 */
	public function loadByUsersAndKeys(array $userIds, array $keys = []): array
	{
		$selection = $this->createQueryBuilder('meta')
			->select('PARTIAL meta.{id, user, key, value}')
			->addSelect('PARTIAL user.{id}')
			->join('meta.user', 'user')
			->where('user.id IN (:ids)')
			->setParameter('ids', $userIds);

		if ($keys !== []) {
			$selection->andWhere('meta.key IN (:keys)')
				->setParameter('keys', $keys);
		}

		/** @var array<int, array{user: array{id: int}, key: string, value: string|null}> $metas */
		$metas = $selection->getQuery()->getArrayResult();

		$return = [];
		foreach ($metas as $meta) {
			$key = $meta['key'];
			$value = $meta['value'] ?? '';
			$return[$meta['user']['id']][$key] = $value;
		}

		return $return;
	}
}
