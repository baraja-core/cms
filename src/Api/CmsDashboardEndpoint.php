<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\CAS\User;
use Baraja\Cms\Announcement\Entity\Announcement;
use Baraja\Cms\Announcement\Entity\AnnouncementRepository;
use Baraja\Localization\Localization;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\StructuredApi\Response\Status\ErrorResponse;
use Baraja\StructuredApi\Response\Status\OkResponse;
use Doctrine\ORM\EntityManagerInterface;

final class CmsDashboardEndpoint extends BaseEndpoint
{
	private AnnouncementRepository $repository;


	public function __construct(
		private EntityManagerInterface $entityManager,
		private User $userService,
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


	public function postPostTopic(string $message, ?int $parentId = null): OkResponse
	{
		$identity = $this->userService->getIdentityEntity();
		if ($identity === null) {
			ErrorResponse::invoke('User must be logged in.');
		}

		$topic = new Announcement(
			user: $identity,
			locale: $this->localization->getLocale(),
			message: $message,
			parent: $parentId !== null
				? $this->repository->getById($parentId)
				: null,
		);

		$topic->setActive();
		$this->entityManager->persist($topic);
		$this->entityManager->flush();

		return new OkResponse;
	}
}
