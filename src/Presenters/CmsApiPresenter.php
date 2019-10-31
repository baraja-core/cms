<?php

declare(strict_types=1);

namespace App\AdminModule\Presenters;


use Baraja\Cms\Api\CmsApiManager;
use Baraja\Cms\Api\Endpoint\ApiEndpointException;
use Baraja\Cms\Api\Endpoint\Redirect;
use Nette\Application\AbortException;
use Nette\Application\Responses\VoidResponse;
use Nette\Application\UI\InvalidLinkException;

class CmsApiPresenter extends BasePresenter
{

	/**
	 * @var CmsApiManager
	 * @inject
	 */
	public $cmsApiManager;

	/**
	 * @throws AbortException
	 */
	public function beforeRender(): void
	{
		parent::beforeRender();
		$this->sendResponse(new VoidResponse);
	}

	/**
	 * @param string $package
	 * @param string|null $signal
	 * @throws AbortException
	 * @throws InvalidLinkException
	 */
	public function actionDefault(string $package, ?string $signal = null): void
	{
		try {
			$response = $this->cmsApiManager->process($package, $signal ?? 'default');
		} catch (Redirect $redirect) {
			$redirectData = $redirect->getData();
			$response = [];

			if (isset($redirectData['url'])) {
				$response['redirectUrl'] = $redirectData['url'];
			} elseif (isset($redirectData['destination'], $redirectData['args'])) {
				$response['redirectUrl'] = $this->link($redirectData['destination'], $redirectData['args']);
			}
		} catch (ApiEndpointException $e) {
			$this->sendJson([
				'error' => $e->getMessage(),
			]);

			return;
		}

		if ($response !== null) {
			if (array_key_exists('error', $response) && $response['error'] !== null) {
				$this->getHttpResponse()->setCode(500);
			} else {
				$response['error'] = null;
			}

			$this->sendJson($response);
		}

		$this->sendJson([
			'error' => null,
		]);
	}

}