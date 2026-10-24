<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Jobs;

use ContentReactor\Traduco\Base\BaseTranslator;
use ContentReactor\Traduco\Helpers\Parser;
use ContentReactor\Traduco\Models\{
	TranslatableElement,
	TranslationRun,
};
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\queue\BaseJob;
use Throwable;
use yii\queue\RetryableJobInterface;

class ElementTranslationJob extends BaseJob implements RetryableJobInterface
{
	/**
	 * How long the queue reserves this job for. Translating one attribute is the longest step, and every one of them
	 * reports progress, which refreshes the reservation, so a long element doesn't need a long reservation. Craft's
	 * own default is 300, and this is a little longer because one rich text field can be many calls to the API.
	 */
	public const TTR = 600;

	public int $elementId;
	public int $siteId;
	public int $targetSiteId;

	/**
	 * The user who asked for the translation. The queue doesn't carry one, and Craft::$app->getUser() is useless in a worker.
	 */
	public ?int $userId = null;

	/**
	 * The history row the control panel queued, which this job finishes, see Services\History
	 */
	public ?int $runId = null;

	public function getTtr(): int
	{
		return self::TTR;
	}

	/**
	 * A translation that reported an error isn't tried again: the element is half translated by then, and every call
	 * it already made has been billed. The error is on the job and in the history for someone to act on.
	 *
	 * @param int $attempt
	 * @param Throwable $error
	 */
	public function canRetry($attempt, $error): bool
	{
		return false;
	}

	public function execute($queue): void
	{
		$history = Plugin::getInstance()->getHistory();
		$notifications = Plugin::getInstance()->getNotifications();
		$run = $this->runId !== null ? $history->getRunById($this->runId) : null;
		$translatableElement = new TranslatableElement($this->elementId, $this->siteId, $this->targetSiteId);
		$source = $translatableElement->getElement();

		// Deleted since the job was queued
		if (!$source) {
			$error = Craft::t('traduco', 'The element was deleted before it could be translated.');
			$history->markFailed($run, $error);
			$notifications->queuedTranslationFailed($translatableElement, $this->userId, $error);

			return;
		}

		// A worker that died without reporting an error leaves its row reserved, and the queue hands the job back out
		// once the reservation runs out. Translating a second time would bill every call the dead worker already made
		// again, so a run that had already started is reported rather than run: its row is the only record that the
		// first attempt got as far as the API, as the queue itself only counts attempts it saw end.
		if ($run !== null && $run->status !== TranslationRun::STATUS_QUEUED) {
			if (!$run->isFinished()) {
				$error = Craft::t('traduco', 'The translation stopped before it finished and wasn’t run again, so the part that was already translated is saved and the rest isn’t. Translate the element again to finish it.');
				$history->markInterrupted($run, $error);
				$notifications->queuedTranslationFailed($translatableElement, $this->userId, $error);
			}

			return;
		}

		$history->markRunning($run);

		try {
			// Read before anything is translated, and from this instance rather than the database afterwards: a job
			// that waited in the queue while someone edited the source mustn't record the new text as translated
			$attributes = Parser::getTranslatableAttributes($source, $translatableElement->getTranslatedElement());

			$translator = Plugin::getInstance()->getTranslations()->getTranslator();
			$translator instanceof BaseTranslator
				? $translator->translateElement($translatableElement, $this->reportProgress($queue))
				: $translator->translateElement($translatableElement);
		} catch (Throwable $e) {
			// Recorded and reported, then rethrown so the queue still shows the job as failed
			$history->markFailed($run, $e);
			$notifications->queuedTranslationFailed($translatableElement, $this->userId, $e);

			throw $e;
		}

		$history->markDone($run);
		$notifications->queuedTranslationDone($translatableElement, $this->userId);

		// The translation reloaded rather than the copy just saved, so both sides of every later comparison are read
		// the same way. Null where the element can't live in the target site, as that path saves a separate copy with
		// an ID of its own which no later run could find again.
		Plugin::getInstance()->getFingerprints()->record(
			$source,
			$this->targetSiteId,
			TranslationRun::KIND_ELEMENT,
			$attributes,
			Craft::$app->getElements()->getElementById($this->elementId, $source::class, $this->targetSiteId),
		);
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('traduco', 'Translating element {id} into {site}', [
			'id' => $this->elementId,
			'site' => Craft::$app->getSites()->getSiteById($this->targetSiteId, true)?->getName() ?? $this->targetSiteId,
		]);
	}

	/**
	 * Reports each attribute as it's translated, which writes the queue row and so pushes the reservation out by
	 * another TTR. The share is worked out from the field layout, which is a count the translation itself doesn't
	 * have: it translates attributes and nested elements too, so the bar is held short of the end until the job is.
	 *
	 * @param \yii\queue\Queue|\craft\queue\QueueInterface $queue
	 * @return callable(string): void
	 */
	private function reportProgress(mixed $queue): callable
	{
		$element = Craft::$app->getElements()->getElementById($this->elementId, siteId: $this->siteId);
		$total = max(count($element?->getFieldLayout()?->getCustomFields() ?? []), 1);
		$done = 0;

		return function (string $attribute) use ($queue, $total, &$done): void {
			$done++;
			$this->setProgress($queue, min($done / $total, 0.95), Craft::t('traduco', 'Translating {attribute}', [
				'attribute' => $attribute,
			]));
		};
	}
}
