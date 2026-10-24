<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Services;

use ContentReactor\Traduco\Base\AttributeType;
use ContentReactor\Traduco\Helpers\Parser;
use ContentReactor\Traduco\Models\TranslationRun;
use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Records\TranslationFingerprint;
use Craft;
use craft\base\{
	Component,
	ElementInterface,
};
use craft\helpers\{
	ArrayHelper,
	Json,
};
use Throwable;

/**
 * Remembers what was translated, so the same text isn't paid for twice and a translation someone corrected isn't
 * overwritten without a word. A digest of the text that went in and the text that was written out, one row per
 * element, site pair and attribute.
 *
 * Deliberately not part of the translation history: this is a current state rather than a log, it outlives the
 * history's retention, and it's kept whatever the edition, because not overwriting someone's work isn't a paid
 * feature. Timestamps can't do this job: `elements.dateUpdated` is one row shared by every site, and Traduco's own
 * write into the target site moves it, so a source would always look newer than the run that just translated it.
 */
class Fingerprints extends Component
{
	/**
	 * The recipe the digests are made with. Bumping it makes every stored row incomparable rather than wrong, so a
	 * release that digests differently goes quiet for an element until it's translated again.
	 */
	public const VERSION = 1;

	/** The translation was written into the target site, so there's one of ours there to be overwritten */
	public const SCOPE_SAVED = 'saved';

	/** The translation was shown for an editor to copy, so nothing was written */
	public const SCOPE_SHOWN = 'shown';

	/** The value was rewritten in its own language and shown, so nothing was written */
	public const SCOPE_REPHRASED = 'rephrased';

	/**
	 * What a run did with the text, which is what the guard goes by. Translating a field and translating a whole
	 * element share one scope on purpose: an element translation followed by a hand correction has to be noticed by
	 * the field action menu too, which keying on the kind would miss.
	 *
	 * @param TranslationRun::KIND_* $kind
	 */
	public static function scopeForKind(string $kind): string
	{
		return match ($kind) {
			TranslationRun::KIND_TEXT => self::SCOPE_SHOWN,
			TranslationRun::KIND_REPHRASE => self::SCOPE_REPHRASED,
			default => self::SCOPE_SAVED,
		};
	}

	/**
	 * What an editor should be asked to confirm before this runs, or null where there's nothing worth asking about.
	 *
	 * @param TranslationRun::KIND_* $kind
	 * @param array<int, array{type: AttributeType, name: string}> $attributes see Parser::getTranslatableAttributes()
	 */
	public function check(ElementInterface $source, int $targetSiteId, string $kind, array $attributes): ?string
	{
		try {
			return $this->getWarning($source, $targetSiteId, $kind, $attributes);
		} catch (Throwable $e) {
			// A guard that can't make up its mind lets the translation through: it's here to save money and work,
			// and neither is worth refusing to translate over
			Craft::warning('Couldn’t check what was already translated: ' . $e->getMessage(), 'traduco');

			return null;
		}
	}

	/**
	 * Records what was just translated. Only ever called once it has succeeded: a run recorded on failure would
	 * claim pre-existing content as Traduco's own and silence the guard for it from then on.
	 *
	 * @param TranslationRun::KIND_* $kind
	 * @param array<int, array{type: AttributeType, name: string}> $attributes
	 * @param ElementInterface|null $target the saved translation, null where the run saved nothing
	 */
	public function record(ElementInterface $source, int $targetSiteId, string $kind, array $attributes, ?ElementInterface $target = null): void
	{
		try {
			$scope = self::scopeForKind($kind);
			$rows = $this->getRows($source, $targetSiteId, $scope);
			$optionsHash = $this->getOptionsHash($source, $targetSiteId);
			$userId = Craft::$app->getUser()->getId();

			foreach ($attributes as $attribute) {
				$sourceHash = Parser::getAttributeTextDigest($source, $attribute['type'], $attribute['name']);
				// Nothing was spent on an attribute with no text, and there's nothing of ours to overwrite
				if ($sourceHash === null) continue;

				$record = $rows[$attribute['name']] ?? new TranslationFingerprint();
				$record->elementId = (int)$source->id;
				$record->elementType = $source::class;
				$record->siteId = (int)$source->siteId;
				$record->targetSiteId = $targetSiteId;
				$record->scope = $scope;
				$record->attribute = $attribute['name'];
				$record->sourceHash = $sourceHash;
				$record->optionsHash = $optionsHash;
				$record->targetHash = $target === null
					? null
					: Parser::getAttributeTextDigest($target, $attribute['type'], $attribute['name']);
				$record->version = self::VERSION;
				$record->kind = $kind;
				$record->userId = $userId;
				$record->save(false);
			}
		} catch (Throwable $e) {
			// The same stance the history takes: bookkeeping is worth less than the translation it's recording
			Craft::warning('Couldn’t record what was translated: ' . $e->getMessage(), 'traduco');
		}
	}

