<?php

declare(strict_types=1);

namespace Baraja\Cms\Announcement\Entity;


use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class AnnouncementRepository extends EntityRepository
{
	/**
	 * @return array<int, array{
	 *     id: int,
	 *     pinned: bool,
	 *     message: string,
	 *     showSince: \DateTime,
	 *     user: array{
	 *         id: int,
	 *         username: string
	 *     }
	 * }>
	 */
	public function getFeed(): array
	{
		/** @phpstan-ignore-next-line */
		return $this->createQueryBuilder('topic')
			->select('PARTIAL topic.{id, pinned, message, showSince}')
			->addSelect('PARTIAL user.{id, username}')
			->join('topic.user', 'user')
			->where('topic.parent IS NULL')
			->andWhere('topic.active = TRUE')
			->andWhere('topic.showSince >= :now')
			->andWhere('topic.showUntil < :now OR topic.showUntil IS NULL')
			->setParameter('now', date('Y-m-d') . ' 00:00:00')
			->orderBy('topic.pinned', 'DESC')
			->addOrderBy('topic.showSince', 'DESC')
			->getQuery()
			->getArrayResult();
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getById(int $id): Announcement
	{
		$announcement = $this->createQueryBuilder('topic')
			->where('topic.id = :id')
			->setParameter('id', $id)
			->setMaxResults(1)
			->getQuery()
			->getSingleResult();
		assert($announcement instanceof Announcement);

		return $announcement;
	}
}
