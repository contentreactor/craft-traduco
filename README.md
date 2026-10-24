# Traduco

Translate entries and other elements into your other Craft CMS sites with [DeepL](https://www.deepl.com/pro-api) or [Google Translate](https://cloud.google.com/translate), right from the element editor.

- Translate a whole element into another site, in the background.
- Translate a single field from its action menu.
- Keeps markup intact and copies everything that isn’t text.
- Never overwrites content the source site shares with the target site.

## Requirements

- Craft CMS 5.9 or later
- PHP 8.2 or later
- A DeepL API key, Free or Pro, or a Google Cloud Translation API key

Traduco reads and copies field values with [Field Value Parser](https://github.com/marcusgaius/field-value-parser), which Composer installs with it. Its plugin doesn't need to be installed.

## Installation

```bash
composer require contentreactor/craft-traduco
php craft plugin/install traduco
```

## Setup

Traduco has its own control panel section, with **History**, **Styles**, **Memories**, **Glossaries** and
**Settings** under it. Which of them
you see depends on your permissions and the edition, see [Permissions](#permissions) and [Editions](#editions).

Go to **Traduco → Settings**.

1. On **System Settings**, select **DeepL**, **Google Translate** or **Google Translate (Advanced)** as the translator and enter its API key. Use an environment variable, like `$DEEPL_API_KEY`, to keep the key out of the database. Translators with options of their own show them under the key, see [Translator options](#translator-options).
2. On **Admin Settings**, you can rename the plugin, put the translation dropdown above or below the rest of an element’s sidebar, choose how rich text with nested entries is translated, see [Rich text with nested entries](#rich-text-with-nested-entries), say how long translation history is kept, see [History](#history), and choose which queued translations send an email, see [Notifications](#notifications). The name is used in the control panel section, the element sidebar and the user permissions screen.

The translator can only be changed where `allowAdminChanges` is enabled. The API key and the translator’s own options can be changed on any environment.

Settings are stored in the database and apply to the whole install.

### Translator options

Each translator keeps its own options, so switching translators and back doesn’t lose them. DeepL has seven:

| Option | What it does |
|---|---|
| **Context** | Text describing what the site is about, sent with every translation and never translated itself. DeepL doesn’t count it towards your character usage, and it’s what tells a short value’s sense apart where the value has no sentence around it to go by. |
| **Formality** | How formally DeepL addresses the reader. Sent only to the languages DeepL reports formality for, and left out for the rest, because an option a language doesn’t take fails the request. |
| **Sentence splitting** | Where DeepL may split a value into sentences. Rich text is never split on line breaks, whichever way this is set, because markup carries line breaks that aren’t sentence ends, which is DeepL’s own default with tag handling on. |
| **Glossaries** | Which of your DeepL glossaries to translate with, see [Glossaries](#glossaries). Needs Pro. |
| **Rephrasing style** | How DeepL Write rewrites a value, see [Rephrasing](#rephrasing). Needs Pro. |
| **Style rules** | Which of your DeepL style rules to translate with, see [Styles](#styles). Needs Pro. |
| **Translation memories** | Which memories to reuse translations from, and how close a match has to be, see [Memories](#memories). Needs Pro. |

DeepL also shows what the key has spent of its character allowance for the current billing period, read at most
once a minute. A key with nothing left refuses a whole-element translation before it is queued, rather than letting
it fail in the background: the element would be half translated and the calls already billed. Only the character
allowance is read, never the document one, since Traduco doesn't translate documents.

Google Translate (Advanced) takes no API key. Put the **path** to a service account key file in the key field, as an
environment variable like `$GOOGLE_APPLICATION_CREDENTIALS`, so the key itself stays out of the database. Traduco
reads the project from that file and signs its own access tokens, so no Google client library is needed. Its options
are the Cloud location to call and which of Google's general models to translate with. Custom and AutoML models
aren't offered: one is trained for a single language pair, while this setting is used for every site.

Google Translate has no options of its own yet. Whether a value is sent as markup or as plain text isn’t a setting for either translator: CKEditor and other HTML fields are sent as markup, everything else as plain text.

### Rephrasing

With Pro, a plain text field's action menu gains **Rephrase Field**, which rewrites the value in the language it's
already in through DeepL Write and shows the result for you to copy.

It isn't saved. Translating writes into a site you aren't looking at, while rephrasing writes into the one you have
open, whose own form would overwrite it the moment you submit.

- One install-wide style or tone, on System Settings. DeepL takes one or the other, never both, so they're one
  setting, and each is sent as a `prefer_` variant so a language without it falls back to DeepL's default.
- Rich text isn't offered. DeepL Write takes no tag handling, so a value's markup would be rewritten as words.
- DeepL Write needs a paid DeepL key. On a Free key the action isn't offered and the settings screen says why, read
  from the key's own suffix rather than by asking DeepL.
- It's recorded in the [history](#history) with what it sent. Nothing was saved, but DeepL Write bills the same
  characters translating does, so a rewrite you threw away is still accounted for.

### Styles

**Traduco → Styles** manages your DeepL style rules: each one is written for a single language and tells DeepL how to
sound in it. Create, rename and delete them, and edit the custom instructions a rule carries, which are free text in
your own words. Choose which of them to translate with on System Settings; only the rule whose language is being
translated into is ever sent.

A rule's *configured rules* are left to DeepL's own interface. Neither its API nor its documentation names one rule
key or one allowed value, so a screen here would be guessing.

### Memories

**Traduco → Memories** lists your DeepL translation memories and deletes them. Choose which to reuse translations
from on System Settings, along with how close a stored segment has to be before DeepL reuses it.

Creating a memory, renaming one and importing segments stay in DeepL's own interface. DeepL has no call for the first
two, and its import and export poll until it has finished, which a control panel request can't wait for.

### Glossaries

Glossaries are made in your DeepL account, and chosen here. Each one is listed with the language pairs it has a
dictionary for, and a translation only ever carries the glossary covering the pair it’s translating, so several can be
chosen at once:

- A glossary for a base language covers its regional sites, so an `en` → `de` glossary is used for an `en-GB` site
  translating into `de-AT`. Two variants of one language don’t stand in for each other, as `en-GB` and `en-US` are
  different languages to DeepL.
- Two glossaries covering the same pair are refused when you save. DeepL doesn’t document which of them would win, so
  the choice is made here instead.
- DeepL needs an explicit source language for a glossary. Sites whose language it only detects can’t use one, and the
  settings screen lists them.
- Glossaries are chosen for the whole install, not per site. Two sites sharing a language share their glossary.
- The list is cached for five minutes, so a glossary you just made in DeepL shows up shortly.

#### Managing them

**Traduco → Glossaries** lists the glossaries of the DeepL account the API key belongs to, and edits them in place.
Nothing is copied into Craft: a glossary made in DeepL’s own interface is managed from the same screen, and one deleted
here is gone from the account.

A glossary holds one dictionary per language pair. The edit screen works a pair at a time: pick the languages, then
either type the entries, one a line with the term and its translation separated by a tab, or upload a TSV or CSV file
with the term in the first column and its translation in the second. Saving **replaces** every entry of that pair, so
export what’s there first if you only mean to add to it.

The language options are your own site languages, labelled with the DeepL code each one resolves to, so a glossary can
only be written for a pair Traduco would actually ask for.

#### Google's glossaries

Google models glossaries differently, so **Traduco → Glossaries** shows Google's own screen when Advanced is the
selected translator. Google builds a glossary from a file in Cloud Storage, so creating one here takes the `gs://` URI
of a TSV, CSV or TMX file you already keep in your own bucket, and the service account needs the Storage Object Viewer
role on it. Traduco never writes to your bucket: uploading the file would mean enabling a second API and granting
write access on top of the read access Google itself needs.

- Google keeps glossaries per region, so the `global` location has none. Set a region to use them.
- Terms are edited one at a time. Google has no call to replace a glossary's entries, only to rebuild it from another
  file.
- Google matches language codes exactly, so a glossary written for `en` isn't used for an `en-GB` site. The screen
  shows the code each site language resolves to.
- An equivalent term set covers several languages at once, with one term per language. Existing ones are listed and
  translated with, but not edited here.

### History

**Traduco → History** keeps a row per translation: the element, the sites it went between, whether a whole element, one
field, a translation shown to copy or a rephrasing, how many characters it sent, who asked for it, which translator
answered, and whether it finished or failed and why. A whole-element translation is recorded as queued and finished by
its queue job.

The character count is what Traduco sent to the service, which is what the service bills, and a translation that failed
halfway still reports what it spent getting there. A translator that doesn’t count what it sends leaves the column
empty.

Rows are kept for as many days as **Keep Translation History For** says on Admin Settings, and deleted on garbage
collection after that. Set it to 0 to keep them until they’re deleted by hand.

A translation the queue hands out a second time, because the worker that had it died without reporting anything, is
recorded as interrupted rather than translated again: the part the dead worker translated is saved and billed, so
repeating it would bill the same text twice. Translate the element again to finish it.

History needs Pro. On Lite nothing is recorded and the screen isn’t in the section.

### Before it runs again

Traduco remembers the text it translated and the translation it wrote, as a digest per element, site pair and field.
Before it translates or rephrases anything it asks about three things:

| It asks when | Because |
|---|---|
| The text and the settings are the same ones it last translated | The result would be the same, and you'd pay for it again |
| The translation has changed since Traduco wrote it | Translating again replaces what's there now, including a correction someone made by hand |
| The target site already holds its own value, which Traduco never wrote | A translation made before Traduco was installed is still somebody's work |

Each one is a question, never a refusal: carry on and it translates. That matters because the signal is deliberately
approximate, and because DeepL Write doesn't give the same phrasing twice, so asking for another suggestion is a
reasonable thing to do.

What it compares is the text, so a changed relation, date or number counts as unchanged. It digests the text of
nested entries too, so editing a Matrix or CKEditor block is noticed. It covers the translator's own settings as
well: identical text translated with a glossary it didn't have before is a different translation and goes through
without a question. Changing a glossary's entries, without changing which glossary is selected, isn't noticed.

This isn't part of the [history](#history): it's kept for as long as the element exists, rather than for the
retention period, and it works on Lite, because not replacing someone's work isn't a paid feature.

### Notifications

Whole elements are translated in the queue, so they finish out of sight. **Email Notifications** on Admin Settings says
whether the user who asked for one gets an email about it: never, **only when a translation fails** (the default), or
every translation.

The emails are Craft’s own system messages, so their subject and body are editable per language under
**Utilities → System Messages**, where they appear as “When a queued translation fails” and “When a queued translation
finishes”. Each one can use `{{ title }}`, `{{ elementId }}`, `{{ sourceSite }}`, `{{ targetSite }}` and `{{ error }}`.

- The recipient is the user the control panel recorded when the translation was queued. A translation queued from the
  console has no user, so nothing is sent.
- A message that can’t be sent is logged and the translation stands. Translating is never held up by reporting it.
- Where Craft’s `testToEmailAddress` is set, it sends every message to that address instead, this one included.
- Notifications need Pro. On Lite nothing is sent and the two messages aren’t registered with Craft at all.

### Permissions

Traduco’s permissions are nested under Craft’s own **Access Traduco**, which is what shows the control panel section.

| Permission | Grants |
|---|---|
| View translation history | The History screen |
| Manage glossaries | The Glossaries screens, which read and write your translator's account |
| Manage style rules | The Styles screens, which read and write your DeepL account |
| Manage translation memories | The Memories screen |
| Manage Settings → Manage Admin Settings | The Admin Settings screen: plugin name, rich text with nested entries, history retention and notifications |
| Manage Settings → Manage System Settings | The System Settings screen: translator, API key and the translator’s own options |

Translating has no permission of its own. Users can translate an element when they can view it in the site they’re editing and save it in the target site.

## Usage

### Translate an element

Open an entry, or any other localized element, and pick a site from the Traduco dropdown in the sidebar. The dropdown lists the sites of the entry’s section, or a node’s navigation, that you can edit, whether the element exists in them yet or not.

The translation runs as a queue job. A spinner shows next to the dropdown while the job is being queued.

- **The element exists in that site:** it’s updated.
- **It doesn’t, but its section can save it there,** like with custom propagation or propagation to all sites: the element is added to that site.
- **Its section can’t have it in that site,** like with “Only save entries to the site they were created in”: a separate, translated copy is created there. Translating again creates another copy.

### Translate a field

Open a field’s action menu and choose **Translate Field into …**, or **Translate Attribute into …** for the title, with the site to translate into. The value is translated from the site you’re editing and saved in that site.

When the element doesn’t exist in that site yet, it’s created there the same way, with only this field translated and its other values copied as they are.

For fields inside Matrix and Neo blocks, the translation is saved when the target site has the same block, like with fields that keep their blocks in every site. Otherwise the menu item ends with “…” and shows the translation to copy. Rich text is previewed rendered, and **Copy** puts its HTML on the clipboard. Links show each of their texts with its own copy button.

### Drafts and revisions

Translations start from saved content. The dropdown and the field menu items are disabled while you’re looking at a draft or a revision, or have unsaved changes, and so are those of the entries nested in them. Save your changes to translate.

## What gets translated

- **Plain Text and HTML fields**, including CKEditor. HTML is sent with DeepL’s tag handling, so markup stays intact. For CKEditor fields with nested entries, see [Rich text with nested entries](#rich-text-with-nested-entries).
- **Titles.** New translations get a slug from their translated title. Existing ones only while they still use the source’s slug, so their URLs don’t change.
- **Matrix, Neo and Content Block fields**, see below.
- **SEOmatic SEO Settings fields**: the titles and descriptions the entry overrides.
- **Link fields**: the label, title text and ARIA label of Craft’s link fields, and the custom label of Typed Link fields ([sebastianlenz/linkfield](https://github.com/sebastianlenz/linkfield)). The link itself is copied, never translated.
- **Navigation nodes**: only their labels, and only for nodes that don’t link to an element. Links and linked elements are never changed.

Everything else is copied as it is. Relation fields keep pointing to the same elements.

**Addresses fields** are left as they are. Craft doesn't localize addresses, so every site of an element reads the same ones, and translating a label would change it for the source site too. A copy of an element in a section that can't hold it gets its own addresses from Craft's duplication.

### Values that aren’t translatable

Traduco follows each field’s translation method, and the entry type’s for titles and slugs. When the source and target site share a value, like for fields that aren’t translatable or fields translated per site group within the same group, the value is left as it is, and a field’s action menu only lists sites that store their own value.

### Nested entries

- **Structure kept in every site,** like Matrix and Neo fields that save entries to all sites, or Content Block fields: entries are never added, removed or reordered. The fields inside them are translated in place, following the same rules. Entries that don’t exist in the target site are skipped.
- **Separate per site**: the target site’s entries are replaced with translated copies of the source’s entries, which keep their enabled state.

### Rich text with nested entries

CKEditor fields can hold nested entries between their text. The **Rich Text with Nested Entries** setting decides how they’re translated. It applies to translating elements and fields alike.

- **Translate nested entries where the target site has its own, render them into the text otherwise** (default): when the target site has its own nested entries in the field, as many and of the same entry types as the source’s, like after copying the field’s value from another site, the text around them is translated, the target keeps its entries, and the fields inside them are translated following the same rules as other nested entries. Otherwise the field is rendered, see below.
- **Always render nested entries into the translated text**: the source’s text is translated with its enabled nested entries rendered into it, through their partial templates, and saved in the target site as plain rich text. The target site’s value no longer has nested entries, and the source’s entries are never changed.

Only the rendered HTML is sent to the translator, never the `<craft-entry>` placeholders. Rendered HTML is saved through the field’s HTML Purifier config, which removes markup the field doesn’t allow. Reference tags in the text are kept, so links point to the elements in the target site.

When the field’s value is shared with the target site, like with fields that aren’t translatable, it’s left as it is, and so are its nested entries. Rich text shown to copy, in blocks the target site doesn’t have, is always rendered.

### Languages

Traduco translates from the source site’s language into the target site’s language. With DeepL:

- Regional languages DeepL supports are used as they are, like `en-US` or `pt-BR`.
- Other regional languages fall back to their base language, so `de-AT` becomes `de`.
- `en` and `pt` translate into `en-GB` and `pt-PT`.
- When DeepL doesn’t support the source language, it detects the language instead.
- DeepL’s languages, and the features each one supports, are read once a day per API key and cached.

With Google Translate:

- The writing system is kept, so a `zh-Hant-TW` site gets Traditional Chinese and a `zh-Hans-HK` site Simplified.
- Regional languages Google supports are used as they are, like `pt-BR` or `fr-CA`. Others fall back to their base language.
- Languages Google reports under an older code are mapped to it, so `he` becomes `iw` and `nb` becomes `no`.
- When Google doesn’t support the source language, it detects the language instead.
- Google Translate (Advanced) maps languages the same way, and caches the list its project reports for a day, since
  that call is authenticated and billed against your own project.

## Extending

### Translatable field types

Register other field types that hold text:

```php
use craft\events\RegisterComponentTypesEvent;
use ContentReactor\Traduco\Services\Traduco;
use yii\base\Event;

Event::on(Traduco::class, Traduco::EVENT_REGISTER_TRANSLATABLE_FIELDS, function (RegisterComponentTypesEvent $event) {
	$event->types[] = MyTextField::class;
});
```

Their values need to be a string, or cast to one, and accept a string back. Field types whose values hold several texts, like a link's label and title text, register their text parts with Field Value Parser, which Traduco reads and replaces them through:

```php
use MarcusGaius\FieldValueParser\Events\RegisterFieldTextsEvent;
use MarcusGaius\FieldValueParser\Services\Texts;

Event::on(Texts::class, Texts::EVENT_REGISTER_FIELD_TEXTS, function (RegisterFieldTextsEvent $event) {
	$event->texts[MyLinkField::class] = new MyLinkFieldTexts(); // implements MarcusGaius\FieldValueParser\Texts\FieldTexts
});
```

### Translators

Extend `ContentReactor\Traduco\Base\BaseTranslator` and implement `translate()`. The base class takes care of translating elements, fields and texts.

```php
use ContentReactor\Traduco\Base\BaseTranslator;

class MyTranslator extends BaseTranslator
{
	public function __construct(string $apiKey)
	{
		// $apiKey is the API key setting, with environment variables resolved
	}

	public static function displayName(): string
	{
		return 'My Translator';
	}

	public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): string
	{
		// $targetLanguage and $sourceLanguage are site languages, like de-AT
		$this->countCharacters($text); // optional: what the history records a translation as having spent
	}
}
```

Call `countCharacters()` with each text before you send it and the translation [history](#history) records what the
run spent. Leave it out and the history's character column stays empty for your translator; nothing else changes.

#### Options of your own

A translator can take options and show them on System Settings. Return a settings model from `createSettings()` and let
the model draw its own fields, then read it back in `getTranslateOptions()`, which both paths that translate text call:

```php
use ContentReactor\Traduco\Base\{
	BaseTranslator,
	TranslatorSettings,
};
use craft\base\{
	ElementInterface,
	FieldInterface,
};

class MyTranslatorSettings extends TranslatorSettings
{
	public string $tone = '';

	public function getSettingsHtml(): string
	{
		// The settings screen namespaces these and decides when they're shown
		return Craft::$app->getView()->renderTemplate('my-plugin/translator-settings.twig', ['settings' => $this]);
	}
}

class MyTranslator extends BaseTranslator
{
	public function __construct(string $apiKey, array $settings = [])
	{
		parent::__construct($apiKey, $settings);
	}

	public static function createSettings(array $values = []): ?TranslatorSettings
	{
		return MyTranslatorSettings::create($values);
	}

	public function getTranslateOptions(ElementInterface $source, string $targetLanguage, ?FieldInterface $field = null): array
	{
		// $field is null for an attribute like a title or a slug, and is what tells markup from plain text
		if (!$this->settingsModel instanceof MyTranslatorSettings) return [];

		return ['tone' => $this->settingsModel->tone];
	}
}
```

The settings are stored under the translator’s class, so each translator keeps its own and switching between them loses
nothing. `TranslatorInterface` declares a constructor that takes only the API key, and PHP holds every implementation
to that signature, so the settings are an extra optional parameter: a translator that takes only the key keeps working.

Then register it, so it can be selected on System Settings:

```php
use craft\events\RegisterComponentTypesEvent;
use ContentReactor\Traduco\Services\Translations;
use yii\base\Event;

Event::on(Translations::class, Translations::EVENT_REGISTER_TRANSLATOR, function (RegisterComponentTypesEvent $event) {
	$event->types[] = MyTranslator::class;
});
```

Google Translate uses Cloud Translation Basic, the edition that takes an API key. Enable the Cloud Translation API on the key’s Google Cloud project.

## Editions

Traduco comes in two editions, from the Plugin Store:

| | Lite | Pro |
|---|:---:|:---:|
| Translating a field from its action menu | ✓ | ✓ |
| DeepL’s context, formality and sentence splitting options | ✓ | ✓ |
| Translating whole elements into other sites, in the background, with their nested entries | | ✓ |
| Translating with your DeepL glossaries | | ✓ |
| Managing your glossaries from the control panel | | ✓ |
| Translation history | | ✓ |
| Email notifications for queued translations | | ✓ |
| Google Translate (Advanced), on a service account, with model choice | | ✓ |
| Rephrasing a field's value with DeepL Write | | ✓ |
| DeepL style rules, custom instructions and translation memories | | ✓ |

On Lite, the element sidebar tells where translating whole elements comes from, and fields are still translated from their action menus.

## Support

Report issues at https://github.com/contentreactor/craft-traduco/issues, or write to support@contentreactor.com.

## License

The Craft License, see [LICENSE.md](LICENSE.md).