	/**
	 * @param TranslationRun::KIND_* $kind
	 * @param array<int, array{type: AttributeType, name: string}> $attributes
	 */
	private function getWarning(ElementInterface $source, int $targetSiteId, string $kind, array $attributes): ?string
	{
		$scope = self::scopeForKind($kind);
		$traduco = Plugin::getInstance()->getTraduco();
		$rows = $this->getRows($source, $targetSiteId, $scope);

		// Where the element can't live in the target site, every run duplicates it into a copy with an ID of its own,
		// so there's never one of ours to compare against, see BaseTranslator::ensureTargetExists()
		$canExist = $traduco->canExistInSite($source, $targetSiteId);
		$target = $scope === self::SCOPE_SAVED && $canExist ? $traduco->getTargetElement($source, $targetSiteId) : null;
		$hasTarget = $target !== null && $target->id !== null && !$target->isNewForSite;

		// The translation is gone: the element has been taken out of the target site since, so a row saying it's
		// already there mustn't stop it being made again
		if ($canExist && !$hasTarget && ArrayHelper::contains($rows, fn(TranslationFingerprint $row): bool => $row->targetHash !== null)) {
			return null;
		}

		$optionsHash = $this->getOptionsHash($source, $targetSiteId);
		$unchanged = [];
		$changed = [];
		$foreign = [];
		$withText = 0;

		foreach ($attributes as $attribute) {
			$sourceHash = Parser::getAttributeTextDigest($source, $attribute['type'], $attribute['name']);
			// Nothing to spend and nothing to lose
			if ($sourceHash === null) continue;

			$withText++;
			$row = $rows[$attribute['name']] ?? null;

			if ($row === null) {
				// Never translated here, so whatever the target holds is somebody else's. A target still reading the
				// source's own text is the untranslated copy Craft leaves behind, not a person's work.
				$targetHash = $hasTarget
					? Parser::getAttributeTextDigest($target, $attribute['type'], $attribute['name'])
					: null;
				if ($targetHash !== null && $targetHash !== $sourceHash) {
					$foreign[] = $this->getLabel($source, $attribute);
				}

				continue;
			}

			// Digested by a release that made them differently, so the two can't be compared
			if ((int)$row->version !== self::VERSION) continue;

			if ($row->sourceHash === $sourceHash && $row->optionsHash === $optionsHash) {
				$unchanged[] = $this->getLabel($source, $attribute);
			}

			// Whether the translation still reads as Traduco left it, whatever the settings say now: a value someone
			// changed is changed whichever glossary is selected today
			if ($hasTarget && $row->targetHash !== null) {
				$targetHash = Parser::getAttributeTextDigest($target, $attribute['type'], $attribute['name']);
				if ($targetHash !== $row->targetHash) $changed[] = $this->getLabel($source, $attribute);
			}
		}

		return $this->getMessage($targetSiteId, $scope, $canExist, $changed, $foreign, $unchanged, $withText);
	}

