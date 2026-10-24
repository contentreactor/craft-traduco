<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Models;

use ContentReactor\Traduco\Base\TranslatorInterface;
use Craft;
use craft\base\{
	ElementInterface,
	Model,
};
use craft\elements\User;
use craft\models\Site;
use DateTime;

/**
 * One translation, as it was asked for and as it went. A row per translation rather than per element and site, so an
 * element translated five times into one site has five of them.
 */
class TranslationRun extends Model
{
	public const STATUS_QUEUED = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE = 'done';
	public const STATUS_FAILED = 'failed';

	/** A whole element into another site, translated in the queue */
	public const KIND_ELEMENT = 'element';

	/** One field or attribute, translated and saved into the target site */
	public const KIND_FIELD = 'field';

	/** One field or attribute, translated and shown for an editor to copy rather than saved */
	public const KIND_TEXT = 'text';

	/** One field or attribute, rewritten in its own language and shown to copy. Billed like a translation. */
	public const KIND_REPHRASE = 'rephrase';

	public ?int $id = null;
	public ?int $elementId = null;
	/** @var class-string<ElementInterface>|null */
	public ?string $elementType = null;
	public ?int $siteId = null;
	public ?int $targetSiteId = null;
	/** @var self::KIND_* */
	public string $kind = self::KIND_ELEMENT;
	/** The field or attribute handle, null for a whole element */
	public ?string $attribute = null;
	/** @var self::STATUS_* */
	public string $status = self::STATUS_QUEUED;
	/**
	 * The translator's class as it was at the time, so a run still says what translated it after the setting changes
	 *
	 * @var class-string<TranslatorInterface>|null
	 */
	public ?string $translator = null;
	public ?string $error = null;
	/**
	 * How many characters were sent to the service, which is what it bills, or null where the translator doesn't
	 * count them. It's the text that went out, so a failed run still says what it spent.
	 */
	public ?int $characters = null;
	/** Who asked for it, kept until the user is deleted */
	public ?int $userId = null;
	public ?DateTime $dateCreated = null;
	public ?DateTime $dateUpdated = null;
	public ?string $uid = null;

	/**
	 * The element as the source site holds it, or null where it's gone or can't be read any more
	 */
	public function getElement(): ?ElementInterface
	{
		if ($this->elementId === null) return null;

		return Craft::$app->getElements()->getElementById($this->elementId, $this->elementType, $this->siteId);
	}

	public function getSite(): ?Site
	{
		return $this->siteId === null ? null : Craft::$app->getSites()->getSiteById($this->siteId, true);
	}

	public function getTargetSite(): ?Site
	{
		return $this->targetSiteId === null ? null : Craft::$app->getSites()->getSiteById($this->targetSiteId, true);
	}

	public function getUser(): ?User
	{
		return $this->userId === null ? null : Craft::$app->getUsers()->getUserById($this->userId);
	}

	/**
	 * What translated it, by the name the translator goes by, or its class where it isn't installed any more
	 */
	public function getTranslatorName(): ?string
	{
		if ($this->translator === null) return null;

		return is_a($this->translator, TranslatorInterface::class, true) ? $this->translator::displayName() : $this->translator;
	}

	public function isFinished(): bool
	{
		return $this->status === self::STATUS_DONE || $this->status === self::STATUS_FAILED;
	}
}
