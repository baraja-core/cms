<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\Cms\Announcement\Entity\Announcement;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\UserManager;
use Baraja\Doctrine\EntityManager;
use Baraja\Localization\Localization;
use Baraja\StructuredApi\BaseEndpoint;

final class CmsDashboardEndpoint extends BaseEndpoint
{
	public function __construct(
		private EntityManager $entityManager,
		private UserManager $userManager,
		private Localization $localization,
	) {
	}


	public function actionFeed(): void
	{
		$this->sendJson(
			[
				'feed' => $this->entityManager->getRepository(Announcement::class)
					->createQueryBuilder('topic')
					->select('PARTIAL topic.{id, pinned, message, showSince}')
					->addSelect('PARTIAL user.{id, username}')
					->leftJoin('topic.user', 'user')
					->where('topic.parent IS NULL')
					->andWhere('topic.active = TRUE')
					->andWhere('topic.showSince >= :now')
					->andWhere('topic.showUntil < :now OR topic.showUntil IS NULL')
					->setParameter('now', date('Y-m-d') . ' 00:00:00')
					->orderBy('topic.pinned', 'DESC')
					->addOrderBy('topic.showSince', 'DESC')
					->getQuery()
					->getArrayResult(),
			],
		);
	}


	public function postPostTopic(string $message): void
	{
		try {
			$topic = new Announcement(
				user: $this->getUserIdentity(),
				locale: $this->localization->getLocale(),
				message: $message,
			);
		} catch (\InvalidArgumentException $e) {
			$this->flashMessage($e->getMessage());
			$this->sendError($e->getMessage());
		}

		$topic->setActive();
		$this->entityManager->persist($topic);
		$this->entityManager->flush();
		$this->sendOk();
	}


	private function getUserIdentity(): User
	{
		$identity = $this->userManager->getCmsIdentity();
		assert($identity instanceof User);

		return $identity;
	}
}
