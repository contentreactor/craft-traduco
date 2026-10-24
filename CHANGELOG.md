# Release Notes for Traduco

## 1.0.0 - 2026-10-24

> [!IMPORTANT]
> Traduco comes in Lite and Pro editions. Lite translates fields from their action menus. Translating whole elements into other sites needs Pro.

- Initial release.
- Lite and Pro editions. Lite translates fields from their action menus. Pro translates whole elements into other sites, in the background.
- DeepL and Google Translate as translators, with the API key from an environment variable.
- Each translator keeps options of its own on System Settings. DeepL takes a context, a formality and a sentence splitting setting, and formality is sent only to the languages DeepL reports it for.
- DeepL glossaries, with Pro. Choose them from your DeepL account, and each translation carries the one covering the language pair it's translating.
- A control panel section, with the history, the glossaries and the settings screens under it, and the plugin's permissions nested under Craft's own permission to access it.
- Translation history, with Pro. A row per translation, with its outcome, kept for as many days as the settings say and deleted on garbage collection.
- Email notifications for queued translations, with Pro. Failures only by default, and the subject and body of each message are editable under Utilities.
- The element translation job reserves itself for 600 seconds and reports progress after every attribute, so a long translation isn't reclaimed and run twice. A translation the queue hands out again, because the worker that had it died, is reported as interrupted rather than translated and billed a second time.
- The translation history records how many characters each translation sent, which is what the service bills, and counts a rephrasing too.
- Traduco asks before translating text that hasn't changed since it last translated it, and before replacing a translation that has changed since it wrote it. It also says when the target site already holds content Traduco didn't write, so a translation someone made by hand isn't replaced without a word. Each one is a question rather than a refusal: carry on and it translates.
- Where the translation dropdown sits in an element's sidebar is a setting on the General screen.
- Rich text is never split into sentences on its line breaks, whichever way the DeepL setting is set: markup carries line breaks that aren't sentence ends.
- DeepL's character usage beside the API key, and a queued translation refused up front when the key has nothing left.
- Google Translate (Advanced), with Pro. Authenticated with a service account whose key file path is an environment variable, and offering Google's general models.
- Google glossaries, with Pro. Built from a file in your own Cloud Storage bucket, managed from the control panel, and sent for the language pair each one covers.
- DeepL Write, with Pro. Rephrase a field's value from its action menu, in one install-wide style or tone, and copy the result.
- DeepL style rules and their custom instructions, with Pro, and translation memories with a reuse threshold. Both chosen on System Settings and sent with every translation.
- Glossary management, with Pro. Name a glossary, write a language pair's entries or upload them as TSV or CSV, and delete glossaries and their dictionaries, all against your DeepL account rather than a copy.
- Whether a value goes out as markup follows the field it's stored in, so a Plain Text value holding a bare `<` isn't sent as HTML.
- Addresses fields are left as they are, as Craft doesn't localize addresses.
- Traduco is licensed under the Craft License.
