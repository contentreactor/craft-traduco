<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Services;

use ContentReactor\Traduco\Base\BaseTranslator;
use ContentReactor\Traduco\Models\TranslationRun;
use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Records\TranslationRun as TranslationRunRecord;
use Craft;
use craft\base\{
	Component,
	ElementInterface,
};
use craft\helpers\DateTimeHelper;
use DateTime;
use Throwable;

/**
 * Keeps a row per translation: what was translated, between which sites, by whom and how it went. Rows are kept for as
 * many days as the settings say and pruned on garbage collection. Recording never gets in a translation's way: a row
 * that can't be written is logged and the translation goes on.
 */
class History extends Component
{
	/**
	 * Translation history comes with Pro, and nothing is recorded without it
	 */
	public function isEnabled(): bool
	{
		return Plugin::getInstance()->isPro();
	}

	/**
	 * Records a translation that's about to run. Null where nothing is recorded, which the rest of the methods here
	 * take, so a caller doesn't have to check.
	 *
	 * @param TranslationRun::KIND_* $kind
	 * @param string|null $attribute the field or attribute handle, null for a whole element
	 * @param int|null $userId who asked for it: the queue doesn't carry a user, so the job's payload does
	 */
	public function start(ElementInterface $element, int $targetSiteId, string $kind, ?string $attribute = null, ?int $userId = null): ?TranslationRun
	{
		if (!$this->isEnabled()) return null;

		$run = new TranslationRun([
			'elementId' => $element->id,
			'elementType' => $element::class,
			'siteId' => $element->siteId,
			'targetSiteId' => $targetSiteId,
			'kind' => $kind,
			'attribute' => $attribute,
			// Only a whole element waits in the queue, the rest are translated while the request is being answered
			'status' => $kind === TranslationRun::KIND_ELEMENT ? TranslationRun::STATUS_QUEUED : TranslationRun::STATUS_RUNNING,
			'translator' => Plugin::getInstance()->getSettings()->getUserSettings()->translator,
			'userId' => $userId,
		]);

		$this->getCountingTranslator()?->resetCharacterCount();

		return $this->save($run) ? $run : null;
	}

	public function markRunning(?TranslationRun $run): void
	{
		$this->setStatus($run, TranslationRun::STATUS_RUNNING);
	}

	public function markDone(?TranslationRun $run): void
	{
		$this->setStatus($run, TranslationRun::STATUS_DONE);
	}

	public function markFailed(?TranslationRun $run, Throwable|string $error): void
	{
		if ($run === null) return;

		$run->error = $error instanceof Throwable ? $error->getMessage() : $error;
		$this->setStatus($run, TranslationRun::STATUS_FAILED);
	}

	/**
	 * Records a run a dead worker left behind, which the queue handed out again. Not markFailed(): what the worker
	 * sent died with it, so the count is left unknown rather than reported as the nothing this process sent.
	 */
	public function markInterrupted(?TranslationRun $run, string $error): void
	{
		if ($run === null) return;

		$run->error = $error;
		$run->status = TranslationRun::STATUS_FAILED;
		$this->save($run);
	}

	public function getRunById(int $id): ?TranslationRun
	{
		$record = TranslationRunRecord::findOne(['id' => $id]);

		return $record === null ? null : $this->createModel($record);
	}

	/**
	 * The most recent runs first
	 *
	 * @return TranslationRun[]
	 */
	public function getRuns(int $limit = 50, int $offset = 0): array
	{
		/** @var TranslationRunRecord[] $records */
		$records = TranslationRunRecord::find()
			->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
			->limit($limit)
			->offset($offset)
			->all();

		return array_map($this->createModel(...), $records);
	}

	public function getTotal(): int
	{
		return (int)TranslationRunRecord::find()->count();
	}

	/**
	 * Deletes the runs older than the retention setting keeps them for
	 *
	 * @return int how many were deleted
	 */
	public function prune(): int
	{
		$days = Plugin::getInstance()->getSettings()->getUserSettings()->historyRetentionDays;
		if ($days <= 0) return 0;

		return TranslationRunRecord::deleteAll(
			['<', 'dateCreated', (new DateTime("-$days days"))->format('Y-m-d H:i:s')],
		);
	}

	public function save(TranslationRun $run): bool
	{
		try {
			$record = ($run->id !== null ? TranslationRunRecord::findOne(['id' => $run->id]) : null) ?? new TranslationRunRecord();
			$record->elementId = (int)$run->elementId;
			$record->elementType = (string)$run->elementType;
			$record->siteId = (int)$run->siteId;
			$record->targetSiteId = (int)$run->targetSiteId;
			$record->kind = $run->kind;
			$record->attribute = $run->attribute;
			$record->status = $run->status;
			$record->translator = $run->translator;
			$record->error = $run->error;
			$record->characters = $run->characters;
			$record->userId = $run->userId;
			$record->save(false);

			$run->id = $record->id;
			$run->uid = $record->uid;
			$run->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
			$run->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;

			return true;
		} catch (Throwable $e) {
			// An audit trail is worth less than the translation it's recording, so this never throws
			Craft::warning('Couldn’t record a translation: ' . $e->getMessage(), 'traduco');

			return false;
		}
	}

	private function setStatus(?TranslationRun $run, string $status): void
	{
		if ($run === null) return;

		$run->status = $status;

		// What a run sent is read off the translator rather than passed in by every caller, which keeps the count out
		// of the translating paths: the translator is one instance per request, and the count starts again as a run
		// does. A queued element starts its count in the worker, as the request that queued it sent nothing.
		if ($status === TranslationRun::STATUS_RUNNING) {
			$this->getCountingTranslator()?->resetCharacterCount();
		} else {
			$run->characters = $this->getCountingTranslator()?->getCharacterCount() ?? $run->characters;
		}

		$this->save($run);
	}

	/**
	 * The translator, where it counts the characters it sends. Null for one that doesn't, which a third-party
	 * translator needn't, and where none can be set up at all.
	 */
	private function getCountingTranslator(): ?BaseTranslator
	{
		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();

		return $translator instanceof BaseTranslator ? $translator : null;
	}

	private function createModel(TranslationRunRecord $record): TranslationRun
	{
		return new TranslationRun([
			'id' => $record->id,
			'elementId' => $record->elementId,
			'elementType' => $record->elementType,
			'siteId' => $record->siteId,
			'targetSiteId' => $record->targetSiteId,
			'kind' => $record->kind,
			'attribute' => $record->attribute,
			'status' => $record->status,
			'translator' => $record->translator,
			'error' => $record->error,
			'characters' => $record->characters,
			'userId' => $record->userId,
			'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
			'dateUpdated' => DateTimeHelper::toDateTime($record->dateUpdated) ?: null,
			'uid' => $record->uid,
		]);
	}
}
