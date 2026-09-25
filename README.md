# Traduco

Translate entries and other elements into your other Craft CMS sites with [DeepL](https://www.deepl.com/pro-api), right from the element editor.

- Translate a whole element into another site, in the background.
- Translate a single field from its action menu.
- Keeps markup intact and copies everything that isn’t text.
- Never overwrites content the source site shares with the target site.

## Requirements

- Craft CMS 5.8 or later
- PHP 8.2 or later
- A DeepL API key, Free or Pro

Traduco reads and copies field values with [Field Value Parser](https://github.com/marcusgaius/field-value-parser), which Composer installs with it. Its plugin doesn't need to be installed.

## Installation

```bash
composer require contentreactor/craft-traduco
php craft plugin/install traduco
```

## Setup

Go to **Settings → Plugins → Traduco**.

1. On **System Settings**, select **DeepL** as the translator and enter your API key. Use an environment variable, like `$DEEPL_API_KEY`, to keep the key out of the database.
2. On **Admin Settings**, you can rename the plugin, and choose how rich text with nested entries is translated, see [Rich text with nested entries](#rich-text-with-nested-entries). The name is used in the element sidebar and on the user permissions screen.

The translator can only be changed where `allowAdminChanges` is enabled. The API key can be changed on any environment.

Settings are stored in the database and apply to the whole install.

### Permissions

| Permission | Grants |
|---|---|
| Manage Settings → Manage Admin Settings | The Admin Settings screen: plugin name and rich text with nested entries |
| Manage Settings → Manage System Settings | The System Settings screen: translator and API key |

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
	}
}
```

Then register it, so it can be selected on System Settings:

```php
use craft\events\RegisterComponentTypesEvent;
use ContentReactor\Traduco\Services\Translations;
use yii\base\Event;

Event::on(Translations::class, Translations::EVENT_REGISTER_TRANSLATOR, function (RegisterComponentTypesEvent $event) {
	$event->types[] = MyTranslator::class;
});
```

Google Translate support is planned. Its translator class is a stub and isn’t offered yet.

## Support

Report issues at https://github.com/contentreactor/craft-traduco/issues, or write to support@contentreactor.com.

## License

MIT, see [LICENSE.md](LICENSE.md).
