<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\CAS\User;
use Baraja\Cms\Announcement\Entity\Announcement;
use Baraja\Cms\Announcement\Entity\AnnouncementRepository;
use Baraja\Localization\Localization;
use Baraja\StructuredApi\BaseEndpoint;
use Doctrine\ORM\EntityManagerInterface;

final class CmsDashboardEndpoint extends BaseEndpoint
{
	private AnnouncementRepository $repository;


	public function __construct(
		private EntityManagerInterface $entityManager,
		private User $user,
		private Localization $localization,
	) {
		$repository = $this->entityManager->getRepository(Announcement::class);
		assert($repository instanceof AnnouncementRepository);
		$this->repository = $repository;
	}


	public function actionFeed(): void
	{
		$this->sendJson([
			'feed' => $this->repository->getFeed(),
		]);
	}


	public function postPostTopic(string $message, ?int $parentId = null): void
	{
		$identity = $this->user->getIdentityEntity();
		assert($identity !== null);

		$parent = null;
		if ($parentId !== null) {
			$parent = $this->repository->find($parentId);
			assert($parent instanceof Announcement);
		}

		$topic = new Announcement(
			user: $identity,
			locale: $this->localization->getLocale(),
			message: $message,
			parent: $parent,
		);

		$topic->setActive();
		$this->entityManager->persist($topic);
		$this->entityManager->flush();
		$this->sendOk();
	}
}
