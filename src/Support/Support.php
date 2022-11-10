<?php

declare(strict_types=1);

namespace Baraja\Cms\Support;


use Baraja\BarajaCloud\CloudManager;
use Nette\Http\Request;
use Nette\Http\UrlScript;

final class Support
{
	public const
		PriorityLow = 'low',
		PriorityNormal = 'normal',
		PriorityUrgent = 'urgent';

	public const PriorityList = [
		self::PriorityLow => 'Low (3 weeks)',
		self::PriorityNormal => 'Normal (7 days)',
		self::PriorityUrgent => 'Urgent (24 hours)',
	];

	private UrlScript $url;


	public function __construct(
		private CloudManager $cloudManager,
		Request $request,
	) {
		$this->url = $request->getUrl();
	}


	/**
	 * @return mixed[][]
	 */
	public function getIssues(): array
	{
		/** @phpstan-ignore-next-line */
		return $this->cloudManager->callRequest('cms-issue', [
			'domain' => $this->getDomain(),
		]);
	}


	public function createIssue(
		string $subject,
		string $message,
		string $priority,
		?\DateTime $dueDate = null,
		?string $url = null,
	): void {
		$this->cloudManager->callRequest('cms-issue', [
			'domain' => $this->getDomain(),
			'subject' => $subject,
			'message' => $message,
			'priority' => $priority,
			'dueDate' => $dueDate?->format(\DateTime::ATOM),
			'url' => $url,
		], 'POST');
	}


	/**
	 * @return mixed[]
	 */
	public function getIssue(string $id): array
	{
		return $this->cloudManager->callRequest('cms-issue/detail', [
			'id' => $id,
			'domain' => $this->getDomain(),
		]);
	}


	private function getDomain(): string
	{
		return str_replace('www.', '', $this->url->getDomain(4));
	}
}
