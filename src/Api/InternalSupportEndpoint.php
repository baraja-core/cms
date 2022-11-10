<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\Cms\Support\Support;
use Baraja\StructuredApi\BaseEndpoint;
use Nette\Utils\Strings;
use Nette\Utils\Validators;

final class InternalSupportEndpoint extends BaseEndpoint
{
	public function __construct(
		private Support $support,
	) {
	}


	public function actionDefault(): void
	{
		$this->sendJson([
			'issues' => $this->support->getIssues(),
		]);
	}


	public function actionCreateIssueInfo(): void
	{
		$this->sendJson([
			'priorityList' => [
				$this->formatBootstrapSelectArray(Support::PriorityList),
			],
		]);
	}


	public function createIssue(
		string $subject,
		string $message,
		string $priority,
		?string $dueDate = null,
		?string $url = null,
	): void {
		if (isset(Support::PriorityList[$priority]) === false) {
			$this->sendError('Priority "' . $priority . '" does not exist. Did you mean "' . implode('", "', array_keys(Support::PriorityList)) . '"?');
		}
		if ($url !== null && Validators::isUrl($url) === false) {
			$this->sendError('URL is not valid. Haystack "' . $url . '" given.');
		}
		$dueDateReal = null;
		if ($dueDate !== null) {
			$dueDateReal = new \DateTime($dueDate);
			if ($dueDateReal->getTimestamp() <= time()) {
				$this->sendError('Ths issue due date cannot be in the past.');
			}
		}

		try {
			$this->support->createIssue(
				Strings::firstUpper($subject),
				trim(Strings::normalize($message)),
				$priority,
				$dueDateReal,
				$url,
			);
		} catch (\Throwable $e) {
			$this->sendError('Can not create new issue: ' . $e->getMessage());
		}
		$this->sendOk();
	}


	public function actionDetail(string $id): void
	{
		$this->sendJson($this->support->getIssue($id));
	}
}