	/**
	 * @param string[] $changed
	 * @param string[] $foreign
	 * @param string[] $unchanged
	 * @param int $withText how many of the attributes hold any text at all
	 */
	private function getMessage(int $targetSiteId, string $scope, bool $canExist, array $changed, array $foreign, array $unchanged, int $withText): ?string
	{
		$site = Craft::$app->getSites()->getSiteById($targetSiteId, true)?->getName() ?? (string)$targetSiteId;
		$lines = [];

		// Worded for what's known: a non-translatable value shared with the source moves the translation on its own,
		// so this never claims a person did it
		if ($changed !== []) {
			$lines[] = Craft::t('traduco', '{attributes} has changed in {site} since Traduco translated it. Translating again replaces what’s there now.', [
				'attributes' => $this->listLabels($changed),
				'site' => $site,
			]);
		} elseif ($foreign !== []) {
			$lines[] = Craft::t('traduco', '{site} already has its own {attributes}, which Traduco didn’t write. Translating replaces it.', [
				'attributes' => $this->listLabels($foreign),
				'site' => $site,
			]);
		}

		// Only where every attribute holding text is one that was already translated, so an element with a single
		// changed field still translates the rest without being asked about
		if ($withText > 0 && count($unchanged) === $withText) {
			$lines[] = match (true) {
				$scope === self::SCOPE_REPHRASED => Craft::t('traduco', 'This was already rephrased from the same text. The result wasn’t saved, so rephrasing again gives another suggestion.'),
				$scope === self::SCOPE_SHOWN => Craft::t('traduco', 'This was already translated from the same text and shown to copy.'),
				!$canExist => Craft::t('traduco', 'A separate copy was already made in {site} from this text. Translating again makes another one.', ['site' => $site]),
				default => Craft::t('traduco', 'Nothing has changed since this was translated into {site}.', ['site' => $site]),
			};
		}

		if ($lines === []) return null;

		$lines[] = $scope === self::SCOPE_REPHRASED
			? Craft::t('traduco', 'Rephrase it anyway?')
			: Craft::t('traduco', 'Translate it anyway?');

		return implode("\n\n", $lines);
	}

	/**
	 * @param string[] $labels
	 */
	private function listLabels(array $labels): string
	{
		return implode(', ', $labels);
	}

	/**
	 * @param array{type: AttributeType, name: string} $attribute
	 */
	private function getLabel(ElementInterface $source, array $attribute): string
	{
		if ($attribute['type'] === AttributeType::FIELD) {
			$field = $source->getFieldLayout()?->getFieldByHandle($attribute['name']);
			if ($field !== null) return "“{$field->name}”";
		}

		return "“{$attribute['name']}”";
	}

	/**
	 * @return array<string, TranslationFingerprint> by attribute
	 */
	private function getRows(ElementInterface $source, int $targetSiteId, string $scope): array
	{
		/** @var TranslationFingerprint[] $records */
		$records = TranslationFingerprint::find()
			->where([
				'elementId' => $source->id,
				'siteId' => $source->siteId,
				'targetSiteId' => $targetSiteId,
				'scope' => $scope,
			])
			->all();

		return ArrayHelper::index($records, 'attribute');
	}

	/**
	 * A digest of everything other than the text that decides what comes back: identical text translated with a
	 * glossary it didn't have before is a different translation.
	 *
	 * The translator's own settings, not its resolved options. Resolving a glossary, a style rule or a language's
	 * features asks the API, and a guard whose job is to avoid spending mustn't make a request to decide. The
	 * translator's class is in here too, so switching translator never counts as unchanged.
	 */
	private function getOptionsHash(ElementInterface $source, int $targetSiteId): string
	{
		$settings = Plugin::getInstance()->getSettings()->getUserSettings();
		$translator = (string)$settings->translator;

		return md5((string)Json::encode([
			$translator,
			$settings->getTranslatorSettings($translator),
			$settings->richTextEntries,
			$source->getLanguage(),
			Craft::$app->getSites()->getSiteById($targetSiteId, true)?->language,
		]));
	}
}
